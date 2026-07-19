<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\OperationsService;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TEST-DATA RESET for the Maintenance Workflow board.
 *
 * Removes live (non-terminal) workflow tickets and everything that would otherwise leave the car
 * stuck in the shop, then re-derives each car's operational_status so it returns to normal
 * (available / rented). Built for wiping test tickets between end-to-end trials of the workflow.
 *
 * Why a command and not raw SQL: a `maintenances` row has children WITHOUT a cascading FK —
 * open `logistics_tasks` (+ their events) keep the car reading `in_transit`, and `maintenance_media`
 * rows orphan. This clears those first, deletes the ticket (tasks / line_items / watchers cascade;
 * vehicle_log_events links auto-null, preserving the audit trail), then reconciles the car.
 *
 * Safety:
 *   --dry-run           run inside a rolled-back transaction and print the plan, writing nothing
 *   --vehicle=PLATE|ID  limit to one car (plate_no or vehicle id)
 *   --all               also remove terminal tickets (closed / diagnostic_cleared), not just the board
 */
class ResetMaintenanceWorkflow extends Command
{
    protected $signature = 'maintenance:reset-workflow
                            {--dry-run : Run inside a rolled-back transaction and report, writing nothing}
                            {--vehicle= : Limit to a single vehicle (plate_no or id)}
                            {--all : Also delete terminal tickets (closed/diagnostic_cleared)}';

    protected $description = 'Wipe maintenance-workflow tickets and return their cars to normal status (test reset)';

    public function __construct(private OperationsService $operations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Maintenance::query()->whereNotNull('workflow_status');

        if (! $this->option('all')) {
            $query->whereNotIn('workflow_status', Maintenance::WF_TERMINAL);
        }

        if ($v = $this->option('vehicle')) {
            $vehicleId = is_numeric($v)
                ? (int) $v
                : PlateResolver::resolve($v, withTrashed: true)?->id;
            if (! $vehicleId) {
                $this->error("No vehicle found for '{$v}'.");
                return self::FAILURE;
            }
            $query->where('vehicle_id', $vehicleId);
        }

        $tickets = $query->get(['id', 'vehicle_id', 'workflow_status']);

        if ($tickets->isEmpty()) {
            $this->info('No matching workflow tickets — board is already clear.');
            return self::SUCCESS;
        }

        $ticketIds  = $tickets->pluck('id')->all();
        $vehicleIds = $tickets->pluck('vehicle_id')->filter()->unique()->values()->all();

        $this->table(
            ['ticket_id', 'vehicle_id', 'workflow_status'],
            $tickets->map(fn ($t) => [$t->id, $t->vehicle_id, $t->workflow_status])->all()
        );
        $this->line(sprintf(
            'Deleting %d ticket(s) across %d vehicle(s)%s.',
            count($ticketIds), count($vehicleIds), $dryRun ? ' [DRY RUN]' : ''
        ));

        try {
            DB::transaction(function () use ($ticketIds, $vehicleIds, $dryRun) {
            // 1) Logistics moves attached to these tickets (no cascading FK) + their events.
            $logisticsIds = DB::table('logistics_tasks')
                ->whereIn('maintenance_id', $ticketIds)->pluck('id')->all();
            if ($logisticsIds) {
                DB::table('logistics_task_events')->whereIn('logistics_task_id', $logisticsIds)->delete();
                DB::table('logistics_tasks')->whereIn('id', $logisticsIds)->delete();
                $this->line('  · cleared ' . count($logisticsIds) . ' logistics task(s)');
            }

            // 2) Ticket media (no cascading FK).
            $media = DB::table('maintenance_media')->whereIn('maintenance_id', $ticketIds)->delete();
            if ($media) {
                $this->line("  · cleared {$media} media row(s)");
            }

            // 3) The tickets. Cascades: maintenance_tasks (+ assignments), maintenance_line_items,
            //    maintenance_watchers. Nulls: vehicle_log_events.maintenance_id (audit kept).
            Maintenance::whereIn('id', $ticketIds)->delete();

            // 4) Re-derive each car's live status now the ticket is gone.
            foreach ($vehicleIds as $vid) {
                if ($vehicle = Vehicle::find($vid)) {
                    $status = $this->operations->reconcileVehicleOperationalStatus($vehicle);
                    $this->line("  · vehicle {$vid} → {$status}");
                }
            }

                if ($dryRun) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback $e) {
            // expected: the transaction rolled back, nothing was written.
        }

        if ($dryRun) {
            $this->warn('DRY RUN — everything above was rolled back. Re-run without --dry-run to apply.');
        } else {
            $this->info('Done. Cars returned to normal. Refresh the board.');
        }

        return self::SUCCESS;
    }
}

/** Internal marker used to roll back a --dry-run transaction. */
class DryRunRollback extends \RuntimeException
{
}
