<?php

namespace Tests\Foundation;

use App\Models\PartPurchase;
use App\Models\Vehicle;
use App\Services\PartIntelligenceService;

/**
 * Repeat detection: the same part, on the same car, twice.
 *
 * Identity is the part NAME, not the SKU. That looks like the weaker choice and is not: 3,550 of
 * 3,552 purchases in this fleet carry a distinct hand-typed part_number, so a SKU-keyed sweep finds
 * zero repeats fleet-wide and reports a clean fleet while the same compressor goes on the same car
 * inside a month.
 */
class RepeatPartDetectionTest extends FoundationTestCase
{
    private PartIntelligenceService $intel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intel = app(PartIntelligenceService::class);
    }

    private function purchase(Vehicle $v, string $name, int $daysAgo, ?string $partNumber = null): PartPurchase
    {
        return PartPurchase::create([
            'vehicle_id'     => $v->id,
            'part_name'      => $name,
            'part_number'    => $partNumber,
            'purchase_source' => 'supplier',
            'purchase_price' => 500,
            'quantity'       => 1,
            'purchased_at'   => now()->subDays($daysAgo),
        ]);
    }

    /**
     * detectDuplicate answers "if I buy this NOW, is it a repeat?" — it is called before the row
     * exists, so a car with no prior purchase of the part has nothing to repeat.
     */
    public function test_a_car_with_no_prior_purchase_is_not_a_duplicate(): void
    {
        $v = $this->makeVehicle();

        $verdict = $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac');

        $this->assertFalse($verdict['duplicate']);
    }

    /** The finding: same part, same car, inside the window. */
    public function test_the_same_part_on_the_same_car_inside_the_window_is_flagged(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 20);

        $verdict = $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac');

        $this->assertTrue($verdict['duplicate']);
        $this->assertSame(20, $verdict['days_between']);
        $this->assertNotNull($verdict['priority']);
    }

    /** A different car is a different story — parts are not fleet-wide state. */
    public function test_the_same_part_on_a_different_car_is_not_a_repeat(): void
    {
        $a = $this->makeVehicle();
        $b = $this->makeVehicle();
        $this->purchase($a, 'AC Compressor', 10);

        $this->assertFalse($this->intel->detectDuplicate($b->id, 'AC Compressor', null, 'ac')['duplicate']);
    }

    public function test_a_different_part_on_the_same_car_is_not_a_repeat(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 10);

        $this->assertFalse($this->intel->detectDuplicate($v->id, 'Alternator', null, 'electrical')['duplicate']);
    }

    /**
     * TWO IDENTITY RULES EXIST, AND THEY DISAGREE. Pinned here because the disagreement is real and
     * currently invisible to anyone reading either path alone.
     *
     *   detectDuplicate (this method, the buy-time modal) keys on the SKU whenever one is supplied,
     *   falling back to the name only when it is blank.
     *
     *   repeatPurchases (the fleet-wide sweep behind the dashboard card) keys on the NAME, because
     *   3,550 of 3,552 purchases carry a distinct hand-typed part_number and a SKU-keyed sweep
     *   therefore finds nothing at all.
     *
     * The consequence: a buyer who types a fresh SKU for the same compressor is NOT warned at the
     * till, and the repeat surfaces only later on the sweep. That is a gap, not a feature — recorded
     * in the architecture review rather than silently changed here, because widening the buy-time
     * check would fire on every legitimate re-buy of a consumable-ish part and needs its own
     * windowing decision.
     */
    public function test_buy_time_identity_is_sku_first_and_misses_a_renamed_sku(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 15, 'SKU-AAA-111');

        // Same part, different hand-typed SKU → buy-time check does NOT flag it.
        $this->assertFalse(
            $this->intel->detectDuplicate($v->id, 'AC Compressor', 'SKU-BBB-222', 'ac')['duplicate'],
            'documents the known gap: SKU-first identity hides a repeat of the same part'
        );

        // With no SKU supplied the same call falls back to the name and DOES flag it.
        $this->assertTrue(
            $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac')['duplicate']
        );
    }

    /** Far enough apart is normal wear, not a failed repair. */
    public function test_a_purchase_outside_every_window_is_not_flagged(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 900);

        $verdict = $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac');

        $this->assertNull($verdict['priority'], 'a repeat 900 days later is a service interval, not a comeback');
    }

    /** Consumables repeat by design — oil is supposed to be bought again. */
    public function test_consumables_are_never_given_a_priority(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'Engine Oil', 5);

        $verdict = $this->intel->detectDuplicate($v->id, 'Engine Oil', null, 'fluids');

        $this->assertNull($verdict['priority']);
    }

    /** A very short gap is the strongest signal there is, whatever the part class. */
    public function test_a_very_short_gap_is_high_priority(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 3);

        $this->assertSame('high', $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac')['priority']);
    }

    /** Excluding a row is how a saved purchase avoids finding itself. */
    public function test_a_purchase_does_not_detect_itself(): void
    {
        $v = $this->makeVehicle();
        $own = $this->purchase($v, 'AC Compressor', 5);

        $verdict = $this->intel->detectDuplicate($v->id, 'AC Compressor', null, 'ac', null, $own->id);

        $this->assertFalse($verdict['duplicate']);
    }

    public function test_a_nameless_purchase_has_no_identity_to_match_on(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 5);

        $this->assertFalse($this->intel->detectDuplicate($v->id, null, null, 'ac')['duplicate']);
    }
}
