<?php

namespace App\Services;

use App\Models\VehicleGarageLocation;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Reads the maintenance sheet's N-Location tab — "Car | Garage", the hand-kept list of which car
 * is standing at which garage — into `vehicle_garage_locations`.
 *
 * WHY THIS EXISTS: for a car OfficeManager sent out on a type-'U' maintenance contract, OM records
 * that it went for maintenance but has NO field for where. The Controllers have always written the
 * garage down on this tab instead. Reading it is the difference between the In the Garage board
 * knowing the answer and asking a person to retype what they already wrote.
 *
 * REPLACE, DON'T ACCUMULATE. The tab is a live list: a car's row is deleted when it comes back. So
 * an import rebuilds the table in one transaction — otherwise a car that came back last week would
 * still be shown at a garage, which is exactly the stale-data failure this board exists to end.
 *
 * A row whose car we cannot resolve is KEPT with a null vehicle_id and counted as unmatched, so a
 * plate nobody can place shows up as a question rather than vanishing.
 *
 * Evidence class: F (Fact) — Produces E-garage-location. Consumes the N-Location sheet tab (F),
 * vehicles (F), vendors (F). Verbatim transcription: the car label and garage name are stored
 * exactly as written, and no garage is invented for a row that has none.
 */
class GarageLocationSheetImporter
{
    /** Normalized garage name => vendor id, built once per import. */
    protected array $vendorByName = [];

    /**
     * @return array{imported:int,matched:int,unmatched:int,vendors_made:int,skipped:int,
     *               rows:array<int,array<string,mixed>>,unmatched_samples:array<int,string>}
     */
    public function import(string $spreadsheetId, int $gid, bool $dryRun = false): array
    {
        $rows = app(GoogleSheetsService::class)->readByGid($spreadsheetId, $gid);
        if (count($rows) < 2) {
            return ['error' => 'The N-Location tab is empty (no header + data rows).'];
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]/i', '', (string) $h))), $rows[0]);
        $carCol    = array_search('car', $header, true);
        $garageCol = array_search('garage', $header, true);
        if ($carCol === false || $garageCol === false) {
            return ['error' => 'The N-Location tab must have a "Car" column and a "Garage" column.'];
        }

        $this->loadVendors();

        $imported = 0; $matched = 0; $unmatched = 0; $vendorsMade = 0; $skipped = 0;
        $parsed = []; $unmatchedSamples = [];
        $now = now();

        foreach (array_slice($rows, 1) as $i => $row) {
            $sheetRow = $i + 2;   // 1-based, and row 1 is the header
            $car    = trim((string) ($row[$carCol] ?? ''));
            $garage = trim((string) ($row[$garageCol] ?? ''));

            // Both cells are the row. A car with no garage says nothing this board can use, and a
            // garage with no car cannot be attached to anything.
            if ($car === '' || $garage === '') {
                $skipped++;
                continue;
            }

            $plate   = $this->plateFromCar($car);
            $vehicle = $plate ? PlateResolver::resolve($plate) : null;
            if ($vehicle) {
                $matched++;
            } else {
                $unmatched++;
                if (count($unmatchedSamples) < 25) {
                    $unmatchedSamples[] = $car;
                }
            }

            $parsed[] = [
                'vehicle_id'  => $vehicle?->id,
                'car_label'   => $car,
                'plate_text'  => $plate,
                'garage_name' => $garage,
                'vendor_id'   => $this->resolveVendor($garage, $vendorsMade, $dryRun),
                'sheet_row'   => $sheetRow,
                'imported_at' => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            $imported++;
        }

        if (! $dryRun) {
            DB::transaction(function () use ($parsed) {
                VehicleGarageLocation::query()->delete();
                foreach (array_chunk($parsed, 500) as $chunk) {
                    VehicleGarageLocation::insert($chunk);
                }
            });
        }

        return [
            'imported'          => $imported,
            'matched'           => $matched,
            'unmatched'         => $unmatched,
            'vendors_made'      => $vendorsMade,
            'skipped'           => $skipped,
            'rows'              => $parsed,
            'unmatched_samples' => $unmatchedSamples,
        ];
    }

    /**
     * The plate out of a "MAKE MODEL - Colour - Year - PLATE" cell.
     *
     * Handles both shapes the tab uses: "H 52979" (emirate letter + digits) and a bare "5107903".
     * Only the LAST dash-separated segment is considered, so a model name containing digits can
     * never be mistaken for a plate.
     */
    public function plateFromCar(string $car): ?string
    {
        $parts = preg_split('/\s+-\s+/u', trim($car));
        $last  = trim((string) end($parts));
        if ($last === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{0,3}\s*\d{1,7}$/', $last)) {
            return $last;
        }

        return null;
    }

    /** Index the vendors we already have, so an import matches names instead of duplicating them. */
    protected function loadVendors(): void
    {
        $this->vendorByName = [];
        foreach (Vendor::whereNotNull('name')->get(['id', 'name']) as $v) {
            $key = $this->normName($v->name);
            if ($key !== '' && ! isset($this->vendorByName[$key])) {
                $this->vendorByName[$key] = $v->id;
            }
        }
    }

    /**
     * Match a garage name to a vendor, creating one when the sheet names a garage we have never
     * recorded — the same rule MaintenanceSheetImporter uses, so both importers converge on one
     * vendor per garage rather than two spellings of it.
     */
    protected function resolveVendor(string $garage, int &$made, bool $dryRun): ?int
    {
        $key = $this->normName($garage);
        if ($key === '') {
            return null;
        }
        if (isset($this->vendorByName[$key])) {
            return $this->vendorByName[$key];
        }
        if ($dryRun) {
            $made++;
            return null;
        }

        $id = Vendor::create([
            'external_id' => 'garage:' . $key,
            'name'        => $garage,
            'type'        => 'garage',
            'active'      => true,
            'origin'      => 'sheet',
            'synced_at'   => now(),
        ])->id;
        $made++;

        return $this->vendorByName[$key] = $id;
    }

    protected function normName(string $n): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $n)));
    }
}
