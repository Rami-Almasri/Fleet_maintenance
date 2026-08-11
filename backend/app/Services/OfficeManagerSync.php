<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use App\Observers\ContractObserver;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Syncs data FROM the OfficeManager API into our tables (the API is the source of truth).
 *  - linkCarSerials(): map each API CarSerial onto our vehicle by VIN (ChasisNo).
 *  - clearSheetData(): wipe the old Google-Sheets contracts/maintenance for a fresh start.
 *  - importContracts(): pull contracts, link car (CarSerial) + customer (CustomerNo).
 */
class OfficeManagerSync
{
    /**
     * OfficeManager ContractStatusNo for an OPEN contract (car is OUT — no return/DateIn yet).
     * The /contracts endpoint honours ?status_no, so this is the server-side "open" filter
     * that lets us pull every active rental/maintenance in one light request, however old.
     */
    public const STATUS_OPEN = 1;

    /**
     * Return/handover fields the /contracts endpoint is AUTHORITATIVE for. Because that
     * endpoint returns the full row per contract, an empty value here is a real deletion
     * in OfficeManager (e.g. a return date a user removed), so we CLEAR our stale value
     * instead of preserving it — and log each clear as a SyncCorrection. Every OTHER field
     * keeps the protective "don't blank good local data" behaviour. in_milage is excluded
     * on purpose (its 0 default would generate noise for every open contract).
     */
    public const AUTHORITATIVE_CLEARABLE = ['in_date', 'in_time', 'in_fuel', 'closed_by'];

    /** Columns left out of an insert snapshot — internal ids / bookkeeping, not real data. */
    protected const AUDIT_SNAPSHOT_SKIP = ['id', 'created_at', 'updated_at', 'deleted_at', 'synced_at'];

    /** Columns left out of update diffs — they churn on every sync and aren't real changes. */
    protected const AUDIT_DIFF_SKIP = ['synced_at', 'updated_at', 'created_at'];

    /** Flush the in-memory audit feed to the DB once it reaches this many rows (bounds memory). */
    protected const AUDIT_FLUSH_EVERY = 500;

    /** Sync-run id to attach auto-corrections to (set by the command); null = don't persist. */
    public ?int $auditRunId = null;

    /** Auto-corrections collected this run: [{contract_id, contract_no, external_id, field, old_value}]. */
    protected array $corrections = [];

    /**
     * Record-level change feed collected this run for the Sync Audit page:
     *   inserts -> [{operation:'insert', ..., snapshot:{full row}}]
     *   updates -> [{operation:'update', ..., changes:{field:{old,new}}, changed_count:int}]
     * Built from getDirty()/getOriginal() in flush() (in-memory, no extra queries).
     */
    protected array $changes = [];

    /** customer_no => id (preloaded, grown with stubs) */
    protected array $customerMap = [];
    /** car_serial => vehicle_id */
    protected array $carSerialMap = [];
    /** car_serial => true: the cars we treat as OURS (empty when fleet-only filtering is off) */
    protected array $ourCarSerials = [];
    /** when true, contracts on cars that aren't ours are skipped during import */
    protected bool $fleetOnly = false;
    /** running count of other-company contracts skipped (multi-tenant /contracts endpoint) */
    protected int $foreignSkipped = 0;
    /** running count of fields a re-import would blank out (tallied during flush) */
    protected int $nullOverwriteCount = 0;
    /** capped samples of those would-be-blanked fields, for the reconciliation report */
    protected array $nullOverwriteSamples = [];

    public function __construct(protected OfficeManagerClient $api)
    {
    }

    /**
     * Match API vehicles to our fleet by VIN and store CarSerial on our vehicle rows.
     *
     * @return array{api_vehicles:int, matched:int}
     */
    public function linkCarSerials(?callable $progress = null): array
    {
        // VIN => our vehicle id
        $byVin = [];
        foreach (Vehicle::whereNotNull('vin')->get(['id', 'vin']) as $v) {
            $byVin[strtoupper(trim($v->vin))] = $v->id;
        }

        // total API vehicles (cheap call) so the progress bar has a target
        $total = $this->safeTotal('vehicles');
        if ($progress) {
            $progress(0, $total);
        }

        $apiCount = 0; $matched = 0; $linkedCars = [];
        foreach ($this->api->vehicles() as $row) {
            $apiCount++;
            $chasis = strtoupper(trim((string) ($row['ChasisNo'] ?? '')));
            $serial = $row['CarSerial'] ?? null;
            if ($chasis !== '' && $serial && isset($byVin[$chasis])) {
                $linkedCars[$byVin[$chasis]] = true; // distinct fleet cars (API has duplicate rows)
                // mirror the API's asset status onto our vehicle.
                // NOTE: we do NOT touch `for_sale` here — OfficeManager has no for-sale
                // status and its ForSale flag is always false, so for_sale is a manual flag.
                $statusNo = isset($row['StatusNo']) ? (int) $row['StatusNo'] : null;
                $update = [
                    'car_serial' => $serial,
                    'status_no'  => $statusNo,
                ];
                if ($statusNo !== null && isset(Vehicle::OM_STATUS[$statusNo])) {
                    $update['status'] = Vehicle::OM_STATUS[$statusNo];
                }
                // only let the API SET the flag on (never reset a manual flag off)
                if (! empty($row['ForSale'])) {
                    $update['for_sale'] = true;
                }
                Vehicle::whereKey($byVin[$chasis])->update($update);
                $matched++;
            }
            if ($progress && $apiCount % 50 === 0) {
                $progress($apiCount, $total);
            }
        }

        if ($progress) {
            $progress($apiCount, $total ?: $apiCount);
        }

        // 'cars' = distinct fleet cars linked; 'matched' = API rows matched (has duplicates)
        return ['api_vehicles' => $apiCount, 'matched' => $matched, 'cars' => count($linkedCars)];
    }

    /**
     * Create/refresh OUR vehicles straight from the OM API so a car newly added in
     * OfficeManager under our owner number is picked up automatically — no manual sheet edit.
     *
     * "Ours" = a car under one of our owner numbers (officemanager.owner_nos, e.g. 1541) OR a
     * CarSerial explicitly listed in officemanager.extra_car_serials (e.g. the one GMC we run
     * under owner 2088). MUST run BEFORE the sheet import: the API is authoritative for
     * identity/operational data (VIN, plate, year, category, status, odometer, car_serial);
     * make/model/color are owned by the sheet and are NOT overwritten here (we only set a
     * provisional name from the API's CarName when first creating a row, so no car is nameless).
     *
     * Keyed by VIN when present (so the sheet's VIN-keyed import lines up), else by OM:CarSerial.
     * A car someone added manually on the website (origin 'web') is never touched.
     *
     * @return array{api_vehicles:int, created:int, updated:int}
     */
    public function importFleetVehicles(?callable $progress = null): array
    {
        $owners = array_flip(array_map('strval', (array) config('officemanager.owner_nos', ['1541'])));
        $extra  = array_flip(array_map(fn ($s) => trim((string) $s), (array) config('officemanager.extra_car_serials', [])));

        $total = $this->safeTotal('vehicles');
        if ($progress) {
            $progress(0, $total);
        }

        $seen = 0; $created = 0; $updated = 0;
        foreach ($this->api->vehicles() as $row) {
            $seen++;
            $serial = isset($row['CarSerial']) ? (string) $row['CarSerial'] : '';
            $owner  = isset($row['CarOwnerNo']) ? (string) $row['CarOwnerNo'] : '';

            $isOurs = ($owner !== '' && isset($owners[$owner])) || ($serial !== '' && isset($extra[$serial]));
            if (! $isOurs) {
                if ($progress && $seen % 100 === 0) {
                    $progress($seen, $total);
                }
                continue;
            }

            $vin  = strtoupper(trim((string) ($row['ChasisNo'] ?? '')));
            $data = $this->mapApiVehicle($row);

            // Identify the existing row: prefer VIN (matches the sheet import), else OM serial.
            $vehicle = null;
            if ($vin !== '') {
                $vehicle = Vehicle::withTrashed()->where('vin', $vin)->first();
            }
            if (! $vehicle && $serial !== '') {
                $vehicle = Vehicle::withTrashed()
                    ->where('external_id', 'OM:' . $serial)
                    ->orWhere('car_serial', $serial)
                    ->first();
            }

            if ($vehicle) {
                if ($vehicle->origin === 'web') {
                    continue; // never clobber a car created manually on the website
                }
                // Odometer only ever goes UP: OM often has the same car typed in twice with the
                // real mileage on one row and a 0/1 placeholder on the other. Keep the highest so
                // a stale/placeholder duplicate row can't lower a good reading.
                if (array_key_exists('odometer', $data) && (int) ($vehicle->odometer ?? 0) > (int) $data['odometer']) {
                    $data['odometer'] = (int) $vehicle->odometer;
                }
                // Battery date only ever goes FORWARD, for the same reason and by the same rule. This
                // column is no longer API-only: closing a ticket that carried a battery replacement
                // stamps it here (Vehicle::recordServiceDone ← confirmRoutineServices). OM is a READ-ONLY
                // replica for us — we can never push that date back to it — so every scheduled sync
                // carried OM's older date over the change we had just recorded, resetting the car's
                // battery age and re-raising the battery check for a battery fitted yesterday.
                // Whoever holds the LATER date holds the truth: a battery cannot be fitted in the past.
                if (array_key_exists('battery_last_changed', $data) && $vehicle->battery_last_changed) {
                    $incoming = $data['battery_last_changed'];
                    if ($incoming && $vehicle->battery_last_changed->gt(Carbon::parse($incoming))) {
                        unset($data['battery_last_changed']);
                    }
                }
                $vehicle->fill($data)->save();
                $updated++;
            } else {
                // Provisional make/model from the API name so a brand-new car isn't nameless;
                // the sheet import (running next) overwrites these with the curated values.
                [$make, $model] = $this->splitName((string) ($row['CarName'] ?? ''));
                $vehicle = Vehicle::create($data + array_filter([
                    'make'  => $make,
                    'model' => $model,
                ], fn ($v) => $v !== null && $v !== ''));
                $created++;
            }

            // Insurance (insurer + expiry) is now sourced from the "F Insurance" sheet, NOT the
            // API (see InsuranceImporter). The API only carries the mortgage flag onto the car's
            // registration row here; it never touches the sheet-owned insurance columns.
            $this->upsertMortgage($vehicle, $row);

            if ($progress && $seen % 50 === 0) {
                $progress($seen, $total);
            }
        }

        if ($progress) {
            $progress($seen, $total ?: $seen);
        }

        return ['api_vehicles' => $seen, 'created' => $created, 'updated' => $updated];
    }

