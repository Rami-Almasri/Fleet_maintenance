<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

// MileageBaselineService is the source of the two mileage-chain checks below.

/**
 * Detects "exceptional cases" — data conflicts and operational gaps that shouldn't
 * normally happen (a car rented AND in maintenance, a sold car still on an open
 * contract, a return logged before the pickup, etc.). Each check returns a group with
 * a severity, a true total count, and a capped list of example rows (with links).
 */
class AnomalyService
{
    /** Max example rows returned per group (the count is always the true total). */
    private const CAP = 100;

    /** A workshop visit longer than this (out → return) is treated as an impossible/mis-keyed date. */
    private const MAX_VISIT_DAYS = 180;

    public function __construct(
        private ContractExchangeService $exchanges,
        private MileageBaselineService $mileage,
    ) {
    }

    private const TYPE_LABEL = [
        'C' => 'Rented', 'U' => 'In maintenance', 'R' => 'Booking',
        'L' => 'Leased', 'P' => 'Reserved', 'T' => 'Test drive',
    ];

    public function all(): array
    {
        $groups = [
            // --- Conflicts: states that cannot all be true at once ---
            $this->doubleBooked(),
            $this->disposedButActive(),
            $this->returnedBeforePickup(),
            $this->billingDateMismatch(),
            // --- Gaps & stale data ---
            $this->forSaleButOut(),
            $this->implausibleWorkshopVisit(),
            $this->mileageRollback(),
            $this->mileageJump(),
            $this->workshopDuringRental(),
            $this->closedWithoutReturn(),
            $this->longOpenRental(),
            $this->staleOpen(),
            $this->openWithoutPickup(),
            $this->maintenanceWithoutGarage(),
            $this->missingMaintenanceContract(),
            $this->untrackedCar(),
            // --- Suggested actions (not conflicts/gaps): retail car swaps to review & link ---
            $this->exchanges->pendingGroup(),
        ];

        // "Cases" counts the genuine anomalies (conflicts + gaps); exchange suggestions are a
        // separate review queue, surfaced via `review_cases`, so they don't distort the
        // anomaly totals / critical ratio the page header gauge reads.
        $anomalyGroups = array_filter($groups, fn ($g) => ($g['severity'] ?? null) !== 'review');
        $reviewGroups  = array_filter($groups, fn ($g) => ($g['severity'] ?? null) === 'review');

        return [
            'total_cases'    => array_sum(array_map(fn ($g) => $g['count'], $anomalyGroups)),
            'review_cases'   => array_sum(array_map(fn ($g) => $g['count'], $reviewGroups)),
            'flagged_groups' => count(array_filter($groups, fn ($g) => $g['count'] > 0)),
            'groups'         => $groups,
        ];
    }

    /** A car with more than one OPEN contract — e.g. rented and in maintenance at once. */
    private function doubleBooked(): array
    {
        $ids = Contract::currentlyOpen()->whereNotNull('vehicle_id')
            ->select('vehicle_id')->groupBy('vehicle_id')->havingRaw('COUNT(*) > 1')
            ->pluck('vehicle_id');

        $vehicles = Vehicle::whereIn('id', $ids->take(self::CAP))
            ->with(['contracts' => fn ($q) => $q->currentlyOpen()->with('customer')])
            ->get();

        $items = $vehicles->map(function ($v) {
            $open = $v->contracts;
            $types = $open->map(fn ($c) => self::TYPE_LABEL[$c->contract_type] ?? ($c->contract_type ?: '—'))
                ->unique()->values()->implode(' + ');

            return $this->row($v, null, $open->count() . ' open contracts at once: ' . $types);
        })->all();

        return $this->group(
            'double_booked', 'Double-booked cars', 'critical',
            'A car with more than one open contract at the same time — for example rented and in maintenance at once. Only one can physically be true, so one of these contracts was never closed.',
            $ids->count(), $items
        );
    }

