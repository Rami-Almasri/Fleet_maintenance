<?php

namespace App\Services;

use App\Models\FinancialEvent;
use App\Models\FuelFill;
use App\Models\LogisticsTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\VehicleRegistration;
use App\Models\VehicleWashJob;
use App\Services\Odoo\FinancialEventBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The four everyday running costs a car incurs outside the workshop: FUEL, CAR WASH, REGISTRATION and
 * the driver's TAXI fare.
 *
 * ── WHY THESE FOUR SHARE A SERVICE AND NOT A TABLE ─────────────────────────────────────────────────
 *
 * They are four different operational events and each is recorded on its own operational record — a
 * fuel fill has litres and an odometer, a wash has a type and changes the car's cleaning status, a
 * renewal belongs to the registration it renewed, a fare belongs to the journey that caused it. Giving
 * them one table would have been exactly the "separate finance module" this integration exists not to
 * be: four unrelated events flattened into rows whose only common feature is that they cost money.
 *
 * What they DO genuinely share is the shape of the recording act — capture the operational facts, store
 * the receipt through the app's existing storage, write the vehicle audit trail, then let the SAME
 * {@see FinancialEventBuilder} raise the obligation. That is what lives here, once, instead of four
 * times.
 *
 * ── THE FINANCIAL HOOK IS BEST-EFFORT, EVERYWHERE ──────────────────────────────────────────────────
 *
 * Same rule as MaintenanceInvoiceService: the obligation is DERIVED and can always be rebuilt
 * (`odoo:rebuild-events`), so a problem in the accounting bridge must never roll back a cost somebody
 * just recorded. An event that fails to build is absent and visible on the sync dashboard, which is far
 * better than a lost receipt.
 */
class VehicleOperatingCostService
{
    public function __construct(
        private FinancialEventBuilder $financial,
        private VehicleLogService $log,
    ) {
    }