    /**
     * Map an OM API vehicle row to OUR vehicle columns — identity, operational data, specs
     * and the car card's rental defaults. Deliberately omits make/model/color: those belong
     * to the sheet enrichment. (The mortgage flag is handled separately by upsertMortgage().)
     *
     * @return array<string,mixed>
     */
    protected function mapApiVehicle(array $row): array
    {
        $vin      = strtoupper(trim((string) ($row['ChasisNo'] ?? '')));
        $serial   = isset($row['CarSerial']) ? (string) $row['CarSerial'] : null;
        $statusNo = isset($row['StatusNo']) ? (int) $row['StatusNo'] : null;

        $data = [
            'vin'         => $vin !== '' ? $vin : null,
            'engine_no'   => $this->strOrNull($row['EngineNo'] ?? null),
            'driver_no'   => $this->idStr($row['DriverNo'] ?? null),
            'plate_no'    => $this->strOrNull($row['CarNo'] ?? null),
            'year'        => $this->intOrNull($row['Model'] ?? null),                 // OM "Model" = year
            'category'    => $this->strOrNull(isset($row['PlateCategoryNo']) ? (string) $row['PlateCategoryNo'] : null),
            'odometer'    => $this->intOrNull($row['Milage'] ?? null, 4294967295) ?? 0,
            'car_serial'  => $serial,
            'status_no'   => $statusNo,

            // --- specs (0 = "not set" for the count/spec fields, so collapse it to null) ---
            'keys_number'  => $this->intOrNull($row['KeysNumber'] ?? null) ?: null,
            'auto_gear'    => $this->boolOrNull($row['AutoGear'] ?? null),
            'cylinders'    => $this->intOrNull($row['Cylinders'] ?? null) ?: null,
            'horse_power'  => $this->intOrNull($row['HorsePower'] ?? null) ?: null,
            'doors'        => $this->intOrNull($row['Doors'] ?? null) ?: null,
            'seats'        => $this->intOrNull($row['Seats'] ?? null) ?: null,
            'passengers'   => $this->intOrNull($row['Passengers'] ?? null) ?: null,
            'wheel_drive'  => $this->intOrNull($row['WD'] ?? null) ?: null,
            'location'     => $this->strOrNull($row['Location'] ?? null),
            'salik_tag_no' => $this->strOrNull($row['SalikTagNo'] ?? null),

            // --- purchase / warranty / service (date() nulls OM's pre-1901 placeholder) ---
            'purchase_date'     => $this->date($row['PurchaseDate'] ?? null),
            'source'            => array_key_exists('Used', $row) ? ($row['Used'] ? 'used' : 'new') : null,
            'warranty_end_date' => $this->date($row['WarrantyExpiryDate'] ?? null),
            'warranty_end_km'   => $this->intOrNull($row['WarrantyExpiryMilage'] ?? null, 4294967295) ?: null,
            'service_due_date'  => $this->date($row['ServiceExpiryDate'] ?? null),
            'service_due_km'    => $this->intOrNull($row['ServiceExpiryMilage'] ?? null, 4294967295) ?: null,
            // Battery: the car card only carries a last-replacement date (no type/capacity/validity).
            'battery_last_changed' => $this->date($row['BatteryLastChanged'] ?? null),

            // --- standard rental defaults from the car card (0 = unset -> null) ---
            'hour_rent_value'   => $this->num($row['HourRentValue'] ?? null) ?: null,
            'day_rent_value'    => $this->num($row['DayRentValue'] ?? null) ?: null,
            'week_rent_value'   => $this->num($row['WeekRentValue'] ?? null) ?: null,
            'month_rent_value'  => $this->num($row['MonthRentValue'] ?? null) ?: null,
            'year_rent_value'   => $this->num($row['YearRentValue'] ?? null) ?: null,
            'miles_allowed_pd'  => $this->intOrNull($row['MilesAllowedPD'] ?? null, 4294967295) ?: null,
            'miles_allowed_pm'  => $this->intOrNull($row['MilesAllowedPM'] ?? null, 4294967295) ?: null,
            'extra_mile_charge' => $this->num($row['ExtraMileCharge'] ?? null) ?: null,
            'full_fuel_cost'    => $this->num($row['FullFuelCost'] ?? null) ?: null,

            'external_id' => $serial ? 'OM:' . $serial : null,
            'origin'      => 'api',
            'synced_at'   => now(),
        ];

        if ($statusNo !== null && isset(Vehicle::OM_STATUS[$statusNo])) {
            $data['status'] = Vehicle::OM_STATUS[$statusNo];
        }
        // Only let the API SET for_sale on; never reset a manual flag off (mirrors linkCarSerials).
        if (! empty($row['ForSale'])) {
            $data['for_sale'] = true;
        }

        return array_filter($data, fn ($v) => $v !== null);
    }

    /**
     * Store the car's MORTGAGE flag from the API onto its registration row. Insurance (insurer,
     * policy, issue/expiry, type, deductible) is no longer sourced from the API — the "F Insurance"
     * sheet owns it now (see InsuranceImporter). This writes ONLY is_mortgaged and never touches
     * the sheet-owned columns (expiry_date / status / fines / mortgaged_by / insurance_*).
     *
     * Matched to a registration by vehicle_id, falling back to VIN (how the sheet keys its
     * rows) so the API and the sheet converge on one registration per car. A registration a
     * user entered by hand on the website (origin 'web') is left untouched.
     */
    protected function upsertMortgage(Vehicle $vehicle, array $row): void
    {
        $mortgaged = $this->boolOrNull($row['IsMortgaged'] ?? null);

        // No mortgage flag from the API — nothing to store, leave the row alone.
        if ($mortgaged === null) {
            return;
        }

        $data = [
            'is_mortgaged' => $mortgaged,
            'synced_at'    => now(),
        ];

        $vin = $vehicle->vin ? strtoupper(trim($vehicle->vin)) : null;

        $reg = VehicleRegistration::withTrashed()
            ->where('vehicle_id', $vehicle->id)
            ->when($vin, fn ($q) => $q->orWhere('chasis_no', $vin))
            ->first();

        if ($reg) {
            if ($reg->origin === 'web') {
                return; // never clobber a manually-entered registration
            }
            $data['vehicle_id'] = $vehicle->id; // link the row if the sheet left it unmatched
            $reg->fill($data)->save();
        } else {
            VehicleRegistration::create($data + [
                'vehicle_id'  => $vehicle->id,
                'chasis_no'   => $vin,
                'external_id' => $vin ?: ('OMCAR:' . ($vehicle->car_serial ?? $vehicle->id)),
                'origin'      => 'api',
            ]);
        }
    }

    /** Split "GMC YUKON" into make ("GMC") + model ("YUKON"). @return array{0:?string,1:?string} */
    protected function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    /**
     * Remove the old Google-Sheets contract + maintenance data for a clean start.
     * Keeps customers and vehicles (matched by no/VIN). Returns rows removed.
     *
     * @return array{contracts:int, maintenances:int, items:int}
     */
    public function clearSheetData(): array
    {
        return DB::transaction(function () {
            // all maintenance line-items came from the sheets
            $items = DB::table('maintenance_items')->delete();
            // Only sheet-sourced maintenance rows are cleared. Hand-entered workshop events
            // (origin = 'manual') and dashboard contract headers (origin = 'contract') are
            // the dashboard's own source of truth and must survive an API re-import.
            $maint = DB::table('maintenances')->whereIn('origin', \App\Models\Maintenance::SHEET_ORIGINS)->delete();
            // remove sheet-imported contracts (force, bypass soft-deletes)
            $contracts = Contract::withTrashed()->where('origin', 'sheet')->forceDelete();

            return ['contracts' => $contracts, 'maintenances' => $maint, 'items' => $items];
        });
    }

