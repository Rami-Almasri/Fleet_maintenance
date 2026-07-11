<?php

namespace App\Services;

use App\Models\MaintenanceLineItem;

/**
 * The fleet-status side of the Vehicle Life-Stream page — one "where is this car RIGHT NOW" chip per
 * vehicle, plus the headline counts for the status strip.
 *
 * It does NOT re-derive status. It leans entirely on VehicleStatusDashboardService::build() (the same
 * single-pane-of-glass cascade the /vehicle-status board trusts), then folds the board's fine stage into
 * the coarse, scannable vocabulary the life-stream speaks:
 *   With Customer · Out for Delivery · In Garage · Waiting for Parts · Available · Grounded · Left.
 *
 * The one thing it adds on top is the "Waiting for Parts" heuristic — a state the workflow engine does
 * not model. A car is treated as waiting on parts when it is physically under repair (a formal ticket in
 * the 'in_garage' stage) yet still has a PART line item that was never marked installed. `installed_on IS
 * NULL` is the closest "not yet fitted" signal the line-item schema keeps, so this stays a heuristic:
 * it can also mean the part's costing simply hasn't been entered.
 */
class VehicleLifeStreamService
{
    /** Coarse life-stream chip → label + shared badge tone. Order here is the KPI-strip order. */
    private const CHIP = [
        'in_garage'        => ['label' => 'In Garage',        'tone' => 'red'],
        'waiting_parts'    => ['label' => 'Waiting for Parts', 'tone' => 'amber'],
        'out_for_delivery' => ['label' => 'Out for Delivery',  'tone' => 'violet'],
        'with_customer'    => ['label' => 'With Customer',     'tone' => 'blue'],
        'available'        => ['label' => 'Available',         'tone' => 'green'],
        'grounded'         => ['label' => 'Grounded',          'tone' => 'red'],
        'left'             => ['label' => 'Left the Fleet',    'tone' => 'gray'],
    ];

    public function __construct(protected VehicleStatusDashboardService $board)
    {
    }

    /**
     * The whole fleet, one live-status chip per car (board order: blocked first, then longest-waiting),
     * plus per-chip counts for the status strip.
     *
     * @return array{vehicles: array<int, array<string,mixed>>, summary: array<string,int>, total: int}
     */
    public function fleet(): array
    {
        $rows = $this->board->build()['rows'];

        $waitingSet = $this->waitingForPartsTickets($rows);

        $summary = array_fill_keys(array_keys(self::CHIP), 0);
        $vehicles = [];

        foreach ($rows as $r) {
            $waiting = $r['ticket_id'] && isset($waitingSet[$r['ticket_id']]);
            $chipKey = $this->chipFor($r, $waiting);
            $chip    = self::CHIP[$chipKey];
            $summary[$chipKey]++;

            $vehicles[] = [
                'vehicle_id'     => $r['id'],
                'plate'          => $r['plate_no'],
                'title'          => $r['title'],
                'model'          => trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? '')),
                'status_key'     => $chipKey,
                'status_label'   => $chip['label'],
                'status_tone'    => $chip['tone'],
                'status_detail'  => $r['status'],        // the board's precise stage label (e.g. "Awaiting Dispatch")
                // The PRECISE workflow stage (+ its own tone) so a task list can follow the exact flow —
                // "Awaiting Dispatch" / "In Transit to Garage" / "QA Pending" — not just the coarse chip.
                'stage'          => $r['stage'],
                'stage_label'    => $r['status'],
                'stage_tone'     => $r['status_tone'],
                'owner'          => $r['owner'],
                'next_action'    => $r['next_action'],
                'next_step'      => $r['next_step'],      // the actionable "do this next" (label + tone + href + branch options)
                'ticket_id'      => $r['ticket_id'],
                'days_in_status' => $r['days_in_status'],
                'blocked'        => $r['blocked'],
            ];
        }

        return ['vehicles' => $vehicles, 'summary' => $summary, 'total' => count($vehicles)];
    }

    /** The set of ticket ids (as array keys) that are under repair AND still have an uninstalled part. */
    private function waitingForPartsTickets(array $rows): array
    {
        // Only cars physically in the bay on a formal ticket can be "waiting for parts".
        $garageTicketIds = collect($rows)
            ->filter(fn ($r) => $r['stage'] === 'in_garage' && $r['ticket_id'])
            ->pluck('ticket_id')
            ->all();

        if (empty($garageTicketIds)) {
            return [];
        }

        return array_flip(
            MaintenanceLineItem::query()
                ->where('kind', MaintenanceLineItem::KIND_PART)
                ->whereNull('installed_on')
                ->whereIn('maintenance_id', $garageTicketIds)
                ->distinct()
                ->pluck('maintenance_id')
                ->all()
        );
    }

    /** Fold the board's bucket/stage into one coarse life-stream chip. */
    private function chipFor(array $r, bool $waitingForParts): string
    {
        return match ($r['bucket']) {
            'rented'      => 'with_customer',
            'transit'     => 'out_for_delivery',
            'available'   => 'available',
            'left'        => 'left',
            'maintenance' => $waitingForParts
                ? 'waiting_parts'
                : ($r['stage'] === 'grounded' ? 'grounded' : 'in_garage'),
            default       => 'available',
        };
    }
}
