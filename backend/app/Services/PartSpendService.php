<?php

namespace App\Services;

use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use Illuminate\Support\Carbon;

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
 *
 * ── Dating a dirham ───────────────────────────────────────────────────────────────────────────────
 * The two ledgers date their money differently, so a window filter has to resolve one "spend date"
 * per row before it can compare them:
 *
 *   purchase   → purchased_at                                    (when the buy happened)
 *   line item  → installed_on, else its invoice's recorded_at     (when the part was fitted / billed)
 *
 * Either can be missing — a line typed straight onto a ticket carries neither. Rather than drop those
 * rows (silently shrinking the total) or leave them undated (silently inflating every window), they
 * fall back to `created_at`, the day the row was ENTERED. That is a bookkeeping date, not a money
 * date, so every response reports how many rows leaned on it in `dated.entry`, and the UI says so.
 * With no window asked for, the fallback never matters — nothing is filtered and nothing is dropped.
 */
class PartSpendService
{
    /**
     * When a part line's money happened, best-effort: fitted date → invoice date → row entry date.
     * A correlated subquery (not a join) so the caller's Eloquent query keeps its shape.
     */
    private const LINE_DATE = 'COALESCE(maintenance_line_items.installed_on,
        (SELECT mi.recorded_at FROM maintenance_invoices mi WHERE mi.id = maintenance_line_items.maintenance_invoice_id),
        maintenance_line_items.created_at)';

    /** The same, minus the entry-date fallback — null here means the row has no real money date. */
    private const LINE_DATE_REAL = 'COALESCE(maintenance_line_items.installed_on,
        (SELECT mi.recorded_at FROM maintenance_invoices mi WHERE mi.id = maintenance_line_items.maintenance_invoice_id))';

    private const PURCHASE_DATE      = 'COALESCE(part_purchases.purchased_at, part_purchases.created_at)';
    private const PURCHASE_DATE_REAL = 'part_purchases.purchased_at';

    /** Group key for a part name — so "Brake pads" and "BRAKE  PADS" are one row. */
    private function nameKey(?string $name): string
    {
        $n = preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $name)));

        return $n !== '' ? $n : 'unnamed part';
    }

    /**
     * Turn the caller's {days, from, to} into a concrete [from, to] pair.
     *
     * An explicit range always wins over a trailing preset — `days` must not clip a window the user drew
     * by hand. Both ends are inclusive of their whole day, so the same date at both ends means "that
     * day". Ends given backwards are swapped rather than returning nothing. Mirrors
     * RecurringFaultService::resolveWindow so every window filter in the app behaves identically.
     *
     * @param  array{days?:?int, from?:?string, to?:?string}  $in
     * @return array{days:int, from:?Carbon, to:?Carbon}
     */
    private function resolveWindow(array $in): array
    {
        $from = ($in['from'] ?? null) ? Carbon::parse($in['from'])->startOfDay() : null;
        $to   = ($in['to'] ?? null) ? Carbon::parse($in['to'])->endOfDay() : null;

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from || $to) {
            return ['days' => 0, 'from' => $from, 'to' => $to];
        }

        $days = max(0, (int) ($in['days'] ?? 0));

        return [
            'days' => $days,
            'from' => $days > 0 ? Carbon::now()->subDays($days)->startOfDay() : null,
            'to'   => null,
        ];
    }

    /**
     * Constrain a query to the window using that table's resolved spend-date expression. A window with
     * neither end leaves the query untouched, so the all-time answer is byte-for-byte what it was before
     * the filter existed.
     *
     * @param array{from:?Carbon, to:?Carbon} $w
     */
    private function inWindow($query, string $dateExpr, array $w)
    {
        return $query
            ->when($w['from'], fn ($q) => $q->whereRaw("$dateExpr >= ?", [$w['from']]))
            ->when($w['to'], fn ($q) => $q->whereRaw("$dateExpr <= ?", [$w['to']]));
    }

    /**
     * Ranked parts spend.
     *
     * @param  string  $by      'part' | 'car'
     * @param  int     $limit   how many rows to return
     * @param  array{days?:?int, from?:?string, to?:?string}  $window  when the money was spent
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     window: array{days:int, from:?string, to:?string},
     *     dated: array{exact:int, entry:int, total:int}
     * }
     */
    public function ranked(string $by = 'part', int $limit = 10, ?int $vehicleId = null, array $window = []): array
    {
        $by = $by === 'car' ? 'car' : 'part';
        $w  = $this->resolveWindow($window);
        $groups = [];
        // How many counted rows carried a real money date vs. fell back to the day they were entered.
        $dated = ['exact' => 0, 'entry' => 0];

        $add = function (string $key, array $seed, float $spend, int $count) use (&$groups) {
            if (! isset($groups[$key])) {
                $groups[$key] = $seed + ['spend' => 0.0, 'count' => 0];
            }
            $groups[$key]['spend'] += $spend;
            $groups[$key]['count'] += $count;
        };

        // --- 1. Itemised part lines (garage invoices + installed purchases) -------------------
        $lines = $this->inWindow(
            MaintenanceLineItem::query()
                ->where('kind', MaintenanceLineItem::KIND_PART)
                ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
                ->with('vehicle:id,plate_no,make,model'),
            self::LINE_DATE,
            $w
        )
            ->selectRaw('maintenance_line_items.id, maintenance_line_items.vehicle_id, maintenance_line_items.description,
                maintenance_line_items.line_total, ('.self::LINE_DATE_REAL.') as spend_date_real')
            ->get();

        foreach ($lines as $l) {
            $spend = (float) $l->line_total;
            $dated[$l->spend_date_real ? 'exact' : 'entry']++;
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
        $pending = $this->inWindow(
            PartPurchase::query()
                ->whereNull('maintenance_line_item_id')
                ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
                ->with('vehicle:id,plate_no,make,model'),
            self::PURCHASE_DATE,
            $w
        )
            ->selectRaw('part_purchases.id, part_purchases.vehicle_id, part_purchases.part_name,
                part_purchases.purchase_price, ('.self::PURCHASE_DATE_REAL.') as spend_date_real')
            ->get();

        foreach ($pending as $p) {
            $dated[$p->spend_date_real ? 'exact' : 'entry']++;
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
            // What slice this is, and how trustworthy its dating is — the caption reads both so the
            // number on screen is never a bare total with no stated scope.
            'window' => [
                'days' => $w['days'],
                'from' => $w['from']?->toDateString(),
                'to'   => $w['to']?->toDateString(),
            ],
            'dated' => $dated + ['total' => $dated['exact'] + $dated['entry']],
        ];
    }
}
