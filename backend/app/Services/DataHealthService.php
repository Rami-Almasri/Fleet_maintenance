<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * "Data Health" — finds INCOMPLETE or BROKEN records that make the rest of the system less
 * reliable: cars missing a VIN/plate/mileage, contracts with no car or customer, duplicate
 * VINs, etc. Distinct from AnomalyService (which finds impossible *states*); this is about
 * missing/garbage *fields* that should be filled in or fixed.
 *
 * Each check returns a group { key, title, severity, description, count, shown, items }.
 * `count` is always the true total; `items` is capped. Vehicle checks look at the ACTIVE
 * fleet only (sold/disposed cars have left the fleet, so their blanks don't matter).
 */
class DataHealthService
{
    /** Max example rows returned per group (the count is always the true total). */
    private const CAP = 100;

    /** Cars that have left the fleet — excluded from vehicle data-quality checks. */
    private const GONE = ['sold', 'disposed'];

    public function all(): array
    {
        $groups = [
            // --- integrity (must fix) ---
            $this->duplicateVin(),
            // --- cars missing key fields (active fleet) ---
            $this->carsNoVin(),
            $this->carsNoMileage(),
            $this->carsNoPlate(),
            $this->carsNoName(),
            $this->carsNotLinked(),
            $this->carsNoPurchase(),
            // --- contracts missing links/fields ---
            $this->contractsNoCar(),
            $this->contractsNoCustomer(),
            $this->contractsNoOutDate(),
            // --- customers ---
            $this->customersNoName(),
        ];

        $bySeverity = fn ($sev) => array_sum(array_map(
            fn ($g) => $g['severity'] === $sev ? $g['count'] : 0,
            $groups
        ));

        return [
            'total_issues'   => array_sum(array_map(fn ($g) => $g['count'], $groups)),
            'flagged_groups' => count(array_filter($groups, fn ($g) => $g['count'] > 0)),
            'critical'       => $bySeverity('critical'),
            'warning'        => $bySeverity('warning'),
            'info'           => $bySeverity('info'),
            'groups'         => $groups,
        ];
    }

    // ---------------------------------------------------------------- vehicle checks

    /** Active fleet base query (cars that are still ours). */
    private function activeVehicles()
    {
        return Vehicle::whereNotIn('status', self::GONE);
    }

    /** Cars with no chassis number (VIN). */
    private function carsNoVin(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('vin')->orWhere('vin', ''));
        $items = (clone $base)->orderBy('plate_no')->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'No VIN (chassis number) on file'))->all();

        return $this->group('cars_no_vin', 'Cars without a VIN', 'warning',
            'Active cars with no chassis number. VIN is how the sheet and the API match a car, so a missing VIN means this car can never be enriched or linked automatically.',
            (clone $base)->count(), $items);
    }

    /** Cars with no real odometer reading (null, 0 or the "1" placeholder). */
    private function carsNoMileage(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('odometer')->orWhere('odometer', '<=', 1));
        $items = (clone $base)->orderBy('plate_no')->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'Odometer is ' . ($v->odometer === null ? 'empty' : (string) $v->odometer) . ' km'))->all();

        return $this->group('cars_no_mileage', 'Cars without mileage', 'warning',
            'Active cars whose odometer is empty, 0 or 1 (a placeholder). Mileage drives service-due and replacement planning, so these need a real reading.',
            (clone $base)->count(), $items);
    }

    /** Cars with no plate number. */
    private function carsNoPlate(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('plate_no')->orWhere('plate_no', ''));
        $items = (clone $base)->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'No plate number'))->all();

        return $this->group('cars_no_plate', 'Cars without a plate number', 'warning',
            'Active cars with no plate. The plate is the main way staff identify a car day-to-day.',
            (clone $base)->count(), $items);
    }

    /** Cars with no make/model name. */
    private function carsNoName(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('make')->orWhere('make', ''));
        $items = (clone $base)->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'No make/model — add it on the "Faster" sheet'))->all();

        return $this->group('cars_no_name', 'Cars without a make/model', 'warning',
            'Active cars with no make/model. Make/model come from the sheet, so these cars are in the API but not on the sheet yet.',
            (clone $base)->count(), $items);
    }

    /** Cars not linked to OfficeManager (no CarSerial). */
    private function carsNotLinked(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('car_serial')->orWhere('car_serial', ''));
        $items = (clone $base)->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'Not linked to OfficeManager (no CarSerial)'))->all();

        return $this->group('cars_not_linked', 'Cars not linked to OfficeManager', 'info',
            'Active cars with no CarSerial, so they have no live status, contracts or invoices from the API. Usually a sheet-only car whose VIN does not match any OM car.',
            (clone $base)->count(), $items);
    }

    /** Cars missing a purchase price or date (needed for depreciation / replacement). */
    private function carsNoPurchase(): array
    {
        $base = $this->activeVehicles()->where(fn ($q) => $q->whereNull('purchase_price')->orWhereNull('purchase_date'));
        $items = (clone $base)->limit(self::CAP)->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'Missing ' . (! $v->purchase_price ? 'purchase price' : 'purchase date')))->all();

        return $this->group('cars_no_purchase', 'Cars without purchase price/date', 'info',
            'Active cars missing a purchase price or date. These feed depreciation and the 4-year replacement plan; they come from the FASTER Asset sheet.',
            (clone $base)->count(), $items);
    }

    /** The same VIN on more than one car — a data-integrity problem. */
    private function duplicateVin(): array
    {
        $vins = Vehicle::whereNotNull('vin')->where('vin', '<>', '')
            ->select('vin')->groupBy('vin')->havingRaw('COUNT(*) > 1')->pluck('vin');

        $count = Vehicle::whereIn('vin', $vins)->count();
        $items = Vehicle::whereIn('vin', $vins->take(self::CAP))->orderBy('vin')->get()
            ->map(fn ($v) => $this->vehicleRow($v, 'VIN ' . $v->vin . ' is used by more than one car'))->all();

        return $this->group('duplicate_vin', 'Duplicate VINs', 'critical',
            'The same chassis number (VIN) appears on more than one car. A VIN is unique to a physical car, so these are duplicates that must be merged or corrected.',
            $count, $items);
    }

    // --------------------------------------------------------------- contract checks

    /** Contracts not linked to any car. */
    private function contractsNoCar(): array
    {
        $base = Contract::whereNull('vehicle_id');
        $items = (clone $base)->with('customer')->orderByDesc('out_date')->limit(self::CAP)->get()
            ->map(fn ($c) => $this->contractRow($c, 'Contract has no car linked (CarSerial ' . ($c->car_serial ?: '—') . ' not in the fleet)'))->all();

        return $this->group('contracts_no_car', 'Contracts without a car', 'warning',
            'Contracts whose car could not be matched to a vehicle in the fleet — usually the car has not been imported yet. Import the car (Cars from API) and re-run contracts to link them.',
            (clone $base)->count(), $items);
    }

    /** Contracts with no customer. */
    private function contractsNoCustomer(): array
    {
        $base = Contract::whereNull('customer_id');
        $items = (clone $base)->with('vehicle')->orderByDesc('out_date')->limit(self::CAP)->get()
            ->map(fn ($c) => $this->contractRow($c, 'Contract has no customer'))->all();

        return $this->group('contracts_no_customer', 'Contracts without a customer', 'warning',
            'Contracts with no customer attached. The renter is unknown, so these will not show on any customer profile or balance.',
            (clone $base)->count(), $items);
    }

    /** Contracts missing a start (out) date. */
    private function contractsNoOutDate(): array
    {
        $base = Contract::whereNull('out_date');
        $items = (clone $base)->with(['vehicle', 'customer'])->limit(self::CAP)->get()
            ->map(fn ($c) => $this->contractRow($c, 'No start date (DateOut) on the contract'))->all();

        return $this->group('contracts_no_out_date', 'Contracts without a start date', 'warning',
            'Contracts with no pickup/start date. Duration, overdue and timeline logic all rely on it.',
            (clone $base)->count(), $items);
    }

    // --------------------------------------------------------------- customer checks

    /** Customers that never got a name. */
    private function customersNoName(): array
    {
        $base = Customer::where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''));
        $items = (clone $base)->withCount('contracts')->orderByDesc('contracts_count')->limit(self::CAP)->get()
            ->map(fn ($c) => [
                'vehicle_id'  => null,
                'plate'       => null,
                'car'         => null,
                'status'      => null,
                'contract_id' => null,
                'contract_no' => null,
                'customer_id' => $c->id,
                'customer'    => $c->customer_no ? '#' . $c->customer_no : ('Customer ' . $c->id),
                'detail'      => 'No name on file' . ($c->contracts_count ? " ({$c->contracts_count} contracts)" : ''),
            ])->all();

        return $this->group('customers_no_name', 'Customers without a name', 'info',
            'Customers that are still nameless after the bulk name fill — either masked at the source ("***") or not present in the OfficeManager customer list.',
            (clone $base)->count(), $items);
    }

    // ------------------------------------------------------------------------ helpers

    private function vehicleRow(Vehicle $v, string $detail): array
    {
        return [
            'vehicle_id'  => $v->id,
            'plate'       => $v->plate_no,
            'car'         => trim((string) ($v->make . ' ' . $v->model)) ?: null,
            'status'      => $v->status,
            'contract_id' => null,
            'contract_no' => null,
            'customer_id' => null,
            'customer'    => null,
            'detail'      => $detail,
        ];
    }

    private function contractRow(Contract $c, string $detail): array
    {
        return [
            'vehicle_id'  => $c->vehicle_id,
            'plate'       => $c->vehicle?->plate_no,
            'car'         => $c->vehicle ? (trim((string) ($c->vehicle->make . ' ' . $c->vehicle->model)) ?: null) : null,
            'status'      => $c->vehicle?->status,
            'contract_id' => $c->id,
            'contract_no' => $c->contract_no,
            'customer_id' => $c->customer_id,
            'customer'    => $c->customer?->name_en ?: ($c->customer?->customer_no ? '#' . $c->customer->customer_no : null),
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
