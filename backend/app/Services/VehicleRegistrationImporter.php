<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use Throwable;

class VehicleRegistrationImporter
{
    protected array $vehicleCache = [];

    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * Import RTA fines + registration status from the "F RTA" tab, one row per car
     * (matched by VIN). The Mulkiya expiry, mortgaged_by and insurer are owned by the
     * "F Insurance" tab (InsuranceImporter), not here.
     *
     * @return array<string, mixed>
     */
    public function import(): array
    {
        $cfg  = config('google.sheets.registrations');
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
                    'vehicle_id'   => $this->resolveVehicle($vin),
                    // Fines + status are unique to the "F RTA" tab. The Mulkiya expiry,
                    // mortgaged_by and insurer now come from the "F Insurance" tab
                    // (see InsuranceImporter), so this importer no longer writes them.
                    'fines_count'  => $this->intOrNull($this->cell($row, $map['nooffines'] ?? null)),
                    'fines_amount' => $this->moneyOrNull($this->cell($row, $map['finesamount'] ?? null)),
                    'status'       => $this->statusFrom($row, $map),
                    'external_id'  => $vin,
                    'synced_at'    => now(),
                    'origin'       => 'sheet',
                ];

                $reg = VehicleRegistration::withTrashed()->updateOrCreate(['chasis_no' => $vin], $data);
                $reg->wasRecentlyCreated ? $created++ : $updated++;
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (VIN {$vin}): " . $e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'protected', 'problems');
    }

    /** The "Registered"/"Expired" status sits in the last (unlabeled) column after "Mortgaged by". */
    protected function statusFrom(array $row, array $map): ?string
    {
        // Prefer an explicit column if one is mapped; otherwise take the last non-empty cell.
        $last = null;
        foreach ($row as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $last = $v;
            }
        }
        if ($last !== null && preg_match('/regist|expire/i', $last)) {
            return $last;
        }
        return null;
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

    protected function intOrNull($s): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $s);
        return $digits === '' ? null : (int) $digits;
    }

    protected function moneyOrNull($s): ?float
    {
        $clean = preg_replace('/[^0-9.]/', '', (string) $s);
        return ($clean === '' || $clean === '.') ? null : (float) $clean;
    }
}
