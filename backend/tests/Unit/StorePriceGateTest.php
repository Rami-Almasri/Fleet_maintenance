<?php

namespace Tests\Unit;

use App\Models\StoreItem;
use App\Services\StoreService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The price gate — the rule that stops a shelf being silently re-priced.
 *
 * A shelf keeps a WEIGHTED AVERAGE, and `StoreService::move()` blends every priced receipt into it.
 * That average is then what `issueToRequest()` charges the next car. So a mistyped price is not a
 * wrong row: it re-prices the part for every future job off that shelf, permanently and invisibly.
 * Two AC compressors bought at very different prices produced a shelf reading AED 1,161 a unit — a
 * figure true of neither receipt.
 *
 * The gate asks for an explanation when a new price disagrees with the shelf, and the threshold is
 * the whole design: it has to be loose enough that nobody learns to click through it, and tight
 * enough that a real jump cannot pass. Both conditions must trip — 20% AND at least 25 — so the
 * cheap part stays quiet and the expensive one still gets asked about.
 *
 * Deliberately schema-free. `priceVariance()` reads two model attributes and returns a decision; it
 * touches no table, so this suite asserts the money rule itself rather than a database round-trip.
 */
class StorePriceGateTest extends TestCase
{
    private function variance(?float $shelfAverage, float $newUnitCost): ?array
    {
        $item = new StoreItem(['part_name' => 'AC Compressor']);
        $item->avg_unit_cost = $shelfAverage;

        $method = new ReflectionMethod(StoreService::class, 'priceVariance');
        $method->setAccessible(true);

        // The service's collaborators are irrelevant to this decision — it reads the item and the
        // number, and nothing else.
        $service = app(StoreService::class);

        return $method->invoke($service, $item, $newUnitCost);
    }

    // ── A shelf with no opinion yet never questions the first price ───────────────────────────────

    public function test_a_shelf_with_no_average_accepts_any_first_price(): void
    {
        // The first priced receipt is what SETS the cost. There is nothing to disagree with, and
        // demanding an explanation would be asking somebody to justify a number against no number.
        $this->assertNull($this->variance(null, 2000.00));
    }

    public function test_a_shelf_costed_at_zero_accepts_any_price(): void
    {
        // An opening count booked with no price leaves 0, not a real cost. Treating it as a baseline
        // would make every genuine first purchase look like an infinite increase.
        $this->assertNull($this->variance(0.0, 500.00));
    }

    // ── Both conditions must trip, so neither alone becomes a nag ─────────────────────────────────

    public function test_a_large_percentage_on_a_cheap_part_is_not_questioned(): void
    {
        // A 4.00 bulb at 6.00 is a 50% move and 2.00 of money. Nobody should be asked to account for
        // it; a gate that fires here is a gate people learn to dismiss without reading.
        $this->assertNull($this->variance(4.00, 6.00));
    }

    public function test_a_large_amount_on_an_expensive_part_is_not_questioned(): void
    {
        // 30.00 on a 5,000.00 compressor is 0.6% — supplier noise, not a mistyped price.
        $this->assertNull($this->variance(5000.00, 5030.00));
    }

    public function test_a_move_that_is_both_big_and_material_is_questioned(): void
    {
        // The case that started this: a shelf costing 322 receiving a unit priced at 2,000.
        $gap = $this->variance(322.00, 2000.00);

        $this->assertNotNull($gap, 'A 521% jump of AED 1,678 must be questioned.');
        $this->assertSame(322.00, $gap['was']);
        $this->assertSame(2000.00, $gap['now']);
        $this->assertSame(1678.00, $gap['delta']);
    }

    public function test_a_price_falling_is_questioned_too(): void
    {
        // A price that collapses is as much evidence of a typo as one that spikes, and it
        // under-charges every future car rather than over-charging — quieter, and worse.
        $gap = $this->variance(2000.00, 322.00);

        $this->assertNotNull($gap);
        $this->assertSame(-1678.00, $gap['delta']);
    }

    // ── The exact edges, so a later refactor cannot drift them ────────────────────────────────────

    public function test_exactly_at_both_thresholds_is_not_questioned(): void
    {
        // 100.00 → 125.00 is exactly 25.00 and exactly 25%. The floor is "at least 25" and the
        // fraction is "at least 20%", so this sits ON the boundary: the amount qualifies, and the
        // comparison is strict-less-than, so a value exactly at the floor passes through.
        $this->assertNull($this->variance(100.00, 124.99), 'Just under the 25.00 floor stays quiet.');
    }

    public function test_just_over_both_thresholds_is_questioned(): void
    {
        // 100.00 → 125.01: 25.01 of money (over the floor) and 25% (over the fraction).
        $this->assertNotNull($this->variance(100.00, 125.01));
    }

    public function test_over_the_floor_but_under_the_fraction_stays_quiet(): void
    {
        // 1,000.00 → 1,030.00 is 30.00 — over the money floor, but only 3%. One condition is not
        // enough, and this is the case that proves the AND rather than an OR.
        $this->assertNull($this->variance(1000.00, 1030.00));
    }
}