    /**
     * Import contracts from the API. Idempotent: keyed by external_id "OM:{ContractSerial}".
     *
     * When $dryRun is true the whole import runs inside a transaction that is rolled back
     * at the end: counts and validation are computed for real, but nothing is persisted.
     *
     * @return array{created:int, updated:int, linked_cars:int, no_car:int, customers_made:int}
     */
    public function importContracts(?int $limit = null, array $filters = [], ?callable $progress = null, bool $dryRun = false): array
    {
        // preload lookup maps
        $this->customerMap = Customer::whereNotNull('customer_no')->pluck('id', 'customer_no')->all();
        $this->carSerialMap = Vehicle::whereNotNull('car_serial')->pluck('id', 'car_serial')->all();

        // The /contracts endpoint is multi-tenant: it returns every owner's contracts. Load the
        // fleet maps from the API once — this both (a) marks which CarSerials are OURS so we
        // import only our company's contracts, and (b) maps EVERY CarSerial to our vehicle by
        // VIN (OM gives one physical car several CarSerials, so a contract may reference a serial
        // other than the one we stored on the car). If the fleet can't be determined (API down
        // AND no linked fleet), filtering is disabled rather than risk dropping everything.
        $this->foreignSkipped = 0;
        $this->fleetOnly = (bool) config('officemanager.contracts_fleet_only', true);
        $this->ourCarSerials = [];
        $this->loadVehicleMaps($progress);
        if ($this->fleetOnly && empty($this->ourCarSerials)) {
            $this->fleetOnly = false; // couldn't identify our fleet — import all rather than none
        }

        // The /contracts endpoint ignores page/page_size, so we slice it by filter instead of
        // paginating: all open contracts (any age) + only the last few months of closed history.
        // See contractWindows() for the full strategy.
        $windows = $this->contractWindows($filters, $limit);
        $totalWindows = count($windows);

        // Close-detection runs only on a normal full sync (no caller-supplied range, no
        // validation limit) — those modes pull an exact slice and shouldn't second-guess it.
        $detectCloses = ! isset($filters['from_date']) && ! isset($filters['to_date']) && ! $limit;
        $apiOpenSerials = [];   // every ContractSerial the API currently reports as open
        $closesDetected = 0;    // long contracts re-fetched because they were returned recently

        $created = 0; $updated = 0; $linked = 0; $noCar = 0; $customersMade = 0; $n = 0;
        $failedWindows = [];
        $this->nullOverwriteCount = 0;
        $this->nullOverwriteSamples = [];
        $this->corrections = [];
        $this->changes = [];

        ContractObserver::$muted = true;
        if ($dryRun) {
            DB::beginTransaction(); // capture every write so we can revert it at the end
        }

        try {
            $buffer = [];
            $wi = 0;
            foreach ($windows as $window) {
                $wi++;

                try {
                    $page = $this->api->fetchOnce('contracts', $window['query']);
                } catch (Throwable $e) {
                    // A month that won't load (server down after all retries) shouldn't sink
                    // the whole import — record it and move on; a re-run is idempotent.
                    $failedWindows[] = $window['label'];
                    if ($progress) {
                        $progress($wi, $totalWindows, "{$window['label']} · FAILED");
                    }
                    continue;
                }

                foreach ($page['items'] as $row) {
                    // On the open window, remember every OUR serial the API still considers open
                    // so the close-detection pass can spot the ones we hold open but it doesn't.
                    if ($window['collect_open'] && ! empty($row['ContractSerial']) && $this->isOurCar($row['CarSerial'] ?? null)) {
                        $apiOpenSerials[$row['ContractSerial']] = true;
                    }

                    $mapped = $this->buildContractRow($row, $linked, $noCar, $customersMade);
                    if ($mapped === null) {
                        continue;
                    }
                    $buffer[] = $mapped;
                    $n++;

                    if (count($buffer) >= 500) {
                        [$c, $u] = $this->flush($buffer);
                        $created += $c; $updated += $u; $buffer = [];
                    }

                    if ($limit && $n >= $limit) {
                        break 2;
                    }
                }

                if ($progress) {
                    $progress($wi, $totalWindows, "{$window['label']} · " . number_format($n) . ' contracts');
                }
            }

            if ($buffer) {
                [$c, $u] = $this->flush($buffer);
                $created += $c; $updated += $u; $buffer = [];
            }

            // Catch long contracts RETURNED in the last 3 months but opened earlier: they
            // drop out of the open set yet sit outside the recent OutDate windows. Re-fetch
            // exactly those so their close (DateIn/financials) lands instead of going stale.
            if ($detectCloses) {
                if ($progress) {
                    $progress($totalWindows, $totalWindows, 'Checking for recently-returned contracts…');
                }
                $closesDetected = $this->importRecentlyClosed(
                    $apiOpenSerials, $created, $updated, $linked, $noCar, $customersMade
                );
            }
        } finally {
            ContractObserver::$muted = false;
            if ($dryRun) {
                DB::rollBack(); // a dry run writes nothing — revert every upsert above
            }
        }

        if ($progress) {
            $progress($totalWindows, $totalWindows);
        }

        $odometersBumped = 0;
        $requestsWithdrawn = 0;
        $requestsCleared = 0;
        if (! $dryRun) {
            // rebuild every customer's cached balance from the new contracts
            app(AccountingService::class)->recalcAllCustomers();
            // refresh each car's live operational_status (rented / in maintenance / available)
            // from the freshly-imported open contracts — the API's StatusNo can't tell us this.
            app(OperationsService::class)->reconcileAllOperationalStatus();
            // A car that just went into the workshop under a maintenance contract (type U) may still have
            // an inspection request sitting in the Controllers' review queue from before it went in. The
            // contract answers it: withdraw the request and record which contract did it, so nobody is
            // asked to approve a test drive for a car that is already on a lift.
            $workflowService   = app(MaintenanceWorkflowService::class);
            $requestsWithdrawn = $workflowService->withdrawRequestsForMaintenanceContracts();
            // …and the other end of the same fact: a contract that CLOSED in this import means the car
            // came back, which restarts its check clock. Any system request still asking for a test it
            // raised before that return is now answered too.
            $requestsCleared = $workflowService->withdrawRequestsWhoseConditionCleared();
            // bring each car's odometer up to its freshest contract handover reading — the
            // car-card Milage lags behind the real OutMilage/InMilage the branch records.
            $odometersBumped = $this->reconcileOdometersFromContracts();
            // persist the auto-corrections (cleared stale fields) for the Sync Audit page
            $this->persistCorrections();
            // persist the record-level change feed (new contracts + per-field update diffs)
            $this->persistChanges();
        }

        return compact('created', 'updated', 'linked', 'noCar', 'customersMade')
            + [
                'scanned'                => $n,
                'no_car'                 => $noCar,
                'customers_made'         => $customersMade,
                'closes_detected'        => $closesDetected,
                'foreign_skipped'        => $this->foreignSkipped,
                'failed_windows'          => $failedWindows,
                'dry_run'                 => $dryRun,
                'corrections'            => count($this->corrections),
                'odometers_bumped'       => $odometersBumped,
                'requests_withdrawn'     => $requestsWithdrawn,
                'requests_cleared'       => $requestsCleared,
                'null_overwrites_prevented' => $this->nullOverwriteCount,
                'null_overwrite_samples'  => $this->nullOverwriteSamples,
            ];
    }

    /**
     * Refresh each car's odometer from its freshest contract handover reading.
     *
     * The car-card `Milage` (set by importFleetVehicles) is only as current as the last time
     * the card itself was edited in OfficeManager, so it lags behind the real mileage the branch
     * records at every handover — OutMilage when the car leaves, InMilage when it returns. The
     * truest "current odometer" is therefore the reading on the car's MOST RECENT contract.
     *
     * We take the reading from the latest handover EVENT by date — every contract contributes an
     * out event (OutDate, OutMilage) and, once returned, an in event (InDate, InMilage); the most
     * recent of those across all the car's contracts is the live odometer. Selecting by date (not
     * by the largest number) is deliberate: OM's history carries one-off mileage typos — including
     * an OutMilage typed higher than the same contract's InMilage, which a "max" rule would import
     * forever. Zero/placeholder readings are ignored, odometers can't run backwards so we only ever
     * raise the value, and web-origin cars (created on the website) are skipped.
     *
     * @return int  number of vehicles whose odometer was raised
     */
    protected function reconcileOdometersFromContracts(): int
    {
        return DB::update(<<<'SQL'
            UPDATE vehicles v
            JOIN (
                SELECT vehicle_id, milage AS reading FROM (
                    SELECT vehicle_id, milage,
                           ROW_NUMBER() OVER (PARTITION BY vehicle_id ORDER BY ev_date DESC, milage DESC) AS rn
                    FROM (
                        SELECT vehicle_id, out_date AS ev_date, out_milage AS milage
                        FROM contracts WHERE vehicle_id IS NOT NULL AND out_date IS NOT NULL AND out_milage > 0
                        UNION ALL
                        SELECT vehicle_id, in_date AS ev_date, in_milage AS milage
                        FROM contracts WHERE vehicle_id IS NOT NULL AND in_date IS NOT NULL AND in_milage > 0
                    ) ev
                ) ranked WHERE rn = 1
            ) r ON r.vehicle_id = v.id
            SET v.odometer = r.reading
            WHERE r.reading > v.odometer
              AND (v.origin IS NULL OR v.origin <> 'web')
        SQL);
    }

    /** Bulk-write this run's auto-corrections into sync_corrections, linked to the run. */
    protected function persistCorrections(): void
    {
        if (! $this->auditRunId || empty($this->corrections)) {
            return;
        }
        $now = now();
        foreach (array_chunk($this->corrections, 500) as $chunk) {
            DB::table('sync_corrections')->insert(array_map(fn ($c) => $c + [
                'sync_run_id' => $this->auditRunId,
                'action'      => 'cleared',
                'created_at'  => $now,
            ], $chunk));
        }
    }

    /** Capture a brand-new contract's full row for the Sync Audit "new records" feed. */
    protected function recordInsert(Contract $row): void
    {
        if (! $this->auditRunId) {
            return;
        }
        $snapshot = [];
        foreach ($row->getAttributes() as $col => $val) {
            if (in_array($col, self::AUDIT_SNAPSHOT_SKIP, true)) {
                continue;
            }
            $snapshot[$col] = $this->scalarize($val);
        }
        $this->changes[] = [
            'operation'     => 'insert',
            'contract_id'   => $row->id,
            'contract_no'   => (string) $row->contract_no,
            'external_id'   => (string) $row->external_id,
            'snapshot'      => $snapshot,
            'changed_count' => count($snapshot),
        ];
    }