    /** A sold/exported car (no longer ours) that still has an open contract. */
    private function disposedButActive(): array
    {
        $base = Contract::currentlyOpen()
            ->whereHas('vehicle', fn ($q) => $q->whereIn('status', ['sold', 'disposed']));

        $rows = (clone $base)->with(['vehicle', 'customer'])->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Car is marked "' . $c->vehicle->status . '" but this ' . $this->typeLabel($c) . ' contract is still open'
        ))->all();

        return $this->group(
            'disposed_active', 'Sold cars still active', 'critical',
            'The vehicle is marked sold or disposed (it has left the fleet) yet it still has an open rental or maintenance contract. The contract should be closed.',
            (clone $base)->count(), $items
        );
    }

    /** Return date earlier than the pickup date — impossible. */
    private function returnedBeforePickup(): array
    {
        $base = Contract::whereNotNull('in_date')->whereNotNull('out_date')
            ->whereColumn('in_date', '<', 'out_date');

        $rows = (clone $base)->with(['vehicle', 'customer'])->latest('out_date')->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Return date (' . $c->in_date->toDateString() . ') is before the pickup date (' . $c->out_date->toDateString() . ')'
        ))->all();

        return $this->group(
            'returned_before_pickup', 'Returned before pickup', 'critical',
            'The recorded return date is earlier than the pickup date — a data-entry error in one of the two dates.',
            (clone $base)->count(), $items
        );
    }

    /**
     * Invoices whose BILLED contract window (ContractOutDate / ContractInDate on the
     * invoice) disagrees with the out/in dates we recorded on the contract itself.
     * Compared on the date portion (both stored as dates). Only invoices that carry a
     * contract date AND are linked to a contract can be checked — rows still null from
     * before the financials backfill are simply skipped, so the check fills in as the
     * invoice sync repopulates them.
     */
    private function billingDateMismatch(): array
    {
        $base = Invoice::query()
            ->join('contracts', 'invoices.contract_id', '=', 'contracts.id')
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->whereNotNull('invoices.contract_out_date')
                        ->whereNotNull('contracts.out_date')
                        ->whereColumn('invoices.contract_out_date', '<>', 'contracts.out_date');
                })->orWhere(function ($q) {
                    $q->whereNotNull('invoices.contract_in_date')
                        ->whereNotNull('contracts.in_date')
                        ->whereColumn('invoices.contract_in_date', '<>', 'contracts.in_date');
                });
            });

        $count = (clone $base)->distinct()->count('invoices.id');

        $rows = (clone $base)
            ->select('invoices.*')
            ->with(['contract.vehicle', 'contract.customer'])
            ->orderByDesc('invoices.invoice_date')
            ->limit(self::CAP)
            ->get();

        $items = $rows->map(function (Invoice $inv) {
            $c = $inv->contract;
            $diffs = [];
            if ($inv->contract_out_date && $c?->out_date && ! $inv->contract_out_date->isSameDay($c->out_date)) {
                $diffs[] = 'out: recorded ' . $c->out_date->toDateString() . ' vs billed ' . $inv->contract_out_date->toDateString();
            }
            if ($inv->contract_in_date && $c?->in_date && ! $inv->contract_in_date->isSameDay($c->in_date)) {
                $diffs[] = 'in: recorded ' . $c->in_date->toDateString() . ' vs billed ' . $inv->contract_in_date->toDateString();
            }

            return [
                'vehicle_id'  => $c?->vehicle_id,
                'plate'       => $c?->vehicle?->plate_no,
                'car'         => $c?->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                'contract_id' => $c?->id,
                'contract_no' => $c?->contract_no,
                'customer_id' => $c?->customer_id,
                'customer'    => $c?->customer?->name_en ?: ($c?->customer?->customer_no ? '#' . $c->customer->customer_no : null),
                'out_date'    => optional($c?->out_date)->toDateString(),
                'in_date'     => optional($c?->in_date)->toDateString(),
                'detail'      => 'Invoice #' . $inv->invoice_no . ' — ' . implode(' · ', $diffs),
            ];
        })->all();

        return $this->group(
            'billing_date_mismatch', 'Billing dates ≠ contract dates', 'warning',
            'The contract out/in dates billed on an invoice do not match the out/in dates we recorded on the contract. Usually the contract was edited or re-synced after it was billed; reconcile so rental days and revenue line up with the actual rental period.',
            $count, $items
        );
    }

    /** A car flagged for sale that is still out on an open contract (softer warning). */
    private function forSaleButOut(): array
    {
        $base = Contract::currentlyOpen()
            ->whereHas('vehicle', fn ($q) => $q->where('for_sale', true));

        $rows = (clone $base)->with(['vehicle', 'customer'])->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Car is flagged For Sale but still out on this ' . $this->typeLabel($c) . ' contract'
        ))->all();

        return $this->group(
            'for_sale_out', 'For-sale cars still out', 'warning',
            'The car is flagged for sale but is still out on an open contract. Usually fine (it can be rented until sold) — just confirm it is not double-promised to a buyer.',
            (clone $base)->count(), $items
        );
    }

    /**
     * Reconciliation between the two sources behind Fleet Utilization: a workshop visit (maintenance
     * sheet / manual log) that overlaps a still-OPEN rental contract for the same car.
     *
     * The utilization math credits an overlapping day to the rental (contract-based priority), so
     * these days count as RENTED, not downtime. That is correct for a genuine accident-during-rental
     * (the customer keeps paying) but wrong for a stale rental that was never closed — there the car
     * actually sat in the garage. Both are surfaced here to confirm rather than trust silently.
     *
     * Overlap = the visit's return is on/after the open rental's pickup (the open rental runs to today,
     * so any later visit falls inside it). Visits are de-duplicated to one row per (car, visit day).
     */
    private function workshopDuringRental(): array
    {
        $base = DB::table('maintenances as m')
            ->join('contracts as c', function ($j) {
                $j->on('c.vehicle_id', '=', 'm.vehicle_id')
                    ->on('m.actual_in_date', '>=', 'c.out_date')   // visit ended on/after the rental started
                    ->where('c.contract_type', 'C')
                    ->whereNull('c.in_date')                       // rental still open
                    ->whereNotNull('c.out_date');
            })
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('m.out_date')
            ->whereNotNull('m.actual_in_date');

        // True total = distinct (car, visit-out-date) pairs, so multi-row visits count once.
        $count = DB::query()->fromSub(
            (clone $base)->select('m.vehicle_id', 'm.out_date')->distinct(),
            't'
        )->count();

        $rows = (clone $base)
            ->leftJoin('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->select(
                'm.vehicle_id', 'm.out_date as visit_out', 'm.actual_in_date as visit_in',
                'm.service_main', 'm.service_sup',
                'c.id as contract_id', 'c.contract_no', 'c.out_date as c_out', 'c.customer_id',
                'v.plate_no', 'v.make', 'v.model', 'cu.name_en', 'cu.customer_no'
            )
            ->orderByDesc('m.out_date')
            ->limit(self::CAP * 4)   // over-fetch so PHP de-dup can still reach CAP distinct visits
            ->get();

        $items = [];
        $seen = [];
        foreach ($rows as $r) {
            $key = $r->vehicle_id . '|' . substr((string) $r->visit_out, 0, 10);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (count($items) >= self::CAP) {
                break;
            }
            $issue = $r->service_main ?: ($r->service_sup ?: 'workshop visit');
            $items[] = [
                'vehicle_id'  => $r->vehicle_id,
                'plate'       => $r->plate_no,
                'car'         => $r->make ? trim($r->make . ' ' . $r->model) : null,
                'contract_id' => $r->contract_id,
                'contract_no' => $r->contract_no,
                'customer_id' => $r->customer_id,
                'customer'    => $r->name_en ?: ($r->customer_no ? '#' . $r->customer_no : null),
                'out_date'    => substr((string) $r->c_out, 0, 10),
                'in_date'     => null,
                'tag'         => 'In shop while rented',
                'detail'      => 'Workshop visit ' . substr((string) $r->visit_out, 0, 10) . ' → '
                    . substr((string) $r->visit_in, 0, 10) . ' (' . $issue . ') fell inside OPEN rental #'
                    . $r->contract_no . ' (out ' . substr((string) $r->c_out, 0, 10) . ', still open). '
                    . 'Utilization counts these days as rented — confirm the customer was billed, or close the rental if the car was actually off-rent.',
            ];
        }

        return $this->group(
            'workshop_during_rental', 'In the shop during an active rental', 'warning',
            'A workshop visit from the maintenance sheet overlaps a still-OPEN rental contract for the same car. Fleet Utilization credits those days as rented (the contract wins the overlap), which is right for an accident during an active rental but wrong for a rental that was never closed. Confirm each: bill the customer, or close the stale rental so the days count as downtime instead.',
            $count, $items
        );
    }

    /**
     * A workshop visit with an IMPOSSIBLE return date — in the future, or implausibly long after the
     * out date (≥ MAX_VISIT_DAYS). Almost always a mis-keyed return (often a wrong year: the sheet
     * writes a bare "5/14" that, parsed against the current year, lands ~365 days out). Such a row
     * silently inflates the car's downtime and "in shop while rented" days on Fleet Utilization, so it
     * is surfaced immediately rather than discovered later through skewed metrics. The importer now
     * anchors year-less dates to the out_date year ([[maintenance-data-architecture]]); this is the
     * safety net for anything that still slips through.
     */
    private function implausibleWorkshopVisit(): array
    {
        $base = Maintenance::query()
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('out_date')
            ->whereNotNull('actual_in_date')
            ->where(function ($q) {
                $q->whereRaw('DATEDIFF(actual_in_date, out_date) >= ?', [self::MAX_VISIT_DAYS])
                    ->orWhereDate('actual_in_date', '>', now()->toDateString());
            });

        $rows = (clone $base)->with('vehicle')
            ->orderByRaw('DATEDIFF(actual_in_date, out_date) DESC')
            ->limit(self::CAP)->get();

        $items = $rows->map(function (Maintenance $m) {
            $v      = $m->vehicle;
            $out    = $m->out_date->toDateString();
            $in     = $m->actual_in_date->toDateString();
            $days   = (int) abs($m->out_date->diffInDays($m->actual_in_date));
            $future = $m->actual_in_date->gt(now());
            $issue  = $m->service_main ?: ($m->service_sup ?: ($m->garage ?: 'workshop visit'));

            $detail = $future
                ? "Return date $in is in the FUTURE (out $out) — impossible for a completed visit; almost certainly a mis-keyed date."
                : "Out $out → return $in = $days days in the workshop — implausibly long, almost certainly a mis-keyed return date (e.g. a wrong year). It inflates downtime / \"in shop while rented\" until corrected in the sheet.";

            return [
                'vehicle_id'  => $m->vehicle_id,
                'plate'       => $v?->plate_no ?: $m->plate,
                'car'         => $v ? trim($v->make . ' ' . $v->model) : $m->car_label,
                'contract_id' => null,
                'contract_no' => null,
                'customer_id' => null,
                'customer'    => null,
                'out_date'    => $out,
                'in_date'     => $in,
                'tag'         => $future ? 'Future return' : $days . 'd visit',
                'detail'      => $detail . ' (' . $issue . ')',
            ];
        })->all();

        return $this->group(
            'implausible_workshop_visit', 'Impossible workshop visit dates', 'warning',
            'A workshop visit whose return date is impossible — in the future, or implausibly long after the out date (≥ ' . self::MAX_VISIT_DAYS . ' days). Almost always a mis-keyed return date (commonly a wrong year, e.g. a bare "5/14" read as next year), which silently inflates a car\'s downtime and "in shop while rented" days on Fleet Utilization until fixed at the source.',
            (clone $base)->count(), $items
        );
    }

    /**
     * Mileage ran BACKWARDS: a later handover reading is lower than an earlier accepted one for
     * the same car. Impossible on a real odometer — one of the two readings was mis-typed. The
     * Global Mileage Baseline excludes these readings when healing the odometer; this surfaces
     * them so the wrong one gets fixed at the source. Computed from contract history by
     * MileageBaselineService (rentals + type-'U' maintenance contracts both count).
     */
    private function mileageRollback(): array
    {
        $rows  = $this->mileage->rollbacks();
        $items = array_slice($rows, 0, self::CAP);

        return $this->group(
            'mileage_rollback', 'Mileage ran backwards', 'warning',
            'A later odometer reading is LOWER than an earlier one for the same car — impossible on a real odometer, so one of the two handover readings (out/in mileage) was mis-typed. These readings are ignored when the Global Mileage Baseline heals the live odometer; fix the wrong reading on its contract.',
            count($rows), $items
        );
    }

    /**
     * Implausible mileage JUMP: two consecutive accepted readings differ by more than a car could
     * realistically travel in the elapsed time (> MAX_KM_PER_DAY, with a floor to cut noise) —
     * almost always a typo or an extra digit. Excluded from odometer healing until corrected.
     */
    private function mileageJump(): array
    {
        $rows  = $this->mileage->jumps();
        $items = array_slice($rows, 0, self::CAP);

        return $this->group(
            'mileage_jump', 'Implausible mileage jump', 'warning',
            'Two consecutive odometer readings for the same car differ by more than the car could plausibly travel between them (over ' . number_format(MileageBaselineService::MAX_KM_PER_DAY) . ' km/day) — almost always a typo or an extra digit. These readings are excluded from Global Mileage Baseline odometer healing until corrected at the source.',
            count($rows), $items
        );
    }

    /** Closed contracts where the car went out but no return date was ever recorded. */
    private function closedWithoutReturn(): array
    {
        $base = Contract::where('state', 'closed')->whereNull('in_date')->whereNotNull('out_date');

        $rows = (clone $base)->with(['vehicle', 'customer'])->latest('out_date')->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Closed, but no return date was recorded (car went out ' . $c->out_date->toDateString() . ')'
        ))->all();

        return $this->group(
            'closed_no_return', 'Closed without a return date', 'warning',
            'The contract is closed and the car was handed out, but no return (in) date was logged. Contracts with no pickup date at all are excluded (those are usually cancelled quotes).',
            (clone $base)->count(), $items
        );
    }

    /**
     * Rental contracts (type 'C') open for MORE than 30 days with no in_date — the car is still
     * booked out on paper. A genuine long-term rental is fine, but most are forgotten open contracts
     * that were never closed; left unchecked they keep accruing "Rental Days" and skew Fleet
     * Utilization. The extreme tail (open over a YEAR) is listed separately under "Stale open
     * contracts", so this band is 30 days … 1 year and each contract appears in exactly one group.
     */
    private function longOpenRental(): array
    {
        $base = Contract::currentlyOpen()
            ->where('contract_type', 'C')
            ->whereNotNull('out_date')
            ->where('out_date', '<', now()->subDays(30))
            ->where('out_date', '>=', now()->subDays(365));

        $rows = (clone $base)->with(['vehicle', 'customer'])->orderBy('out_date')->limit(self::CAP)->get();

        $items = $rows->map(function ($c) {
            $days = (int) abs($c->out_date->diffInDays(now()));

            return $this->contractRow(
                $c,
                'Open ' . $days . ' days — car went out ' . $c->out_date->toDateString()
                . ' with no return recorded. Close it if the car is back, or confirm it is a genuine '
                . 'long-term rental; until then it keeps accruing rental days.'
            );
        })->all();

        return $this->group(
            'long_open_rental', 'Rentals open 30+ days', 'warning',
            'A rental contract has been open more than 30 days with no return (in) date. Usually a forgotten open contract that should be closed — left open it inflates Rental Days and skews Fleet Utilization. Genuine long-term rentals are expected, so this is a prompt to confirm, not necessarily an error. Rentals open more than a year appear under "Stale open contracts".',
            (clone $base)->count(), $items
        );
    }

    /** Open contracts where the car went out over a year ago and was never returned. */
    private function staleOpen(): array
    {
        $cutoff = now()->subDays(365);

        $base = Contract::currentlyOpen()->whereNotNull('out_date')->where('out_date', '<', $cutoff);

        $rows = (clone $base)->with(['vehicle', 'customer'])->orderBy('out_date')->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Still open — car went out ' . $c->out_date->toDateString() . ' (' . (int) abs($c->out_date->diffInDays(now())) . ' days ago) and was never closed'
        ))->all();

        return $this->group(
            'stale_open', 'Stale open contracts', 'warning',
            'The contract is still open but the car went out more than a year ago. It was almost certainly returned without being closed in the system.',
            (clone $base)->count(), $items
        );
    }

    /** Open contracts with no pickup/out date at all. */
    private function openWithoutPickup(): array
    {
        $base = Contract::currentlyOpen()->whereNull('out_date');

        $rows = (clone $base)->with(['vehicle', 'customer'])->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow($c, 'Open contract with no pickup (out) date recorded'))->all();

        return $this->group(
            'open_no_pickup', 'Open contract, no pickup date', 'warning',
            'The contract is open but has no out date, so we cannot tell when (or whether) the car actually left.',
            (clone $base)->count(), $items
        );
    }

    /** Cars currently in maintenance with no garage/vendor assigned. */
    private function maintenanceWithoutGarage(): array
    {
        $base = Contract::currentlyOpen()->where('contract_type', 'U')
            ->whereDoesntHave('maintenance', fn ($q) => $q->whereNotNull('vendor_id'));

        $rows = (clone $base)->with(['vehicle', 'customer'])->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow($c, 'In maintenance but no garage / vendor is assigned'))->all();

        return $this->group(
            'maintenance_no_garage', 'Maintenance without a garage', 'warning',
            'The car is in maintenance but no garage/vendor has been set, so it is missing from garage performance and cost tracking.',
            (clone $base)->count(), $items
        );
    }

    /**
     * Cars the maintenance sheet shows in the garage, but with NO open maintenance
     * contract in OfficeManager — the master cleanup list of administrative gaps.
     *
     * A car qualifies when its LATEST sheet event is an open visit (not 'IN' = returned)
     * that is real work, NOT a 'Test' / 'Under Test' quick check (those are never given a
     * contract), and the car has no currently-open type-U contract. Sorted newest-first;
     * no age cap (the older ones are the long tail of the cleanup list).
     */
    private function missingMaintenanceContract(): array
    {
        // Cars already covered by an open maintenance contract — never a gap.
        $coveredVids = Contract::currentlyOpen()->where('contract_type', 'U')
            ->whereNotNull('vehicle_id')->pluck('vehicle_id')->unique();

        // Latest sheet event per vehicle (id follows the sheet's append order).
        $latestIds = Maintenance::where('origin', 'sheet')
            ->whereNotNull('out_date')
            ->selectRaw('MAX(id) as id')
            ->groupBy('vehicle_id')
            ->pluck('id');

        $base = Maintenance::whereIn('id', $latestIds)
            ->whereNotIn('event_status', ['IN', 'Test', 'Under Test'])
            ->whereNotIn('vehicle_id', $coveredVids);

        $rows = (clone $base)->with(['vehicle', 'vendor'])
            ->orderByDesc('out_date')->limit(self::CAP)->get();

        $items = $rows->map(function (Maintenance $m) {
            $v      = $m->vehicle;
            $stage  = $m->event_status ?: 'in garage';
            $issue  = $m->service_main ?: ($m->service_sup ?: 'maintenance');
            $garage = $m->vendor?->name ?: $m->garage;

            return [
                'vehicle_id'  => $m->vehicle_id,
                'plate'       => $v?->plate_no ?: $m->plate,
                'car'         => $v ? trim($v->make . ' ' . $v->model) : $m->car_label,
                'contract_id' => null,
                'contract_no' => null,
                'customer_id' => null,
                'customer'    => null,
                'out_date'    => optional($m->out_date)->toDateString(),
                'in_date'     => null,
                'tag'         => 'No Open Contract',
                'detail'      => 'In the garage per the sheet since ' . $m->out_date->toDateString()
                    . ' (' . $stage . ($garage ? ' @ ' . $garage : '') . ' · ' . $issue . ')'
                    . ' — no open maintenance contract in OfficeManager',
            ];
        })->all();

        return $this->group(
            'missing_maintenance_contract', 'Missing Maintenance Contracts', 'warning',
            'The maintenance sheet shows the car is in the garage (an open visit, not just a quick test), but there is no open maintenance contract in OfficeManager. Either open a contract, or ask the garage to close the visit (log an IN) in the sheet. "Test" / "Under Test" visits are excluded — they are not given a contract.',
            (clone $base)->count(), $items
        );
    }

    /** Open contracts whose car is not linked to a vehicle in our fleet. */
    private function untrackedCar(): array
    {
        $base = Contract::currentlyOpen()->whereNull('vehicle_id');

        $rows = (clone $base)->with('customer')->limit(self::CAP)->get();

        $items = $rows->map(fn ($c) => $this->contractRow(
            $c,
            'Open contract but the car is not in your fleet' . ($c->car_serial ? ' (car serial ' . $c->car_serial . ')' : '')
        ))->all();

        return $this->group(
            'untracked_car', 'Open contract, car not in fleet', 'warning',
            'The contract is open but its car could not be matched to a vehicle in your fleet, so its status will not show on the Vehicles page.',
            (clone $base)->count(), $items
        );
    }

    // ---- helpers -------------------------------------------------------------

    private function typeLabel(Contract $c): string
    {
        return self::TYPE_LABEL[$c->contract_type] ?? ($c->contract_type ?: 'contract');
    }

    /** Uniform row built from a contract. */
    private function contractRow(Contract $c, string $detail): array
    {
        return [
            'vehicle_id'  => $c->vehicle_id,
            'plate'       => $c->vehicle?->plate_no,
            'car'         => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
            'contract_id' => $c->id,
            'contract_no' => $c->contract_no,
            'customer_id' => $c->customer_id,
            'customer'    => $c->customer?->name_en ?: ($c->customer?->customer_no ? '#' . $c->customer->customer_no : null),
            'out_date'    => optional($c->out_date)->toDateString(),
            'in_date'     => optional($c->in_date)->toDateString(),
            'detail'      => $detail,
        ];
    }

    /** Uniform row built from a vehicle (no single contract to point at). */
    private function row(Vehicle $v, ?Contract $c, string $detail): array
    {
        return [
            'vehicle_id'  => $v->id,
            'plate'       => $v->plate_no,
            'car'         => trim($v->make . ' ' . $v->model),
            'contract_id' => $c?->id,
            'contract_no' => $c?->contract_no,
            'customer_id' => null,
            'customer'    => null,
            'out_date'    => optional($c?->out_date)->toDateString(),
            'in_date'     => optional($c?->in_date)->toDateString(),
            'detail'      => $detail,
        ];
    }

    private function group(string $key, string $title, string $severity, string $description, int $count, array $items): array
    {
        return [
            'key'         => $key,
            'title'       => $title,
            'severity'    => $severity,
            'description' => $description,
            'count'       => $count,
            'shown'       => count($items),
            'items'       => $items,
        ];
    }
}
