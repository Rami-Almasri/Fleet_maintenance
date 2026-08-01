<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTombstone;
use App\Models\Vehicle;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the "N-Maintenance & Repair" Google-Sheet log into the `maintenances` table
 * as STANDALONE rows (origin = 'sheet') — one row per maintenance EVENT (OUT / IN /
 * Follow up) for a car. Cars are matched to the fleet by PLATE (the last token of the
 * "CAR" cell); garages are matched/created as vendors by name. Idempotent: each row is
 * keyed by a hash of its identifying fields, so re-running updates instead of duplicating.
 */
class MaintenanceSheetImporter
{
    /** normalized plate (letters+digits) => [Vehicle, ...] sharing that plate */
    protected array $vehiclesByNormPlate = [];
    /** digits-only plate => [Vehicle, ...] sharing those digits */
    protected array $vehiclesByDigits = [];
    /** normalized garage name => vendor id */
    protected array $vendorByName = [];
    /** row_hash => true for events the user deleted from the dashboard — never re-imported. */
    protected array $tombstonedHashes = [];

    /** Normalized sheet header => our column. Headers carry newlines/odd hyphens — normHeader strips them. */
    protected const FIELD_MAP = [
        'car'                       => 'car',           // virtual — split into plate/vehicle_id/car_label
        'outin'                     => 'event_status',
        'fixedoutdate'              => 'out_date',
        'expectedreturndate'        => 'expected_return_date',
        'followdate'                => 'follow_date',
        'actualindate'              => 'actual_in_date',
        'baseon'                    => 'base_on',
        'approveby'                 => 'approved_by',
        'driver'                    => 'driver',
        'liableparty'               => 'liable_party',
        'customerstaffcharge'       => 'charge_to',
        'garage'                    => 'garage',
        'maintenancetype'           => 'maintenance_type',
        'main'                      => 'service_main',
        'sup'                       => 'service_sup',
        'damagelocation'            => 'damage_location',
        'severity'                  => 'severity',
        'followupofficer'           => 'responsible',
        'maintenanceandrepairnotes' => 'maintenance_notes',
        'sparepart'                 => 'spare_part',
        'invoiceno'                 => 'invoice_no',
        'cost'                      => 'cost',
        'costnotes'                 => 'cost_notes',

        // --- "Customer Cases" tab aliases. A given sheet only carries ONE side of each
        //     pair, so these never collide. This tab's headers are MISLEADING — mapped by
        //     ACTUAL cell content, verified against the sheet:
        //       "Main Issue"   = the issue            -> service_main
        //       "Main Cause"   = sub-category         -> service_sup
        //       "Contract No." = a person's NAME      -> responsible  (NOT a contract no)
        //       "Responsible"  = free-text work notes -> maintenance_notes
        //     "Invoice Amount" (TRUE/FALSE) and "Invoice No" (a month) are noise and skipped. ---
        'mainissue'                 => 'service_main',
        'maincause'                 => 'service_sup',
        'customercharge'            => 'charge_to',
        'followdateddmmyy'          => 'follow_date',
        'contractno'                => 'responsible',
        'responsible'               => 'maintenance_notes',
        'note'                      => 'maintenance_notes', // skipped if "Responsible" already filled it
        'billreceive'               => 'bill_receive',
    ];

    protected const DATE_FIELDS = ['out_date', 'expected_return_date', 'follow_date', 'actual_in_date'];

    /** Columns stored as TEXT — left full-length. Every other string column is varchar(255),
     *  so values are capped to fit (the customer-cases tab has long free-text in odd columns). */
    protected const LONG_TEXT_FIELDS = ['maintenance_notes', 'spare_part', 'cost_notes'];

    public function __construct(
        protected GoogleSheetsService $sheets,
        protected MaintenanceReasonMatcher $reasonMatcher,
    ) {
    }