    // ── FUEL ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Record a tank of fuel.
     *
     * The odometer reading is stored as evidence of THIS fill and is deliberately not written through
     * to `vehicles.odometer` — that column has a documented set of writers and an ordering rule
     * ([[odometer-source-race]]), and adding a silent eighth writer through a fuel form is how such an
     * invariant gets broken.
     *
     * @param array{filled_at?:?string, litres?:?float, odometer_km?:?int, fuel_grade?:?string,
     *              vendor_id?:?int, station_name?:?string, cost?:?float, currency?:?string,
     *              receipt_no?:?string, receipt_date?:?string, maintenance_id?:?int, notes?:?string} $data
     */
    public function recordFuelFill(Vehicle $vehicle, array $data, User $actor, ?UploadedFile $receipt = null): FuelFill
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $receipt) {
            $fill = new FuelFill([
                'vehicle_id'     => $vehicle->id,
                'maintenance_id' => $data['maintenance_id'] ?? null,
                'filled_at'      => $data['filled_at'] ?? now(),
                'litres'         => $data['litres'] ?? null,
                'odometer_km'    => $data['odometer_km'] ?? null,
                'fuel_grade'     => $data['fuel_grade'] ?? null,
                'vendor_id'      => $data['vendor_id'] ?? null,
                'station_name'   => $data['station_name'] ?? null,
                'cost'           => round((float) ($data['cost'] ?? 0), 2),
                'currency'       => $data['currency'] ?? 'AED',
                'receipt_no'     => $data['receipt_no'] ?? null,
                'receipt_date'   => $data['receipt_date'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'recorded_by'    => $actor->id,
            ]);

            $this->attachTo($fill, $receipt, 'fuel-receipts', 'receipt_disk', 'receipt_key');
            $fill->save();

            $this->audit($vehicle, $actor, 'Fuel recorded', [
                'fuel_fill_id' => $fill->id,
                'litres'       => $fill->litres,
                'cost'         => (float) $fill->cost,
            ]);

            $this->raise($fill->fresh(['vehicle', 'vendor']), $actor);

            return $fill;
        });
    }

    /** Update a fill (a corrected litre count, a receipt that arrived late) and re-derive its event. */
    public function updateFuelFill(FuelFill $fill, array $data, User $actor, ?UploadedFile $receipt = null): FuelFill
    {
        return DB::transaction(function () use ($fill, $data, $actor, $receipt) {
            $fill->fill(array_intersect_key($data, array_flip([
                'filled_at', 'litres', 'odometer_km', 'fuel_grade', 'vendor_id', 'station_name',
                'cost', 'currency', 'receipt_no', 'receipt_date', 'maintenance_id', 'notes',
            ])));

            $this->attachTo($fill, $receipt, 'fuel-receipts', 'receipt_disk', 'receipt_key');
            $fill->save();

            $this->raise($fill->fresh(['vehicle', 'vendor']), $actor);

            return $fill;
        });
    }

    // ── CAR WASH ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Record a wash.
     *
     * Two things happen beyond writing the row, and both matter for keeping this ONE workflow rather
     * than two:
     *
     *  1. The car's `cleaning_status` moves to clean, through the same field ContractEligibilityService
     *     already reads — so a wash recorded here clears the Booking Readiness cleaning blocker exactly
     *     as the existing cleaning screen does. Recording a wash and marking the car clean are the same
     *     act, and making a user do both would be the bug.
     *  2. An INTERNAL wash raises no obligation at all (see VehicleWashJob::financialExpenseType()) —
     *     the row is history, and finance never sees a nil bill for work our own staff did.
     *
     * @param array{washed_at?:?string, wash_type?:?string, performed_by_kind?:?string, vendor_id?:?int,
     *              cost?:?float, currency?:?string, invoice_no?:?string, invoice_date?:?string,
     *              notes?:?string, set_clean?:bool} $data
     */
    public function recordWash(Vehicle $vehicle, array $data, User $actor, ?UploadedFile $receipt = null): VehicleWashJob
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $receipt) {
            $wash = new VehicleWashJob([
                'vehicle_id'        => $vehicle->id,
                'washed_at'         => $data['washed_at'] ?? now(),
                'wash_type'         => $data['wash_type'] ?? VehicleWashJob::TYPE_EXTERIOR,
                'performed_by_kind' => $data['performed_by_kind'] ?? VehicleWashJob::BY_EXTERNAL,
                'vendor_id'         => $data['vendor_id'] ?? null,
                'cost'              => isset($data['cost']) ? round((float) $data['cost'], 2) : null,
                'currency'          => $data['currency'] ?? 'AED',
                'invoice_no'        => $data['invoice_no'] ?? null,
                'invoice_date'      => $data['invoice_date'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'recorded_by'       => $actor->id,
            ]);

            // An internal wash was not bought from anyone — force the vendor away rather than letting a
            // stale form value attach a supplier to work we did ourselves.
            if ($wash->isInternal()) {
                $wash->vendor_id = null;
                $wash->cost      = null;
            }

            $this->attachTo($wash, $receipt, 'wash-receipts', 'receipt_disk', 'receipt_key');
            $wash->save();

            // The car is clean now — same field, same meaning, same readiness gate as the cleaning screen.
            if (($data['set_clean'] ?? true) && $vehicle->cleaning_status !== 'clean') {
                $previous = $vehicle->cleaning_status;
                $vehicle->update(['cleaning_status' => 'clean']);

                $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_CLEANING_UPDATED, $actor, [
                    'description' => 'Cleaning ' . ($previous ?: 'unset') . ' → clean (wash recorded)',
                    'meta'        => ['field' => 'cleaning_status', 'from' => $previous, 'to' => 'clean',
                                      'vehicle_wash_job_id' => $wash->id],
                ]);
            }

            $this->raise($wash->fresh(['vehicle', 'vendor']), $actor);

            return $wash;
        });
    }

    // ── REGISTRATION RENEWAL ──────────────────────────────────────────────────────────────────────

    /**
     * Record what a registration renewal cost, on the registration it renewed.
     *
     * Note what is NOT touched: `expiry_date`, `status` and everything else OM supplies. Those are the
     * authority's facts and arrive through the sync; this records only what WE paid, in columns the
     * importer never writes.
     *
     * @param array{renewal_cost:float, renewal_currency?:?string, renewal_date?:?string,
     *              renewal_vendor_id?:?int, renewal_reference?:?string} $data
     */
    public function recordRegistrationRenewal(
        VehicleRegistration $registration,
        array $data,
        User $actor,
        ?UploadedFile $receipt = null,
    ): VehicleRegistration {
        return DB::transaction(function () use ($registration, $data, $actor, $receipt) {
            $registration->fill([
                'renewal_cost'        => round((float) $data['renewal_cost'], 2),
                'renewal_currency'    => $data['renewal_currency'] ?? 'AED',
                'renewal_date'        => $data['renewal_date'] ?? now()->toDateString(),
                'renewal_vendor_id'   => $data['renewal_vendor_id'] ?? null,
                'renewal_reference'   => $data['renewal_reference'] ?? null,
                'renewal_recorded_by' => $actor->id,
                'renewal_recorded_at' => now(),
            ]);

            $this->attachTo($registration, $receipt, 'registration-receipts', 'renewal_receipt_disk', 'renewal_receipt_key');
            $registration->save();

            if ($registration->vehicle) {
                $this->audit($registration->vehicle, $actor, 'Registration renewal recorded', [
                    'vehicle_registration_id' => $registration->id,
                    'renewal_cost'            => (float) $registration->renewal_cost,
                ]);
            }

            $this->raise($registration->fresh(['vehicle', 'renewalVendor']), $actor);

            return $registration;
        });
    }

    // ── TAXI FARE ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Record the driver's fare on a movement.
     *
     * Recorded against the journey rather than on a standalone claim form, so the fare inherits the
     * movement's own audit trail and the question "why was this taxi taken?" is answerable from the
     * row itself.
     *
     * @param array{taxi_fare:float, taxi_currency?:?string, taxi_date?:?string, taxi_leg?:?string,
     *              taxi_from?:?string, taxi_to?:?string, taxi_reference?:?string} $data
     */
    public function recordTaxiFare(LogisticsTask $task, array $data, User $actor, ?UploadedFile $receipt = null): LogisticsTask
    {
        return DB::transaction(function () use ($task, $data, $actor, $receipt) {
            $task->fill([
                'taxi_fare'        => round((float) $data['taxi_fare'], 2),
                'taxi_currency'    => $data['taxi_currency'] ?? 'AED',
                'taxi_date'        => $data['taxi_date'] ?? now()->toDateString(),
                'taxi_leg'         => $data['taxi_leg'] ?? LogisticsTask::TAXI_LEG_RETURN,
                'taxi_from'        => $data['taxi_from'] ?? null,
                'taxi_to'          => $data['taxi_to'] ?? null,
                'taxi_reference'   => $data['taxi_reference'] ?? null,
                'taxi_recorded_by' => $actor->id,
                'taxi_recorded_at' => now(),
            ]);

            $this->attachTo($task, $receipt, 'taxi-receipts', 'taxi_receipt_disk', 'taxi_receipt_key');
            $task->save();

            if ($task->vehicle) {
                $this->audit($task->vehicle, $actor, 'Taxi fare recorded', [
                    'logistics_task_id' => $task->id,
                    'taxi_fare'         => (float) $task->taxi_fare,
                ]);
            }

            $this->raise($task->fresh(['vehicle']), $actor);

            return $task;
        });
    }

    // ── Shared plumbing ───────────────────────────────────────────────────────────────────────────

    /**
     * Store a receipt on the app's EXISTING storage and point the record at it (§29 — never a second
     * file system). No upload leaves the previous attachment untouched, so re-saving a form without
     * re-picking the file does not silently delete the evidence.
     */
    private function attachTo(Model $record, ?UploadedFile $file, string $folder, string $diskColumn, string $keyColumn): void
    {
        if (! $file) {
            return;
        }

        $key = $file->store("vehicles/{$folder}", 'public');

        $record->{$diskColumn} = 'public';
        $record->{$keyColumn}  = $key;
    }

    /** Raise or refresh the obligation. Best-effort — see the class docblock. */
    private function raise(Model $source, User $actor): ?FinancialEvent
    {
        try {
            return $this->financial->syncFor($source, $actor);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** One line on the car's own timeline, through the existing audit infrastructure (§33). */
    private function audit(Vehicle $vehicle, User $actor, string $description, array $meta): void
    {
        $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_FINANCIAL_EVENT_RAISED, $actor, [
            'description' => $description,
            'meta'        => $meta,
        ]);
    }
}
