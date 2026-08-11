<?php

namespace App\Services;

use App\Models\Vehicle;
use Throwable;

/**
 * Import the per-car service interval + last-service baseline from the "Oil Change" tab of
 * the maintenance spreadsheet.
 *
 * OWNERSHIP, precisely — the sheet is no longer the only writer here:
 *   - service_interval_km   is SHEET-OWNED. Nothing in the app sets a car's oil cadence, so the
 *                           sheet's value always wins.
 *   - last_service_odometer is SHARED, and FORWARD-ONLY. A closed maintenance ticket moves this
 *                           anchor through Vehicle::recordOilService, and the sheet cannot know
 *                           that happened. The sheet may therefore only ever RAISE it; a lower
 *                           (older) sheet figure is kept out and counted as `preserved`.
 *
 * Taken from the sheet (and NOTHING else):
 *   - Chassis      -> VIN, used solely to match an existing vehicle (never creates one).
 *   - LAST CHANGE  -> last_service_odometer (the odometer reading at the last oil change).
 *   - VALIDITY     -> service_interval_km   (how many km between services for this car).
 *
 * Current mileage (odometer) stays API-owned, and the API's ServiceExpiryDate/Milage
 * (service_due_date / service_due_km) are deliberately ignored for the service-due math.
 *
 * Service-due is computed strictly in km elsewhere (Vehicle::serviceStatus):
 *   (odometer - last_service_odometer) >= service_interval_km  ->  Service Due.
 *
 * A blank cell never blanks a stored value (each field is written only when present), and a
 * VIN with no matching vehicle is counted as unmatched and skipped — no guessing.
 */
class OilChangeImporter
{
    protected array $vehicleCache = [];

    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /** @return array<string, mixed> */
    public function import(): array
    {
        $id  = (string) config('google.sheets.maintenance.id');
        $gid = (int) config('google.sheets.maintenance.oil_change_gid');

        $rows = $this->sheets->readByGid($id, $gid);

        if (count($rows) < 2) {
            return ['error' => 'No data found.'];
        }

        $map = $this->headerMap($rows[0]);

        if (! isset($map['chassis'])) {
            return ['error' => "Missing 'Chassis' column."];
        }

        $updated = 0;
        $unmatched = 0;
        $skipped = 0;
        $preserved = 0;   // rows where OUR newer service anchor beat the sheet's older one
        $problems = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $rowNo = $i + 2;

            $vin = trim($this->cell($row, $map['chassis']));
            if ($vin === '') {
                $skipped++;
                continue;
            }

            $vehicleId = $this->resolveVehicle($vin);
            if ($vehicleId === null) {
                $unmatched++;   // no car for this VIN — do not guess, do not create
                continue;
            }

            try {
                $data = ['service_synced_at' => now()];

                // Only write each field when the cell carries a value, so a blank cell can
                // never wipe an existing baseline/interval.
                $baseline = $this->kmOrNull($this->cell($row, $map['lastchange'] ?? null));
                if ($baseline !== null) {
                    // FORWARD-ONLY. The sheet was the only writer of this anchor when this importer was
                    // written; it no longer is. A closed maintenance ticket now moves it too, via
                    // Vehicle::recordOilService (MaintenanceWorkflowService::confirmRoutineServices), and
                    // the sheet has no idea that happened — it is filled in by hand, days later at best.
                    // Writing the sheet's older figure over ours rolled the car BACKWARD: an oil change
                    // recorded at 120,000 km reverted to the sheet's 95,000, and the car instantly read
                    // 25,000 km overdue, re-raising the Service & Inspection alert for work already done.
                    // The oil anchor only ever advances, exactly like the odometer it is measured against.
                    $current = (int) (Vehicle::whereKey($vehicleId)->value('last_service_odometer') ?? 0);
                    if ($baseline > $current) {
                        $data['last_service_odometer'] = $baseline;
                    } else {
                        $preserved++;   // ours is newer — keep it, and say so in the run summary
                    }
                }

                $interval = $this->kmOrNull($this->cell($row, $map['validity'] ?? null));
                if ($interval !== null) {
                    $data['service_interval_km'] = $interval;
                }

                Vehicle::whereKey($vehicleId)->update($data);
                $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (VIN {$vin}): " . $e->getMessage();
            }
        }

        return compact('updated', 'unmatched', 'skipped', 'preserved', 'problems');
    }

    protected function resolveVehicle(string $vin): ?int
    {
        $vin = trim($vin);
        if ($vin === '') {
            return null;
        }
        if (array_key_exists($vin, $this->vehicleCache)) {
            return $this->vehicleCache[$vin];
        }
        return $this->vehicleCache[$vin] = Vehicle::where('vin', $vin)->value('id');
    }

    /**
     * Build a lookup of normalised-header => column index. Headers are lowercased and
     * stripped of non-alphanumerics ("LAST CHANGE" -> "lastchange"), and the FIRST
     * occurrence wins — so the km "LAST CHANGE" column beats the later date "Last Change".
     *
     * @return array<string,int>
     */
    protected function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', str_replace(["\r", "\n"], ' ', (string) $h)));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        return $map;
    }

    protected function cell(array $row, $idx): string
    {
        return $idx === null ? '' : (string) ($row[$idx] ?? '');
    }

    /** Parse a km figure that may carry thousands separators ("10,980" -> 10980). */
    protected function kmOrNull($s): ?int
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $s);
        return $digits === '' ? null : (int) $digits;
    }
}