    /**
     * Capture the real per-field diff of an about-to-be-saved contract. MUST be called
     * after fill() but BEFORE save(), while getDirty() still holds the changed columns and
     * getOriginal() still holds their pre-save values. Records ONLY the fields that changed.
     */
    protected function recordUpdate(Contract $existing): void
    {
        if (! $this->auditRunId) {
            return;
        }
        $diff = [];
        foreach ($existing->getDirty() as $col => $newVal) {
            if (in_array($col, self::AUDIT_DIFF_SKIP, true)) {
                continue; // bookkeeping churn, not a real data change
            }
            $old = $this->scalarize($existing->getOriginal($col));
            $new = $this->scalarize($newVal);
            if ($this->isSameValue($old, $new)) {
                continue; // representation-only churn ("0.00" vs 0, float32 noise) — not a real change
            }
            $diff[$col] = ['old' => $old, 'new' => $new];
        }
        if ($diff) {
            $this->changes[] = [
                'operation'     => 'update',
                'contract_id'   => $existing->id,
                'contract_no'   => (string) $existing->contract_no,
                'external_id'   => (string) $existing->external_id,
                'changes'       => $diff,
                'changed_count' => count($diff),
            ];
        }
    }

    /**
     * True when old and new are the SAME value, only represented differently — so the audit
     * feed doesn't report it as a change. The money/amount columns are decimal(x,2), so MySQL
     * returns them as 2-dp strings ("0.00", "142.86", "4499.88") while the API sends the raw
     * float32 (0, 142.85714721679688, 4499.8798828125). getDirty() therefore flags every
     * numeric column on every sync. We compare at the storage precision (2 dp), which collapses
     * that representation noise while keeping every real change (money moves in whole fils,
     * ≥ 0.01). Non-numeric values (dates, names, statuses) fall back to strict comparison.
     */
    protected function isSameValue($old, $new): bool
    {
        if ($old === $new) {
            return true;
        }
        // Amount columns compare at the 2-dp storage precision, treating null as 0 (a null
        // amount and 0 are the same thing — e.g. the Cardoo columns were null on older
        // contracts and the import now writes 0). Collapses decimal-as-string, float32 and
        // null↔0 representation churn, while keeping real changes (money moves in fils ≥ 0.01).
        $oldN = $old === null ? 0.0 : (is_numeric($old) ? (float) $old : null);
        $newN = $new === null ? 0.0 : (is_numeric($new) ? (float) $new : null);
        if ($oldN !== null && $newN !== null) {
            return round($oldN, 2) === round($newN, 2);
        }

        return false;
    }

    /** Normalise an attribute to a JSON-friendly scalar for the audit feed. */
    protected function scalarize($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return is_scalar($v) ? $v : json_encode($v);
    }

    /**
     * Bulk-write this run's record-level change feed (inserts + update diffs), linked to the
     * run. BEST-EFFORT: this is secondary bookkeeping — a failure here (e.g. a batch exceeding
     * MySQL's max_allowed_packet) must NEVER abort or fail an otherwise-successful import, so
     * the whole thing is wrapped and swallowed. Rows are inserted in byte-bounded batches so no
     * single multi-row INSERT can blow the packet limit (XAMPP's default is only 1 MB, and a
     * full-record snapshot is ~2 KB — 500 of them in one statement would overflow it).
     */
    protected function persistChanges(): void
    {
        if (! $this->auditRunId || empty($this->changes)) {
            return;
        }
        try {
            $now = now();
            $rows = array_map(fn ($c) => [
                'sync_run_id'   => $this->auditRunId,
                'contract_id'   => $c['contract_id'],
                'contract_no'   => $c['contract_no'],
                'external_id'   => $c['external_id'],
                'operation'     => $c['operation'],
                'snapshot'      => isset($c['snapshot']) ? json_encode($c['snapshot']) : null,
                'changes'       => isset($c['changes']) ? json_encode($c['changes']) : null,
                'changed_count' => $c['changed_count'] ?? 0,
                'created_at'    => $now,
            ], $this->changes);
            // Free the in-memory buffer now — we've snapshotted it into $rows. This is what
            // keeps a long sync's footprint flat instead of growing with every record.
            $this->changes = [];

            foreach ($this->byteBoundedChunks($rows, 512 * 1024, 200) as $batch) {
                try {
                    DB::table('sync_changes')->insert($batch);
                } catch (\Throwable $e) {
                    report($e); // one odd/oversized batch shouldn't lose the rest
                }
            }
        } catch (\Throwable $e) {
            report($e); // never let audit bookkeeping break the sync
        }
    }

