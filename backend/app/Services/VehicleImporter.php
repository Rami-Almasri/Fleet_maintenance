<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\Carbon;
use Throwable;

class VehicleImporter
{
    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /**
     * Import the fleet from the "Faster" master tab: this sheet is the SOLE source of WHICH cars
     * exist. A row is matched to a car by VIN (then by plate, for the API cars that carry no VIN);
     * a row that matches nothing is CREATED as a new vehicle with origin='sheet'.
     *
     * Reversed on 2026-08-19 at the owner's instruction — the OfficeManager API used to decide
     * membership, but OM lists hundreds of long-sold historical cars under our owner number and a
     * car the team actually takes on appears in this sheet first. OfficeManagerSync::importFleetVehicles()
     * is now enrich-only and can no longer create a car, so the fleet is exactly what this tab lists.
     *
     * The sheet still only OWNS make/model/colour + the FASTER Asset price; the API keeps writing
     * the identity/operational fields (plate, year, odometer, status, car_serial) it holds, because
     * it runs after this phase and this phase preserves any value already on the row.
     *
     * @return array{created:int, updated:int, skipped:int, problems:array<int,string>}
     */
    public function import(bool $overwriteEnrichment = false, array $overwriteVins = []): array
    {
        // VINs to force-refresh price/color/category for, normalized for comparison.
        $overwriteVins = array_filter(array_map(fn ($v) => strtoupper(trim((string) $v)), $overwriteVins));

        $cars      = config('google.sheets.cars');
        $headerRow = max(1, (int) ($cars['header_row'] ?? 1));

        $rows = $this->sheets->readByGid($cars['id'], (int) $cars['gid']);

        if (count($rows) < $headerRow) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'problems' => ['No header row found.']];
        }

        $map = $this->headerMap($rows[$headerRow - 1]);

        foreach (['name', 'chassis'] as $required) {
            if (! isset($map[$required])) {
                return ['created' => 0, 'updated' => 0, 'skipped' => 0,
                    'problems' => ["Missing required column '{$required}' in header row {$headerRow}."]];
            }
        }

        $prices = $this->priceMap();

        // ~23% of our API cars have NO VIN (the API doesn't store ChasisNo for them), so the
        // VIN-keyed match below can't reach them. Index those null-VIN API cars by normalized
        // plate digits so a sheet row can still find its car by PLATE and backfill the VIN —
        // otherwise every null-VIN car would be orphaned as "unmatched" and never enriched.
        $nullVinByPlate = $this->nullVinApiCarsByPlate();

        $created = 0;            // sheet rows that matched no car and became one
        $updated = 0;
        $skipped = 0;
        $protected = 0;
        $preserved = 0;
        $backfilled = 0;         // null-VIN API cars matched by plate and given their VIN
        $duplicates = 0;         // the same VIN listed twice in the tab — only the first row counts
        $restored = 0;           // retired cars the register lists again, brought back with their history
        $createdSamples = [];
        $problems = [];
        $seenVins = [];

        foreach (array_slice($rows, $headerRow) as $i => $row) {
            $rowNo = $headerRow + $i + 1;

            $vin = strtoupper(trim($this->cell($row, $map['chassis'] ?? null)));
            if ($vin === '') {
                $skipped++;
                continue;
            }

            // The tab is the fleet register now, so a VIN typed twice would otherwise create the
            // car twice. First row wins; the rest are reported, not written.
            if (isset($seenVins[$vin])) {
                $duplicates++;
                continue;
            }
            $seenVins[$vin] = true;

            [$make, $model] = $this->splitName($this->cell($row, $map['name'] ?? null));

            $plateNo = trim(trim($this->cell($row, $map['code'] ?? null)) . ' ' . trim($this->cell($row, $map['plate'] ?? null)));

            // The sheet decides which cars exist. Match by VIN first; if that misses (the API car
            // has no VIN), fall back to matching a null-VIN API car by plate and backfill its VIN.
            // A row that matches nothing is a car we do not have yet — so we CREATE it.
            $matchedByPlate = false;
            $existing = Vehicle::withTrashed()->where('vin', $vin)->first();
            if (! $existing) {
                $existing = $this->matchNullVinByPlate($plateNo, $make, $nullVinByPlate)
                    ?? $this->matchVinTypedAsPlate($vin, $nullVinByPlate);
                $matchedByPlate = (bool) $existing;
            }
            // Never overwrite a car someone created manually on the website.
            if ($existing && $existing->origin === 'web') {
                $protected++;
                continue;
            }

            // A retired car that is back on the register. The register is the answer to "which cars
            // do we have", so listing one again is how a car returns to the fleet — with its
            // contracts, tickets and mileage history still attached to the same row.
            if ($existing && $existing->trashed()) {
                $existing->restore();
                $restored++;
            }

            $data = [
                'make'           => $make,
                'model'          => $model,
                'year'           => $this->intOrNull($this->cell($row, $map['model'] ?? null)), // "Model" column holds the YEAR
                'color'          => $this->strOrNull($this->cell($row, $map['color'] ?? null)),
                'category'       => $this->strOrNull($this->cell($row, $map['category'] ?? null)),
                'odometer'       => $this->intOrNull($this->cell($row, $map['km'] ?? null)) ?? 0,
                'plate_no'       => $plateNo !== '' ? $plateNo : null,
                'purchase_price' => $prices[$vin]['price'] ?? null,
                'purchase_date'  => $prices[$vin]['date'] ?? null,
                'external_id'    => $vin,
                'synced_at'      => now(),
                // NOTE: no 'origin' here — we only ENRICH an existing API car, so it keeps its
                // 'api' origin. The sheet is no longer a source of vehicles.
            ];

            // When we matched a null-VIN API car by plate, give it the sheet's VIN so it links
            // by VIN on every future sync (this is a one-time backfill, never a preserve target).
            if ($matchedByPlate) {
                $data['vin'] = $vin;
            }

            // Status comes straight from the sheet's "Status" column (Active / Sold / For sale / ...).
            $status = $this->mapStatus($this->cell($row, $map['status'] ?? null));
            if ($status !== null) {
                $data['status'] = $status;
            }

            // A car the sheet lists and we do not hold yet. Create it here — this is the whole
            // point of the sheet being the register: a car the team takes on is on this tab
            // before OfficeManager has it, and OM's own list is full of cars we sold years ago.
            // It starts as a plain sheet car; the API phase that runs next fills in car_serial,
            // specs and the live status if OfficeManager knows it.
            if (! $existing) {
                try {
                    Vehicle::create($data + [
                        'vin'       => $vin,
                        'origin'    => 'sheet',
                        'plate_key' => $plateNo !== '' ? PlateResolver::plateDigits($plateNo) : null,
                        // The sheet's rental segment ("Premium Sedan"), kept in its own column the
                        // way VehicleStatusImporter keeps it — `category` is OM's plate category.
                        'sheet_category' => $this->strOrNull($this->cell($row, $map['category'] ?? null)),
                    ]);
                    $created++;
                    if (count($createdSamples) < 50) {
                        $createdSamples[] = $vin . ' — ' . trim($this->cell($row, $map['name'] ?? null)) . ($plateNo !== '' ? " (plate {$plateNo})" : '');
                    }
                } catch (Throwable $e) {
                    $problems[] = "Row {$rowNo} (VIN {$vin}): could not create — " . $e->getMessage();
                }
                continue;
            }

            // The OM API phase runs AFTER this one and is authoritative for identity/operational
            // data, so the sheet must not clobber it: keep any existing value for those fields and
            // only fill them when the API left them empty. The sheet owns make/model (always
            // overwritten above) plus color.
            if (! ($overwriteEnrichment || in_array($vin, $overwriteVins, true))) {
                foreach (['year', 'plate_no', 'odometer', 'status', 'color', 'category'] as $field) {
                    if ($existing->{$field} !== null && $existing->{$field} !== '') {
                        unset($data[$field]);
                        $preserved++;
                    }
                }
            }

            // purchase_price + purchase_date: the FASTER Asset sheet is their single source of
            // truth, so a real sheet value ALWAYS wins (even over a prior value) — this is what
            // lets a correction in the master sheet flow automatically on every sync. But a BLANK
            // sheet cell must never wipe an existing value (e.g. a PurchaseDate the API supplied
            // for a car the sheet hasn't priced yet), so drop the field when the sheet gave null.
            foreach (['purchase_price', 'purchase_date'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] === null) {
                    unset($data[$field]);
                }
            }

            try {
                $existing->fill($data)->save();
                $updated++;
                if ($matchedByPlate) {
                    $backfilled++;
                }
            } catch (Throwable $e) {
                $problems[] = "Row {$rowNo} (VIN {$vin}): " . $e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'protected', 'preserved', 'backfilled', 'duplicates', 'restored', 'problems')
            + ['created_samples' => $createdSamples];
    }

    /**
     * Index our null-VIN API cars by normalized plate digits, so a sheet row can find its car
     * by plate when the API gave us no VIN to match on.
     *
     * @return array<string, array<int, Vehicle>>  plateDigits => vehicles (only kept when unique)
     */
    protected function nullVinApiCarsByPlate(): array
    {
        $byPlate = [];
        foreach (Vehicle::whereNotNull('car_serial')->whereNull('vin')->get() as $v) {
            $digits = $this->plateDigits($v->plate_no);
            if ($digits !== '') {
                $byPlate[$digits][] = $v;
            }
        }
        return $byPlate;
    }

    /**
     * Find the null-VIN API car whose plate matches this sheet row, so we can backfill its VIN
     * instead of orphaning the row. When a plate is shared (reused after a sale), PlateResolver
     * picks the current car rather than bailing. The make must still agree (first word), guarding
     * against two genuinely different cars that share plate digits. Consumes the match so two
     * rows can't claim it.
     */
    protected function matchNullVinByPlate(string $plateNo, ?string $sheetMake, array &$nullVinByPlate): ?Vehicle
    {
        $digits = $this->plateDigits($plateNo);
        if ($digits === '' || empty($nullVinByPlate[$digits])) {
            return null;
        }

        $car = PlateResolver::pickBest($nullVinByPlate[$digits]);
        if (! $car) {
            return null;
        }

        // Make must agree (first word) — guards against two different cars sharing plate digits.
        $apiMake = strtok((string) $car->make, ' ');
        if ($sheetMake && $apiMake && strcasecmp($sheetMake, $apiMake) !== 0) {
            return null;
        }

        unset($nullVinByPlate[$digits]); // claimed — don't let another sheet row reuse it
        return $car;
    }

    /**
     * Catch the car whose "plate" in OfficeManager is really the tail of its own VIN — somebody
     * typed the chassis number into the plate field. Those rows carry no VIN and no usable plate,
     * so neither match above can reach them, and the sheet row would create the car a SECOND time
     * (splitting its contracts and repair history across two rows). Six digits or more standing at
     * the end of this row's VIN is not a coincidence, it is that typo.
     *
     * A VIN does not always END in its digits (one reads A11446994423C), so a run of EIGHT or more
     * digits is also accepted anywhere inside it. Eight is where a coincidence stops being credible:
     * six digits loose in a 17-character VIN would collide by chance roughly once per full sync.
     *
     * Deliberately no make check: these rows are damaged, and their make is damaged with them
     * ("DODGE CHECKER MARTHON" was filed as make "CHECKER"). The VIN digits are the harder evidence.
     */
    protected function matchVinTypedAsPlate(string $vin, array &$nullVinByPlate): ?Vehicle
    {
        foreach ($nullVinByPlate as $digits => $cars) {
            $len = strlen($digits);
            if (($len >= 6 && str_ends_with($vin, $digits)) || ($len >= 8 && str_contains($vin, $digits))) {
                $car = PlateResolver::pickBest($cars);
                if ($car) {
                    unset($nullVinByPlate[$digits]); // claimed — don't let another sheet row reuse it
                    return $car;
                }
            }
        }

        return null;
    }

    /** A plate's digits with leading zeros stripped, so "K 20756" / "0020756" / "20756" all match. */
    protected function plateDigits($plate): string
    {
        return PlateResolver::plateDigits($plate);
    }

    /**
     * Build a VIN => [price, date] map from the FASTER Asset sheet.
     *
     * @return array<string, array{price: ?float, date: ?string}>
     */
    protected function priceMap(): array
    {
        $asset = config('google.sheets.asset');
        if (empty($asset['id'])) {
            return [];
        }

        try {
            $rows = $this->sheets->readByGid($asset['id'], (int) $asset['gid']);
        } catch (Throwable $e) {
            return [];
        }

        if (empty($rows)) {
            return [];
        }

        $map      = $this->headerMap($rows[0]);
        $vinIdx   = $map['chassis'] ?? null;
        $priceIdx = $map['purchase price'] ?? null;
        $dateIdx  = $map['purchase date'] ?? null;

        if ($vinIdx === null) {
            return [];
        }

        $out = [];
        foreach (array_slice($rows, 1) as $row) {
            // Upper-cased so it lines up with the fleet tab's VIN, which the import normalizes.
            $vin = strtoupper(trim($this->cell($row, $vinIdx)));
            if ($vin === '') {
                continue;
            }
            $out[$vin] = [
                'price' => $this->moneyOrNull($this->cell($row, $priceIdx)),
                'date'  => $this->dateOrNull($this->cell($row, $dateIdx)),
            ];
        }

        return $out;
    }

    /**
     * Map normalized header name => column index (first occurrence wins).
     *
     * @return array<string, int>
     */
    protected function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $key = $this->norm($h);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        return $map;
    }

    protected function norm($s): string
    {
        $s = str_replace(["\r", "\n"], ' ', (string) $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return strtolower(trim($s));
    }

    protected function cell(array $row, $idx): string
    {
        return $idx === null ? '' : (string) ($row[$idx] ?? '');
    }

    /**
     * Normalize the sheet's Status text to an OfficeManager status slug.
     * (The API is the real source of truth; this only covers legacy sheet imports.)
     * Unknown/blank returns null so the column default ('ready') stays.
     */
    protected function mapStatus($raw): ?string
    {
        $key = strtolower(trim((string) $raw));
        $key = preg_replace('/\s+/', '_', $key);

        $map = [
            'active' => 'ready', 'ready' => 'ready', 'available' => 'ready',
            'rented' => 'rented', 'sold' => 'sold', 'disposed' => 'disposed',
            'exported' => 'disposed', 'office' => 'office_use', 'office_use' => 'office_use',
            'personal' => 'office_use', 'suspended' => 'suspended', 'under_process' => 'suspended',
            'under_maintenance' => 'under_maintenance', 'maintenance' => 'under_maintenance',
            'out_of_order' => 'out_of_order', 'o.o.o' => 'out_of_order',
            'insurance_claim' => 'out_of_order', 'returned' => 'returned',
            // "for sale" is now a separate boolean flag, not a status
            'for_sale' => 'ready',
        ];

        return $map[$key] ?? null;
    }

    /**
     * Split "AUDI A5" into make ("AUDI") + model ("A5").
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    protected function strOrNull($s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : $s;
    }

    protected function intOrNull($s): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $s);
        return $digits === '' ? null : (int) $digits;
    }

    protected function moneyOrNull($s): ?float
    {
        $clean = preg_replace('/[^0-9.]/', '', (string) $s);
        return $clean === '' ? null : (float) $clean;
    }

    protected function dateOrNull($s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }

        foreach (['d/M/Y', 'j/M/Y', 'd/m/Y', 'Y-m-d', 'd-M-Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $s);
                if ($d !== false) {
                    return $d->format('Y-m-d');
                }
            } catch (Throwable $e) {
                // try next format
            }
        }

        try {
            return Carbon::parse($s)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
}
