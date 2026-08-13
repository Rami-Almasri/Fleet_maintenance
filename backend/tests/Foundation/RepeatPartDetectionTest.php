<?php

namespace Tests\Foundation;

use App\Models\PartPurchase;
use App\Models\Vehicle;
use App\Services\PartIntelligenceService;

/**
 * Repeat detection: the same part, on the same car, twice.
 *
 * WHAT "THE SAME PART" MEANS is PartIdentityService's ruling, and these tests exist to hold it to both
 * halves of its job. It must catch the part bought again under another name — a trade name, the Arabic
 * word, a different brand, a different hand-typed SKU — because a warning that only fires when two
 * people type the same characters is a coincidence detector, not a control. And it must REFUSE to
 * claim identity from a symptom or from wording that fits two parts, because a false "you already
 * bought this" accuses an innocent purchase and an innocent approver, and is the failure that destroys
 * trust in the warning fastest.
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
     * A FRESH SKU FOR THE SAME PART NO LONGER HIDES THE REPEAT — the gap this suite used to pin open.
     *
     * The buy-time check keyed on the SKU whenever one was supplied, so typing a different hand-typed
     * part number for the same compressor produced a clean bill at the till while the fleet-wide sweep
     * (which keys on the part, not the SKU) reported the repeat days later. The two paths now share
     * one ruling — PartIdentityService — so they cannot grade the same buy differently.
     */
    public function test_a_different_sku_for_the_same_part_is_still_a_repeat(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'AC Compressor', 15, 'SKU-AAA-111');

        $verdict = $this->intel->detectDuplicate($v->id, 'AC Compressor', 'SKU-BBB-222', 'ac');

        $this->assertTrue($verdict['duplicate'], 'the SKU changed; the part did not');
        $this->assertSame(15, $verdict['days_between']);
    }

    /**
     * THE POINT OF THE WHOLE FEATURE: the same part bought again under a DIFFERENT NAME.
     *
     * 'dynamo' is a curated identity alias of Alternator, so the two records are one part however each
     * was typed. Before identity-based matching this returned a clean bill — the warning only ever
     * fired when two people happened to type the same characters.
     */
    public function test_the_same_part_under_another_name_is_a_repeat(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'dynamo', 10);

        $verdict = $this->intel->detectDuplicate($v->id, 'Alternator', null, 'electrical');

        $this->assertTrue($verdict['duplicate'], "'dynamo' and 'Alternator' are the same part");
        $this->assertSame('catalog', $verdict['matched_via'], 'both rows resolve to the catalog row');
    }

    /** The Arabic workshop word is the same part as the English name. */
    public function test_the_arabic_name_is_the_same_part(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'دينمو', 7);

        $this->assertTrue($this->intel->detectDuplicate($v->id, 'Alternator', null, 'electrical')['duplicate']);
    }

    /**
     * A BRAND IS NOT A PART. The fleet's own records are written "part — brand", so a check that
     * treats the suffix as part of the name finds almost no repeats: 3,542 of 3,552 purchase rows
     * carry that shape, and on this database the fleet-wide sweep went from 97 pairs to 547 when the
     * suffix stopped counting.
     */
    public function test_the_same_part_from_another_brand_is_a_repeat(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'Shock Absorber — KYB', 12);

        $verdict = $this->intel->detectDuplicate($v->id, 'Shock Absorber — Monroe', null, 'suspension');

        $this->assertTrue($verdict['duplicate'], 'a KYB shock and a Monroe shock are the same part');
    }

    /**
     * A SYMPTOM IS NOT AN IDENTITY. 'battery not charging' lives in the SEARCH alias list precisely
     * because it does not name a part — it is as true of the battery and the wiring as of the
     * alternator. Matching on it would tell a buyer a repair failed when nobody ever bought this part.
     */
    public function test_a_symptom_alias_never_establishes_identity(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'battery not charging', 5);

        $this->assertFalse(
            $this->intel->detectDuplicate($v->id, 'Alternator', null, 'electrical')['duplicate'],
            'a complaint recorded as a part name identifies no part'
        );
    }

    /**
     * AN AMBIGUOUS NAME DECIDES NOTHING. 'fan motor' is said of the radiator fan AND the A/C blower, so
     * it is search-only wording. Even if it reached the identity list by mistake, the runtime refuses
     * any surface it finds under two catalog rows rather than picking one.
     */
    public function test_wording_shared_by_two_parts_is_refused(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, 'fan motor', 5);

        $this->assertFalse(
            $this->intel->detectDuplicate($v->id, 'Radiator Fan', null, 'engine')['duplicate'],
            "'fan motor' cannot say which of the two fans was bought"
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