    /**
     * @return array{imported:int, updated:int, skipped:int, ignored:int, unmatched_cars:int, ambiguous_cars:int, vendors_made:int, samples:array, unmatched_samples:array, ambiguous_samples:array}
     */
    public function import(string $spreadsheetId, int $gid, bool $dryRun = false, ?int $limit = null, string $origin = 'sheet'): array
    {
        $rows = $this->sheets->readByGid($spreadsheetId, $gid);
        if (count($rows) < 2) {
            return ['error' => 'No data found in the sheet.'];
        }

        [$headerRow, $map] = $this->locateHeader($rows);
        if ($map === null || ! in_array('car', $map, true) || ! in_array('garage', $map, true)) {
            return ['error' => "Couldn't find the header row (expected 'CAR' and 'Garage' columns)."];
        }
        $carCol = array_search('car', $map, true);

        $this->preloadCaches();

        $imported = 0; $updated = 0; $skipped = 0; $ignored = 0; $unmatched = 0; $ambiguous = 0; $vendorsMade = 0;
        // Rows that were soft-deleted locally and have been brought back because the sheet still
        // publishes them. Counted separately so a revival is never mistaken for a routine update.
        $revived = 0;
        $samples = []; $unmatchedSamples = []; $ambiguousSamples = [];
        $seen = [];   // (plate|event|out_date) => running occurrence count, for a stable identity key

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach (array_slice($rows, $headerRow + 1) as $row) {
                $car = trim((string) ($row[$carCol] ?? ''));
                $plate = $this->plateFromCar($car);
                if ($plate === null) {
                    $skipped++; // blank / label / summary row (no recognizable plate)
                    continue;
                }

                $data = ['origin' => $origin, 'car_label' => $car, 'plate' => $plate];
                $rawDates = [];
                foreach ($map as $col => $field) {
                    if ($field === 'car') {
                        continue;
                    }
                    $val = trim((string) ($row[$col] ?? ''));
                    if ($val === '') {
                        continue;
                    }
                    if (in_array($field, self::DATE_FIELDS, true)) {
                        $rawDates[$field] = $val;   // resolved after the loop with out_date as the year anchor
                    } elseif ($field === 'cost') {
                        $data[$field] = $this->money($val);
                    } elseif (in_array($field, self::LONG_TEXT_FIELDS, true)) {
                        $data[$field] = $val;
                    } else {
                        $data[$field] = mb_substr($val, 0, 250); // varchar(255) columns
                    }
                }

                // Resolve dates AFTER the row is read so the return/follow dates can inherit the
                // out_date's year. The sheet often writes a return as a bare "5/14" (no year); parsed
                // alone that lands on TODAY's year (e.g. 2026), inventing a return a year in the future
                // and a phantom ~365-day workshop visit. Anchored to out_date it stays correct, rolling
                // forward only for genuine year-boundary returns (a Dec out → Jan return).
                $data['out_date'] = isset($rawDates['out_date']) ? $this->date($rawDates['out_date']) : null;
                foreach (['expected_return_date', 'follow_date', 'actual_in_date'] as $depDate) {
                    if (isset($rawDates[$depDate])) {
                        $data[$depDate] = $this->date($rawDates[$depDate], $data['out_date']);
                    }
                }

                // car -> vehicle: try the full plate first, then fall back to the digits-only
                // CarNo (the API plate form). A plate reused after a sale maps to several cars,
                // so this is a HISTORICAL lookup: resolveAsOf() attaches the event to the car
                // that held the plate on the event's out_date, NOT the current car — the sold
                // car's repairs stay on the sold car. Genuinely ambiguous rows (a purchase-date
                // tie, or a date before every candidate) are reported, never guessed.
                $candidates = $this->vehiclesByNormPlate[$this->normPlate($plate)] ?? [];
                if (empty($candidates)) {
                    $digits = PlateResolver::plateDigits($plate);
                    $candidates = $digits !== '' ? ($this->vehiclesByDigits[$digits] ?? []) : [];
                }
                $reason = null;
                $vehicleId = optional(PlateResolver::resolveAsOf($candidates, $data['out_date'] ?? null, $car, $reason))->id;
                $data['vehicle_id'] = $vehicleId;
                if ($vehicleId === null) {
                    if (in_array($reason, ['ambiguous_nodate', 'ambiguous_predate', 'ambiguous_tie'], true)) {
                        $ambiguous++;
                        if (count($ambiguousSamples) < 25) {
                            $ambiguousSamples[] = $plate . ' @ ' . ($data['out_date'] ?? 'no-date') . ' (' . $reason . ')';
                        }
                    } else {
                        $unmatched++;
                        if (count($unmatchedSamples) < 25) {
                            $unmatchedSamples[] = $plate;
                        }
                    }
                }

                // garage -> vendor (match by name, create the garage vendor if new)
                if (! empty($data['garage'])) {
                    $data['vendor_id'] = $this->resolveVendor($data['garage'], $vendorsMade, $dryRun);
                }

                // Cross-reference the controlled "Maintenance Reason" vocabulary from the
                // MAIN column (refined by an EXACT SUP match only). Deterministic, no guessing.
                $reason = $this->reasonMatcher->resolve($data['service_main'] ?? null, $data['service_sup'] ?? null);
                $data['maintenance_reason_id'] = $reason?->id;
                // Provenance: this category came from the sheet's own MAIN column at import
                // time. Distinguishes it from a later system backfill or a manual edit.
                $data['reason_source'] = $reason ? 'sheet' : null;

                // Occurrence index within this import: the k-th row sharing the same
                // (plate, event, out_date). Kept stable by the sheet's append order so it
                // disambiguates same-day same-type events WITHOUT relying on mutable fields.
                $occKey = $this->normPlate($data['plate'] ?? '') . '|'
                    . strtolower($data['event_status'] ?? '') . '|'
                    . ($data['out_date'] ?? '') . '|'
                    . ($origin !== 'sheet' ? $origin : '');
                $occurrence = $seen[$occKey] = ($seen[$occKey] ?? -1) + 1;

                $hash = $this->rowHash($data, $occurrence);
                $data['row_hash'] = $hash;

                // The user deleted this exact sheet event from the dashboard — honour that
                // tombstone and never re-create it (occurrence already counted above, so the
                // identity of the remaining same-day events stays stable).
                if (isset($this->tombstonedHashes[$hash])) {
                    $ignored++;
                    continue;
                }

                // withTrashed() IS LOad-BEARING, not defensive tidiness. `row_hash` is UNIQUE and a
                // soft-deleted row keeps occupying it while being invisible to a normal query — so a
                // scoped lookup would miss it, fall through to create(), and blow up on the unique
                // index. This job runs unattended at 02:30; that failure would be a nightly outage.
                $existing = Maintenance::withTrashed()->where('row_hash', $hash)->first();

                if ($existing) {
                    // A trashed row here means the sheet still publishes an event somebody retired
                    // locally WITHOUT tombstoning it (a tombstoned hash never reaches this line — it is
                    // skipped above). The sheet is the source of truth for its own rows, so its
                    // re-appearance revives ours; the tombstone remains the ONLY way to say "stay gone".
                    if ($existing->trashed()) {
                        $existing->restore();
                        $revived++;
                    }
                    $existing->fill($data)->save();   // edits (return date, follow date, notes) UPDATE in place
                    $updated++;
                } else {
                    Maintenance::create($data);
                    $imported++;
                    if (count($samples) < 10) {
                        $samples[] = [
                            'plate'  => $plate,
                            'event'  => $data['event_status'] ?? '',
                            'out'    => $data['out_date'] ?? '',
                            'garage' => $data['garage'] ?? '',
                            'matched_car' => $vehicleId !== null,
                        ];
                    }
                }

                if ($limit && ($imported + $updated) >= $limit) {
                    break;
                }
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return [
            'imported'          => $imported,
            'updated'           => $updated,
            'revived'           => $revived,
            'skipped'           => $skipped,
            'ignored'           => $ignored,
            'unmatched_cars'    => $unmatched,
            'ambiguous_cars'    => $ambiguous,
            'vendors_made'      => $vendorsMade,
            'samples'           => $samples,
            'unmatched_samples' => $unmatchedSamples,
            'ambiguous_samples' => $ambiguousSamples,
        ];
    }

    /**
     * Find the header row (the one carrying 'CAR' and 'Garage') and build its
     * column => field map. Returns [rowIndex, map] or [-1, null] if not found.
     *
     * @param  array<int,array<int,mixed>>  $rows
     * @return array{0:int,1:array<int,string>|null}
     */
    protected function locateHeader(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $map = [];
            foreach ($row as $col => $cell) {
                $key = $this->normHeader($cell);
                if ($key !== '' && isset(self::FIELD_MAP[$key]) && ! in_array(self::FIELD_MAP[$key], $map, true)) {
                    $map[$col] = self::FIELD_MAP[$key];
                }
            }
            if (in_array('car', $map, true) && in_array('garage', $map, true)) {
                return [$i, $map];
            }
        }
        return [-1, null];
    }

