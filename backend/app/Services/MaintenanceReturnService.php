<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;

/**
 * Reconciles a maintenance CONTRACT's open/closed state against what the
 * "N-Maintenance & Repair" sheet log (gid 400222171) says about the car.
 *
 * The mismatch the owner cares about: the sheet has logged the car back from the
 * garage (a closing `IN` event, and/or an Actual-In return date) but the matching
 * OfficeManager maintenance contract (type 'U') is still OPEN — so the car wrongly
 * looks like it's still in for repair and can't be rented. These contracts just
 * need closing in OfficeManager.
 *
 * Only the maintenance sheet carries a reliable "car is back" signal, so this is a
 * maintenance-only reconciliation (rental returns aren't in this sheet).
 */
class MaintenanceReturnService
{
    public function __construct(private MaintenanceAnalyticsService $analytics)
    {
    }

    /**
     * One row per car currently in the garage (open type-U contract), each paired
     * with its latest sheet event, plus a summary. Rows are ordered worst-first:
     * cars the sheet says are already back (most stale first), then jobs still in
     * progress, then cars with no sheet event to compare against.
     */
    public function reconcile(): array
    {
        $today = Carbon::today();

        $open = Contract::where('contract_type', 'U')
            ->currentlyOpen()
            ->with('vehicle')
            ->orderBy('out_date')
            ->get();

        // Hard Lock: only consider sheet events that belong to THIS contract's visit
        // (same vehicle AND within the contract's lifespan window). Otherwise a previous
        // visit's closing IN (e.g. the car came back, then went out again on a new
        // contract) wrongly flags the new open contract as "sheet says back".
        $sheetEvents = $this->analytics->linkedSheetEvents($open);

        $rows = $open->map(function (Contract $c) use ($sheetEvents, $today) {
            $seq = $sheetEvents[$c->id] ?? collect();   // full window sequence (ping-pong)
            $s   = $seq->last();                        // latest event = current state

            // "Back" per the sheet = the LATEST event is a closing IN. An IN always
            // follows an OUT (a car must be OUT to come back IN), so the latest event
            // being IN means the most recent trip is closed → the car is back. If the
            // latest event is OUT / Follow up the car is out again / still being repaired,
            // even on rows that carry a stray actual_in_date. This also handles ping-pong
            // (out → back → out again reads as "in progress", not "back").
            $isBack     = $s && $s->event_status === 'IN';
            $returnedOn = $isBack ? ($s->actual_in_date ?: $s->out_date) : null;

            $flag = $s === null ? 'no_sheet' : ($isBack ? 'sheet_back' : 'in_progress');

            // Number of completed garage trips so far (each closing IN = one round trip).
            $trips = $seq->where('event_status', 'IN')->count();

            $outDate = $c->out_date ? Carbon::parse($c->out_date) : null;

            return [
                'contract_id'           => $c->id,
                'contract_no'           => $c->contract_no,
                'vehicle_id'            => $c->vehicle_id,
                'plate'                 => $c->vehicle?->plate_no,
                'car'                   => $c->vehicle ? (trim($c->vehicle->make . ' ' . $c->vehicle->model) ?: null) : null,
                'out_date'              => optional($outDate)->toDateString(),
                'days_open'             => $outDate ? $outDate->diffInDays($today) : null,
                'garage'                => $s?->vendor?->name ?: $s?->garage,
                'sheet_stage'           => $s?->event_status,
                'sheet_out_date'        => optional($s?->out_date)->toDateString(),
                'sheet_expected_return' => optional($s?->expected_return_date)->toDateString(),
                'sheet_returned_on'     => optional($returnedOn)->toDateString(),
                'days_since_return'     => $returnedOn ? Carbon::parse($returnedOn)->diffInDays($today) : null,
                'trips'                 => $trips,
                'events'                => $seq->map(fn ($e) => [
                    'stage'          => $e->event_status,
                    'out_date'       => optional($e->out_date)->toDateString(),
                    'actual_in_date' => optional($e->actual_in_date)->toDateString(),
                ])->all(),
                'flag'                  => $flag,
            ];
        })->values();

        // Worst-first: sheet_back, then in_progress, then no_sheet. Within "back",
        // the longest-stale (came back furthest in the past) surfaces first.
        $rank = ['sheet_back' => 0, 'in_progress' => 1, 'no_sheet' => 2];
        $rows = $rows->sortBy([
            fn ($r) => $rank[$r['flag']],
            fn ($r) => -($r['days_since_return'] ?? $r['days_open'] ?? 0),
        ])->values();

        return [
            'summary' => [
                'in_garage'       => $rows->count(),
                'sheet_says_back' => $rows->where('flag', 'sheet_back')->count(),
                'in_progress'     => $rows->where('flag', 'in_progress')->count(),
                'no_sheet'        => $rows->where('flag', 'no_sheet')->count(),
            ],
            'rows' => $rows->all(),
        ];
    }
}
