<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Services\ContractValidator;
use App\Services\DatabaseBackup;
use App\Services\OfficeManagerSync;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class OfficeManagerSyncCommand extends Command
{
    protected $signature = 'om:sync
        {--vehicles : Import OUR vehicles from the API (owner 1541 + extra car serials)}
        {--link : Match API vehicles to our fleet (set car_serial)}
        {--clear : Delete the old Google-Sheets contracts + maintenance data}
        {--contracts : Import contracts from the API}
        {--invoices : Import invoices and roll them into contract debit}
        {--customers : Fill in missing customer names/details from the API (one call per customer)}
        {--customers-bulk : Fill missing customer names in BULK via the paged customers list (503-resilient)}
        {--top-customers=0 : Enrich the N most-referenced (high-traffic) customers first}
        {--from= : Only contracts/invoices with OutDate on/after this date (YYYY-MM-DD)}
        {--to= : ...and on/before this date, inclusive (optional)}
        {--months= : Closed-contract history depth in months for this run (0 = ALL). Overrides the config default. Always includes every OPEN contract regardless.}
        {--dry-run : Simulate in a rolled-back transaction — write nothing, just report}
        {--skip-backup : Skip the automatic pre-flight db:backup (for routine/scheduled runs)}
        {--zombie-days=90 : Days without an in_date before an open contract is a "zombie"}
        {--limit=0 : Only import N records (validation)}
        {--run-id=0 : Existing sync_runs row to report progress into}';

    protected $description = 'Sync from the OfficeManager API (source of truth): link cars, import contracts/invoices, enrich, etc.';

    public function handle(OfficeManagerSync $sync, ContractValidator $validator, DatabaseBackup $backup): int
    {
        $limit      = (int) $this->option('limit') ?: null;
        $dryRun     = (bool) $this->option('dry-run');
        $zombieDays = (int) $this->option('zombie-days') ?: 90;

        $filters = $this->dateFilters();
        if ($filters === null) {
            return self::FAILURE; // invalid --from/--to, already reported
        }

        // Per-run override of the closed-contract history depth (0 = all). Lets the runner
        // offer a fast "last N months" contracts sync vs a full-history one.
        if (($months = (string) $this->option('months')) !== '') {
            config(['officemanager.contracts_closed_months' => max(0, (int) $months)]);
        }

        if ($dryRun) {
            $this->warn('DRY RUN -- simulating in a rolled-back transaction. Nothing will be written.');
        }

        // A live run that writes anything is gated behind a fresh backup (unless told to skip).
        if (! $dryRun && ! $this->option('skip-backup') && $this->hasWork()) {
            $this->info('Pre-flight backup (safety gate before a live write)...');
            try {
                $file = $backup->run();
            } catch (Throwable $e) {
                $this->error('Backup failed -- aborting before any write. ' . $e->getMessage());
                return self::FAILURE;
            }
            $this->info('Backup OK: ' . $file . ' (' . number_format(filesize($file) / 1048576, 2) . ' MB)');
        }

        $run = $this->resolveRun();
        $sync->auditRunId = $run->id; // attach any auto-corrections (cleared stale fields) to this run
        $result = [];
        $errors = [];

        try {
            // Each phase runs independently: a failure is recorded and we carry on, so one
            // bad phase (e.g. the API dropping mid-contracts) never discards the work the
            // other phases completed. Every phase is idempotent, so re-running resumes.
            // Phases that write directly aren't simulated, so a dry run skips them.
            if ($this->option('vehicles')) {
                $this->mutating($dryRun, $run, 'Cars from API', 'api_vehicles', fn () => $sync->importFleetVehicles($this->progress($run)), $result, $errors);
            }
            if ($this->option('link')) {
                $this->mutating($dryRun, $run, 'Linking cars', 'link', fn () => $sync->linkCarSerials($this->progress($run)), $result, $errors);
            }
            if ($this->option('clear')) {
                $this->mutating($dryRun, $run, 'Clearing sheet data', 'clear', fn () => $sync->clearSheetData(), $result, $errors);
            }
            if ($this->option('contracts')) {
                $this->phase($run, 'Contracts');
                $result['contracts'] = $this->guard('contracts', fn () => $sync->importContracts($limit, $filters, $this->progress($run), $dryRun), $errors);
            }
            if ($this->option('invoices')) {
                $this->phase($run, 'Invoices');
                $result['invoices'] = $this->guard('invoices', fn () => $sync->importInvoices($limit, $filters, $this->progress($run), $dryRun), $errors);
            }
            if ($this->option('customers')) {
                $this->mutating($dryRun, $run, 'Customer names', 'customers', fn () => $sync->enrichCustomers($limit, true, $this->progress($run)), $result, $errors);
            }
            if ($this->option('customers-bulk')) {
                $this->mutating($dryRun, $run, 'Customer names (bulk)', 'customers_bulk', fn () => $sync->enrichCustomersBulk($this->progress($run)), $result, $errors);
            }
            if ((int) $this->option('top-customers') > 0) {
                $this->mutating($dryRun, $run, 'Top customers', 'top_customers', fn () => $sync->enrichTopCustomers((int) $this->option('top-customers')), $result, $errors);
            }

            // A phase that threw returned null via guard(); drop those so the stored result and
            // the terminal status reflect ONLY what was actually saved.
            $result = array_filter($result, fn ($v) => $v !== null);

            // Drop failed phases (null) so the status + reconciliation reflect only real work.
            $result = array_filter($result, fn ($v) => $v !== null);

            $run->update([
                'status'      => $this->terminalStatus($dryRun, $result, $errors),
                'phase'       => null,
                'result'      => $result,
                // Keep a readable summary of any failed phase; clear it when all went fine
                // (also clears any transient orphan-guard flag from a long, healthy run).
                'error'       => $errors ? $this->errorSummary($errors) : null,
                // null-safe: a dry run that skips every phase leaves both at null, and
                // `processed` is NOT NULL in the schema.
                'processed'   => $run->total ?: ($run->processed ?? 0),
                'finished_at' => now(),
            ]);

            $this->info($dryRun
                ? 'Dry run done -- no data written.'
                : ($errors ? 'Sync finished — some phases failed (see summary); completed phases were saved.' : 'Sync done.'));
            $this->reconciliation($result, $validator, $dryRun, $zombieDays);

            // Keep the Sync Audit feed tidy: drop runs past the retention window (cascades to
            // their corrections + change rows). Only on a real run, and never the current one.
            if (! $dryRun) {
                $this->pruneAuditHistory();
                // Fresh fleet/financial data landed → drop the cached dashboard aggregates so the
                // homepage reflects the sync immediately instead of waiting out the cache TTL.
                \App\Services\DashboardService::flushCache();
            }
        } catch (Throwable $e) {
            // Safety net for anything outside the per-phase guards (e.g. reconciliation).
            // Clip the message: a DB error can embed the whole failing SQL, and writing an
            // oversized string back into sync_runs.error would re-trigger the very packet
            // failure we're handling — leaving the row stuck at "running".
            $run->update(['status' => 'failed', 'error' => $this->clip($e->getMessage(), 500), 'finished_at' => now()]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // Only a total wipe-out (every requested phase failed, nothing saved) is a CLI failure.
        return ($errors && ! $result) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run a single phase body and RETURN its result, capturing any failure into $errors so
     * the phases that follow still run. This is what makes a multi-phase sync "modular" — one
     * phase dying no longer takes the whole batch down with it. Returning the value (instead
     * of assigning inside an arrow fn, which copies $result by value and silently loses the
     * write) is what lets the run row record real per-phase counts.
     *
     * @return mixed the phase's return value, or null if it threw
     */
    private function guard(string $key, callable $fn, array &$errors)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $errors[$key] = $e->getMessage();
            $this->error("Phase '{$key}' failed (continuing with the rest): " . $e->getMessage());

            return null;
        }
    }

    /**
     * Delete sync_runs older than the retention window so the audit history (and its
     * cascaded sync_corrections / sync_changes) doesn't grow forever. The FK constraints
     * are ON DELETE CASCADE, so removing the run rows clears their detail automatically.
     * 0 days = keep forever (opt out). Failure here never fails the sync — it's housekeeping.
     */
    private function pruneAuditHistory(): void
    {
        $days = (int) config('officemanager.audit_retention_days', 90);
        if ($days <= 0) {
            return;
        }
        try {
            $cutoff = Carbon::now()->subDays($days);
            $deleted = SyncRun::where('started_at', '<', $cutoff)->delete();
            if ($deleted > 0) {
                $this->info("Audit retention: pruned {$deleted} sync run(s) older than {$days} days.");
            }
        } catch (Throwable $e) {
            $this->warn('Audit retention prune skipped: ' . $e->getMessage());
        }
    }

    /** done = all phases ok · partial = some saved, some failed · failed = nothing saved. */
    private function terminalStatus(bool $dryRun, array $result, array $errors): string
    {
        if ($dryRun) {
            return 'dry-run';
        }
        if (! $errors) {
            return 'done';
        }
        return $result ? 'partial' : 'failed';
    }

    /**
     * One-line "phase: reason | phase: reason" summary for the run log. Each reason is hard-
     * capped because a DB error message can embed the entire failing SQL (incl. bound JSON) —
     * writing an unbounded string into sync_runs.error can itself exceed max_allowed_packet and
     * kill the finalize update, leaving the row frozen at "running".
     */
    private function errorSummary(array $errors): string
    {
        return collect($errors)
            ->map(fn ($msg, $key) => "{$key}: " . $this->clip((string) $msg, 500))
            ->implode(' | ');
    }

    /** Trim a message to a safe length so it can never overflow a TEXT/packet limit. */
    private function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /** Build the API date window from --from/--to, or null if a date is invalid. */
    private function dateFilters(): ?array
    {
        $filters = [];
        try {
            if (($from = (string) $this->option('from')) !== '') {
                $filters['from_date'] = Carbon::parse($from)->toDateString();
            }
            if (($to = (string) $this->option('to')) !== '') {
                // --to is inclusive for the user; the API's upper bound is exclusive, so +1 day.
                // Worst case we over-capture one day, which is harmless (the import is idempotent).
                $filters['to_date'] = Carbon::parse($to)->addDay()->toDateString();
            }
        } catch (Throwable $e) {
            $this->error('Invalid --from/--to date. Use YYYY-MM-DD.');
            return null;
        }
        return $filters;
    }

    /** Run a phase that writes directly — skipped (with a note) during a dry run. */
    private function mutating(bool $dryRun, SyncRun $run, string $label, string $key, callable $fn, array &$result, array &$errors): void
    {
        if ($dryRun) {
            $this->warn("Skipping '{$label}' in dry-run (it writes directly and isn't simulated).");
            return;
        }
        $this->phase($run, $label);
        $result[$key] = $this->guard($key, $fn, $errors);
    }

    /** Print the post-import reconciliation report: counts, null-overwrites, zombies. */
    private function reconciliation(array $result, ContractValidator $validator, bool $dryRun, int $zombieDays): void
    {
        $this->newLine();
        $this->line('---- Reconciliation' . ($dryRun ? ' (DRY RUN -- nothing written)' : '') . ' ----');

        if ($v = ($result['api_vehicles'] ?? null)) {
            $this->line("Cars from API (our owners): +{$v['created']} new / {$v['updated']} updated (scanned {$v['api_vehicles']} API cars)");
        }

        if ($c = ($result['contracts'] ?? null)) {
            $verb = $dryRun ? 'would add / update' : 'added / updated';
            $this->line("Contracts {$verb}: {$c['created']} / {$c['updated']}  (no car: {$c['no_car']})");
            if (($c['closes_detected'] ?? 0) > 0) {
                $this->line("  recently-returned long contracts re-fetched: {$c['closes_detected']}");
            }
            if (($c['foreign_skipped'] ?? 0) > 0) {
                $this->line("  other-company contracts skipped (not our cars): {$c['foreign_skipped']}");
            }
            if (! empty($c['failed_windows'])) {
                $this->warn('  windows that failed to load: ' . implode(', ', $c['failed_windows']));
            }
            $no = $c['null_overwrites_prevented'] ?? 0;
            if ($no > 0) {
                $this->warn("  null-overwrites prevented (kept existing local value): {$no}");
                $this->table(
                    ['Contract', 'Field', 'Kept value'],
                    array_map(fn ($s) => [$s['contract_no'], $s['field'], $s['keeps']], array_slice($c['null_overwrite_samples'] ?? [], 0, 15))
                );
            } else {
                $this->info('  no null-overwrites needed preventing.');
            }
        }

        if ($i = ($result['invoices'] ?? null)) {
            $verb = $dryRun ? 'would add / update' : 'added / updated';
            $this->line("Invoices {$verb}: {$i['created']} / {$i['updated']}");
        }

        $z = $validator->zombies($zombieDays);
        $this->line("Zombie contracts (open, no return before {$z['cutoff']}, {$zombieDays}d): {$z['count']}");
        if ($z['count'] > 0) {
            $this->table(
                ['Contract', 'Serial', 'Out date', 'Days out', 'Vehicle'],
                array_map(fn ($s) => [$s['contract_no'], $s['contract_serial'], $s['out_date'], $s['days_out'], $s['vehicle_id']], array_slice($z['samples'], 0, 15))
            );
            if ($z['count'] > count($z['samples'])) {
                $this->line('  ... and ' . ($z['count'] - count($z['samples'])) . ' more (see: php artisan contracts:validate).');
            }
        }
    }

    private function resolveRun(): SyncRun
    {
        $this->reapStaleRuns(); // self-heal orphaned "running" rows from killed syncs

        $id = (int) $this->option('run-id');
        if ($id && $existing = SyncRun::find($id)) {
            return $existing;
        }
        return SyncRun::create([
            'action'     => $this->actionLabel(),
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark long-dead "running" rows as aborted. A sync killed mid-flight (window closed,
     * Ctrl+C, or the machine slept during a slow OM API step) never reaches the finalize
     * step, so its row is frozen at status=running forever — showing a phantom "running" on
     * the Sync Audit page. The progress callback bumps updated_at on every window/flush, so a
     * row with no heartbeat for 30 min is dead. Runs at the start of every sync, so orphans
     * self-heal without manual cleanup. Best-effort: a failure here never blocks the sync.
     */
    private function reapStaleRuns(): void
    {
        try {
            $reaped = SyncRun::where('status', 'running')
                ->whereNull('finished_at')
                ->where('updated_at', '<', Carbon::now()->subMinutes(30))
                ->update([
                    'status'      => 'aborted',
                    'error'       => 'Aborted — the sync ended before finishing (window closed or interrupted, often during a slow OM API step). Re-run to complete.',
                    'finished_at' => now(),
                ]);
            if ($reaped > 0) {
                $this->warn("Cleaned up {$reaped} stale 'running' sync run(s) left by an interrupted sync.");
            }
        } catch (Throwable $e) {
            // housekeeping only — never let it block the real sync
        }
    }

    /** Start a new phase: reset the counters and show the label. */
    private function phase(SyncRun $run, string $label): void
    {
        $run->update(['phase' => $label, 'processed' => 0, 'total' => 0]);
        $this->info($label.'…');
    }

    /**
     * Progress callback that writes processed/total into the run row. Callers report at
     * coarse boundaries (per window / per flush / per chunk), so we write on every call.
     * An optional $note updates the live phase label (e.g. the month being imported).
     */
    private function progress(SyncRun $run): callable
    {
        return function (int $processed, int $total, ?string $note = null) use ($run) {
            $attrs = ['processed' => $processed, 'total' => $total];
            if ($note !== null) {
                $attrs['phase'] = $note;
            }
            $run->forceFill($attrs)->save();
        };
    }

    /** True when at least one mutating phase was requested (so a backup is warranted). */
    private function hasWork(): bool
    {
        foreach (['vehicles', 'link', 'clear', 'contracts', 'invoices', 'customers', 'customers-bulk'] as $opt) {
            if ($this->option($opt)) {
                return true;
            }
        }
        return (int) $this->option('top-customers') > 0;
    }

    private function actionLabel(): string
    {
        $parts = [];
        foreach (['vehicles' => 'Cars (API)', 'link' => 'Link cars', 'clear' => 'Clear sheets', 'contracts' => 'Contracts', 'invoices' => 'Invoices', 'customers' => 'Customers', 'customers-bulk' => 'Customer names (bulk)'] as $opt => $label) {
            if ($this->option($opt)) {
                $parts[] = $label;
            }
        }
        if ((int) $this->option('top-customers') > 0) {
            $parts[] = 'Top customers';
        }
        return $parts ? implode(' + ', $parts) : 'Sync';
    }
}