    protected function preloadCaches(): void
    {
        // Map cars by full plate AND by digits-only. The API stores plate_no as the bare
        // CarNo (digits, no emirate letter), while the sheet writes "K 20773"; the digits
        // bridge the two. Plates shared by >1 car (reused after a sale) keep ALL candidates —
        // PlateResolver picks the current one at lookup time, so a maintenance row can never
        // link to the sold history car.
        foreach (Vehicle::whereNotNull('plate_no')->where('plate_no', '<>', '')->get(['id', 'plate_no', 'make', 'model', 'status', 'car_serial', 'purchase_date']) as $v) {
            $this->vehiclesByNormPlate[$this->normPlate($v->plate_no)][] = $v;
            $digits = PlateResolver::plateDigits($v->plate_no);
            if ($digits !== '') {
                $this->vehiclesByDigits[$digits][] = $v;
            }
        }
        foreach (Vendor::whereNotNull('name')->get(['id', 'name']) as $vn) {
            $key = $this->normName($vn->name);
            if ($key !== '' && ! isset($this->vendorByName[$key])) {
                $this->vendorByName[$key] = $vn->id;
            }
        }

        // Events the user deleted from the dashboard — skipped so a sync can't resurrect them.
        $this->tombstonedHashes = array_fill_keys(
            MaintenanceTombstone::pluck('row_hash')->all(),
            true
        );
    }

