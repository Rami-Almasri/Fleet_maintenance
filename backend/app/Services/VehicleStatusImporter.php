<?php

namespace App\Services;

use App\Models\Vehicle;

/**
 * Overlay each car's REAL status from the fleet "Status" sheet (Code | Plate | Chassis | Status),
 * matched to our cars by VIN (primary) or plate digits (fallback).
 *
 * Runs AFTER the API+sheet sync (om:sync sets the base status from OfficeManager, then this pulls
 * out cars the sheet marks Sold / Personal / Office / For sale). The rule is deliberately one-way:
 *   - Sheet 'Active'  -> LEAVE the car alone (keep om:sync's live ready/rented/maintenance status).
 *   - Anything mapped -> set the car's status (or the for_sale flag) to the sheet's verdict.
 * So the sheet can only ever REMOVE a car from the active pool, never flatten a live car to "active".
 * When a car later returns to 'Active' in the sheet, om:sync's status simply governs again — this
 * step just stops overriding it.
 *
 * Web-created cars (origin='web') are never touched, matching every other importer's guard.
 */
class VehicleStatusImporter
{
    public function __construct(protected GoogleSheetsService $sheets)
    {
    }

    /** Cache of plate-digits => [Vehicle, ...] for the plate fallback match. */
    protected ?array $plateIndex = null;

    /**
     * @return array{
     *   dry:bool, header_row:int, counts:array<string,int>,
     *   changes:array<int,array<string,mixed>>, unmatched_samples:array<int,string>,
     *   unknown_statuses:array<string,int>
     * }|array{error:string}
     */
    public function import(?string $sheetId = null, ?int $gid = null, bool $dry = false, ?int $limit = null): array
    {
        $sheetId = $sheetId ?: (string) config('vehicle_status.sheet_id');
        $gid     = $gid ?? (int) config('vehicle_status.gid');
        $map     = $this->normalizeMap((array) config('vehicle_status.status_map', []));
        $valid   = array_values(Vehicle::OM_STATUS);

        if ($sheetId === '') {
            return ['error' => 'No spreadsheet id — set GOOGLE_SHEETS_VEHICLE_STATUS_ID or pass --sheet.'];
        }

        $rows = $this->sheets->readByGid($sheetId, $gid);
        if (empty($rows)) {
            return ['error' => "Tab gid {$gid} is empty."];
        }

        // The tab may carry banner/title rows above the real header (and even stacked owner
        // sections), so locate the header by content: the first row with both a Chassis and a
        // Status column. Everything below it is data until the values run out.
        $header = $this->findHeaderRow($rows);
        if ($header === null) {
            return ['error' => "Could not find a header row with both a 'Chassis' and a 'Status' column in gid {$gid}."];
        }
        [$headerIdx, $col] = $header;

        $counts = [
            'rows' => 0, 'matched' => 0, 'unmatched' => 0, 'unchanged' => 0,
            'protected' => 0, 'changed' => 0, 'flagged' => 0, 'category_set' => 0,
            'sheet_status_set' => 0,
        ];
        $changes         = [];
        $unmatched       = [];
        $unknownStatuses = [];

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            $rawStatus = trim($this->cell($row, $col['status'] ?? null));
            $vin       = strtoupper(trim($this->cell($row, $col['chassis'] ?? null)));
            $plateNo   = trim(trim($this->cell($row, $col['code'] ?? null)) . ' ' . trim($this->cell($row, $col['plate'] ?? null)));

            // Skip empties, banner rows, and a repeated header inside a stacked tab.
            if ($vin === '' && $this->plateDigits($plateNo) === '') {
                continue;
            }
            if ($this->norm($rawStatus) === 'status') {
                continue; // a repeated header row
            }

            if ($limit !== null && $counts['rows'] >= $limit) {
                break;
            }
            $counts['rows']++;

            $vehicle = $this->matchVehicle($vin, $plateNo);
            if (! $vehicle) {
                $counts['unmatched']++;
                if (count($unmatched) < 50) {
                    $unmatched[] = ($plateNo !== '' ? $plateNo : $vin) . ($rawStatus !== '' ? " [{$rawStatus}]" : '');
                }
                continue;
            }
            $counts['matched']++;

            // Never touch a car someone created manually on the website.
            if ($vehicle->origin === 'web') {
                $counts['protected']++;
                continue;
            }

            // Capture the sheet's human rental segment ("Premium Sedan" / "Premium SUV" / …) into
            // sheet_category. This is independent of the status verdict below (a car keeps its
            // category whether it stays Active or is pulled out), so persist it here before any of
            // the status branches can `continue` past a save.
            $category = trim($this->cell($row, $col['category'] ?? null));
            $dirty = false;
            if ($category !== '' && $vehicle->sheet_category !== $category) {
                $counts['category_set']++;
                $vehicle->sheet_category = $category;
                $dirty = true;
            }

            // Keep the register's own word verbatim ("Active" / "For sale" / "Office" / …) beside
            // the category. This must happen HERE, before the branches below can `continue`: the
            // most important value to record is "Active", and that maps to null precisely so we do
            // NOT touch the car's status — so a capture placed after the mapping would miss every
            // Active car and store only the exceptions. `status` remains our operational answer;
            // this is the register's claim, kept so a screen can show both when they disagree.
            if ($rawStatus !== '' && $vehicle->sheet_status !== $rawStatus) {
                $counts['sheet_status_set']++;
                $vehicle->sheet_status = $rawStatus;
                $dirty = true;
            }

            if ($dirty && ! $dry) {
                $vehicle->save();
            }

            $key = $this->norm($rawStatus);
            if ($key === '' || ! array_key_exists($key, $map)) {
                // A status value we have no rule for — record it (so you can extend the map) and leave the car.
                if ($rawStatus !== '') {
                    $unknownStatuses[$rawStatus] = ($unknownStatuses[$rawStatus] ?? 0) + 1;
                }
                $counts['unchanged']++;
                continue;
            }

            $target = $map[$key]; // status slug | 'for_sale' | null
            if ($target === null) {
                $counts['unchanged']++; // 'active', 'exported', … — the sheet says nothing we act on
                continue;
            }

            $label = trim(($vehicle->code ? $vehicle->code . ' ' : '') . (string) $vehicle->plate_no);
            $label = $label !== '' ? $label : ($plateNo !== '' ? $plateNo : $vin);

            // Special token: leave the status, just raise the for_sale flag.
            if ($target === 'for_sale') {
                if (! $vehicle->for_sale) {
                    $counts['flagged']++;
                    $changes[] = [
                        'label' => $label, 'vin' => $vehicle->vin, 'sheet' => $rawStatus,
                        'from' => $vehicle->status, 'to' => $vehicle->status, 'action' => 'set for_sale = true',
                    ];
                    if (! $dry) {
                        $vehicle->for_sale = true;
                        $vehicle->save();
                    }
                } else {
                    $counts['unchanged']++;
                }
                continue;
            }

            // A status slug — guard against a mis-typed map value before writing it.
            if (! in_array($target, $valid, true)) {
                $unknownStatuses["(bad map target: {$target})"] = ($unknownStatuses["(bad map target: {$target})"] ?? 0) + 1;
                $counts['unchanged']++;
                continue;
            }

            if ($vehicle->status === $target) {
                $counts['unchanged']++;
                continue;
            }

            $counts['changed']++;
            $changes[] = [
                'label' => $label, 'vin' => $vehicle->vin, 'sheet' => $rawStatus,
                'from' => $vehicle->status, 'to' => $target, 'action' => 'set status',
            ];
            if (! $dry) {
                $vehicle->status = $target;
                $vehicle->save();
            }
        }