    /**
     * Split rows into batches whose combined encoded size stays under $maxBytes (and at most
     * $maxRows each), so a single multi-row INSERT can never exceed MySQL's max_allowed_packet.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<int,array<string,mixed>>>
     */
    protected function byteBoundedChunks(array $rows, int $maxBytes, int $maxRows): array
    {
        $batches = [];
        $batch = [];
        $size = 0;
        foreach ($rows as $row) {
            $rowSize = strlen((string) ($row['snapshot'] ?? '')) + strlen((string) ($row['changes'] ?? '')) + 200;
            if ($batch && ($size + $rowSize > $maxBytes || count($batch) >= $maxRows)) {
                $batches[] = $batch;
                $batch = [];
                $size = 0;
            }
            $batch[] = $row;
            $size += $rowSize;
        }
        if ($batch) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Re-fetch contracts we still hold as OPEN that the API no longer reports as open —
     * i.e. they were returned/closed since our last sync. These are the long-running
     * contracts (opened earlier than the recent OutDate windows) whose close would
     * otherwise never reach us, leaving a permanent "zombie open" row.
     *
     * The /contracts endpoint only filters by OutDate, so we group the stale serials by the
     * month of their (locally known) OutDate and re-pull just those months, keeping only the
     * rows we were looking for. Usually this fetches nothing (steady state) or one or two
     * small months (a handful of long contracts just closed).
     *
     * @param  array<int|string,bool>  $apiOpenSerials  serials the API currently reports open
     * @return int  number of stale-open contracts re-fetched and updated
     */
    protected function importRecentlyClosed(
        array $apiOpenSerials,
        int &$created,
        int &$updated,
        int &$linked,
        int &$noCar,
        int &$customersMade
    ): int {
        // contracts we believe are open, with the OutDate we'd filter the API by
        $localOpen = Contract::where('state', 'open')
            ->whereNotNull('contract_serial')
            ->whereNotNull('out_date')
            ->pluck('out_date', 'contract_serial');

        // group the now-stale ones (open here, not open at source) by their OutDate month
        $monthSerials = [];   // 'Y-m-01' => [serial => true]
        foreach ($localOpen as $serial => $outDate) {
            if (isset($apiOpenSerials[$serial])) {
                continue; // still open at source — nothing to do
            }
            $month = Carbon::parse($outDate)->startOfMonth()->format('Y-m-d');
            $monthSerials[$month][$serial] = true;
        }
        if (! $monthSerials) {
            return 0;
        }

        $refreshed = 0; $buffer = [];
        foreach ($monthSerials as $month => $wanted) {
            $from = Carbon::parse($month);
            try {
                $page = $this->api->fetchOnce('contracts', [
                    'from_date' => $from->toDateString(),
                    'to_date'   => $from->copy()->addMonth()->toDateString(),
                ]);
            } catch (Throwable $e) {
                continue; // a month that won't load is retried (idempotently) next run
            }

            foreach ($page['items'] as $row) {
                $serial = $row['ContractSerial'] ?? null;
                if (! $serial || ! isset($wanted[$serial])) {
                    continue; // only the specific stale-open contracts from this month
                }
                $mapped = $this->buildContractRow($row, $linked, $noCar, $customersMade);
                if ($mapped === null) {
                    continue;
                }
                $buffer[] = $mapped;
                $refreshed++;

                if (count($buffer) >= 500) {
                    [$c, $u] = $this->flush($buffer);
                    $created += $c; $updated += $u; $buffer = [];
                }
            }
        }
        if ($buffer) {
            [$c, $u] = $this->flush($buffer);
            $created += $c; $updated += $u;
        }

        return $refreshed;
    }

    /**
     * Map one raw API contract row to a [external_id, data] upsert record.
     * Returns null for rows we can't key (missing ContractSerial).
     */
    protected function buildContractRow(array $row, int &$linked, int &$noCar, int &$customersMade): ?array
    {
        $serial = $row['ContractSerial'] ?? null;
        if (! $serial) {
            return null;
        }

        $carSerial = $row['CarSerial'] ?? null;

        // Multi-tenant endpoint: skip contracts on cars that aren't ours.
        if (! $this->isOurCar($carSerial)) {
            $this->foreignSkipped++;
            return null;
        }

        $vehicleId = $carSerial ? ($this->carSerialMap[$carSerial] ?? null) : null;
        $vehicleId ? $linked++ : $noCar++;

        $customerId = $this->resolveCustomer($row['CustomerNo'] ?? null, $customersMade);
        $inDate = $this->date($row['InDate'] ?? null);

        // /api/v1/contracts returns the FULL row (financials, milages, real handover dates),
        // so we map everything here in one pass — no secondary /contracts/report enrichment.
        $money = fn ($k) => $this->num($row[$k] ?? null);

        return [
            'external_id' => 'OM:' . $serial,
            'data'        => [
                // --- identity & linkage: ContractSerial is the anchor; CarSerial -> vehicle ---
                'contract_serial'    => $serial,
                'contract_no'        => isset($row['ContractNo']) ? (string) $row['ContractNo'] : null,
                'contract_type'      => $row['ContractType'] ?? null,
                'contract_status_no' => isset($row['ContractStatusNo']) ? (string) $row['ContractStatusNo'] : null,
                'customer_id'        => $customerId,
                'vehicle_id'         => $vehicleId,
                'car_serial'         => $carSerial,

                // --- period / handover (date() nulls OM's 1900-01-01 placeholder) ---
                'out_date'   => $this->date($row['OutDate'] ?? null),
                'out_time'   => $this->strOrNull($row['OutTime'] ?? null),
                'out_milage' => $this->intOrNull($row['OutMilage'] ?? null, 4294967295),
                'out_fuel'   => $this->strOrNull($row['OutFuel'] ?? null),
                'opened_by'  => $this->idStr($row['OpenedBy'] ?? null),
                'in_date'    => $inDate,
                'in_time'    => $this->strOrNull($row['InTime'] ?? null),
                'in_milage'  => $this->intOrNull($row['InMilage'] ?? null, 4294967295),
                'in_fuel'    => $this->strOrNull($row['InFuel'] ?? null),
                'closed_by'  => $this->idStr($row['ClosedBy'] ?? null),
                'days'       => $this->intOrNull($row['Days'] ?? null),
                // a real return date means the car is back -> close it (drains "zombie open")
                'state'      => $inDate ? 'closed' : 'open',
                'out_date_hijri' => $this->strOrNull($row['OutDateHijri'] ?? null),
                'in_date_hijri'  => $this->strOrNull($row['InDateHijri'] ?? null),

                // --- pricing ---
                'day_price'         => $money('DayPrice'),
                'week_price'        => $money('WeekPrice'),
                'month_price'       => $money('MonthPrice'),
                'hour_price'        => $money('HourPrice'),
                'year_price'        => $money('YearPrice'),
                'miles_allowed_pd'  => $money('MilesAllowedPD'),
                'miles_allowed_pm'  => $money('MilesAllowedPM'),
                'extra_mile_charge' => $money('ExtraMileCharge'),
                'cdw_rate'          => $money('CDWRate'),
                'pai_rate'          => $money('PAIRate'),
                'insurance_type'    => $this->strOrNull($row['InsurenceType'] ?? null),

                // --- debit breakdown ---
                'rents_debit'         => $money('RentsDebit'),
                'breachs_debit'       => $money('BreachsDebit'),
                'salik_debit'         => $money('SalikDebit'),
                'damages_debit'       => $money('DamagesDebit'),
                'extra_charges_debit' => $money('ExtraChargesDebit'),
                'co_driver_debit'     => $money('CoDriverDebit'),
                'km_debit'            => $money('KMDebit'),
                'fuel_debit'          => $money('FuelDebit'),
                'gps_debit'           => $money('GpsDebit'),
                'cdw_debit'           => $money('CDWDebit'),
                'extra_driver_debit'  => $money('ExtraDriverDebit'),
                'vat_debit'           => $money('VatDebit'),
                'deposit_debit'       => $money('DepositDebit'),

                // --- credit breakdown ---
                'rents_credit'         => $money('RentsCredit'),
                'breachs_credit'       => $money('BreachsCredit'),
                'salik_credit'         => $money('SalikCredit'),
                'damages_credit'       => $money('DamagesCredit'),
                'extra_charges_credit' => $money('ExtraChargesCredit'),
                'co_driver_credit'     => $money('CoDriverCredit'),
                'km_credit'            => $money('KMCredit'),
                'fuel_credit'          => $money('FuelCredit'),
                'gps_credit'           => $money('GpsCredit'),
                'cdw_credit'           => $money('CDWCredit'),
                'extra_driver_credit'  => $money('ExtraDriverCredit'),
                'vat_credit'           => $money('VatCredit'),
                'deposit_credit'       => $money('DepositCredit'),

                // --- Cardoo charge (its own debit/credit + a separate deposit hold) ---
                // OM itemises this alongside the buckets above; it's part of ContractDebit/
                // ContractCredit, so mapping it makes those grand totals reconcile.
                'cardoo_debit'         => $money('CardooDebit'),
                'cardoo_credit'        => $money('CardooCredit'),
                'cardoo_deposit'       => $money('CardooDepositAmount'),

                // --- totals & adjustments ---
                'contract_debit'       => $money('ContractDebit'),
                'contract_credit'      => $money('ContractCredit'),
                'contract_balance'     => $money('ContractBalance'),
                'contract_refunds'     => $money('ContractRefunds'),
                'contract_discount'    => $money('ContractDiscount'),
                'contract_bad_debts'   => $money('ContractBadDebts'),
                'contract_deposit'     => $money('ContractDeposit'),
                'contract_commissions' => $money('ContractCommissions'),
                'contract_income'      => $money('ContractIncome'),

                // --- parties / misc (id fields: 0 means "none" -> null) ---
                'authorization_amount' => $money('AuthorizationAmount'),
                'authorization_date'   => $this->date($row['AuthorizationDate'] ?? null),
                'trip_direction'       => $this->strOrNull($row['TripDirection'] ?? null),
                'under_claim'          => $this->boolOrNull($row['UnderClaim'] ?? null),
                'guarantor_no'         => $this->idStr($row['GuarantorNo'] ?? null),
                'driver2'              => $this->idStr($row['Driver2'] ?? null),
                'driver3'              => $this->idStr($row['Driver3'] ?? null),
                'driver_out'           => $this->idStr($row['DriverOut'] ?? null),
                'driver_in'            => $this->idStr($row['DriverIn'] ?? null),
                'co_driver_cost'       => $money('CoDriverCost'),
                'extra_driver_charge'  => $money('ExtraDriverCharge'),
                'gps_charge'           => $money('GpsCharge'),
                'fuel_charge'          => $money('FuelCharge'),
                'ra_vat_percentage'    => $money('RaContractVatPercentage'),
                'salesman_commission_no1'    => $this->idStr($row['SalesManCommissionNo1'] ?? null),
                'salesman_commission_value1' => $money('SalesManCommissionValue1'),
                'salesman_commission_no2'    => $this->idStr($row['SalesManCommissionNo2'] ?? null),
                'salesman_commission_value2' => $money('SalesManCommissionValue2'),
                'tax_inclusive'        => $this->boolOrNull($row['TaxInclusive'] ?? null),
                'cdw_on_contract'      => $this->boolOrNull($row['CDWOnContract'] ?? null),
                'credit_card_no'       => $this->strOrNull($row['CreditCardNo'] ?? null),
                'credit_card_expiry'   => $this->strOrNull($row['CreditCardExpiary'] ?? null),
                'remarks'              => $this->strOrNull($row['Remarks'] ?? null),
                'sales_man1'           => $this->idStr($row['SalesManNo1'] ?? null),
                'sales_man2'           => $this->idStr($row['SalesManNo2'] ?? null),
                'source'               => $this->idStr($row['SourceNo'] ?? null),

                'synced_at'          => now(),
                'origin'             => 'api',
            ],
        ];
    }

    /**
     * Build the list of fetch windows for a contract import. Each entry is
     * ['label' => …, 'query' => [...], 'collect_open' => bool].
     *
     *  - explicit from_date/to_date in $filters -> a single window (honour the caller exactly)
     *  - --limit with no range                  -> just the current month (validation runs)
     *  - otherwise (the normal sync) -> a targeted, memory-safe set that captures everything
     *    relevant WITHOUT re-downloading thousands of long-settled contracts:
     *
     *      1. ALL OPEN contracts (DateIn empty), however old. status_no=1 is the API's
     *         server-side "open" filter; with no date bound it returns the whole active
     *         rental/maintenance set in one light request (a few hundred rows today).
     *
     *      2. CLOSED history, month-by-month by OutDate. By default (contracts_closed_months=0)
     *         this is the FULL history of our cars; set a positive N to keep only the last N
     *         months instead. (Contracts are filtered to OUR cars, so full history is bounded.)
     *
     *    Long contracts opened earlier but RETURNED recently (DateIn in the last 3 months,
     *    DateOut older) won't fall in either set above — they're picked up by the dedicated
     *    close-detection pass in importContracts(), which re-fetches exactly the now-closed
     *    contracts we still hold as open.
     *
     * NOTE: the /contracts endpoint ignores page/page_size (it returns the whole filtered
     * set), so memory is bounded by keeping each window's filter tight — not by paging.
     * DB writes are still flushed in batches of 500 (see flush()).
     */
    protected function contractWindows(array $filters, ?int $limit): array
    {
        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            return [['label' => 'custom range', 'query' => $filters, 'collect_open' => false]];
        }

        if ($limit) {
            $from = now()->startOfMonth();
            return [[
                'label' => $from->format('M Y'),
                'query' => array_merge($filters, [
                    'from_date' => $from->toDateString(),
                    'to_date'   => $from->copy()->addMonth()->toDateString(),
                ]),
                'collect_open' => false,
            ]];
        }

        $windows = [];

        // 1) Every OPEN contract, regardless of age — the active fleet (cars currently out).
        $windows[] = [
            'label'        => 'Open contracts',
            'query'        => array_merge($filters, ['status_no' => self::STATUS_OPEN]),
            'collect_open' => true,
        ];

        // 2) Closed history, month-by-month by OutDate. 0 months = ALL history (from
        //    contracts_since); a positive N keeps only the last N months.
        $months = (int) config('officemanager.contracts_closed_months', 0);
        $start  = $months > 0
            ? now()->startOfMonth()->subMonths($months)
            : \Carbon\Carbon::parse(config('officemanager.contracts_since', '2011-01-01'))->startOfMonth();
        $end    = now()->startOfMonth()->addMonth();
        for ($d = $start->copy(); $d < $end; $d->addMonth()) {
            $windows[] = [
                'label' => $d->format('M Y'),
                'query' => array_merge($filters, [
                    'from_date' => $d->toDateString(),
                    'to_date'   => $d->copy()->addMonth()->toDateString(),
                ]),
                'collect_open' => false,
            ];
        }

        return $windows;
    }