    /** Match a garage name to a vendor; create one (type garage) if it's new. */
    protected function resolveVendor(string $garage, int &$made, bool $dryRun): ?int
    {
        $key = $this->normName($garage);
        if ($key === '') {
            return null;
        }
        if (isset($this->vendorByName[$key])) {
            return $this->vendorByName[$key];
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

    /** Plate = the last " - " segment of the CAR cell, e.g. "… - K 20773" -> "K 20773". */
    protected function plateFromCar(string $car): ?string
    {
        $car = trim($car);
        if ($car === '') {
            return null;
        }
        $parts = preg_split('/\s+-\s+/u', $car);
        $last = trim((string) end($parts));
        if (preg_match('/^[A-Za-z]{1,3}\s*\d{1,6}$/', $last)) {
            return $last;
        }
        // fallback: a trailing "<letters> <digits>" anywhere at the end
        if (preg_match('/([A-Za-z]{1,3}\s*\d{2,6})\s*$/', $car, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function normPlate(string $p): string
    {
        return strtoupper(preg_replace('/\s+/', '', $p));
    }

    protected function normName(string $n): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $n)));
    }

    /** Strip a header cell down to comparable letters/digits ("OUT\nIN" -> "outin"). */
    protected function normHeader($h): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $h));
    }

    /**
     * A stable per-event identity key (the sheet has no row id) so re-imports UPDATE
     * instead of duplicating. Built from IMMUTABLE fields ONLY — plate, event type,
     * out_date, and the occurrence index for same-day same-type events. Mutable fields
     * (actual_in_date, follow_date, notes, garage, cost…) are deliberately EXCLUDED so
     * that filling in a return date or appending a note updates the existing row rather
     * than creating a stale clone.
     */
    protected function rowHash(array $d, int $occurrence = 0): string
    {
        $parts = [
            $this->normPlate($d['plate'] ?? ''),
            strtolower($d['event_status'] ?? ''),
            $d['out_date'] ?? '',
            $occurrence,
        ];
        // Namespace non-default origins so a customer-case row can never collide with a
        // fleet-log row.
        if (($d['origin'] ?? 'sheet') !== 'sheet') {
            $parts[] = $d['origin'];
        }
        return md5(implode('|', $parts));
    }

    /**
     * Parse a sheet date cell to 'Y-m-d'. When the cell carries NO explicit 4-digit year
     * (e.g. a bare "5/14") and an $anchor date is given, the year is taken from $anchor instead
     * of defaulting to the current year — so a return cell inherits its out_date's year. A result
     * that lands before the anchor rolls forward one year (a genuine Dec → Jan return).
     */
    protected function date($s, ?string $anchor = null): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        // Collapse odd spacing so "2026  Apr 7" still matches a fixed format.
        $norm = preg_replace('/\s+/', ' ', $s);
        $hasYear = (bool) preg_match('/\d{4}/', $norm);

        // The sheet writes dates year-first with a text month, e.g. "2026 Apr 07" —
        // a form Carbon::parse() rejects, so it would silently drop the date. Try
        // those explicit formats first; anything else falls through to the permissive
        // parser below (unchanged behavior for dates that already worked). These all carry
        // an explicit year, so anchoring never applies here.
        foreach (['Y M d', 'Y M j', 'Y F d', 'Y F j'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!' . $fmt, $norm);
            } catch (Throwable $e) {
                continue; // string doesn't fit this format — try the next one
            }
            $errs = Carbon::getLastErrors();
            if ($d !== false && ($errs['warning_count'] ?? 0) === 0 && ($errs['error_count'] ?? 0) === 0) {
                return ($d->year < 1990 || $d->year > 2100) ? null : $d->format('Y-m-d');
            }
        }

        try {
            $d = Carbon::parse($norm);
        } catch (Throwable $e) {
            return null;
        }
        if ($d->year < 1990 || $d->year > 2100) {
            return null;
        }

        // Year-less cell: inherit the anchor's year instead of today's, rolling forward a year
        // only if the result would otherwise precede the anchor (out → next-year return).
        if (! $hasYear && $anchor !== null) {
            try {
                $a = Carbon::parse($anchor);
                $d = $d->copy()->year($a->year);
                if ($d->lt($a)) {
                    $d->addYear();
                }
            } catch (Throwable $e) {
                // anchor unparseable — fall back to the un-anchored date
            }
        }

        return $d->format('Y-m-d');
    }

    protected function money($s): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $s);
        return ($clean === '' || $clean === '-' || $clean === '.') ? null : (float) $clean;
    }
}
