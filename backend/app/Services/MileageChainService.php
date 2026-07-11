<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\MileageOverride;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Mileage Chain Audit — verifies the odometer hands off cleanly from one contract to the next.
 *
 * A car's contracts, ordered by out_date, should form an unbroken mileage chain: the odometer a
 * contract recorded on RETURN (in_milage = its "end mileage") should equal the odometer the NEXT
 * contract recorded on PICKUP (out_milage = its "start mileage"). For each consecutive pair we
 * emit one LINK and classify it:
 *
 *   - match    : both readings present and equal.
 *   - mismatch : both present but unequal — almost always a mis-typed handover reading (delta shown).
 *   - missing  : either side is a placeholder (null / 0 / 1) — a "no reading" gap.
 *
 * Manual corrections live in `mileage_overrides` as a non-destructive overlay (see MileageOverride):
 * when a reading has an override with a corrected_value, the audit uses that instead of the synced
 * value, so fixing a typo here survives the next OfficeManager re-sync.
 *
 * Placeholder convention (null / 0 / 1 = "no reading") is shared with MileageBaselineService so the
 * two mileage tools agree on what counts as a real reading.
 */
class MileageChainService
{
    /** Lifecycle states whose cars have left the fleet — their history isn't worth auditing. */
    private const GONE = ['sold', 'disposed'];

    /** Vehicles per page — the audit renders one table per car, so we page by vehicle. */
    private const PER_PAGE = 20;

    /**
     * Build a page of vehicles' mileage chains plus a fleet-wide funnel summary.
     *
     * The summary is always computed over the WHOLE fleet (so the metric cards stay accurate
     * regardless of page/filter); only the `vehicles` list is filtered and paginated.
     *
     * @param  string  $filter  all | matches | errors — applied per link, then empty cars dropped.
     *
     * @return array{
     *   vehicles: list<array{vehicle_id:int, plate:?string, car:?string, links:list<array>}>,
     *   summary: array{vehicles:int, links:int, matches:int, mismatches:int, missing:int},
     *   pagination: array{page:int, per_page:int, total:int, total_pages:int}
     * }
     */
    public function chains(int $page = 1, ?int $perPage = null, string $filter = 'all'): array
    {
        // All active overrides up front, keyed "contractId|field", so we never query per reading.
        $overrides = MileageOverride::all()->keyBy(fn (MileageOverride $o) => $o->contract_id.'|'.$o->field);

        $vehicles = Vehicle::query()
            ->whereNotIn('status', self::GONE)
            ->orderBy('plate_no')
            ->get(['id', 'plate_no', 'make', 'model'])
            ->keyBy('id');

        if ($vehicles->isEmpty()) {
            return ['vehicles' => [], 'summary' => $this->emptySummary()];
        }

        // Every contract reading for those cars in one query.
        $rows = DB::table('contracts')
            ->whereNull('deleted_at')
            ->whereNotNull('vehicle_id')
            ->whereIn('vehicle_id', $vehicles->keys())
            ->get(['id', 'vehicle_id', 'contract_no', 'contract_type', 'out_date', 'out_milage', 'in_date', 'in_milage']);

        $byVehicle = [];
        foreach ($rows as $r) {
            $byVehicle[$r->vehicle_id][] = $r;
        }

        $out = [];
        $summary = $this->emptySummary();

        foreach ($vehicles as $vid => $v) {
            $contracts = $byVehicle[$vid] ?? [];
            if (count($contracts) < 2) {
                continue; // no consecutive pair → no chain to audit
            }

            // Chronological order: out_date first, contract id as the stable tiebreak
            // (same ordering MileageBaselineService uses for a car's reading history).
            usort($contracts, fn ($a, $b) => [$a->out_date ?? '9999-12-31', (int) $a->id]
                <=> [$b->out_date ?? '9999-12-31', (int) $b->id]);

            $links = [];
            for ($i = 0; $i < count($contracts) - 1; $i++) {
                $c1 = $contracts[$i];   // previous contract — its END mileage (in_milage)
                $c2 = $contracts[$i + 1]; // next contract — its START mileage (out_milage)

                $end   = $this->reading($c1->id, 'in_milage', $c1->in_milage, $overrides);
                $start = $this->reading($c2->id, 'out_milage', $c2->out_milage, $overrides);

                $bothReal = $this->isReal($end['value']) && $this->isReal($start['value']);
                $delta = $bothReal ? $start['value'] - $end['value'] : null;
                $status = ! $bothReal ? 'missing' : ($delta === 0 ? 'match' : 'mismatch');

                $links[] = [
                    'from_contract_id'   => (int) $c1->id,
                    'from_contract_no'   => $c1->contract_no,
                    'from_type'          => $c1->contract_type,
                    'from_in_date'       => $c1->in_date,
                    'end_mileage'        => $end['value'],
                    'end_raw'            => $end['raw'],
                    'end_overridden'     => $end['overridden'],
                    'end_note'           => $end['note'],

                    'to_contract_id'     => (int) $c2->id,
                    'to_contract_no'     => $c2->contract_no,
                    'to_type'            => $c2->contract_type,
                    'to_out_date'        => $c2->out_date,
                    'start_mileage'      => $start['value'],
                    'start_raw'          => $start['raw'],
                    'start_overridden'   => $start['overridden'],
                    'start_note'         => $start['note'],

                    'delta'              => $delta,
                    'status'             => $status,
                ];

                $summary['links']++;
                $summary[$status === 'match' ? 'matches' : ($status === 'mismatch' ? 'mismatches' : 'missing')]++;
            }

            $summary['vehicles']++;
            $out[] = [
                'vehicle_id' => (int) $vid,
                'plate'      => $v->plate_no,
                'car'        => trim($v->make.' '.$v->model) ?: null,
                'links'      => $links,
            ];
        }

        // Cars with the most broken handoffs first, so the worst chains surface at the top.
        usort($out, fn ($a, $b) => $this->breakCount($b['links']) <=> $this->breakCount($a['links']));

        // Apply the funnel filter (links kept per car, then cars with nothing left dropped) BEFORE
        // paginating, so each page is full and "Errors only" pages through error cars only. The
        // summary above is untouched — it always reflects the whole fleet.
        $out = $this->applyFilter($out, $filter);

        $perPage = max(1, $perPage ?? self::PER_PAGE);
        $total   = count($out);
        $pages   = (int) max(1, ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $slice   = array_slice($out, ($page - 1) * $perPage, $perPage);

        return [
            'vehicles'   => array_values($slice),
            'summary'    => $summary,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $pages,
            ],
        ];
    }

