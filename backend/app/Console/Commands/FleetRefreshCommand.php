<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Services\DatabaseBackup;
use App\Services\InsuranceImporter;
use App\Services\MaintenanceSheetImporter;
use App\Services\OfficeManagerSync;
use App\Services\OilChangeImporter;
use App\Services\VehicleImporter;
use App\Services\VehicleRegistrationImporter;
use App\Services\VehicleStatusImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-click "fresh sync": pull every source in the correct order and store progress in
 * a SyncRun row (so the Data Sync page can show it live). Backs up first.
 *
 * Order matters (SHEET FIRST, then API — the "Faster" tab is the fleet register since 2026-08-19):
 *  1. Sheet "Faster" tab     -> WHICH CARS EXIST. Creates any car the tab lists and we do not
 *                               hold, and enriches make/model/color + purchase price.
 *  2. API vehicles           -> refresh identity + operational data on the cars the sheet listed
 *                               (VIN/plate/year/status/odometer/car_serial), specs + rental
 *                               defaults, and the MORTGAGE flag. This IS the link+status step —
 *                               no separate link phase. It NEVER creates a car: an OM car the
 *                               sheet does not list is reported as `unlisted` and skipped.
 *  3. Sheet "F RTA"          -> registration fines + status text
 *  4. Sheet "F Insurance"    -> Mulkiya (registration) expiry, mortgaged-by, AND the insurance
 *                               itself: insurer + insurance_expiry. Reverted to the sheet on
 *                               2026-07-23 — the API no longer owns insurance, upsertMortgage()
 *                               writes only is_mortgaged. This phase is also scheduled on its
 *                               own as `sync:insurance` (03:20), because leaving it reachable
 *                               only through this command left production months stale.
 *  5. API contracts          -> contracts for our cars (creates customer stubs; null-safe)
 *  6. API invoices           -> charges rolled into contract debit
 *  7. API customers          -> fill in customer names for the new stubs
 *  8. Sheet maintenance log  -> each car's repair status/garage/issues/maintenance type
 *                               (the /maintenance board's source; OfficeManager has none)
 */
class FleetRefreshCommand extends Command
{
    protected $signature = 'fleet:refresh
        {--wipe : DESTRUCTIVE: delete contracts, invoices, customers, vehicles, registrations + maintenance before rebuilding}
        {--skip-backup : Skip the pre-flight db:backup (ignored when --wipe is set — a wipe always backs up)}
        {--run-id=0 : Existing sync_runs row to report progress into}';

    protected $description = 'Fresh full sync: sheets + OfficeManager API in the right order (status from the API). Backs up first.';

    public function handle(
        DatabaseBackup $backup,
        OfficeManagerSync $om,
        VehicleImporter $vehicles,
        VehicleRegistrationImporter $registrations,
        InsuranceImporter $insurance,
        MaintenanceSheetImporter $maintenance,
        OilChangeImporter $oilChange,
        VehicleStatusImporter $vehicleStatus
    ): int {
        $run = $this->resolveRun();
        $result = [];
        $errors = [];
        $dataDone = [];

        try {
            // --- Pre-flight backup (HARD gate: never sync/wipe without one). ---
            if (! $this->option('skip-backup') || $this->option('wipe')) {
                $this->setPhase($run, 'Backup');
                $this->info('Backing up the database first...');
                try {
                    $result['backup'] = basename($backup->run());
                } catch (Throwable $e) {
                    $run->update(['status' => 'failed', 'phase' => null, 'finished_at' => now(),
                        'error' => 'Backup failed — aborted before any write: ' . $e->getMessage()]);
                    $this->error('Backup failed — aborting before any write. ' . $e->getMessage());
                    return self::FAILURE;
                }
            }

            // --- Optional wipe (HARD gate: a failed wipe must not half-rebuild). ---
            if ($this->option('wipe')) {
                $this->setPhase($run, 'Wiping data');
                $this->warn('Wiping contracts, invoices, customers, vehicles, registrations + maintenance...');
                $result['wipe'] = $this->wipe();
            }

            // --- Data phases: each is GUARDED, so one failing phase (a sheet glitch, an API
            //     hiccup) records the error and the rest STILL run. Every phase is idempotent,
            //     so a failed phase is simply retried on the next run. Order matters: the sheet
            //     first (it decides which cars exist), then the API refreshes identity/status on
            //     the cars it listed. Reversed 2026-08-19 — the API used to run first and create.
            $this->setPhase($run, 'Fleet register (sheet)');
            $result['vehicles'] = $this->guard('vehicles', fn () => $vehicles->import(), $errors);

            $this->setPhase($run, 'Cars from API');
            $result['api_vehicles'] = $this->guard('api_vehicles', fn () => $om->importFleetVehicles($this->progress($run)), $errors);

            $this->setPhase($run, 'Registrations (sheet)');
            $result['registrations'] = $this->guard('registrations', fn () => $registrations->import(), $errors);

            $this->setPhase($run, 'Insurance + Mulkiya (sheet)');
            $result['insurance'] = $this->guard('insurance', fn () => $insurance->import(), $errors);

            $this->setPhase($run, 'Contracts (API)');
            $om->auditRunId = $run->id; // attach auto-corrections (cleared stale fields) to this run
            $result['contracts'] = $this->guard('contracts', fn () => $om->importContracts(null, [], $this->progress($run)), $errors);

            $this->setPhase($run, 'Invoices (API)');
            $result['invoices'] = $this->guard('invoices', fn () => $om->importInvoices(null, [], $this->progress($run)), $errors);

            // Customer names, in ONE request. This phase used to call enrichCustomers(), which
            // fires a request per nameless stub — and enrichCustomersBulk() exists precisely
            // because that behaves like a self-inflicted DDoS against OfficeManager's
            // intermittent 503s. On the 2026-08-18 rebuild the per-customer path ran for two
            // hours and reported "+0 named, 15438 not found": every single lookup failed, and
            // because a phase that fails on every row still counts as a phase that ran, the
            // summary printed [ OK ] over 15k customers left as nameless stubs. The bulk list
            // endpoint filled all 15,442 immediately afterwards.
            //
            // It also sat in front of the maintenance-log import, so those two wasted hours were
            // hours with an empty maintenance board. Cheap phase first, and the expensive one
            // can no longer hold the sheet imports hostage.
            $this->setPhase($run, 'Customer names (API)');
            $result['customers'] = $this->guard('customers', fn () => $om->enrichCustomersBulk($this->progress($run)), $errors);

            // Maintenance log (sheet): each car's repair status / garage / issues / maintenance
            // type. OfficeManager carries none of this, so the /maintenance board reads it from
            // here — runs last, after vehicles exist to match the log rows to.
            $this->setPhase($run, 'Maintenance log (sheet)');
            $result['maintenance'] = $this->guard('maintenance', fn () => $maintenance->import(
                (string) config('google.sheets.maintenance.id'),
                (int) config('google.sheets.maintenance.log_gid'),
            ), $errors);

            // Customer cases (sheet): a customer-charge maintenance log on a separate tab.
            // Same importer, origin='customer-sheet' so it stays out of the fleet board.
            $this->setPhase($run, 'Customer cases (sheet)');
            $result['customer_cases'] = $this->guard('customer_cases', fn () => $maintenance->import(
                (string) config('google.sheets.maintenance.id'),
                (int) config('google.sheets.maintenance.customer_cases_gid'),
                false,
                null,
                'customer-sheet',
            ), $errors);

            // Oil change (sheet): per-car service interval (VALIDITY) + last-service baseline
            // (LAST CHANGE), matched to our cars by VIN. The ONLY source for the service-due
            // math; current mileage stays API-owned. Runs after API vehicles set the odometer.
            $this->setPhase($run, 'Oil change (sheet)');
            $result['oil_change'] = $this->guard('oil_change', fn () => $oilChange->import(), $errors);

            // Vehicle status overlay (sheet): the FINAL step. om:sync (step 1) set each car's status
            // from OfficeManager, which still lists sold/exported cars under our owner number; this
            // overlays the human-maintained Status sheet on top so Sold/Personal/Office/For-sale cars
            // are pulled out of the active pool. Active cars are left on their live om:sync status.
            $this->setPhase($run, 'Vehicle status overlay (sheet)');
            $result['vehicle_status'] = $this->guard('vehicle_status', fn () => $vehicleStatus->import(), $errors);

            // Drop failed phases (null) so the stored result + counts reflect only real work.
            $result = array_filter($result, fn ($v) => $v !== null);
            $dataDone = array_diff_key($result, ['backup' => 1, 'wipe' => 1]);

            // done = every phase ok · partial = some ran, some failed · failed = none ran.
            $status = ! $errors ? 'done' : ($dataDone ? 'partial' : 'failed');

            $run->update([
                'status'      => $status,
                'phase'       => null,
                'result'      => $result,
                'error'       => $errors ? $this->errorSummary($errors) : null,
                'processed'   => $run->total ?: $run->processed,
                'finished_at' => now(),
            ]);

            $this->summary($result, $errors);
        } catch (Throwable $e) {
            // Safety net for anything thrown outside the per-phase guards.
            $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Non-zero ONLY on a total wipe-out (every data phase failed), so the CMD/bash retry
        // loop fires for a full outage — but not for a single minor phase that can wait for
        // the next scheduled run.
        return ($errors && ! $dataDone) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one phase, RETURNING its result, and capture any failure into $errors so the phases
     * that follow still run. Returning the value (rather than assigning inside an arrow fn,
     * which copies $result by value and silently loses the write) keeps the per-phase counts.
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

    /** One-line "phase: reason | phase: reason" summary for the run row's error column. */
    private function errorSummary(array $errors): string
    {
        return collect($errors)->map(fn ($msg, $key) => "{$key}: {$msg}")->implode(' | ');
    }

    /** Clear per-phase OK/FAIL report printed at the end of the run (and into the CLI log). */
    private function summary(array $result, array $errors): void
    {
        $phases = [
            'vehicles'      => 'Fleet register (sheet)',
            'api_vehicles'  => 'Cars (API)',
            'registrations' => 'Registrations (sheet)',
            'insurance'     => 'Insurance + Mulkiya (sheet)',
            'contracts'     => 'Contracts (API)',
            'invoices'      => 'Invoices (API)',
            'customers'     => 'Customer names (API)',
            'maintenance'   => 'Maintenance log (sheet)',
            'customer_cases' => 'Customer cases (sheet)',
            'oil_change'    => 'Oil change (sheet)',
            'vehicle_status' => 'Vehicle status overlay (sheet)',
        ];

        $this->newLine();
        $this->line('==================== Sync summary ====================');
        $ok = 0; $failed = 0;
        foreach ($phases as $key => $label) {
            if (isset($errors[$key])) {
                $failed++;
                $this->error(sprintf('  [FAIL] %-26s %s', $label, $errors[$key]));
            } elseif (array_key_exists($key, $result)) {
                $ok++;
                $this->info(sprintf('  [ OK ] %-26s %s', $label, $this->phaseCounts($key, $result[$key])));
            } else {
                $this->line(sprintf('  [skip] %-26s not run', $label));
            }
        }
        $this->line('------------------------------------------------------');
        $this->line("{$ok} phase(s) OK · {$failed} failed.");
        if ($failed > 0) {
            $this->warn('Failed phases are recorded and will be retried on the next run (every phase is idempotent).');
        } else {
            $this->info('Fresh sync done — all phases completed.');
        }
    }

    /** A short count line for a phase result, defensive about missing keys. */
    private function phaseCounts(string $key, $r): string
    {
        if (! is_array($r)) {
            return 'done';
        }
        $c = fn ($k) => $r[$k] ?? 0;

        return match ($key) {
            // The API can no longer add a car, so what matters here is how many it refreshed and
            // how many of its own cars our register does not list.
            'api_vehicles'  => "{$c('updated')} refreshed" . ($c('unlisted') ? ", {$c('unlisted')} OM car(s) not on the sheet" : ''),
            'vehicles'      => "+{$c('created')} new / {$c('updated')} enriched"
                . ($c('duplicates') ? ", {$c('duplicates')} duplicate VIN row(s)" : ''),
            'contracts'     => "+{$c('created')} new / {$c('updated')} updated" . ($c('foreign_skipped') ? ", {$c('foreign_skipped')} other-company skipped" : ''),
            'invoices'      => "+{$c('created')} new / {$c('updated')} updated",
            // enrichCustomersBulk returns updated/masked/missing/target — NOT the old 'failed'.
            // Reading a key the phase never returns is how "+0 named" once printed as [ OK ].
            'customers'     => "+{$c('updated')} named of {$c('target')}"
                . ($c('masked') ? ", {$c('masked')} masked at source" : '')
                . ($c('missing') ? ", {$c('missing')} not in the list" : ''),
            'maintenance'   => "+{$c('imported')} new / {$c('updated')} updated" . ($c('unmatched_cars') ? ", {$c('unmatched_cars')} unmatched" : ''),
            'customer_cases' => "+{$c('imported')} new / {$c('updated')} updated" . ($c('unmatched_cars') ? ", {$c('unmatched_cars')} unmatched" : ''),
            'oil_change'    => "{$c('updated')} updated" . ($c('unmatched') ? ", {$c('unmatched')} unmatched" : ''),
            'registrations', 'insurance' => "{$c('updated')} updated",
            'vehicle_status' => (($x = $r['counts'] ?? []) ? "{$x['changed']} status changed, {$x['flagged']} flagged for-sale" : 'done'),
            default         => 'done',
        };
    }

    /**
     * DESTRUCTIVE full wipe of the operational data, deleted child-first so foreign keys
     * never block it. KEPT: vendors (re-used by name), maintenance_reasons (vocabulary),
     * users/roles, sync_runs. Contracts/invoices/customers/vehicles are rebuilt by the
     * steps that follow. The type-U maintenance CONTRACTS come from the API like any other
     * contract; the `maintenances` sheet LOG (status/garage/issues/type) is rebuilt by the
     * maintenance phase (step 8). Hand-entered maintenance headers, if any, are not.
     *
     * @return array<string,int>  table => rows deleted
     */
    private function wipe(): array
    {
        $counts = [];
        DB::transaction(function () use (&$counts) {
            foreach ([
                'maintenance_items',
                'maintenances',
                'invoices',
                'vehicle_registrations',
                'contracts',
                'customers',
                'vehicles',
            ] as $table) {
                $counts[$table] = DB::table($table)->delete();
            }
        });

        return $counts;
    }

    private function resolveRun(): SyncRun
    {
        $id = (int) $this->option('run-id');
        if ($id && $existing = SyncRun::find($id)) {
            return $existing;
        }

        return SyncRun::create([
            'action'     => 'Fresh Full Sync',
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    /** Start a new phase: reset the counters and show the label. */
    private function setPhase(SyncRun $run, string $label): void
    {
        $run->update(['phase' => $label, 'processed' => 0, 'total' => 0]);
        $this->info($label . '…');
    }

    /** Progress callback that writes processed/total into the run row. */
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
}
