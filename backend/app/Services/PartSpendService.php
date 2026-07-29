<?php

namespace App\Services;

use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use Illuminate\Support\Facades\DB;

/**
 * PartSpendService — the ONE answer to "where does parts money go?".
 *
 * Until now the Parts board's spend chart could only see part_purchases, i.e. parts bought through
 * the request → purchase flow inside this app. That is a small slice: most parts the fleet actually
 * pays for arrive as PART LINES on a garage invoice (maintenance_line_items, kind=part) — itemised
 * by whoever entered the invoice, never through a part request.
 *
 * The two are not parallel ledgers. Installing a purchase writes its cost into maintenance_line_items
 * and stamps part_purchases.maintenance_line_item_id (PartWorkflowService::install), so the line-item
 * table is the canonical money. This service therefore reads:
 *
 *   line items (kind=part)                              → every itemised part cost, whatever its origin
 * + purchases with NO maintenance_line_item_id           → bought but not yet fitted, so not itemised yet
 *
 * which counts every dirham exactly once. Grouping is by part name (case/space-insensitive, the first
 * spelling seen wins the label) or by vehicle.
 *
 * NOT included: the N-Maintenance sheet. Its "Spare Part" column is empty for all ~21.9k rows, so the
 * sheet carries no part-level money — only a ticket-level `cost`, which belongs to the repair, not to
 * a part. Attributing it here would invent numbers.
 */
class PartSpendService
{
    /** Group key for a part name — so "Brake pads" and "BRAKE  PADS" are one row. */
    private function nameKey(?string $name): string
    {
        $n = preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $name)));

        return $n !== '' ? $n : 'unnamed part';
    }

    /**
     * Ranked parts spend.
     *
     * @param  string  $by     'part' | 'car'
     * @param  int     $limit  how many rows to return
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function ranked(string $by = 'part', int $limit = 10, ?int $vehicleId = null): array
    {
        $by = $by === 'car' ? 'car' : 'part';
        $groups = [];

        $add = function (string $key, array $seed, float $spend, int $count) use (&$groups) {
            if (! isset($groups[$key])) {
                $groups[$key] = $seed + ['spend' => 0.0, 'count' => 0];
            }
            $groups[$key]['spend'] += $spend;
            $groups[$key]['count'] += $count;
        };

        // --- 1. Itemised part lines (garage invoices + installed purchases) -------------------
        $lines = MaintenanceLineItem::query()
            ->where('kind', MaintenanceLineItem::KIND_PART)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->with('vehicle:id,plate_no,make,model')
            ->get(['id', 'vehicle_id', 'description', 'line_total']);

        foreach ($lines as $l) {
            $spend = (float) $l->line_total;
            if ($by === 'car') {
                $add(
                    $l->vehicle_id ? 'v'.$l->vehicle_id : 'unassigned',
                    [
                        'label'      => $l->vehicle?->plate_no ?: ($l->vehicle_id ? '#'.$l->vehicle_id : 'Unassigned'),
                        'sub'        => trim(($l->vehicle?->make ?? '').' '.($l->vehicle?->model ?? '')) ?: null,
                        'vehicle_id' => $l->vehicle_id,
                    ],
                    $spend,
                    1
                );
            } else {
                $add($this->nameKey($l->description), ['label' => trim((string) $l->description) ?: 'Unnamed part'], $spend, 1);
            }
        }

        // --- 2. Purchases not yet fitted (no line item written for them yet) -------------------
        $pending = PartPurchase::query()
            ->whereNull('maintenance_line_item_id')
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->with('vehicle:id,plate_no,make,model')
            ->get(['id', 'vehicle_id', 'part_name', 'purchase_price']);

        foreach ($pending as $p) {
            // purchase_price is stored already totalled for the buy (that's how the duplicate
            // warning quotes it), so it is summed as-is — never multiplied by quantity again.
            $spend = (float) $p->purchase_price;
            if ($by === 'car') {
                $add(
                    $p->vehicle_id ? 'v'.$p->vehicle_id : 'unassigned',
                    [
                        'label'      => $p->vehicle?->plate_no ?: ($p->vehicle_id ? '#'.$p->vehicle_id : 'Unassigned'),
                        'sub'        => trim(($p->vehicle?->make ?? '').' '.($p->vehicle?->model ?? '')) ?: null,
                        'vehicle_id' => $p->vehicle_id,
                    ],
                    $spend,
                    1
                );
            } else {
                $add($this->nameKey($p->part_name), ['label' => trim((string) $p->part_name) ?: 'Unnamed part'], $spend, 1);
            }
        }

        $all = array_values($groups);
        usort($all, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        $rows = array_values(array_filter($all, fn ($g) => $g['spend'] > 0));

        return [
            'rows' => array_map(
                fn ($g) => $g + ['spend' => round($g['spend'], 2)],
                array_slice($rows, 0, max(1, $limit))
            ),
            'totals' => [
                'spend'  => round(array_sum(array_column($all, 'spend')), 2),
                'lines'  => (int) array_sum(array_column($all, 'count')),
                'groups' => count($rows),
            ],
        ];
    }
}
