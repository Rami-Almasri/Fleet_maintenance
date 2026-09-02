<?php

namespace Tests\Crud;

use App\Models\PartPurchase;
use App\Services\PartIdentityService;
use App\Services\PartIntelligenceService;
use Illuminate\Support\Carbon;

/**
 * Pins the FULL purchase record shown at buy time (PartIntelligenceService::partHistory).
 *
 * The claim under test is the one the feature exists for: the alert is windowed, the RECORD is not. A
 * purchase old enough to raise no warning must still be listed — the old behaviour ("nothing inside 90
 * days ⇒ nothing to show") is exactly the bug. So every test here pairs the two reads and asserts they
 * disagree in the right direction: detectDuplicate() stays quiet while partHistory() still tells the story.
 */
class PartHistoryTest extends CrudTestCase
{
    private PartIntelligenceService $intel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intel = app(PartIntelligenceService::class);
    }

    private function purchase(int $vehicleId, array $attrs = []): PartPurchase
    {
        return PartPurchase::create(array_merge([
            'vehicle_id'      => $vehicleId,
            'part_name'       => 'Alternator',
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'  => 500,
            'currency'        => 'AED',
            'quantity'        => 1,
            'purchased_at'    => Carbon::now()->subDays(10),
        ], $attrs));
    }

    /** The core regression: a repeat outside every alert window is silent, but must NOT be invisible. */
    public function test_purchase_outside_every_window_raises_no_alert_but_still_appears_in_the_record(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['purchased_at' => Carbon::now()->subDays(400)]);

        $verdict = $this->intel->detectDuplicate($v, 'Alternator', null, null);
        $this->assertFalse($verdict['duplicate'], 'a 400-day-old repeat must not raise an alert');

        $history = $this->intel->partHistory($v, 'Alternator', null);
        $this->assertCount(1, $history['records'], 'the old purchase must still be in the record');
        $this->assertSame(1, $history['summary']['total_purchases']);
        $this->assertSame(0, $history['summary']['in_alert_window']);
        $this->assertFalse($history['records'][0]['within_alert_window']);
        $this->assertSame(400, $history['records'][0]['days_ago']);
    }

    /** Every purchase, newest first — not merely the newest one the duplicate verdict carries. */
    public function test_history_returns_all_purchases_newest_first(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['purchased_at' => Carbon::now()->subDays(500)]);
        $this->purchase($v, ['purchased_at' => Carbon::now()->subDays(200)]);
        $this->purchase($v, ['purchased_at' => Carbon::now()->subDays(3)]);

        $history = $this->intel->partHistory($v, 'Alternator', null);

        $this->assertSame(3, $history['summary']['total_purchases']);
        $this->assertSame([3, 200, 500], array_column($history['records'], 'days_ago'));
        $this->assertSame(3, $history['summary']['days_since_last']);
        $this->assertFalse($history['truncated']);
    }

    /** Money is summed per line (unit price × quantity), and only over rows in the base currency. */
    public function test_summary_totals_spend_by_quantity_and_reports_its_coverage(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['purchase_price' => 300, 'quantity' => 2, 'purchased_at' => Carbon::now()->subDays(30)]);
        $this->purchase($v, ['purchase_price' => 800, 'currency' => 'USD', 'purchased_at' => Carbon::now()->subDays(60)]);

        $history = $this->intel->partHistory($v, 'Alternator', null);

        $this->assertSame(600.0, $history['records'][0]['total_price'], 'unit price × quantity');
        $this->assertSame(800.0, $history['records'][1]['total_price'], 'the foreign-currency row is still listed…');
        $this->assertSame(600.0, $history['summary']['total_spend'], '…but never added to a dirham total');
        $this->assertSame(1, $history['summary']['spend_covers']);
        $this->assertSame(2, $history['summary']['total_purchases']);
    }

    /** A failed prior fit is the single most decision-relevant row on the list, so it is counted. */
    public function test_summary_counts_failed_flagged_and_never_fitted_rows(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['result' => PartPurchase::RESULT_FAILED, 'installed_at' => Carbon::now()->subDays(9), 'purchased_at' => Carbon::now()->subDays(120)]);
        $this->purchase($v, ['requires_review' => true, 'purchased_at' => Carbon::now()->subDays(80)]);

        $s = $this->intel->partHistory($v, 'Alternator', null)['summary'];

        $this->assertSame(1, $s['failed']);
        $this->assertSame(1, $s['flagged']);
        $this->assertSame(1, $s['never_installed']);
    }

    /** Identity is SKU-first: a different name under the same part number is the same part. */
    public function test_history_matches_on_part_number_across_differing_names(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['part_name' => 'Alt. (OEM)', 'part_number' => 'ALT 1234', 'purchased_at' => Carbon::now()->subDays(300)]);

        $history = $this->intel->partHistory($v, 'Alternator', 'alt1234');

        $this->assertCount(1, $history['records'], 'whitespace/case must not split one SKU into two parts');
    }

    /**
     * A hand-typed SKU that matches nothing must fall back to the part NAME, never report "never bought".
     * Regression: a request carrying the placeholder part number "1111" reported a clean history for a
     * vehicle that had already had the part twice — the one wrong answer this feature cannot give.
     */
    public function test_unmatched_part_number_falls_back_to_the_name_instead_of_reporting_no_history(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-9931', 'purchased_at' => Carbon::now()->subDays(200)]);
        $this->purchase($v, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-4410', 'purchased_at' => Carbon::now()->subDays(600)]);

        $history = $this->intel->partHistory($v, 'Air Filter — Bosch', '1111');

        $this->assertCount(2, $history['records'], 'a placeholder SKU must not hide a real history');
        // The vocabulary is PartIdentityService's, not this test's — asserting the literal string is how
        // this drifted from VIA_NAME in the first place.
        $this->assertSame(
            PartIdentityService::VIA_NAME,
            $history['summary']['matched_by'],
            'and the UI must be told how they were found'
        );
    }

    /**
     * A matching SKU narrows nothing when the NAMES already agree.
     *
     * This test used to assert the opposite ("a SKU that does match wins, and stays the reported
     * identity") and was written against an SKU-first model. PartIdentityService::matchedVia now states
     * its precedence explicitly and in the other order — catalog → name → part_number — so a row whose
     * wording already matches is recognised BY THAT, and the SKU is the last rung rather than the first.
     *
     * Both readings are defensible; the code's is the one that ships, is documented, and is what the
     * test above this one depends on (a placeholder SKU falling back to the name). Asserting the old
     * order here would have contradicted it.
     */
    public function test_a_matching_part_number_does_not_split_same_named_purchases(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-9931', 'purchased_at' => Carbon::now()->subDays(200)]);
        $this->purchase($v, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-4410', 'purchased_at' => Carbon::now()->subDays(600)]);

        $history = $this->intel->partHistory($v, 'Air Filter — Bosch', 'BOS-9931');

        // BOTH rows come back, and that is the identity model working rather than leaking: these two
        // purchases carry the SAME NAME, so they are the same part — exactly what the test above proves
        // when the SKU matches nothing. Identity is name OR part number; a matching SKU does not narrow
        // the history back down to one row, it decides how the part is REPORTED.
        $this->assertCount(2, $history['records'], 'same-named purchases are one part, however the SKU was typed');

        // Recognised by NAME, because that rung outranks the SKU — see the docblock. The SKU still
        // decided which row is newest and therefore which one the list leads with.
        $this->assertSame(PartIdentityService::VIA_NAME, $history['summary']['matched_by']);
        $this->assertSame('BOS-9931', $history['records'][0]['part_number']);
    }

    /** The fleet roll-up must use whichever identity found the records — not the SKU that found nothing. */
    public function test_fleet_stats_follow_the_identity_that_matched(): void
    {
        $v     = $this->makeVehicle();
        $other = $this->makeVehicle();
        $this->purchase($v, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-9931']);
        $this->purchase($other, ['part_name' => 'Air Filter — Bosch', 'part_number' => 'BOS-2200', 'purchase_price' => 120]);

        $history = $this->intel->partHistory($v, 'Air Filter — Bosch', '1111');

        $this->assertSame(1, $history['fleet']['purchases'], 'the fleet block must see the other vehicle too');
        $this->assertSame(120.0, $history['fleet']['avg_price']);
    }

    /** The fleet block answers "what does this part normally cost?" — from OTHER vehicles only. */
    public function test_fleet_stats_cover_other_vehicles_and_exclude_this_one(): void
    {
        $v     = $this->makeVehicle();
        $other = $this->makeVehicle();
        $this->purchase($v, ['purchase_price' => 900]);
        $this->purchase($other, ['purchase_price' => 400]);
        $this->purchase($other, ['purchase_price' => 600]);

        $history = $this->intel->partHistory($v, 'Alternator', null);

        $this->assertSame(2, $history['fleet']['purchases']);
        $this->assertSame(1, $history['fleet']['vehicles']);
        $this->assertSame(500.0, $history['fleet']['avg_price'], 'this vehicle\'s own 900 must not skew the fleet price');
        $this->assertSame(400.0, $history['fleet']['min_price']);
        $this->assertSame(600.0, $history['fleet']['max_price']);
    }

    /** A part never bought for this car returns an empty record, not a fabricated one. */
    public function test_no_prior_purchase_returns_an_empty_record(): void
    {
        $v = $this->makeVehicle();

        $history = $this->intel->partHistory($v, 'Alternator', null);

        $this->assertSame([], $history['records']);
        $this->assertNull($history['summary']);
        $this->assertNull($history['fleet']);
    }

    /** The record rides along with the pre-buy check, so one call answers both questions. */
    public function test_duplicate_check_endpoint_ships_the_full_record(): void
    {
        $v = $this->makeVehicle();
        $this->purchase($v, ['purchased_at' => Carbon::now()->subDays(400)]);

        $res = $this->getJson('/api/part-purchases/duplicate-check?' . http_build_query([
            'vehicle_id' => $v,
            'part_name'  => 'Alternator',
        ]));
        $res->assertSuccessful();

        $data = $res->json('data');
        $this->assertFalse($data['duplicate'], 'no alert for a 400-day-old repeat…');
        $this->assertCount(1, $data['history']['records'], '…but the record is still returned');
    }
}
