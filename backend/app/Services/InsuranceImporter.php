<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use App\Models\Vendor;
use Carbon\Carbon;
use Throwable;

class InsuranceImporter
{
    protected array $vehicleCache = [];

    protected array $insurerCache = [];

    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * Sync vehicle_registrations from the "F Insurance" tab — the source of truth for the
     * Mulkiya (registration) expiry + mortgaged-by AND the car's INSURANCE (insurer + expiry).
     * The OfficeManager API no longer owns insurance; this tab does. The tab carries an
     * "Insurance Co." name (resolved to a vendor of type=insurance) and an "INSURANCE EXPIRY
     * DATE"; it does NOT carry the policy number / issue date / type / deductible, so those
     * insurance_* columns are simply left as-is (never blanked). Matched to a registration row
     * by VIN (chasis_no); creates one if missing.
     *
     * @return array<string, mixed>
     */
    public function import(): array
    {
        $cfg  = config('google.sheets.insurance');
        $rows = $this->sheets->readByGid($cfg['id'], (int) $cfg['gid']);

        if (count($rows) < 2) {
            return ['error' => 'No data found.'];
        }

        $map = $this->headerMap($rows[0]);

        if (! isset($map['chassisno'])) {
            return ['error' => "Missing 'Chassis no' column."];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $protected = 0;
        $problems = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $rowNo = $i + 2;

            $vin = trim($this->cell($row, $map['chassisno'] ?? null));
            if ($vin === '') {
                $skipped++;
                continue;
            }

            $existing = VehicleRegistration::withTrashed()->where('chasis_no', $vin)->first();
            if ($existing && $existing->origin === 'web') {
                $protected++;
                continue;
            }

            try {
                $data = [
                    'vehicle_id'           => $this->resolveVehicle($vin),
                    'mortgaged_by'         => $this->strOrNull($this->cell($row, $map['mortgagedby'] ?? null)),
                    'external_id'          => $vin,
                    'origin'               => 'sheet',
                    'synced_at'            => now(),
                ];

                // This tab is the source of truth for the Mulkiya (registration) expiry.
                // Its date column is US m/d/Y (e.g. 5/7/2026 = 7 May), so parse month-first
                // when the day/month is ambiguous. Only written when the column is present,
                // so a structural change can never blank every registration's expiry.
                if (isset($map['mulkiyaexpirydate'])) {
                    $data['expiry_date'] = $this->dateOrNull($this->cell($row, $map['mulkiyaexpirydate']), true);
                }

                // This tab is now the source of truth for the car's INSURANCE too.
                // "Insurance Co." -> the insurer vendor (insurance_company_id, shown in the UI).
                // "INSURANCE EXPIRY DATE" -> insurance_expiry (d/m/Y, e.g. 03/07/2026 = 3 Jul).
                // Each is only written when its column is present, so a structural change can
                // never wipe every car's insurer/expiry.
                if (isset($map['insuranceco'])) {
                    $data['insurance_company_id'] = $this->resolveInsurer($this->cell($row, $map['insuranceco']));
                }
                if (isset($map['insuranceexpirydate'])) {
                    $data['insurance_expiry'] = $this->dateOrNull($this->cell($row, $map['insuranceexpirydate']), false);
                }

                $reg = VehicleRegistration::withTrashed()->updateOrCreate(['chasis_no' => $vin], $data);
                $reg->wasRecentlyCreated ? $created++ : $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (VIN {$vin}): " . $e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'protected', 'problems');
    }

    /**
     * Resolve the sheet's "Insurance Co." name to a vendor of type=insurance, creating one
     * on first sight (mirrors VendorInsuranceImporter). Returns null for a blank cell so an
     * empty name never links a bogus vendor.
     */
    protected function resolveInsurer($rawName): ?int
    {
        $name = trim((string) $rawName);
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, $this->insurerCache)) {
            return $this->insurerCache[$name];
        }
        $vendor = Vendor::firstOrCreate(
            ['name' => $name, 'type' => 'insurance'],
            ['active' => true, 'origin' => 'sheet']
        );
        return $this->insurerCache[$name] = $vendor->id;
    }

    protected function resolveVehicle($rawVin): ?int
    {
        $vin = trim((string) $rawVin);
        if ($vin === '') {
            return null;
        }
        if (array_key_exists($vin, $this->vehicleCache)) {
            return $this->vehicleCache[$vin];
        }
        return $this->vehicleCache[$vin] = Vehicle::where('vin', $vin)->value('id');
    }

    /** @return array<string,int> */
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

    protected function strOrNull($s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : $s;
    }

    /**
     * Parse a "X/Y/Z" date, deciding day-vs-month by which part is > 12. When both
     * parts are <= 12 the value is ambiguous, so the caller picks the convention:
     *   $monthFirst = true  -> m/d/Y (the Mulkiya column, e.g. 5/7/2026 = 7 May)
     *   $monthFirst = false -> d/m/Y (the insurance column, e.g. 7/6/2026 = 7 Jun)
     */
    protected function dateOrNull($s, bool $monthFirst = false): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = (int) $m[3];

            if ($a > 12) {            // first part must be the day
                $day = $a; $mon = $b;
            } elseif ($b > 12) {      // second part must be the day
                $mon = $a; $day = $b;
            } elseif ($monthFirst) {  // ambiguous, column is m/d/Y
                $mon = $a; $day = $b;
            } else {                  // ambiguous, column is d/m/Y
                $day = $a; $mon = $b;
            }

            if ($y > 1901 && $mon >= 1 && $mon <= 12 && $day >= 1 && $day <= 31) {
                try {
                    return Carbon::create($y, $mon, $day)->format('Y-m-d');
                } catch (Throwable $e) {
                }
            }
        }

        try {
            $d = Carbon::parse($s);
            return $d->year > 1901 ? $d->format('Y-m-d') : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
