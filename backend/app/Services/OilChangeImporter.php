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
 *   - odometer              is SHARED with the OM API (and with handovers, tickets and manual
 *                           edits), and FORWARD-ONLY by the same rule — see below.
 *
 * Taken from the sheet (and NOTHING else):
 *   - Chassis      -> VIN, used solely to match an existing vehicle (never creates one).
 *   - LAST CHANGE  -> last_service_odometer (the odometer reading at the last oil change).
 *   - VALIDITY     -> service_interval_km   (how many km between services for this car).
 *
 * Taken from the sheet, but only when it BEATS what we already hold:
 *   - MILAGE       -> odometer (the car's current mileage as the garage last read it).
 *
 * CURRENT MILEAGE, and why the sheet is now allowed to touch it. The odometer used to be
 * declared "API-owned", which quietly meant the sheet's MILAGE column was thrown away. But the
 * two disagree in BOTH directions and neither is a liar: OM's car card is only as fresh as the
 * last time someone typed into OfficeManager, while the sheet's MILAGE is read off the cluster
 * by the garage — often days newer, sometimes days older. Declaring either one the owner throws
 * away a real reading half the time, and the half we were throwing away made cars read
 * under-driven, which delays a service that is genuinely due.
 *
 * So no source owns the number. The HIGHER reading wins, because a car cannot un-drive
 * kilometres, and that is exactly the rule the OM sync already applies to its own duplicate
 * rows. The winner's name is stamped on odometer_source so the car card can say whether it is
 * showing the sheet's figure or OM's. A sheet figure that loses is counted as
 * `odometer_preserved` — it is never written, so a sync can never walk a car backwards.
 *
 * The API's ServiceExpiryDate/Milage (service_due_date / service_due_km) remain deliberately
 * ignored for the service-due math.
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
        $preserved = 0;            // rows where OUR newer service anchor beat the sheet's older one
        $odometerRaised = 0;       // cars whose current mileage the sheet moved forward
        $odometerPreserved = 0;    // cars where OUR mileage was already ahead of the sheet's
        $problems = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $rowNo = $i + 2;

            $vin = trim($this->cell($row, $map['chassis']));
            if ($vin === '') {
                $skipped++;
                continue;
            }

            $vehicle = $this->resolveVehicle($vin);
            if ($vehicle === null) {
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
                    $current = (int) ($vehicle->last_service_odometer ?? 0);
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

                // CURRENT MILEAGE — the sheet's MILAGE column races whatever we already hold
                // (usually OM's car card). Higher wins; a lower sheet figure is simply not written,
                // so this import can never walk a car's odometer backwards.
                $mileage = $this->kmOrNull($this->cell($row, $map['milage'] ?? null));
                if ($mileage !== null) {
                    // WHEN the garage read it — the sheet's own LAST EDIT stamp, not our import
                    // time. Without this date the reading is just a number: the oil projection
                    // works in days-since-anchor and cannot use an undated figure at all. Null
                    // when the row carries no stamp; "we don't know when" must never silently
                    // become "today".
                    $readOn = $this->dateOrNull($this->cell($row, $map['lastedit'] ?? null));

                    if ($vehicle->advanceOdometer($mileage, 'sheet')) {
                        $data['odometer']            = $vehicle->odometer;
                        $data['odometer_source']     = $vehicle->odometer_source;
                        $data['odometer_source_at']  = $vehicle->odometer_source_at;
                        $data['odometer_reading_on'] = $readOn;
                        $odometerRaised++;
                    } else {
                        // The date belongs to the READING, not to the act of raising. This import is
                        // idempotent — the second run of the day raises nothing — so stamping the
                        // date only on a raise left every already-imported car dateless, and an
                        // undated reading is invisible to the projection. Whenever the stored
                        // odometer IS this sheet row (same number, and the sheet is what put it
                        // there), LAST EDIT describes it and is recorded.
                        if ($vehicle->odometer_source === 'sheet' && (int) $vehicle->odometer === $mileage) {
                            $data['odometer_reading_on'] = $readOn;
                        }
                        $odometerPreserved++;
                    }
                }

                Vehicle::whereKey($vehicle->id)->update($data);
                $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (VIN {$vin}): " . $e->getMessage();
            }
        }

        return [
            'updated'            => $updated,
            'unmatched'          => $unmatched,
            'skipped'            => $skipped,
            'preserved'          => $preserved,
            'odometer_raised'    => $odometerRaised,
            'odometer_preserved' => $odometerPreserved,
            'problems'           => $problems,
        ];
    }

    protected function resolveVehicle(string $vin): ?Vehicle
    {
        $vin = trim($vin);
        if ($vin === '') {
            return null;
        }
        if (array_key_exists($vin, $this->vehicleCache)) {
            return $this->vehicleCache[$vin];
        }
        return $this->vehicleCache[$vin] = Vehicle::where('vin', $vin)
            ->first(['id', 'odometer', 'odometer_source', 'odometer_source_at', 'last_service_odometer']);
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

    /**
     * Parse the sheet's LAST EDIT stamp — US month/day/year ("8/7/2026" is 7 August 2026), which
     * is how Google Sheets renders dates for this workbook's locale.
     *
     * Returns null rather than guessing on anything unparseable, and refuses a FUTURE date: a
     * typo'd year would otherwise mint an anchor newer than every real reading and freeze the
     * projection at zero days elapsed, quietly hiding a car that is genuinely overdue.
     */
    protected function dateOrNull($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }

        try {
            $d = \Illuminate\Support\Carbon::createFromFormat('n/j/Y', $s);
        } catch (Throwable) {
            return null;
        }

        if (! $d || $d->isFuture()) {
            return null;
        }

        return $d->toDateString();
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
