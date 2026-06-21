<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Compare the "Faster" car list (a Google-Sheets tab) against the live OfficeManager API,
 * matched by VIN (chassis). Read-only: for every chassis in the sheet we look it up in the
 * API and report, field by field, where the two sources disagree.
 *
 * Source of truth for the car list is the live sheet (NOT a desktop export), read through
 * the same service-account GoogleSheetsService the rest of the app uses.
 */
class VehicleCsvSyncService
{
    /** The "Faster" fleet tab. */
    public const SHEET_ID  = '1z1SoOSy9On0dwU2nMiIS1MNpp6j1fztAhoh_snFr3Hk';
    public const SHEET_GID = 723314813;
    /** 1-based row that holds the column titles (rows 1-2 are group banners). */
    public const HEADER_ROW = 3;
    /** Cache key for the computed Sheet↔API diff (both source reads are slow). */
    public const DIFF_CACHE_KEY = 'vehicle_sheet_api_diff';

    public function __construct(
        protected OfficeManagerClient $api,
        protected GoogleSheetsService $sheets,
    ) {
    }

    /**
     * Read the live "Faster" sheet and return one entry per car (rows that have a chassis).
     *
     * @return array<int, array<string,string>>
     */
    public function readCars(): array
    {
        $rows = $this->sheets->readByGid(self::SHEET_ID, self::SHEET_GID);

        if (count($rows) < self::HEADER_ROW) {
            throw new RuntimeException('The Faster sheet has no header row.');
        }

        $map = $this->headerMap($rows[self::HEADER_ROW - 1]);
        if (! isset($map['chassis'])) {
            throw new RuntimeException("No 'Chassis' column found in the Faster sheet header.");
        }

        $out = [];
        $ser = 0;
        foreach (array_slice($rows, self::HEADER_ROW) as $row) {
            $vin = strtoupper(trim($this->cell($row, $map['chassis'] ?? null)));

            // A real car needs a chassis; skip blank/spacer rows.
            if ($vin === '') {
                continue;
            }
            $ser++;

            $out[] = [
                'ser'      => (string) $ser,
                'code'     => trim($this->cell($row, $map['code'] ?? null)),
                'name'     => trim($this->cell($row, $map['name'] ?? null)),
                'vin'      => $vin,
                'year'     => trim($this->cell($row, $map['model'] ?? null)),   // "Model" column = the YEAR
                'plate'    => trim($this->cell($row, $map['plate'] ?? null)),
                'color'    => trim($this->cell($row, $map['color'] ?? null)),
                'category' => trim($this->cell($row, $map['category'] ?? null)),
                'status'   => trim($this->cell($row, $map['status'] ?? null)),
                'km'       => trim($this->cell($row, $map['km'] ?? null)),
            ];
        }

        return $out;
    }

    /** Normalized header label => column index (first occurrence wins). */
    protected function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', (string) $h))));
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
     * Index the live API vehicles by normalized VIN (first row wins; the list has dupes).
     *
     * @return array<string, array<string,mixed>>
     */
    public function apiByVin(): array
    {
        $byVin = [];
        foreach ($this->api->vehicles() as $r) {
            $vin = strtoupper(trim((string) ($r['ChasisNo'] ?? '')));
            if ($vin === '' || isset($byVin[$vin])) {
                continue;
            }
            $byVin[$vin] = $r;
        }
        return $byVin;
    }