        return [
            'dry'               => $dry,
            'header_row'        => $headerIdx + 1,
            'counts'            => $counts,
            'changes'           => $changes,
            'unmatched_samples' => $unmatched,
            'unknown_statuses'  => $unknownStatuses,
        ];
    }

    /**
     * Find the header row: the first row that has BOTH a 'chassis' and a 'status' column.
     *
     * @return array{0:int, 1:array<string,int>}|null  [rowIndex, normalizedHeaderMap]
     */
    protected function findHeaderRow(array $rows): ?array
    {
        foreach ($rows as $i => $row) {
            $map = $this->headerMap($row);
            if (isset($map['chassis']) && isset($map['status'])) {
                return [$i, $map];
            }
        }

        return null;
    }

    /** Match a sheet row to one of OUR cars: by VIN first, then by unique plate digits. */
    protected function matchVehicle(string $vin, string $plateNo): ?Vehicle
    {
        if ($vin !== '') {
            $v = Vehicle::withTrashed()->where('vin', $vin)->first();
            if ($v) {
                return $v;
            }
        }

        $digits = $this->plateDigits($plateNo);
        if ($digits === '') {
            return null;
        }

        // A plate shared by a sold history car and its current replacement resolves to the
        // current one — see PlateResolver. Never bail on ambiguity or take an arbitrary match.
        return PlateResolver::pickBest($this->plateIndexMap()[$digits] ?? []);
    }

    /** Index our fleet by plate digits so the fallback match is one pass, not a query per row. */
    protected function plateIndexMap(): array
    {
        if ($this->plateIndex === null) {
            $this->plateIndex = [];
            foreach (Vehicle::withTrashed()->get(['id', 'plate_no', 'make', 'model', 'status', 'car_serial']) as $v) {
                $digits = $this->plateDigits($v->plate_no);
                if ($digits !== '') {
                    $this->plateIndex[$digits][] = $v;
                }
            }
        }

        return $this->plateIndex;
    }

    /** A plate's digits with leading zeros stripped, so "K 20756" / "0020756" / "20756" all match. */
    protected function plateDigits($plate): string
    {
        return PlateResolver::plateDigits($plate);
    }

    /** Normalize config map keys (lower-cased, whitespace collapsed) so "For Sale" == "for sale". */
    protected function normalizeMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[$this->norm($k)] = is_string($v) ? trim($v) : $v; // keep null as null
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
}