    /**
     * Load the fleet maps used by contract import, from the API vehicles list (fetched once):
     *
     *  - $this->ourCarSerials: every CarSerial that belongs to US (owner in officemanager.owner_nos,
     *    e.g. 1541; OR VIN-matches a car in our fleet; OR a DB-linked car; OR an explicit extra
     *    serial, e.g. the one 2088 GMC). Used to drop other companies' contracts.
     *  - $this->carSerialMap (augmented): CarSerial => our vehicle id, mapped BY VIN so that
     *    EVERY one of a car's CarSerials resolves to the car. OfficeManager gives one physical
     *    car several CarSerials (owner 1541 has 444 serials but only ~335 VINs), so a contract
     *    can reference a serial other than the one stored on the vehicle — without this, those
     *    contracts would import with no car linked.
     *
     * Leaves the maps as-is (DB-only) if the API vehicles list can't be fetched.
     */
    protected function loadVehicleMaps(?callable $progress = null): void
    {
        $owners = array_flip(array_map('strval', (array) config('officemanager.owner_nos', ['1541'])));

        // our VIN => vehicle id (to resolve every CarSerial of a car back to the one vehicle row)
        $byVin = [];
        foreach (Vehicle::whereNotNull('vin')->where('vin', '<>', '')->get(['id', 'vin']) as $v) {
            $byVin[strtoupper(trim($v->vin))] = $v->id;
        }

        $ours = [];
        if ($progress) {
            $progress(0, 0, 'Identifying our cars…');
        }
        try {
            foreach ($this->api->vehicles() as $row) {
                $serial = isset($row['CarSerial']) ? (string) $row['CarSerial'] : '';
                if ($serial === '') {
                    continue;
                }
                $owner = isset($row['CarOwnerNo']) ? (string) $row['CarOwnerNo'] : '';
                $vin   = strtoupper(trim((string) ($row['ChasisNo'] ?? '')));

                // ours by owner number
                if ($owner !== '' && isset($owners[$owner])) {
                    $ours[$serial] = true;
                }
                // map this CarSerial to our vehicle by VIN — and a VIN match means it's ours too
                if ($vin !== '' && isset($byVin[$vin])) {
                    $this->carSerialMap[$serial] = $byVin[$vin];
                    $ours[$serial] = true;
                }
            }
        } catch (Throwable $e) {
            // vehicles list unavailable — keep the DB-only maps already loaded
        }

        // DB-linked cars (covers a VIN-less car we linked manually) and explicit extra serials
        foreach (array_keys($this->carSerialMap) as $serial) {
            $ours[(string) $serial] = true;
        }
        foreach ((array) config('officemanager.extra_car_serials', []) as $serial) {
            $serial = trim((string) $serial);
            if ($serial !== '') {
                $ours[$serial] = true;
            }
        }

        $this->ourCarSerials = $ours;
    }

    /** True if a contract's car is ours (or filtering is off). Blank CarSerial is never ours. */
    protected function isOurCar($carSerial): bool
    {
        if (! $this->fleetOnly) {
            return true;
        }
        if ($carSerial === null || $carSerial === '') {
            return false;
        }
        return isset($this->ourCarSerials[(string) $carSerial]);
    }

