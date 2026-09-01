<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\PlateCode;
use App\Models\SpareKeyRequirement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Turn the "Spare keys" register into real components on real cars.
 *
 * WHAT THE REGISTER IS. One row per car — model, year, plate, how many keys we hold, and a remark.
 * It is a stock count somebody maintains by hand, and it is the ONLY record of how many keys the
 * fleet actually has. That makes it evidence of a physical object, which the "NEED SPARE KEY" job
 * list is not (@see SpareKeysImportHistory for why that one creates no keys).
 *
 * ── THE PLATE TRAP THIS EXISTS TO AVOID ─────────────────────────────────────────────────────────
 *
 * {@see PlateResolver} matches on DIGITS ALONE — "K 19397", "0019397" and "19397" are deliberately
 * one key, because that is what every other importer needs. This register breaks that assumption:
 * it carries `U76722` and `P76722` as two separate cars, and they ARE two separate cars. Resolving
 * by digits would have put both cars' keys on whichever one won, and the fleet's key count would
 * have been wrong in a way nobody could see.
 *
 * So matching here is letter-aware, in three strict steps, and refuses rather than guesses:
 *   1. digits narrow the field;
 *   2. the plate LETTER (via the plate_code dictionary) must agree — unless the fleet row carries no
 *      code at all AND the make agrees, which is a gap in our data, not a disagreement with it;
 *   3. when a plate has been re-issued across several physical cars, {@see PlateResolver::pickBest}
 *      picks the current holder, disambiguated by the register's own make/model text.
 * Anything still unresolved is REPORTED and skipped. A key put on the wrong car is worse than a key
 * we have not recorded yet.
 */
class SpareKeyInventoryImporter
{
    /** Row shape in the register: B=model, C=year, D=plate, E=quantity, F=remark. */
    private const COL_MODEL = 1;
    private const COL_YEAR = 2;
    private const COL_PLATE = 3;
    private const COL_QTY = 4;
    private const COL_REMARK = 5;

    /** First data row (rows 0-2 are the title, the as-of date and the header). */
    private const FIRST_DATA_ROW = 3;

    /**
     * Remarks that state WHAT KIND of key it is, longest phrase first so "PHYSICAL + REMOTE" is not
     * swallowed by "PHYSICAL". Everything else in the remark column is context about the car, not the
     * key, and is preserved verbatim on the component's biography instead of being parsed.
     */
    private const KEY_TYPES = [
        'PHYSICAL + REMOTE' => 'Physical + Remote Key',
        'TWO REMOTE'        => 'Remote Key',
        'PHYSICAL CARD'     => 'Key Card',
        'PHYSICAL KEY'      => 'Physical Key',
        'REMOTE KEY'        => 'Remote Key',
    ];

    /** code => letter, loaded once. */
    private ?array $letterMap = null;

    /** @var Collection<string, Collection<int, Vehicle>>|null digits => vehicles */
    private ?Collection $index = null;

    public function __construct(private ComponentService $components) {}

    /**
     * @param  array<int, array<int, string>> $rows raw sheet values
     * @return array{applied:int, created:int, skipped:int, rows:array<int,array>}
     */
    public function import(array $rows, User $actor, bool $dryRun = true): array
    {
        $catalog = ComponentCatalog::where('slug', SpareKeyRequirement::CATALOG_SLUG)->firstOrFail();

        $created = 0;
        $applied = 0;
        $report  = [];

        foreach (array_slice($rows, self::FIRST_DATA_ROW) as $raw) {
            $model  = trim((string) ($raw[self::COL_MODEL] ?? ''));
            $plate  = trim((string) ($raw[self::COL_PLATE] ?? ''));
            $remark = trim((string) ($raw[self::COL_REMARK] ?? ''));

            if ($model === '' || $plate === '') {
                continue; // spacer / formula overflow row
            }

            $qty = (int) ($raw[self::COL_QTY] ?? 0);

            [$vehicle, $why] = $this->resolve($plate, $model);

            if (! $vehicle) {
                $report[] = ['plate' => $plate, 'model' => $model, 'qty' => $qty, 'outcome' => 'unmatched', 'detail' => $why];
                continue;
            }

            // Quantity 0 is a real statement, not a blank: the key exists but is not with us — it is
            // out with a customer or lent to a driver. Recording a component for it would claim the
            // fleet holds a key it cannot lay hands on, so nothing is written and the row is named.
            if ($qty < 1) {
                $report[] = [
                    'plate' => $vehicle->plate_no, 'model' => $model, 'qty' => 0,
                    'outcome' => 'zero_on_hand', 'detail' => $remark ?: 'register says none on hand',
                ];
                continue;
            }

            $applied++;

            if ($dryRun) {
                $report[] = ['plate' => $vehicle->plate_no, 'model' => $model, 'qty' => $qty, 'outcome' => 'would_create', 'detail' => $why];
                continue;
            }

            $result = $this->components->importSetUnits($catalog, $vehicle, $qty, [
                'label' => $this->keyType($remark) ?? $catalog->name,
                'note'  => $this->provenanceNote($remark),
            ], $actor);

            $created += count($result['created']);
            $report[] = [
                'plate'   => $vehicle->plate_no,
                'model'   => $model,
                'qty'     => $qty,
                'outcome' => count($result['created']) > 0 ? 'created' : ($result['surplus'] > 0 ? 'surplus' : 'already_present'),
                'detail'  => $result['surplus'] > 0
                    // Never auto-removed: a removal needs a reason and a disposition the sheet has not got.
                    ? "ledger holds {$result['existing']}, register says {$qty} — {$result['surplus']} extra left alone for a human"
                    : ($why . ($remark !== '' ? " · {$remark}" : '')),
            ];
        }

        return [
            'applied' => $applied,
            'created' => $created,
            'skipped' => count(array_filter($report, fn ($r) => in_array($r['outcome'], ['unmatched', 'zero_on_hand'], true))),
            'rows'    => $report,
        ];
    }

