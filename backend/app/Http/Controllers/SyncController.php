<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SyncRun;
use App\Models\Vehicle;
use App\Services\OfficeManagerClient;
use Illuminate\Http\Request;

/**
 * Manual, on-demand data sync — the "buttons" on the Data Sync page.
 * Each action launches an artisan command in the BACKGROUND (long syncs can't run
 * inside one HTTP request), and the status endpoint reports the live row counts so
 * you can watch the data fill in. All syncs are idempotent (no duplicates).
 */
class SyncController extends Controller
{
    /** action key => artisan command (whitelist — no user input ever reaches the shell) */
    private const ACTIONS = [
        // --- One-click full refresh: sheets + API in the right order, status from the API ---
        'fresh'         => 'fleet:refresh',
        // --- DESTRUCTIVE: wipe all operational data, then rebuild from every source ---
        'reset'         => 'fleet:refresh --wipe',
        // --- OfficeManager API (primary source) ---
        // Import OUR cars from the API (owner 1541 + the extra serials, e.g. the 2088 GMC).
        'api_vehicles'  => 'om:sync --vehicles',
        'api_link'      => 'om:sync --link',
        'api_contracts' => 'om:sync --contracts',
        'api_invoices'  => 'om:sync --invoices',
        'api_customers' => 'om:sync --customers',
        // BULK name fill via the paged customers list — 503-resilient, fills all stubs fast.
        'api_customers_bulk' => 'om:sync --customers-bulk',
        // Full sync now runs each phase independently (a failing phase won't abort the
        // rest) and uses the fast, 503-resilient BULK name fill instead of the fragile
        // one-call-per-customer path that used to make this monolith time out.
        // --vehicles now sets car_serial + status (the old --link step is folded in).
        'api_all'       => 'om:sync --vehicles --contracts --invoices --customers-bulk',
        // --- Google Sheets ---
        'sheet_vehicles'        => 'sync:vehicles',
        'sheet_registrations'   => 'sync:registrations',
        'sheet_insurance'       => 'sync:insurance',
        // N-Maintenance & Repair log -> maintenances table (standalone rows, car matched by plate).
        'sheet_maintenance'     => 'import:maintenance-sheet',
        // Curated garages + used-parts list -> vendors (matched by name, not plate).
        'sheet_garages'         => 'garages:sync',
    ];

    /** Friendly labels for the run log. */
    private const LABELS = [
        'fresh' => 'Fresh Full Sync', 'reset' => 'Reset & Rebuild (wipe)',
        'api_vehicles' => 'Cars (API)', 'api_link' => 'Link cars', 'api_contracts' => 'Contracts', 'api_invoices' => 'Invoices',
        'api_customers' => 'Customer names', 'api_customers_bulk' => 'Customer names (bulk)', 'api_all' => 'Full API Sync',
        'sheet_vehicles' => 'Cars info (sheet)', 'sheet_registrations' => 'Registrations (sheet)',
        'sheet_insurance' => 'Insurance + Mulkiya (sheet)', 'sheet_maintenance' => 'Maintenance log (sheet)',
        'sheet_garages' => 'Garages list (sheet)',
    ];