    /**
     * Keep only links matching the funnel (all / matches / errors) and drop cars left with none.
     * Mirrors the frontend's matchesFilter so the two never disagree.
     */
    private function applyFilter(array $vehicles, string $filter): array
    {
        if ($filter === 'all') {
            return $vehicles;
        }

        $keep = $filter === 'matches'
            ? fn (array $l) => $l['status'] === 'match'
            : fn (array $l) => $l['status'] !== 'match'; // errors

        $result = [];
        foreach ($vehicles as $v) {
            $links = array_values(array_filter($v['links'], $keep));
            if ($links) {
                $result[] = ['vehicle_id' => $v['vehicle_id'], 'plate' => $v['plate'], 'car' => $v['car'], 'links' => $links];
            }
        }

        return $result;
    }

    /**
     * Upsert a manual correction on one reading and return it. `$value === null` keeps the reading
     * as-is but records the note (a flag without a fix). Validates the field name defensively.
     */
    public function saveOverride(Contract $contract, string $field, ?int $value, ?string $note, $user = null): MileageOverride
    {
        if (! in_array($field, ['out_milage', 'in_milage'], true)) {
            throw new \InvalidArgumentException("Unknown mileage field [{$field}].");
        }

        return MileageOverride::updateOrCreate(
            ['contract_id' => $contract->id, 'field' => $field],
            [
                'original_value'  => $contract->{$field},
                'corrected_value' => $value,
                'note'            => $note,
                'user_id'         => $user?->id,
                'user_name'       => $user?->name,
            ],
        );
    }

    /** Drop a correction, reverting the reading to its synced value. */
    public function clearOverride(Contract $contract, string $field): void
    {
        MileageOverride::where('contract_id', $contract->id)->where('field', $field)->delete();
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * Resolve one reading to the value the audit should use, applying any override.
     *
     * @return array{value:?int, raw:?int, overridden:bool, note:?string}
     */
    private function reading(int $contractId, string $field, $rawValue, $overrides): array
    {
        $raw = $rawValue !== null ? (int) $rawValue : null;
        $ov  = $overrides->get($contractId.'|'.$field);

        // An override with a corrected_value replaces the reading; a note-only override leaves the
        // value but still carries its note onto the link.
        $value = ($ov && $ov->corrected_value !== null) ? (int) $ov->corrected_value : $raw;

        return [
            'value'      => $value,
            'raw'        => $raw,
            'overridden' => (bool) ($ov && $ov->corrected_value !== null),
            'note'       => $ov?->note,
        ];
    }

    /** A real reading is present and above the "no reading" placeholder (null / 0 / 1). */
    private function isReal(?int $value): bool
    {
        return $value !== null && $value > MileageBaselineService::PLACEHOLDER_MAX;
    }

    private function breakCount(array $links): int
    {
        return count(array_filter($links, fn ($l) => $l['status'] !== 'match'));
    }

    private function emptySummary(): array
    {
        return ['vehicles' => 0, 'links' => 0, 'matches' => 0, 'mismatches' => 0, 'missing' => 0];
    }
}