    // ───────────────────────────── plate resolution ─────────────────────────────

    /**
     * @return array{0: Vehicle|null, 1: string} the car and HOW it was matched (or why it was not)
     */
    private function resolve(string $sheetPlate, string $label): array
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $sheetPlate));

        if (! preg_match('/^([A-Z]*)(\d+)$/', $clean, $m)) {
            return [null, "unreadable plate “{$sheetPlate}”"];
        }

        $letter = $m[1];
        $digits = ltrim($m[2], '0');

        $candidates = $this->vehiclesByDigits()->get($digits, collect());

        if ($candidates->isEmpty()) {
            return [null, "no car in the fleet carries plate digits {$digits}"];
        }

        if ($letter === '') {
            $pick = PlateResolver::pickBest($candidates, $label);

            return $pick ? [$pick, 'matched on digits (register gave no letter)'] : [null, 'digits matched several cars and none stood out'];
        }

        $withLetter = $candidates->filter(fn (Vehicle $v) => $this->letterFor($v) === $letter)->values();

        if ($withLetter->count() === 1) {
            return [$withLetter->first(), 'matched on plate letter + digits'];
        }

        if ($withLetter->count() > 1) {
            // The plate was re-issued. pickBest prefers the in-fleet holder over sold/disposed ones.
            $pick = PlateResolver::pickBest($withLetter, $label);

            return $pick
                ? [$pick, "matched the current holder of {$letter} {$digits} ({$withLetter->count()} cars have carried it)"]
                : [null, "plate {$letter} {$digits} has had {$withLetter->count()} holders and none is clearly current"];
        }

        // Digits match, letter does not. The ONE case we accept is a fleet row with no plate code
        // recorded at all — that is a hole in our data rather than a contradiction of the register —
        // and only when the make agrees, so a blank code can never silently absorb another car's keys.
        if ($candidates->count() === 1) {
            $only = $candidates->first();
            if ($this->letterFor($only) === null && $this->makeAgrees($only, $label)) {
                return [$only, "matched on digits; the fleet record has no plate code, and the make agrees"];
            }

            return [null, sprintf('register says %s %s, the fleet says %s (%s %s) — needs a human',
                $letter, $digits, $only->plate_no, $only->make, $only->model)];
        }

        return [null, "no car with plate letter {$letter} among the {$candidates->count()} carrying digits {$digits}"];
    }

    /** Does the register's free text name the same make as the fleet record? */
    private function makeAgrees(Vehicle $vehicle, string $label): bool
    {
        $make = trim((string) $vehicle->make);

        return $make !== '' && str_contains(strtoupper($label), strtoupper($make));
    }

    private function letterFor(Vehicle $vehicle): ?string
    {
        $code = $vehicle->plate_code;
        if ($code === null || $code === '') {
            return null;
        }

        $this->letterMap ??= PlateCode::letterMap();

        return $this->letterMap[$code] ?? $this->letterMap[(int) $code] ?? null;
    }

    /** One query for the whole fleet, indexed by plate digits — this runs over ~130 rows. */
    private function vehiclesByDigits(): Collection
    {
        return $this->index ??= Vehicle::query()
            ->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'plate_code', 'make', 'model', 'year', 'status', 'car_serial'])
            ->groupBy(fn (Vehicle $v) => PlateResolver::plateDigits($v->plate_no));
    }

    // ───────────────────────────── remarks ─────────────────────────────

    /** The kind of key, when the remark states one. Null means "just a spare key". */
    public function keyType(string $remark): ?string
    {
        $needle = strtoupper($remark);

        foreach (self::KEY_TYPES as $phrase => $label) {
            if (str_contains($needle, $phrase)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The component's first biography line. Says where the belief came from AND repeats the
     * register's own words, because a remark like "SPARE KEY IS NOT WORKING" changes what the row
     * means and must not be lost to a parser that was only looking for a key type.
     */
    private function provenanceNote(string $remark): string
    {
        $note = 'Backfilled from the spare-key register (count as held on the day of import; '
            . 'the register records no date, supplier or price, so none is claimed here)';

        return $remark !== '' ? $note . ' — register remark: “' . $remark . '”' : $note;
    }
}
