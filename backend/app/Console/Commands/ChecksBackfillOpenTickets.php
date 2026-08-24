<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleCheckRequirement;
use App\Services\VehicleCheckService;
use App\Support\VehicleCheckCatalog;
use Illuminate\Console\Command;

/**
 * Give the inspections ALREADY IN FLIGHT the same obligations a new one would get.
 *
 * Without this the feature would only work forward: every ticket created after deploy would carry
 * answerable checks, while the tickets sitting on the board today — the ones people are actually
 * working — would show none, and the first month of analytics would report a fleet that apparently
 * stopped needing oil changes on the day we shipped.
 *
 * WHERE THE EVIDENCE COMES FROM. Nothing is re-derived from the car's CURRENT state, which would be
 * wrong: a ticket raised three weeks ago was raised for the conditions that were true THEN, and the
 * car may have been driven, serviced or repaired since. Instead each ticket's own `trigger_detail`
 * snapshot is read back — the frozen "why" the monitor wrote at creation
 * (MaintenanceWorkflowService::buildTriggerDetail) — so a reconstructed requirement carries the
 * evidence the request was actually made on.
 *
 * Tickets with no snapshot (human-raised requests, and system requests that predate trigger_detail)
 * are counted and SKIPPED rather than given invented checks. A request whose reasons we cannot
 * recover honestly has none to reconstruct, and manufacturing them would poison the very numbers
 * this layer exists to make trustworthy.
 *
 * Safe to run repeatedly: raise() is idempotent on the gate's own cycle_key, so a second pass over
 * the same tickets adds nothing. Source-stamped `backfill` so reconstructed obligations are always
 * distinguishable from ones the monitor raised live.
 */
class ChecksBackfillOpenTickets extends Command
{
    protected $signature = 'checks:backfill-open-tickets
                            {--dry : Report what would be created, write nothing}
                            {--ticket= : Restrict to one ticket id}
                            {--limit=1000 : Maximum tickets to process}';

    protected $description = 'Reconstruct system check requirements for inspections already in flight, from each ticket\'s own trigger snapshot.';

    public function handle(VehicleCheckService $checks): int
    {
        $dry   = (bool) $this->option('dry');
        $limit = max(1, (int) $this->option('limit'));

        $tickets = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->whereNotNull('trigger_detail')
            ->when($this->option('ticket'), fn ($q) => $q->whereKey((int) $this->option('ticket')))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'vehicle_id', 'workflow_status', 'trigger_detail', 'requested_at']);

        // Human-raised requests carry no machine-readable "why". Counted so the run says out loud how
        // much of the open board it could not speak for, rather than implying it covered everything.
        $noSnapshot = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->whereNull('trigger_detail')
            ->count();

        if ($tickets->isEmpty()) {
            $this->info('No open tickets carry a trigger snapshot — nothing to reconstruct.');
            $this->line("Open tickets with no snapshot (human-raised or pre-snapshot): {$noSnapshot} — skipped by design.");

            return self::SUCCESS;
        }

        $rows      = [];
        $created   = 0;
        $existing  = 0;
        $attached  = 0;
        $skipped   = 0;

        foreach ($tickets as $ticket) {
            $vehicle = Vehicle::find($ticket->vehicle_id);
            if (! $vehicle) {
                $skipped++;
                continue;
            }

            // Rebuild the gate's condition shape from the snapshot. buildTriggerDetail() flattened
            // `detail` → `why` and dropped nothing else we need, so this is a faithful reversal.
            $conditions = collect(data_get($ticket->trigger_detail, 'rules', []))
                ->filter(fn ($rule) => is_array($rule))
                ->map(fn (array $rule) => array_filter([
                    'key'       => $rule['key'] ?? null,
                    'label'     => $rule['label'] ?? null,
                    'directive' => $rule['directive'] ?? null,
                    'severity'  => $rule['severity'] ?? null,
                    'axis'      => $rule['axis'] ?? null,
                    'detail'    => $rule['why'] ?? null,
                    'days'      => $rule['idle_days'] ?? null,
                    'checklist' => $rule['checklist'] ?? null,
                    // The snapshot predates cycle_key, so the ticket itself anchors the cycle. That is
                    // the correct grain: these conditions belong to THIS request, and a later live
                    // reading of the same rule is a genuinely new cycle that should raise its own check.
                    'cycle_key' => 'backfill|ticket:' . $ticket->id . '|' . ($rule['key'] ?? 'condition'),
                ], fn ($v) => $v !== null))
                ->filter(fn ($c) => ! empty($c['key']))
                ->values()
                ->all();

            if ($conditions === []) {
                $skipped++;
                continue;
            }

            $service = data_get($ticket->trigger_detail, 'service');

            if ($dry) {
                // NOT count($conditions): an agenda condition ("check Battery, Fluids and Brakes")
                // becomes three obligations, so counting conditions would under-report the dry run and
                // make the real run look like it over-created. Expanded through the same catalog the
                // service uses, so the estimate and the outcome cannot disagree.
                $expected = array_sum(array_map(
                    fn (array $c) => max(1, count(VehicleCheckCatalog::expansionsFor((string) $c['key']))),
                    $conditions,
                ));
                $rows[] = [$ticket->id, $vehicle->code ?: $vehicle->plate_no, $ticket->workflow_status, $expected, 'would create'];
                $created += $expected;
                continue;
            }

            $raised = $checks->raiseFromConditions($vehicle, $conditions, [
                'service' => is_array($service) ? [
                    'current'    => $service['current_km'] ?? null,
                    'interval'   => $service['interval_km'] ?? null,
                    'overdue_km' => $service['overdue_km'] ?? null,
                    'next_due_at' => $service['next_due_at'] ?? null,
                    'status'     => $service['status'] ?? null,
                ] : null,
                'source'  => VehicleCheckRequirement::SOURCE_BACKFILL,
            ]);

            // Counted off the rows themselves rather than a before/after tally on the vehicle: two
            // open tickets can share a car, so a vehicle-wide count attributes the first ticket's
            // checks to the second as well. `wasRecentlyCreated` is per-row truth.
            $new = count(array_filter($raised, fn ($r) => $r->wasRecentlyCreated));

            $created  += $new;
            $existing += count($raised) - $new;
            $attached += $checks->attachToTicket($ticket);

            $rows[] = [$ticket->id, $vehicle->code ?: $vehicle->plate_no, $ticket->workflow_status, $new, $new > 0 ? 'created' : 'already present'];
        }

        $this->newLine();
        $this->line(($dry ? '<comment>[DRY RUN]</comment> ' : '') . 'Check requirement backfill — open inspections');
        $this->table(['Ticket', 'Vehicle', 'Stage', 'Checks', 'Action'], array_slice($rows, 0, 60));

        if (count($rows) > 60) {
            $this->line('… and ' . (count($rows) - 60) . ' more tickets (table truncated, all were processed).');
        }

        $this->newLine();
        $this->info("Tickets processed : {$tickets->count()}");
        $this->info("Checks created    : {$created}");
        $this->info("Already present   : {$existing}");
        $this->info("Attached to ticket: {$attached}");
        $this->line("Skipped (no usable snapshot in trigger_detail): {$skipped}");
        // Never let a partial run read as full coverage.
        $this->line("Open tickets with NO snapshot at all (human-raised / pre-snapshot): {$noSnapshot} — "
                  . 'deliberately untouched; their reasons were never recorded machine-readably and '
                  . 'inventing them would corrupt the analytics.');

        return self::SUCCESS;
    }
}
