<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;

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

    public function __construct(private ContractExchangeService $exchanges)
    {
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
            // --- Gaps & stale data ---
            $this->forSaleButOut(),
            $this->closedWithoutReturn(),
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