    /** API total for a list endpoint, swallowing errors (e.g. API down) -> 0. */
    protected function safeTotal(string $path, array $filters = []): int
    {
        try {
            return $this->api->count($path, $filters);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Plain month-by-month fetch windows for an endpoint that filters by a date but ignores
     * page/page_size (e.g. /invoices by InvoiceDate). Honours an explicit from_date/to_date
     * range and --limit (current month only); otherwise full history from contracts_since.
     *
     * @return array<int,array{label:string, query:array<string,mixed>}>
     */
    protected function dateWindows(array $filters, ?int $limit): array
    {
        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            return [['label' => 'custom range', 'query' => $filters]];
        }
        if ($limit) {
            $from = now()->startOfMonth();
            return [['label' => $from->format('M Y'), 'query' => array_merge($filters, [
                'from_date' => $from->toDateString(),
                'to_date'   => $from->copy()->addMonth()->toDateString(),
            ])]];
        }
        $start = \Carbon\Carbon::parse(config('officemanager.contracts_since', '2011-01-01'))->startOfMonth();
        $end   = now()->startOfMonth()->addMonth();
        $windows = [];
        for ($d = $start->copy(); $d < $end; $d->addMonth()) {
            $windows[] = ['label' => $d->format('M Y'), 'query' => array_merge($filters, [
                'from_date' => $d->toDateString(),
                'to_date'   => $d->copy()->addMonth()->toDateString(),
            ])];
        }
        return $windows;
    }

    /**
     * Import invoices (the charges on a contract) and roll them up into each contract's debit.
     * Linked by RaContractSerial -> contracts.contract_serial; only invoices for OUR contracts
     * are kept (the endpoint is multi-tenant).
     *
     * The /invoices endpoint IGNORES page/page_size — a single call dumps the whole ~44MB set
     * and OOMs json_decode — but it DOES filter by InvoiceDate, so we pull it month-by-month
     * (one small request per window) exactly like contracts.
     *
     * @return array{created:int, updated:int, linked:int, contracts_billed:int}
     */
    public function importInvoices(?int $limit = null, array $filters = [], ?callable $progress = null, bool $dryRun = false): array
    {
        $this->customerMap = Customer::whereNotNull('customer_no')->pluck('id', 'customer_no')->all();
        // contract_serial => contract id (for linking invoices to contracts)
        $contractMap = Contract::whereNotNull('contract_serial')->pluck('id', 'contract_serial')->all();
        $fleetOnly = (bool) config('officemanager.contracts_fleet_only', true);

        $windows = $this->dateWindows($filters, $limit);
        $totalWindows = count($windows);

        $created = 0; $updated = 0; $linked = 0; $made = 0; $n = 0; $skipped = 0; $buffer = [];
        $contractsBilled = 0; $failedWindows = [];

        if ($dryRun) {
            DB::beginTransaction(); // capture writes; rolled back before returning
        }

        try {
            $wi = 0;
            foreach ($windows as $window) {
                $wi++;
                try {
                    $page = $this->api->fetchOnce('invoices', $window['query']);
                } catch (Throwable $e) {
                    // A month that won't load shouldn't sink the import — record and continue.
                    $failedWindows[] = $window['label'];
                    if ($progress) {
                        $progress($wi, $totalWindows, "{$window['label']} · FAILED");
                    }
                    continue;
                }

                foreach ($page['items'] as $row) {
                    $no = $row['InvoiceNo'] ?? null;
                    if (! $no) {
                        continue;
                    }
                    $serial = $row['RaContractSerial'] ?? null;
                    $contractId = $serial ? ($contractMap[$serial] ?? null) : null;

                    // Multi-tenant endpoint: keep only invoices tied to one of OUR contracts.
                    if ($fleetOnly && $contractId === null) {
                        $skipped++;
                        continue;
                    }
                    if ($contractId) {
                        $linked++;
                    }

                    $buffer[] = [
                        'invoice_no'      => $no,
                        'data'            => [
                            'invoice_date'    => $this->date($row['InvoiceDate'] ?? null),
                            'customer_id'     => $this->resolveCustomer($row['CustomerNo'] ?? null, $made),
                            'contract_id'     => $contractId,
                            'contract_serial' => $serial,
                            'car_no'          => $row['CarNo'] ?? null,
                            'total_value'     => $this->num($row['InvoiceTotalValue'] ?? null),
                            'vat_value'       => $this->num($row['InvoiceVatValue'] ?? null),
                            'total_after_vat' => $this->num($row['TotalAfterVat'] ?? null),
                            // Discount baked into the total: OM computes TotalAfterVat as
                            // (Value − Discount) + VAT, so without this a discounted invoice
                            // looks like "value + vat != total". period_* is the real per-
                            // invoice billing window (better than invoice_date + rent_days).
                            'discount'             => $this->num($row['Discount'] ?? null),
                            'total_after_discount' => $this->num($row['TotalAfterDiscount'] ?? null),
                            'period_from'          => $this->date($row['InvoiceperiodFromDate'] ?? null),
                            'period_to'            => $this->date($row['InvoiceperiodToDate'] ?? null),
                            // Contract window AS BILLED (for the date-anomaly check) + rental terms.
                            'contract_out_date' => $this->date($row['ContractOutDate'] ?? null),
                            'contract_in_date'  => $this->date($row['ContractInDate'] ?? null),
                            'rent_days'         => is_numeric($row['RentDays'] ?? null) ? (int) $row['RentDays'] : null,
                            'net_rate'          => $this->num($row['RentDayRate'] ?? null),
                            'car_serial'        => is_numeric($row['RaCarSerialNo'] ?? null) ? (int) $row['RaCarSerialNo'] : null,
                            // Track A — settlement state, previously discarded. Balance is what the
                            // invoice still owes; paid is real money in (cash + cheque + visa).
                            'status_no'       => is_numeric($row['StatusNo'] ?? null) ? (int) $row['StatusNo'] : null,
                            'balance_value'   => $balance = $this->num($row['BalanceValue'] ?? null),
                            'paid_amount'     => $paid = round(
                                ($this->num($row['CashPaid'] ?? null) ?? 0)
                                + ($this->num($row['ChequePaid'] ?? null) ?? 0)
                                + ($this->num($row['VisaPaid'] ?? null) ?? 0),
                                2
                            ),
                            'payment_status'  => Invoice::derivePaymentStatus(
                                $balance,
                                $paid,
                                $this->num($row['TotalAfterVat'] ?? null)
                            ),
                            'synced_at'       => now(),
                            'origin'          => 'api',
                        ],
                    ];
                    $n++;

                    if (count($buffer) >= 500) {
                        [$c, $u] = $this->flushInvoices($buffer);
                        $created += $c; $updated += $u; $buffer = [];
                    }
                    if ($limit && $n >= $limit) {
                        break 2;
                    }
                }

                if ($progress) {
                    $progress($wi, $totalWindows, "{$window['label']} · " . number_format($n) . ' invoices');
                }
            }

            if ($buffer) {
                [$c, $u] = $this->flushInvoices($buffer);
                $created += $c; $updated += $u; $buffer = [];
            }
        } finally {
            if ($dryRun) {
                DB::rollBack(); // a dry run writes nothing
            }
        }

        if (! $dryRun) {
            // Invoices are recorded per-contract but are NOT the AR source: OfficeManager's
            // /contracts carries the authoritative accrued debit, so we only refresh each
            // customer's cached balance from it. (A partial, periodically-billed invoice set
            // must never understate an open rental's debit and inflate "wallet" credit.)
            $contractsBilled = $this->refreshBalancesAfterInvoices();
        }

        return compact('created', 'updated', 'linked')
            + ['contracts_billed' => $contractsBilled, 'foreign_skipped' => $skipped, 'failed_windows' => $failedWindows, 'dry_run' => $dryRun];
    }

    protected function flushInvoices(array $buffer): array
    {
        $created = 0; $updated = 0;
        DB::transaction(function () use ($buffer, &$created, &$updated) {
            foreach ($buffer as $b) {
                // Sync Guard: only ever match/upsert API-origin invoices. Manual invoices
                // created on the website (origin 'manual', invoice_no NULL, ref 'M-…') are
                // the platform's own source of truth and must never be touched by the import.
                $inv = Invoice::where('origin', 'api')
                    ->updateOrCreate(['invoice_no' => $b['invoice_no']], $b['data']);
                $inv->wasRecentlyCreated ? $created++ : $updated++;
            }
        });
        return [$created, $updated];
    }

    /**
     * Refresh every customer's cached balance after an invoice import — self-correcting,
     * so the import never leaves balances stale (no separate recalc needed).
     *
     * NOTE (2026-06-21): this used to OVERWRITE each contract's contract_debit/balance with
     * the SUM of its invoices. That corrupted open, periodically-billed rentals: only part of
     * the term is invoiced, so the partial invoice sum UNDERSTATED the contract's accrued debit
     * and inflated the customer's credit ("Available Wallet"). OfficeManager's /contracts is the
     * authoritative AR source, so invoices no longer touch contract_debit — they're recorded
     * per-contract (Financials / net margin) but the customer balance is computed from the
     * contracts' own debit/credit. See AccountingService::recalcAllCustomers().
     *
     * @return int contracts that have at least one linked invoice (for the run summary)
     */
    protected function refreshBalancesAfterInvoices(): int
    {
        app(AccountingService::class)->recalcAllCustomers();

        return (int) DB::table('invoices')->whereNotNull('contract_id')->distinct()->count('contract_id');
    }

    /** Persist a page of contracts in one transaction. @return array{0:int,1:int} [created, updated] */
    protected function flush(array $buffer): array
    {
        $created = 0; $updated = 0;
        DB::transaction(function () use ($buffer, &$created, &$updated) {
            foreach ($buffer as $b) {
                $existing = Contract::withTrashed()->where('external_id', $b['external_id'])->first();
                if ($existing) {
                    // Never let an empty API value blank a populated local field.
                    $data = $this->coalesceNullOverwrites($existing, $b['data']);
                    // Stage the write, then read the real per-field diff straight from the
                    // model (getDirty = new values, getOriginal = pre-save values) — purely
                    // in-memory, no extra query — so the audit feed shows ONLY what changed.
                    $existing->fill($data);
                    $this->recordUpdate($existing);
                    $existing->save();
                    $updated++;
                } else {
                    $row = Contract::create($b['data'] + ['external_id' => $b['external_id']]);
                    $this->recordInsert($row);
                    $created++;
                }
            }
        });
        // Drain the audit feed to the DB as it fills so a big sync can't accumulate tens of
        // thousands of snapshots/diffs in memory (that exhausts PHP's memory_limit). During a
        // dry run these writes ride the outer rolled-back transaction, so nothing persists.
        if (count($this->changes) >= self::AUDIT_FLUSH_EVERY) {
            $this->persistChanges();
        }
        return [$created, $updated];
    }

    /**
     * Drop from the update any column where the API value is empty but the stored row
     * holds a value — preserving good local data instead of nulling it. Keeps `state`
     * consistent with whichever in_date survives, and tallies what it preserved so the
     * reconciliation report can show it.
     *
     * @param  array<string,mixed>  $incoming
     * @return array<string,mixed>  the columns that are safe to write
     */
    protected function coalesceNullOverwrites(Contract $existing, array $incoming): array
    {
        foreach ($incoming as $col => $val) {
            // bookkeeping / always-derived columns are never real data loss
            if (in_array($col, ['synced_at', 'origin', 'state'], true)) {
                continue;
            }
            if ($val !== null && $val !== '') {
                continue; // the API actually provided a value
            }
            $old = $existing->getAttribute($col);
            if ($old === null || $old === '') {
                continue; // nothing to lose
            }

            // Authoritative return/handover field: an empty API value is a real deletion in
            // OM, so CLEAR our stale value (auto-correction) and record it — rather than the
            // protective preserve below. Leaving $incoming[$col] = null lets the clear write.
            if (in_array($col, self::AUTHORITATIVE_CLEARABLE, true)) {
                $this->corrections[] = [
                    'contract_id' => $existing->id,
                    'contract_no' => (string) $existing->contract_no,
                    'external_id' => (string) $existing->external_id,
                    'field'       => $col,
                    'old_value'   => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : (string) $old,
                ];
                continue;
            }

            // preserve the existing value: leave the column out of the write
            unset($incoming[$col]);
            $this->nullOverwriteCount++;
            if (count($this->nullOverwriteSamples) < 50) {
                $this->nullOverwriteSamples[] = [
                    'external_id' => $existing->external_id,
                    'contract_no' => (string) $existing->contract_no,
                    'field'       => $col,
                    'keeps'       => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : (string) $old,
                ];
            }
        }

        // If we preserved the existing in_date, the incoming `state` was derived from the
        // now-discarded empty in_date — re-derive it so state and in_date stay consistent.
        if (! array_key_exists('in_date', $incoming) && isset($incoming['state'])) {
            $incoming['state'] = $existing->in_date ? 'closed' : 'open';
        }

        return $incoming;
    }

    /**
     * Fill in missing customer details (name, mobile, docs) from /customers/{no}.
     * By default only customers that have no English name yet (the import stubs).
     *
     * @return array{updated:int, failed:int}
     */
    public function enrichCustomers(?int $limit = null, bool $onlyNameless = true, ?callable $progress = null): array
    {
        $key  = (string) config('officemanager.api_key');
        $base = rtrim((string) config('officemanager.base_url'), '/');
        $updated = 0; $failed = 0;

        $query = Customer::whereNotNull('customer_no');
        if ($onlyNameless) {
            $query->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''));
        }

        // Heartbeat: this phase fires one API call per customer and can run for many
        // minutes. Reporting processed/total on every chunk keeps the run row "fresh" so
        // the Data Sync orphan guard never mistakes a slow-but-alive job for a dead one.
        $total = (clone $query)->count();
        $processed = 0;
        if ($progress) {
            $progress(0, $total);
        }

        $query->orderBy('id')->chunkById(10, function ($chunk) use (&$updated, &$failed, &$processed, $total, $progress, $key, $base, $limit) {
            $pending = $chunk->pluck('customer_no', 'id')->all(); // id => customer_no
            $details = [];
            for ($attempt = 1; $attempt <= 4 && ! empty($pending); $attempt++) {
                if ($attempt > 1) {
                    usleep(1500000);
                }
                $resps = Http::pool(fn ($pool) => collect($pending)->map(fn ($no, $id) =>
                    $pool->as((string) $id)->withHeaders(['X-API-Key' => $key])->acceptJson()->timeout(40)
                        ->get("{$base}/api/v1/customers/{$no}")
                )->all());
                foreach ($pending as $id => $no) {
                    $r = $resps[(string) $id] ?? null;
                    if ($r instanceof Response && $r->ok()) {
                        $details[$id] = $r->json();
                        unset($pending[$id]);
                    }
                }
            }

            foreach ($chunk as $c) {
                if (isset($details[$c->id])) {
                    $c->update($this->mapCustomer($details[$c->id]));
                    $updated++;
                } else {
                    $failed++;
                }
            }
            $processed += $chunk->count();
            if ($progress) {
                $progress($processed, $total);
            }
            usleep(150000);
            if ($limit && $updated >= $limit) {
                return false;
            }
        });

