<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Finds vehicles whose lifecycle status (the OfficeManager AssetStatus stored on the
 * vehicle) disagrees with what its CONTRACTS actually say is happening right now.
 *
 * A car's real "current movement" is driven by its open contracts: an open rental
 * (type C) means it's out, an open maintenance (type U) means it's in the garage.
 * The vehicle's `status` should reflect that, but a sync gap or a contract that was
 * never closed/opened can leave the two out of step. Each check returns a group with
 * a severity, the true total count, and a capped list of example rows (with links).
 *
 * Mirrors AnomalyService's shape so the frontend can render it the same way.
 */
class StatusMismatchService
{
    /** Max example rows returned per group (the count is always the true total). */
    private const CAP = 100;

    public function all(): array
    {
        // Cars with an open rental / open maintenance contract right now.
        $maintIds = $this->openVehicleIds('U');
        $rentIds  = $this->openVehicleIds('C');

        // Maintenance wins over a rental (a car in the garage isn't "rented"), matching
        // OperationsService::reconcileAllOperationalStatus precedence.
        $rentExpected = array_values(array_diff($rentIds, $maintIds));
        $busy         = array_values(array_unique(array_merge($rentIds, $maintIds)));

        $groups = [
            $this->onRentStatusWrong($rentExpected),
            $this->inGarageStatusWrong($maintIds),
            $this->rentedNoContract($busy),
            $this->maintNoContract($busy),
        ];

        return [
            'total_mismatches' => array_sum(array_map(fn ($g) => $g['count'], $groups)),
            'flagged_groups'   => count(array_filter($groups, fn ($g) => $g['count'] > 0)),
            'groups'           => $groups,
        ];
    }

    /** Car is OUT on an open rental, but its status isn't "Rented". */
    private function onRentStatusWrong(array $rentExpected): array
    {
        $base = Vehicle::whereIn('id', $rentExpected ?: [0])->where('status', '!=', 'rented');

        $vehicles  = (clone $base)->limit(self::CAP)->get();
        $contracts = $this->openContractsByVehicle($vehicles->pluck('id')->all(), 'C');

        $items = $vehicles->map(function ($v) use ($contracts) {
            $c = $contracts->get($v->id);
            return $this->row(
                $v, $c,
                'Out on rental' . $this->ref($c) . ' but the car status is "' . $this->statusLabel($v) . '" instead of "Rented"',
                'Open rental' . $this->ref($c)
            );
        })->all();

        return $this->group(
            'on_rent_status_wrong', 'Out on rental, not marked Rented', 'critical',
            'The car has an open rental contract (it is physically out) but its vehicle status is not "Rented". The status is stale, so the car may wrongly appear available and get double-booked.',
            (clone $base)->count(), $items
        );
    }

    /** Car is in the garage on an open maintenance, but its status isn't "Under Maintenance". */
    private function inGarageStatusWrong(array $maintIds): array
    {
        $base = Vehicle::whereIn('id', $maintIds ?: [0])->where('status', '!=', 'under_maintenance');

        $vehicles  = (clone $base)->limit(self::CAP)->get();
        $contracts = $this->openContractsByVehicle($vehicles->pluck('id')->all(), 'U');

        $items = $vehicles->map(function ($v) use ($contracts) {
            $c = $contracts->get($v->id);
            return $this->row(
                $v, $c,
                'In the garage on maintenance' . $this->ref($c) . ' but the car status is "' . $this->statusLabel($v) . '" instead of "Under Maintenance"',
                'Open maintenance' . $this->ref($c)
            );
        })->all();

        return $this->group(
            'in_garage_status_wrong', 'In garage, not marked Under Maintenance', 'critical',
            'The car has an open maintenance contract (it is physically in the garage) but its vehicle status is not "Under Maintenance". The status is stale and the car may wrongly appear rentable.',
            (clone $base)->count(), $items
        );
    }

    /** Status says "Rented" but the car has no open contract backing it. */
    private function rentedNoContract(array $busy): array
    {
        $base = Vehicle::where('status', 'rented')->whereNotIn('id', $busy ?: [0]);

        $vehicles = (clone $base)->limit(self::CAP)->get();

        $items = $vehicles->map(fn ($v) => $this->row(
            $v, null,
            'Marked "Rented" but has no open rental contract — the car is actually free',
            'No open contract'
        ))->all();

        return $this->group(
            'rented_no_contract', 'Marked Rented, but no open contract', 'warning',
            'The vehicle status is "Rented" yet there is no open rental contract. The rental was probably closed without the status being reset, so the car looks unavailable when it can be rented.',
            (clone $base)->count(), $items
        );
    }

    /** Status says "Under Maintenance" but the car has no open contract backing it. */
    private function maintNoContract(array $busy): array
    {
        $base = Vehicle::where('status', 'under_maintenance')->whereNotIn('id', $busy ?: [0]);

        $vehicles = (clone $base)->limit(self::CAP)->get();

        $items = $vehicles->map(fn ($v) => $this->row(
            $v, null,
            'Marked "Under Maintenance" but has no open maintenance contract — the car is actually free',
            'No open contract'
        ))->all();

        return $this->group(
            'maint_no_contract', 'Marked Under Maintenance, but no open contract', 'warning',
            'The vehicle status is "Under Maintenance" yet there is no open maintenance contract. The job was probably closed without the status being reset, so the car looks unavailable when it can be used.',
            (clone $base)->count(), $items
        );
    }

    // ---- helpers -------------------------------------------------------------

    /** Vehicle ids that have a currently-open contract of the given type. */
    private function openVehicleIds(string $type): array
    {
        return Contract::currentlyOpen()
            ->where('contract_type', $type)
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id')
            ->all();
    }

    /** Map of vehicle_id => its latest currently-open contract of the given type. */
    private function openContractsByVehicle(array $vehicleIds, string $type): Collection
    {
        if (empty($vehicleIds)) {
            return collect();
        }

        return Contract::currentlyOpen()
            ->where('contract_type', $type)
            ->whereIn('vehicle_id', $vehicleIds)
            ->with('customer')
            ->orderByDesc('id')
            ->get()
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->first());
    }

    private function statusLabel(Vehicle $v): string
    {
        return Vehicle::STATUS_LABELS[$v->status] ?? ($v->status ?: '—');
    }

    /** " #1234" when the contract carries a number, else "". */
    private function ref(?Contract $c): string
    {
        return $c && $c->contract_no ? ' #' . $c->contract_no : '';
    }

    private function row(Vehicle $v, ?Contract $c, string $detail, string $contractState): array
    {
        return [
            'vehicle_id'     => $v->id,
            'plate'          => $v->plate_no,
            'car'            => trim($v->make . ' ' . $v->model) ?: null,
            'vehicle_status' => $this->statusLabel($v),
            'contract_state' => $contractState,
            'contract_id'    => $c?->id,
            'contract_no'    => $c?->contract_no,
            'customer_id'    => $c?->customer_id,
            'customer'       => $c?->customer?->name_en ?: ($c?->customer?->customer_no ? '#' . $c->customer->customer_no : null),
            'detail'         => $detail,
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
