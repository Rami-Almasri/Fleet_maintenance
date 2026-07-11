<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\InspectionRecord;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * The Readiness Dashboard — a role-scoped task board built around the FOUR inspection pillars:
 *
 *   • Check-Out / Check-In   → the LOGISTICS queue  (hand-overs & returns still to document)
 *   • Damage Assessment      → the SUPERVISOR queue (flagged damage awaiting approval)
 *   • Garage Inspection      → the INSPECTOR queue   (Abu Marouf's repairs pending sign-off)
 *
 * Every bucket is DERIVED live from tables the app already trusts (contracts, inspection_records,
 * the maintenance workflow) — nothing is stored. The garage-sign-off rows additionally carry a live
 * Pre-Delivery Readiness verdict (VehicleReadinessService) so the frontend can disable the
 * "Set to Ready" button and show exactly which pillar is blocking it.
 */
class ReadinessDashboardService
{
    /** Handovers/returns older than this are treated as historical, not an actionable task. */
    private const TASK_WINDOW_DAYS = 21;

    /** A safety cap so a sparse-inspection backlog can never flood the board. */
    private const MAX_ROWS = 60;

    /** A handover undocumented longer than this is CRITICAL (at risk), surfaced first. */
    private const CRITICAL_HOURS = 24;

    /** A genuine Cleaning entry — anything else (null / pending) counts as "missing data". */
    private const REAL_CLEANING = ['clean', 'dirty'];

    public function __construct(
        private VehicleReadinessService $readiness,
        private DashboardService $dashboard,
    ) {
    }

    /** @return array{counts:array<string,int>, fleet_status:array, checkouts:array, checkins:array, damage_reviews:array, garage_signoffs:array, missing_data:array} */
    public function build(): array
    {
        $checkouts      = $this->checkoutQueue();
        $checkins       = $this->checkinQueue();
        $damageReviews  = $this->damageReviewQueue();
        $garageSignoffs = $this->garageSignoffQueue();
        $missingData    = $this->missingDataQueue();

        return [
            'counts' => [
                'checkouts'          => $checkouts['total'],
                'checkouts_critical' => $checkouts['critical'],
                'checkins'           => $checkins['total'],
                'checkins_critical'  => $checkins['critical'],
                'damage_reviews'     => count($damageReviews),
                'garage_signoffs'    => count($garageSignoffs),
                'missing_data'       => $missingData['total'],
            ],
            // The 4-segment live fleet donut (Available / Rented / Maintenance / Unavailable), reusing
            // the single contract-derived source of truth so the page opens on the fleet picture.
            'fleet_status'    => $this->dashboard->fleetStatus(),
            'checkouts'       => $checkouts['rows'],
            'checkins'        => $checkins['rows'],
            'damage_reviews'  => $damageReviews,
            'garage_signoffs' => $garageSignoffs,
            'missing_data'    => $missingData['rows'],
        ];
    }

    // ── LOGISTICS: Check-Out — cars recently handed out with no documented handover (pre-inspection) ──
    // Filters the "already documented" contracts in SQL (NOT EXISTS) so the cap is honest, then orders
    // OLDEST-FIRST: the longest-undocumented handovers are the most at-risk, so they survive the cap and
    // sit at the top. Returns the true total + the critical (>24h) count alongside the capped rows.
    private function checkoutQueue(): array
    {
        return $this->handoverQueue('out_date', 'pre', 'checkout');
    }

    // ── LOGISTICS: Check-In — cars recently returned with no documented return (post-inspection) ──
    private function checkinQueue(): array
    {
        return $this->handoverQueue('in_date', 'post', 'checkin', requireInDate: true);
    }

    /**
     * Shared undocumented-handover query for both directions.
     *
     * @param string $dateCol   'out_date' (check-out) | 'in_date' (check-in)
     * @param string $phase     the inspection phase that would document it ('pre' | 'post')
     * @param string $kind      the row kind tag ('checkout' | 'checkin')
     * @return array{total:int, critical:int, rows:array}
     */
    private function handoverQueue(string $dateCol, string $phase, string $kind, bool $requireInDate = false): array
    {
        $window   = Carbon::now()->subDays(self::TASK_WINDOW_DAYS)->startOfDay();
        $critical = Carbon::now()->subHours(self::CRITICAL_HOURS);

        $base = function () use ($dateCol, $phase, $window, $requireInDate) {
            $q = Contract::where('contract_type', 'C')
                ->whereNotNull('vehicle_id')
                ->where($dateCol, '>=', $window)
                ->whereNotExists(fn ($sub) => $sub->from('inspection_records')
                    ->whereColumn('inspection_records.contract_id', 'contracts.id')
                    ->where('inspection_records.phase', $phase)
                    ->selectRaw('1'));
            // Check-out looks at currently-open rentals; check-in needs an actual return on record.
            return $requireInDate ? $q->whereNotNull('in_date') : $q->currentlyOpen();
        };

        $rows = $base()
            ->with(['vehicle:id,make,model,year,plate_no,cleaning_status', 'customer:id,name_en,name_ar'])
            ->orderBy($dateCol)          // oldest (most overdue) first
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Contract $c) => $this->handoverRow($c, $kind, $c->{$dateCol}))
            ->values()->all();

        return [
            'total'    => $base()->count(),
            'critical' => $base()->where($dateCol, '<=', $critical)->count(),
            'rows'     => $rows,
        ];
    }

    // ── SUPERVISOR: Damage Assessment — flagged damage awaiting review/approval ──
    private function damageReviewQueue(): array
    {
        return InspectionRecord::where('damage_flagged', true)
            ->whereNull('review_outcome')
            ->with('vehicle:id,make,model,year,plate_no')
            ->orderByDesc('captured_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (InspectionRecord $r) => [
                'id'          => $r->id,
                'vehicle_id'  => $r->vehicle_id,
                'car'         => $this->carLabel($r->vehicle),
                'plate_no'    => $r->vehicle?->plate_no,
                'damage_type' => $r->damage_type ?: 'other',
                'severity'    => $r->severity ?: 'low',
                'body_part'   => $r->body_part,
                'note'        => $r->note,
                'inspector'   => $r->inspector_name,
                'flagged_at'  => optional($r->captured_at)->toIso8601String(),
            ])
            ->values()->all();
    }

    // ── INSPECTOR (Abu Marouf): Garage Inspection — repairs pending re-inspection sign-off ──
    private function garageSignoffQueue(): array
    {
        $tickets = Maintenance::openWorkflow()
            ->where('workflow_status', Maintenance::WF_READY_REINSPECTION)
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,make,model,year,plate_no,condition_grade,cleaning_status,gps_last_seen_at,odometer,last_service_odometer,service_interval_km', 'vehicle.registration', 'inspector:id,name'])
            ->orderBy('last_state_change_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return $tickets->map(function (Maintenance $t) {
            $eval   = $t->vehicle ? $this->readiness->evaluate($t->vehicle) : null;
            $anchor = $t->last_state_change_at ?? $t->updated_at;

            $blockers  = $eval ? $eval['gate_blockers'] : [];
            $gateReady = empty($blockers);

            return [
                'id'              => $t->id,
                'ticket_id'       => $t->id,
                'vehicle_id'      => $t->vehicle_id,
                'car'             => $this->carLabel($t->vehicle),
                'plate_no'        => $t->vehicle?->plate_no,
                'stage'           => 'Re-inspection',
                'workflow_status' => $t->workflow_status,
                'inspector'       => $t->inspector?->name,
                'days_waiting'    => $anchor ? (int) Carbon::parse($anchor)->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null,
                // The live Pre-Delivery verdict for the disabled-button + tooltip. `gate_ready` = the
                // sign-off is allowed; `gate_blockers` name the pillar(s) / precondition still blocking it.
                'readiness'       => $eval ? [
                    'gate_ready'    => $gateReady,
                    'gate_blockers' => array_values($blockers),
                    'summary'       => $eval['summary'],
                ] : null,
            ];
        })->values()->all();
    }

    // ── MANAGER: Missing Data — cars whose Cleaning status was never entered ──
    //
    // A data-entry accountability alert: the team must record each car's Cleaning (clean|dirty) status.
    // A blank / "pending" value means nobody did the entry — it silently hid the car's true state. This
    // bucket surfaces exactly those cars so a manager can chase the gap. Retired cars (sold / disposed)
    // are excluded. `total` is the true fleet-wide count; `rows` is capped at MAX_ROWS for display.
    private function missingDataQueue(): array
    {
        $realCleaning = self::REAL_CLEANING;   // a genuine cleaning entry (clean|dirty)

        // Fresh builder per use: one for the accurate count, one for the capped display list.
        // Retired cars are excluded null-safely — a NULL status is still an operational car, so we
        // can't lean on whereNotIn alone (SQL `NULL NOT IN (...)` is NULL, which would drop the row).
        $base = fn () => Vehicle::where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['disposed', 'sold']);
            })
            ->where(function ($q) use ($realCleaning) {
                $q->whereNull('cleaning_status')->orWhereNotIn('cleaning_status', $realCleaning);
            });

        $rows = $base()
            ->orderBy('plate_no')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'make', 'model', 'year', 'plate_no', 'cleaning_status'])
            ->map(fn (Vehicle $v) => [
                'vehicle_id'      => $v->id,
                'car'             => $this->carLabel($v),
                'plate_no'        => $v->plate_no,
                'cleaning_status' => $v->cleaning_status,
                'missing'         => ['Cleaning'], // the blank field (Cleaning is the only tracked entry)
            ])
            ->values()->all();

        return ['total' => $base()->count(), 'rows' => $rows];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    private function handoverRow(Contract $c, string $kind, $when): array
    {
        $customer = $c->customer;
        $cname    = $customer ? ($customer->name_en ?: $customer->name_ar) : null;
        // Hours undocumented → drives the "at risk" (>24h) flag and the urgency sort on the board.
        $hours = $when ? (int) abs(Carbon::parse($when)->diffInHours(Carbon::now())) : null;

        return [
            'id'           => $c->id,
            'kind'         => $kind, // checkout | checkin
            'contract_id'  => $c->id,
            'vehicle_id'   => $c->vehicle_id,
            'car'          => $this->carLabel($c->vehicle),
            'plate_no'     => $c->vehicle?->plate_no,
            'customer'     => $cname,
            'when'         => $when ? Carbon::parse($when)->toIso8601String() : null,
            'hours'        => $hours,
            'overdue'      => $hours !== null && $hours >= self::CRITICAL_HOURS,
            'missing_data' => $this->vehicleMissingData($c->vehicle), // highlight cars with a blank entry
        ];
    }

    /** True when the car is missing its Cleaning entry — reused to red-flag handover rows. */
    private function vehicleMissingData(?Vehicle $v): bool
    {
        if (! $v) {
            return false;
        }
        return ! in_array($v->cleaning_status, self::REAL_CLEANING, true);
    }

    private function carLabel(?Vehicle $v): string
    {
        if (! $v) {
            return 'Vehicle';
        }
        return trim(($v->model ?: ($v->make ?: 'Vehicle')) . ' ' . ($v->year ?? ''));
    }
}
