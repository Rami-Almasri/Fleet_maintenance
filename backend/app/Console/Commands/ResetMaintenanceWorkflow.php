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
 * vehicle_log_events links auto-null), then reconciles the car.
 *
 * ── THIS COMMAND NO LONGER DESTROYS ANYTHING ─────────────────────────────────────────────────────
 * It was the measured source of 1,559 orphaned `vehicle_log_events` (79% of the whole log): the rows
 * survived but `maintenance_id` was nulled, so a ticket-level timeline — every stage duration, repair
 * cycle and waiting period — was gone for good, while the vehicle history still read as complete.
 *
 * `maintenances` is now soft-deleted, so `->delete()` below RETIRES each ticket instead. No cascade
 * fires, no link is nulled: the faults, garage stints, inspections and timeline stay attached, and the
 * board clears because every Eloquent read is scoped. Reversing a reset is `restore()` on the rows.
 *
 * Each retirement still writes a VehicleLogEvent::EVENT_TICKET_DELETED marker. It is no longer a
 * record of destruction but of INTENT — who took these tickets off the board and when — which is what
 * a later completeness check reads to explain a gap in the live view.
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
        // Bulk rewind: each per-vehicle reconcile below would otherwise re-grade the car's garage
        // behaviour and could alert on state being unwound rather than lived. Silence it — the
        // nightly `garage:intelligence-sweep` re-reads the whole fleet afterwards.
        \App\Services\Garage\GarageIntelligenceService::$muted = true;

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

            // 2) Ticket media is KEPT. It used to be deleted here because a hard-deleted ticket left it
            //    orphaned (no cascading FK) — but the ticket is only retired now, so the photos and
            //    videos stay attached and come back with it. They are evidence of the car's condition,
            //    the least reversible thing on the ticket, and nothing surfaces them once the ticket is
            //    scoped out. Deleting them would be the only truly destructive step left in a command
            //    that no longer destroys anything.
            $media = DB::table('maintenance_media')->whereIn('maintenance_id', $ticketIds)->count();
            if ($media) {
                $this->line("  · kept {$media} media row(s) — they return with the ticket");
            }

            // 3) Mark each destruction on the vehicle trail BEFORE deleting — once the rows are gone
            //    there is nothing left to describe them, and the nulled event links (below) make the
            //    loss otherwise indistinguishable from a ticket that never had any history.
            $this->logDestructions($ticketIds);

            // 4) Retire the tickets. SOFT delete (see Maintenance::$dates / SoftDeletes): no cascade
            //    fires and no `vehicle_log_events` link is nulled, so the evidence graph stays whole
            //    and the board clears purely because Eloquent reads are scoped.
            Maintenance::whereIn('id', $ticketIds)->delete();

            // 5) Re-derive each car's live status now the ticket is gone.
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

    /**
     * Write one EVENT_TICKET_DELETED marker per ticket about to be destroyed.
     *
     * Delegates to WorkshopEventService so a deletion record has ONE definition regardless of which
     * path destroys the ticket — the UI delete, the tombstone, or this bulk reset. Duplicating the
     * meta shape here would guarantee the three drift apart, and a completeness check reading them
     * would have to know which path wrote which fields.
     *
     * @param  list<int>  $ticketIds
     */
    private function logDestructions(array $ticketIds): void
    {
        $service = app(\App\Services\WorkshopEventService::class);

        // A CLI reset has no authenticated user — actor_id stays null and reads as "System".
        foreach (Maintenance::whereIn('id', $ticketIds)->get() as $ticket) {
            $service->logDestruction($ticket, 'bulk_reset', null);
        }

        $this->line('  · recorded ' . count($ticketIds) . ' ticket-deleted audit event(s)');
    }
}

/** Internal marker used to roll back a --dry-run transaction. */
class DryRunRollback extends \RuntimeException
{
}