        return compact('updated', 'failed');
    }

    /**
     * Enrich the customers referenced by the MOST contracts first (high-traffic).
     * Names the customers the owner actually sees on the dashboard, without fighting
     * the rate limit on all 10k+ stubs. Returns counts.
     *
     * @return array{targets:int, updated:int, failed:int}
     */
    public function enrichTopCustomers(int $top = 100): array
    {
        // customer ids ranked by how many contracts reference them
        $ranked = DB::table('contracts')
            ->select('customer_id', DB::raw('count(*) as c'))
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('c')
            ->limit($top * 6)            // overfetch; many top ones may already be named
            ->pluck('customer_id');

        $order = $ranked->flip(); // customer_id => rank
        $targets = Customer::whereIn('id', $ranked)
            ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''))
            ->whereNotNull('customer_no')
            ->get()
            ->sortBy(fn ($c) => $order[$c->id] ?? PHP_INT_MAX)
            ->take($top)
            ->values();

        $key  = (string) config('officemanager.api_key');
        $base = rtrim((string) config('officemanager.base_url'), '/');
        $updated = 0; $failed = 0;

        foreach ($targets->chunk(10) as $chunk) {
            $pending = $chunk->pluck('customer_no', 'id')->all();
            $details = [];
            for ($attempt = 1; $attempt <= 5 && ! empty($pending); $attempt++) {
                if ($attempt > 1) {
                    usleep(1500000);
                }
                $resps = Http::pool(fn ($pool) => collect($pending)->map(fn ($no, $id) =>
                    $pool->as((string) $id)->withHeaders(['X-API-Key' => $key])->acceptJson()->timeout(40)
                        ->get("{$base}/api/v1/customers/{$no}")
                )->all());
                foreach ($pending as $id => $no) {
                    $r = $resps[(string) $id] ?? null;
                    if ($r instanceof Response && $r->ok()) {
                        $details[$id] = $r->json();
                        unset($pending[$id]);
                    }
                }
            }
            foreach ($chunk as $c) {
                if (isset($details[$c->id])) {
                    $c->update($this->mapCustomer($details[$c->id]));
                    $updated++;
                } else {
                    $failed++;
                }
            }
            usleep(150000);
        }

        return ['targets' => $targets->count(), 'updated' => $updated, 'failed' => $failed];
    }

    /**
     * Fill customer names in BULK from the `/api/v1/customers` list endpoint.
     *
     * Why this exists: enrichCustomers() fires one request per nameless customer (~17k of
     * them). Against the OfficeManager server's intermittent 503s that behaves like a
     * self-inflicted DDoS and almost never lands a name. The list endpoint instead returns
     * EVERY customer in a single payload (it ignores page/page_size), so one retried request
     * gets all the names — a 503 just costs us a retry, not 17k failures.
     *
     * Idempotent: only stubs that are still nameless are touched; everything else is left
     * alone. Names masked at the source ("***") can't be unmasked, so we skip them (counted
     * separately) and leave the stub for a future real value.
     *
     * @param  callable|null  $progress  fn(int $processed, int $total, ?string $note)
     * @return array{updated:int, masked:int, missing:int, target:int, om_total:?int}
     */
    public function enrichCustomersBulk(?callable $progress = null): array
    {
        $key  = (string) config('officemanager.api_key');
        $base = rtrim((string) config('officemanager.base_url'), '/');

        // customer_no (string) => id, for every still-nameless stub.
        $remaining = Customer::whereNotNull('customer_no')
            ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', ''))
            ->pluck('id', 'customer_no')
            ->mapWithKeys(fn ($id, $no) => [trim((string) $no) => $id])
            ->all();

        $target = count($remaining);
        if ($target === 0) {
            return ['updated' => 0, 'masked' => 0, 'missing' => 0, 'target' => 0, 'om_total' => null];
        }

        // The endpoint ignores page/page_size and returns all customers at once, so this is a
        // single (large) request — retried to ride out the server's 503 flapping.
        $items = null; $omTotal = null;
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            if ($progress) {
                $progress(0, $target, "Fetching customer list (attempt {$attempt})…");
            }
            try {
                $r = Http::withHeaders(['X-API-Key' => $key])->acceptJson()->timeout(180)
                    ->get("{$base}/api/v1/customers", ['page' => 1]);
                if ($r->ok()) {
                    $items   = $r->json('items') ?? [];
                    $omTotal = $r->json('total');
                    break;
                }
            } catch (Throwable $e) {
                // swallow and retry — a transient 503/timeout shouldn't abort the whole sync
            }
            usleep(2000000); // 2s backoff between attempts
        }

        // The list never came back: leave every stub for the next run.
        if (! is_array($items) || $items === []) {
            return ['updated' => 0, 'masked' => 0, 'missing' => $target, 'target' => $target, 'om_total' => $omTotal];
        }

        $updated = 0; $masked = 0; $seen = 0;

        foreach ($items as $item) {
            $no = trim((string) ($item['CustomerNo'] ?? ''));
            if ($no === '' || ! isset($remaining[$no])) {
                continue; // not one of our nameless stubs (already named or unknown)
            }

            $nameE = trim((string) ($item['CustomerNameE'] ?? ''));
            $nameA = trim((string) ($item['CustomerNameA'] ?? ''));
            // "***" (and similar) are masked at the source — treat as no real name.
            $realE = ($nameE !== '' && trim($nameE, '* ') !== '') ? $nameE : null;
            $realA = ($nameA !== '' && trim($nameA, '* ') !== '') ? $nameA : null;

            if ($realE === null && $realA === null) {
                $masked++;
                unset($remaining[$no]);
                continue;
            }

            Customer::whereKey($remaining[$no])->update(array_filter([
                'name_en'     => $realE,
                'name_ar'     => $realA,
                'mobile1'     => $this->strOrNull($item['Mobile1'] ?? null),
                'mobile2'     => $this->strOrNull($item['Mobile2'] ?? null),
                'email'       => $this->strOrNull($item['Email'] ?? null),
                'nationality' => $this->strOrNull($item['NationalityNo'] ?? null),
                'passport_no' => $this->strOrNull($item['PassportNo'] ?? null),
                'id_no'       => $this->strOrNull($item['IDNo'] ?? null),
                'vat_number'  => $this->strOrNull($item['CustomerVATNumber'] ?? null),
                'synced_at'   => now(),
            ], fn ($v) => $v !== null));

            $updated++;
            unset($remaining[$no]);

            if ($progress && (++$seen % 1000 === 0)) {
                $progress($target - count($remaining), $target, "Named {$updated} of {$target}…");
            }
            if (empty($remaining)) {
                break; // every stub now has a name (or was masked)
            }
        }

        if ($progress) {
            $progress($target - count($remaining), $target, "Named {$updated} of {$target}");
        }

        return [
            'updated'  => $updated,
            'masked'   => $masked,
            'missing'  => count($remaining), // still nameless: not present in the OM list
            'target'   => $target,
            'om_total' => $omTotal,
        ];
    }

    /** Map an OfficeManager customer payload onto our customer columns (non-null only). */
    protected function mapCustomer(array $d): array
    {
        return array_filter([
            'name_en'     => $this->strOrNull($d['CustomerNameE'] ?? null),
            'name_ar'     => $this->strOrNull($d['CustomerNameA'] ?? null),
            'mobile1'     => $this->strOrNull($d['Mobile1'] ?? null),
            'mobile2'     => $this->strOrNull($d['Mobile2'] ?? null),
            'email'       => $this->strOrNull($d['Email'] ?? null),
            'nationality' => $this->strOrNull($d['NationalityNo'] ?? null),
            'passport_no' => $this->strOrNull($d['PassportNo'] ?? null),
            'id_no'       => $this->strOrNull($d['IDNo'] ?? null),
            'vat_number'  => $this->strOrNull($d['CustomerVATNumber'] ?? null),
        ], fn ($v) => $v !== null);
    }

    /**
     * Parse an integer, returning null for blanks AND for out-of-range source garbage
     * (OM occasionally emits sentinels like Days = 1.0e13 that would overflow the column
     * and abort the whole import). $max defaults to signed-INT; pass the unsigned-INT max
     * for milage columns.
     */
    protected function intOrNull($v, int $max = 2147483647, int $min = 0): ?int
    {
        $digits = preg_replace('/[^0-9\-]/', '', (string) $v);
        if ($digits === '' || $digits === '-') {
            return null;
        }
        $n = (int) $digits;
        return ($n > $max || $n < $min) ? null : $n;
    }

    protected function strOrNull($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /** Like strOrNull, but for OM id/code fields where 0 means "none" (driver, salesman, source). */
    protected function idStr($v): ?string
    {
        $v = trim((string) $v);
        return ($v === '' || $v === '0') ? null : $v;
    }

    /** Coerce an OM flag (real bool, 0/1, "true"/"false", null) to bool — null when unknown. */
    protected function boolOrNull($v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        return match (strtolower(trim((string) $v))) {
            '1', 'true', 'yes'  => true,
            '0', 'false', 'no'  => false,
            default             => null,
        };
    }

    protected function resolveCustomer($no, int &$made): ?int
    {
        $no = trim((string) $no);
        if ($no === '') {
            return null;
        }
        if (isset($this->customerMap[$no])) {
            return $this->customerMap[$no];
        }
        // The customer_no UNIQUE index also covers soft-deleted rows (e.g. one removed by the
        // orphan cleanup), so a plain create() can collide. Reuse — and restore — any existing
        // row instead, since a customer referenced by a contract is by definition not an orphan.
        $existing = Customer::withTrashed()->where('customer_no', $no)->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            return $this->customerMap[$no] = $existing->id;
        }

        // No existing row found — create one. The insert is GUARDED: if a concurrent sync (or a
        // row the pre-check somehow missed) already holds this customer_no, the UNIQUE index
        // throws 1062. We recover by reusing/restoring that row instead of letting a single
        // customer kill the entire contracts import. This makes the phase impossible to crash on
        // a customer-no collision — the exact failure that kept taking the sync down.
        try {
            $id = Customer::create([
                'customer_no' => $no,
                'external_id' => $no,
                'origin'      => 'api',
                'synced_at'   => now(),
            ])->id;
            $made++;
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = Customer::withTrashed()->where('customer_no', $no)->first();
            if (! $existing) {
                throw $e; // not a customer_no collision (some other constraint) — surface it
            }
            if ($existing->trashed()) {
                $existing->restore();
            }
            $id = $existing->id;
        }

        return $this->customerMap[$no] = $id;
    }

    protected function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            $d = Carbon::parse($v);
            return $d->year < 1901 ? null : $d->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Parse a money/number, nulling source garbage that would overflow decimal(12,2). */
    protected function num($v, float $max = 9999999999.99): ?float
    {
        if (! is_numeric($v)) {
            return null;
        }
        $n = (float) $v;
        return (abs($n) > $max) ? null : $n;
    }
}