    /**
     * Cached entry point for the Sheet↔API comparison. Both source reads (the live sheet
     * and paging every API vehicle) are slow against a fragile server, and the result
     * doesn't change second-to-second — so we serve a cached copy and recompute only when
     * it expires or the caller asks for a fresh run.
     *
     * @return array{as_of:string, summary:array<string,int>, matched:array<int,mixed>, missing:array<int,mixed>}
     */
    public function sheetVsApi(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::DIFF_CACHE_KEY);
        }

        $ttl = (int) config('officemanager.diff_cache_ttl', 600);

        return Cache::remember(self::DIFF_CACHE_KEY, $ttl, fn () => $this->computeSheetVsApi());
    }

    /**
     * Direct SHEET ↔ API comparison, by VIN. For each of the matched cars it lists every
     * comparable field with the sheet value, the API value, and whether they differ (after
     * sensible normalization: case/spacing for names, digits for plate/year/km, a shared
     * vocabulary for status). Read-only. This is the uncached worker behind sheetVsApi().
     *
     * @return array{as_of:string, summary:array<string,int>, matched:array<int,mixed>, missing:array<int,mixed>}
     */
    protected function computeSheetVsApi(): array
    {
        $cars = $this->readCars();
        $apiByVin = $this->apiByVin();

        $matched = [];
        $missing = [];
        $withDiffs = 0;

        foreach ($cars as $car) {
            $vin = $car['vin'];
            if (! isset($apiByVin[$vin])) {
                $missing[] = $car;
                continue;
            }

            $api = $apiByVin[$vin];
            $fields = $this->compareFields($car, $api);
            $diffCount = count(array_filter($fields, fn ($f) => $f['different']));
            if ($diffCount > 0) {
                $withDiffs++;
            }

            $matched[] = [
                'vin'        => $vin,
                'code'       => $car['code'],
                'car_serial' => (string) ($api['CarSerial'] ?? ''),
                'owner_no'   => (string) ($api['CarOwnerNo'] ?? ''),
                'traffic_id' => (string) ($api['TrafficID'] ?? ''),
                'diff_count' => $diffCount,
                'fields'     => $fields,
            ];
        }

        return [
            'as_of'   => now()->toIso8601String(),
            'summary' => [
                'sheet_total'    => count($cars),
                'api_vehicles'   => count($apiByVin),
                'matched'        => count($matched),
                'with_diffs'     => $withDiffs,
                'identical'      => count($matched) - $withDiffs,
                'missing_in_api' => count($missing),
            ],
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    /**
     * Build the per-field sheet-vs-api comparison for one car.
     *
     * @return array<int, array{field:string, sheet:string, api:string, different:bool, compared:bool}>
     */
    protected function compareFields(array $car, array $api): array
    {
        $statusNo = isset($api['StatusNo']) ? (int) $api['StatusNo'] : null;
        $apiStatus = $statusNo !== null ? (Vehicle::OM_STATUS[$statusNo] ?? '') : '';

        $rows = [
            // field label, sheet raw, api raw, normalizer
            ['name',     $car['name'],     (string) ($api['CarName'] ?? ''), fn ($s) => strtoupper(preg_replace('/[^a-z0-9]+/i', ' ', $s))],
            ['year',     $car['year'],     (string) ($api['Model'] ?? ''),   fn ($s) => preg_replace('/\D+/', '', $s)],
            ['plate',    $car['plate'],    (string) ($api['CarNo'] ?? ''),   fn ($s) => ltrim(preg_replace('/\D+/', '', $s), '0')],
            ['status',   $car['status'],   $apiStatus,                       fn ($s) => $this->statusSlug($s)],
            ['odometer', $car['km'],       (string) ($api['Milage'] ?? ''),  fn ($s) => preg_replace('/\D+/', '', $s)],
            ['color',    $car['color'],    '',                               null], // API colour is a numeric code → show sheet only
            ['category', $car['category'], '',                               null], // no comparable API field
        ];

        $out = [];
        foreach ($rows as [$field, $sheet, $apiVal, $norm]) {
            $sheet = trim((string) $sheet);
            $apiVal = trim((string) $apiVal);

            $different = $norm !== null && trim($norm($sheet)) !== trim($norm($apiVal));

            $out[] = [
                'field'     => $field,
                'sheet'     => $sheet,
                'api'       => $apiVal,
                'different' => $different,
                'compared'  => $norm !== null,
            ];
        }
        return $out;
    }

    /** Normalize either a sheet status text or an OM status slug to one shared vocabulary. */
    protected function statusSlug(string $raw): string
    {
        $k = preg_replace('/\s+/', '_', strtolower(trim($raw)));
        $map = [
            'active' => 'ready', 'ready' => 'ready', 'available' => 'ready',
            'rented' => 'rented', 'sold' => 'sold', 'disposed' => 'disposed', 'exported' => 'disposed',
            'office' => 'office_use', 'office_use' => 'office_use', 'personal' => 'office_use',
            'suspended' => 'suspended', 'under_process' => 'suspended',
            'under_maintenance' => 'under_maintenance', 'maintenance' => 'under_maintenance',
            'out_of_order' => 'out_of_order', 'o.o.o' => 'out_of_order', 'insurance_claim' => 'out_of_order',
            'returned' => 'returned', 'for_sale' => 'ready',
        ];
        return $map[$k] ?? $k;
    }
}