    /** Live counts of what's stored locally + whether a sync is running. */
    public function status()
    {
        try {
            $named = Customer::whereNotNull('name_en')->where('name_en', '<>', '')->count();

            // Live OS processes (scanned once, reused below).
            $procs = $this->runningSyncProcesses();

            // ORPHAN GUARD: a row marked 'running' whose background process is gone
            // (crashed / killed) — clear it within seconds instead of waiting 30 min.
            // A short startup grace avoids racing a just-launched, not-yet-visible process.
            $liveRunIds = collect($procs)
                ->map(fn ($p) => preg_match('/--run-id=(\d+)/', $p['command'], $m) ? (int) $m[1] : null)
                ->filter()->values()->all();

            // Flag only if the process is gone AND the row hasn't progressed in 5 min —
            // so a live, actively-updating job (even a manual one with no --run-id) is never
            // wrongly failed, and a genuinely dead one still clears within ~5 min (not 30).
            // The window is generous on purpose: every long phase now writes a heartbeat,
            // so a healthy job updates far more often than this; the slack only absorbs a
            // transient miss by the (best-effort) OS process scan.
            SyncRun::where('status', 'running')
                ->whereNotIn('id', $liveRunIds ?: [0])
                ->where('updated_at', '<', now()->subMinutes(5))
                ->update([
                    'status'      => 'failed',
                    'error'       => 'Process ended before completing (no running job found).',
                    'finished_at' => now(),
                ]);

            // STALENESS BACKSTOP: a row whose process is alive but hasn't progressed in 30 min.
            SyncRun::where('status', 'running')
                ->where('updated_at', '<', now()->subMinutes(30))
                ->update([
                    'status'      => 'failed',
                    'error'       => 'Sync stalled — no progress for 30 minutes (the API may be slow or unreachable).',
                    'finished_at' => now(),
                ]);

            $current = SyncRun::where('status', 'running')->latest('id')->first();
            $recent = SyncRun::whereIn('status', ['done', 'partial', 'failed'])->latest('id')->limit(6)->get();

            return ResponseHelper::SuccessResponse([
                'contracts'           => Contract::count(),
                'invoices'            => Invoice::count(),
                'customers'           => Customer::count(),
                'customers_named'     => $named,
                'vehicles'            => Vehicle::count(),
                'contracts_enriched'  => Contract::where('out_milage', '>', 0)->count(),
                'last_synced'         => optional(Contract::max('synced_at')) ? (string) Contract::max('synced_at') : null,
                'sync_running'        => (bool) $current,
                'current_run'         => $current ? [
                    'action'     => $current->action,
                    'phase'      => $current->phase,
                    'processed'  => $current->processed,
                    'total'      => $current->total,
                    'started_at' => (string) $current->started_at,
                ] : null,
                'recent_runs'         => $recent->map(fn ($r) => [
                    'action'      => $r->action,
                    'status'      => $r->status,
                    'changed'     => $this->summarize($r->result ?? []),
                    'error'       => $r->error,
                    'finished_at' => (string) $r->finished_at,
                    'seconds'     => ($r->started_at && $r->finished_at) ? $r->started_at->diffInSeconds($r->finished_at) : null,
                ])->values(),
                // actual OS-level background jobs (not just DB rows) so you can see/kill them
                'processes'           => $procs,
            ], "Sync status retrieved", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** Launch a sync action in the background. */
    public function run(Request $request)
    {
        // The web UI is a read-only monitor: syncs (and the wipe) run from the CLI on a
        // schedule, never from the browser. Refuse here too, so even a hand-crafted request
        // can't start a sync or a wipe. Flip officemanager.web_sync_enabled to re-enable.
        if (! config('officemanager.web_sync_enabled', false)) {
            return ResponseHelper::FailureResponse(
                null,
                'Web-triggered syncs are disabled — this page is read-only. Run syncs from the server (sync-fleet). See DEPLOYMENT.md.',
                403
            );
        }

        try {
            $action = (string) $request->input('action');
            if (! isset(self::ACTIONS[$action])) {
                return ResponseHelper::FailureResponse(null, "Unknown sync action: {$action}", 422);
            }

            $cmd = self::ACTIONS[$action];

            // For the API syncs (and the orchestrated fresh sync), create a tracked run row
            // and report progress into it. These commands accept --run-id.
            if (str_starts_with($action, 'api_') || $action === 'fresh' || $action === 'reset') {
                $run = SyncRun::create([
                    'action'     => self::LABELS[$action] ?? $action,
                    'status'     => 'running',
                    'started_at' => now(),
                ]);
                $cmd .= ' --run-id=' . $run->id;
            }

            $this->launch($cmd);

            return ResponseHelper::SuccessResponse(
                ['action' => $action, 'started' => true],
                "Sync started in the background — progress and results will appear as it runs.",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Fetch a small LIVE sample straight from the OfficeManager API so you can see the
     * raw response (every field) for cars / contracts / invoices. Read-only (GET).
     */
    public function preview(Request $request, OfficeManagerClient $api)
    {
        try {
            $endpoint = (string) $request->query('endpoint', 'vehicles');

            switch ($endpoint) {
                case 'vehicles':
                    // vehicles honors page_size -> just grab 2
                    $page = $api->fetchOnce('vehicles', ['page' => 1, 'page_size' => 2]);
                    break;
                case 'invoices':
                    $page = $api->fetchOnce('invoices', ['page' => 1, 'page_size' => 2]);
                    break;
                case 'contracts':
                    // contracts IGNORES page_size (would dump all ~45k) -> sample a small date window
                    $page = $api->fetchOnce('contracts', [
                        'from_date' => now()->subDays(14)->toDateString(),
                        'to_date'   => now()->addDay()->toDateString(),
                    ]);
                    break;
                default:
                    return ResponseHelper::FailureResponse(null, "Unknown endpoint: {$endpoint}", 422);
            }

            return ResponseHelper::SuccessResponse([
                'endpoint' => $endpoint,
                'total'    => $page['total'],
                'showing'  => min(2, count($page['items'])),
                'sample'   => array_slice($page['items'], 0, 2),
            ], "Live API sample for {$endpoint}", 200);
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(null, 'API request failed: ' . $e->getMessage(), 502);
        }
    }

    /**
     * Kill a running background sync process by PID. SAFETY: only PIDs that are
     * actually one of OUR om:sync / import jobs (per the live process scan) can be
     * killed — never an arbitrary process.
     */
    public function kill(Request $request)
    {
        try {
            $pid = (int) $request->input('pid');

            $match = collect($this->runningSyncProcesses())->firstWhere('pid', $pid);
            if (! $match) {
                return ResponseHelper::FailureResponse(
                    null,
                    "PID {$pid} is not an active sync job (it may have already finished).",
                    404
                );
            }

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // /T kills the child tree, /F forces it
                @shell_exec('taskkill /PID ' . $pid . ' /T /F 2>&1');
            } else {
                @shell_exec('kill -9 ' . $pid . ' 2>&1');
            }

            // mark any matching still-"running" sync row as stopped so the log is tidy
            SyncRun::where('status', 'running')->update([
                'status'      => 'failed',
                'error'       => 'Stopped manually from the Data Sync page.',
                'finished_at' => now(),
            ]);

            return ResponseHelper::SuccessResponse(
                ['pid' => $pid],
                "Stopped “{$match['job']}” (PID {$pid}).",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Live list of background sync jobs actually running on the OS (om:sync / import:*),
     * each with its PID, friendly job name and start time.
     *
     * @return array<int, array{pid:int, job:string, command:string, started:?string}>
     */
    private function runningSyncProcesses(): array
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                return $this->scanWindows();
            }
            return $this->scanUnix();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function scanWindows(): array
    {
        // Run via a temp .ps1 to avoid inline-quoting issues; emits one JSON object per line.
        $script = <<<'PS'
Get-CimInstance Win32_Process -Filter "name='php.exe'" |
  Where-Object { $_.CommandLine -match 'om:sync|import:|fleet:refresh|sync:|garages:' } |
  ForEach-Object {
    [pscustomobject]@{ pid = $_.ProcessId; cmd = $_.CommandLine; started = $_.CreationDate.ToString('yyyy-MM-dd HH:mm:ss') } |
      ConvertTo-Json -Compress
  }
PS;
        $tmp = storage_path('app/_sync_procscan_' . getmypid() . '_' . uniqid() . '.ps1');
        @file_put_contents($tmp, $script);
        $out = (string) @shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $tmp . '" 2>&1');
        @unlink($tmp);

        $procs = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $p = json_decode($line, true);
            if (! is_array($p) || ! isset($p['pid'])) {
                continue;
            }
            $procs[] = [
                'pid'     => (int) $p['pid'],
                'job'     => $this->jobLabel((string) ($p['cmd'] ?? '')),
                'command' => $this->shortCommand((string) ($p['cmd'] ?? '')),
                'started' => $p['started'] ?? null,
            ];
        }

        usort($procs, fn ($a, $b) => ($a['started'] ?? '') <=> ($b['started'] ?? ''));
        return $procs;
    }

    private function scanUnix(): array
    {
        $out = (string) @shell_exec("ps -eo pid=,lstart=,args= | grep -E 'om:sync|import:|fleet:refresh|sync:|garages:' | grep -v grep");
        $procs = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(.{24})\s+(.*)$/', $line, $m)) {
                continue;
            }
            $procs[] = [
                'pid'     => (int) $m[1],
                'job'     => $this->jobLabel($m[3]),
                'command' => $this->shortCommand($m[3]),
                'started' => trim($m[2]),
            ];
        }
        return $procs;
    }

    /** A friendly job name from a command line (e.g. "Contracts + Customer names"). */
    private function jobLabel(string $cmd): string
    {
        if (str_contains($cmd, 'fleet:refresh')) {
            return str_contains($cmd, '--wipe') ? 'Reset & Rebuild (wipe)' : 'Fresh Full Sync';
        }
        if (str_contains($cmd, 'sync:vehicles')) {
            return 'Cars info (sheet)';
        }
        if (str_contains($cmd, 'sync:registrations')) {
            return 'Registrations (sheet)';
        }
        if (str_contains($cmd, 'sync:insurance')) {
            return 'Insurance + Mulkiya (sheet)';
        }
        if (str_contains($cmd, 'import:maintenance-sheet')) {
            return 'Maintenance log (sheet)';
        }
        if (str_contains($cmd, 'import:maintenance-reasons')) {
            return 'Maintenance reasons (sheet)';
        }
        if (str_contains($cmd, 'maintenance:link-reasons')) {
            return 'Link maintenance reasons';
        }
        if (str_contains($cmd, 'garages:sync')) {
            return 'Garages list (sheet)';
        }
        if (str_contains($cmd, 'om:sync')) {
            $parts = [];
            foreach ([
                '--vehicles' => 'Cars (API)', '--link' => 'Link cars', '--clear' => 'Clear sheets', '--contracts' => 'Contracts',
                '--invoices' => 'Invoices',
            ] as $flag => $label) {
                if (str_contains($cmd, $flag)) {
                    $parts[] = $label;
                }
            }
            if (str_contains($cmd, '--customers-bulk')) {
                $parts[] = 'Customer names (bulk)';
            } elseif (preg_match('/--customers(?!-bulk)/', $cmd)) {
                // plain per-customer enrich (also matches the --customers in api_all)
                $parts[] = 'Customer names';
            }
            return $parts ? implode(' + ', $parts) : 'Sync';
        }
        return 'Background job';
    }

    /** Trim the command down to the artisan part for display. */
    private function shortCommand(string $cmd): string
    {
        if (preg_match('/artisan["\']?\s+(.*)$/', $cmd, $m)) {
            return 'artisan ' . trim($m[1]);
        }
        return trim($cmd);
    }

    /** Spawn a detached artisan command (Windows `start /B`, otherwise nohup &). */
    private function launch(string $command): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $full = sprintf('start /B "" "%s" "%s" %s > NUL 2>&1', $php, $artisan, $command);
            pclose(popen($full, 'r'));
        } else {
            $full = sprintf('nohup "%s" "%s" %s > /dev/null 2>&1 &', $php, $artisan, $command);
            exec($full);
        }
    }

    /** Turn a run's result payload into a short "what changed" line. */
    private function summarize(array $r): string
    {
        $p = [];
        if (isset($r['backup'])) {
            $p[] = 'backed up';
        }
        if (isset($r['wipe'])) {
            $w = $r['wipe'];
            $p[] = 'wiped ' . ($w['contracts'] ?? 0) . ' contracts / ' . ($w['customers'] ?? 0) . ' customers / ' . ($w['vehicles'] ?? 0) . ' vehicles';
        }
        if (isset($r['api_vehicles'])) {
            $p[] = 'cars (API) +' . ($r['api_vehicles']['created'] ?? 0) . ' / ' . ($r['api_vehicles']['updated'] ?? 0) . ' updated';
        }
        if (isset($r['vehicles'])) {
            $p[] = 'cars +' . ($r['vehicles']['created'] ?? 0) . ' / ' . ($r['vehicles']['updated'] ?? 0) . ' updated';
        }
        if (isset($r['link'])) {
            $p[] = 'linked ' . ($r['link']['cars'] ?? $r['link']['matched']) . ' cars';
        }
        if (isset($r['registrations'])) {
            $p[] = 'registrations ' . ($r['registrations']['updated'] ?? 0) . ' updated';
        }
        if (isset($r['insurance'])) {
            $p[] = 'insurance ' . ($r['insurance']['updated'] ?? 0) . ' updated';
        }
        if (isset($r['clear'])) {
            $p[] = "cleared {$r['clear']['contracts']} old contracts";
        }
        if (isset($r['contracts'])) {
            $line = "contracts +{$r['contracts']['created']} new, {$r['contracts']['updated']} updated";
            if (($r['contracts']['closes_detected'] ?? 0) > 0) {
                $line .= ", {$r['contracts']['closes_detected']} returned";
            }
            if (($r['contracts']['foreign_skipped'] ?? 0) > 0) {
                $line .= ", {$r['contracts']['foreign_skipped']} other-company skipped";
            }
            $failed = count($r['contracts']['failed_windows'] ?? []);
            if ($failed > 0) {
                $line .= " ({$failed} month(s) failed — will retry next run)";
            }
            $p[] = $line;
        }
        if (isset($r['invoices'])) {
            $p[] = "invoices +{$r['invoices']['created']} new, {$r['invoices']['updated']} updated";
        }
        if (isset($r['customers'])) {
            $p[] = "names +{$r['customers']['updated']}".(($r['customers']['failed'] ?? 0) ? " ({$r['customers']['failed']} not found)" : '');
        }
        if (isset($r['customers_bulk'])) {
            $b = $r['customers_bulk'];
            $line = "names (bulk) +{$b['updated']} of {$b['target']}";
            if (($b['masked'] ?? 0) > 0) {
                $line .= ", {$b['masked']} masked";
            }
            if (($b['missing'] ?? 0) > 0) {
                $line .= ", {$b['missing']} still pending";
            }
            $p[] = $line;
        }
        if (isset($r['top_customers'])) {
            $p[] = "top customers named {$r['top_customers']['updated']}";
        }
        return $p ? implode(' · ', $p) : 'no changes';
    }
}
