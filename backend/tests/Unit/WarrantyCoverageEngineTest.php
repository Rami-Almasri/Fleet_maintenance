<?php

namespace Tests\Unit;

use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Warranty\CoverageSubject;
use App\Services\Warranty\WarrantyCoverageEngine;
use App\Services\Warranty\WarrantyStatusService;
use App\Support\WarrantyCoverage;
use Tests\TestCase;

/**
 * The warranty decision rules, pinned.
 *
 * IN-MEMORY, NO DATABASE — the same idiom as PartRequestOutstandingTest and
 * WorkflowStateResolverTest. Every rule under test here is pure by construction: the engine's
 * decide() takes rows and an odometer and returns a verdict, and the status service's summarise()
 * takes a collection and a reading. That purity is not a coding preference, it is what makes it
 * possible to pin the sixteen awkward combinations below — live vehicle cover plus expired part
 * cover plus a recorded decision that contradicts both — without a single fixture.
 *
 * WHAT THESE TESTS ARE REALLY GUARDING is one property: the engine decides from recorded facts or it
 * says UNKNOWN. There is no third mode, no inference, no scoring. Several tests below exist purely
 * to fail if somebody later "improves" the engine by making it guess.
 */
class WarrantyCoverageEngineTest extends TestCase
{
    private WarrantyCoverageEngine $engine;
    private WarrantyStatusService $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new WarrantyCoverageEngine();
        $this->status = new WarrantyStatusService();
    }

    /**
     * A warranty built by hand, with BOTH derived expiry legs set explicitly.
     *
     * The model derives them on save(); nothing is saved here, so the test sets what a save would
     * have produced. Doing it explicitly is a feature of these tests, not a workaround — it makes
     * each case state its own window instead of inheriting one from a fixture.
     */
    private function warranty(array $attrs = []): Warranty
    {
        $starts = $attrs['starts_on'] ?? now()->subMonths(6)->toDateString();
        $months = $attrs['duration_months'] ?? null;
        $km     = $attrs['duration_km'] ?? null;
        $startKm = $attrs['start_odometer'] ?? null;

        return (new Warranty())->forceFill(array_merge([
            'id'              => $attrs['id'] ?? random_int(1, 100000),
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => 1,
            'subject'         => 'Manufacturer warranty',
            'status'          => Warranty::STATUS_ACTIVE,
            'starts_on'       => $starts,
            'start_odometer'  => $startKm,
            'duration_months' => $months,
            'duration_km'     => $km,
            'expires_on'      => $months ? \Carbon\Carbon::parse($starts)->addMonths((int) $months)->toDateString() : null,
            'expires_at_km'   => ($startKm !== null && $km) ? (int) $startKm + (int) $km : null,
        ], $attrs));
    }

    private function subject(?int $catalogId = 7, ?int $taskId = null, ?int $componentId = null): CoverageSubject
    {
        return new CoverageSubject(catalogId: $catalogId, partName: 'Transmission', componentId: $componentId, taskId: $taskId);
    }

    private function decide(array $warranties, array $cases, CoverageSubject $subject, ?int $odometer)
    {
        return $this->engine->decide(collect($warranties), collect($cases), $subject, $odometer);
    }

    // ── 1–5 · The window: date, distance, and whichever comes first ───────────────────────────────

    /** #3 The date leg alone ends a time-only warranty. */
    public function test_expiry_by_date_ends_the_cover(): void
    {
        $expired = $this->warranty(['starts_on' => now()->subMonths(14)->toDateString(), 'duration_months' => 12]);

        $this->assertSame(Warranty::STATE_EXPIRED, $expired->evaluate()['state']);
        $this->assertSame(Warranty::BY_TIME, $expired->evaluate()['ended_by']);
    }

    /** #4 The distance leg alone ends a km-bounded warranty, whatever the calendar says. */
    public function test_expiry_by_kilometres_ends_the_cover(): void
    {
        // Started last month: comfortably inside any date window. Already 5,000 km past its limit.
        $w = $this->warranty([
            'starts_on' => now()->subMonth()->toDateString(),
            'duration_months' => 36, 'start_odometer' => 10000, 'duration_km' => 20000,
        ]);

        $verdict = $w->evaluate(null, 35000);

        $this->assertSame(Warranty::STATE_EXPIRED, $verdict['state']);
        $this->assertSame(Warranty::BY_DISTANCE, $verdict['ended_by']);
    }

    /**
     * #5 WHICHEVER COMES FIRST — the rule the whole feature rests on.
     *
     * The same warranty, the same day, two different cars. The one that has been driven hard has no
     * cover left; the one that has not, has. A system that stored a boolean or a single expiry date
     * cannot express this, and would grant cover to the first car — which is exactly the car whose
     * warranty is worth the most.
     */
    public function test_whichever_comes_first_binds(): void
    {
        $w = $this->warranty([
            'starts_on' => now()->subMonths(6)->toDateString(),
            'duration_months' => 36, 'start_odometer' => 0, 'duration_km' => 20000,
        ]);

        // Hard-driven: six months in, already past 20,000 km. Distance binds.
        $this->assertSame(Warranty::STATE_EXPIRED, $w->evaluate(null, 24000)['state']);
        // Lightly used: same six months, 9,000 km. Still covered.
        $this->assertSame(Warranty::STATE_ACTIVE, $w->evaluate(null, 9000)['state']);
    }

    /**
     * A km-bounded warranty judged with NO odometer is reported as unknown-on-distance, never
     * silently as covered. This is the assumption the model refuses to make and the engine refuses
     * to make on top of it.
     */
    public function test_missing_odometer_is_reported_not_assumed(): void
    {
        $w = $this->warranty([
            'duration_months' => 36, 'start_odometer' => 1000, 'duration_km' => 20000,
        ]);

        $verdict = $w->evaluate(null, null);

        $this->assertTrue($verdict['distance_unknown'], 'a km leg with no reading must be flagged');
        $this->assertNull($verdict['km_remaining'], 'unknowable remaining distance must be null, never 0');
    }

    /** #6 Expiring soon fires on EITHER leg — the km side is the one a date threshold misses. */
    public function test_expiring_soon_fires_on_distance_as_well_as_time(): void
    {
        // Years of calendar left, 800 km of cover. Any date-only check calls this healthy.
        $w = $this->warranty([
            'starts_on' => now()->subMonths(2)->toDateString(),
            'duration_months' => 36, 'start_odometer' => 0, 'duration_km' => 20000,
        ]);

        $verdict = $w->evaluate(null, 19200);

        $this->assertSame(Warranty::STATE_ACTIVE, $verdict['state']);
        $this->assertTrue($verdict['expiring_soon']);
        $this->assertSame(800, $verdict['km_remaining']);
    }

    // ── 8 · Several coverages on one car, and what the badge says ────────────────────────────────

    /** #1/#2/#8 The roll-up: many promises, one word, and NONE is not EXPIRED. */
    public function test_vehicle_state_rolls_up_many_coverages(): void
    {
        $live    = $this->warranty(['id' => 1, 'duration_months' => 60]);
        $expired = $this->warranty(['id' => 2, 'starts_on' => now()->subYears(4)->toDateString(), 'duration_months' => 12]);

        $this->assertSame(
            WarrantyCoverage::STATE_UNDER_WARRANTY,
            $this->status->summarise(collect([$live, $expired]), 50000)['state'],
            'one live promise covers the car even when another has run out',
        );

        $this->assertSame(
            WarrantyCoverage::STATE_EXPIRED,
            $this->status->summarise(collect([$expired]), 50000)['state'],
        );

        // NONE is not EXPIRED: "we know the cover ended" and "nobody has recorded any cover" are
        // different operational facts, and collapsing them hides every warranty still in a glovebox.
        $this->assertSame(WarrantyCoverage::STATE_NONE, $this->status->summarise(collect(), 50000)['state']);
    }

    /** #7 A car reads EXPIRING_SOON only when everything still live is near its end. */
    public function test_a_long_warranty_keeps_the_badge_green(): void
    {
        $endingSoon = $this->warranty(['id' => 1, 'starts_on' => now()->subMonths(35)->toDateString(), 'duration_months' => 36]);
        $longRun    = $this->warranty(['id' => 2, 'duration_months' => 60]);

        $this->assertSame(
            WarrantyCoverage::STATE_EXPIRING_SOON,
            $this->status->summarise(collect([$endingSoon]), null)['state'],
        );

        $both = $this->status->summarise(collect([$endingSoon, $longRun]), null);
        $this->assertSame(WarrantyCoverage::STATE_UNDER_WARRANTY, $both['state'], 'the badge is not a to-do list');
        $this->assertTrue($both['expiring_soon'], 'but the page still needs to know something is ending');
    }

    // ── 10–13 · The verdicts ─────────────────────────────────────────────────────────────────────

    /** #12 No cover at all → NOT_COVERED, and procurement is untouched. */
    public function test_no_cover_lets_procurement_through(): void
    {
        $a = $this->decide([], [], $this->subject(), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_NO_LIVE_COVER, $a->reasonCode);
        $this->assertFalse($a->blocksProcurement());
    }

    /** Cover that HAS run out is a different, more useful answer than never having had any. */
    public function test_expired_cover_is_distinguished_from_no_cover(): void
    {
        $expired = $this->warranty(['starts_on' => now()->subYears(5)->toDateString(), 'duration_months' => 36]);

        $a = $this->decide([$expired], [], $this->subject(), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_COVER_EXPIRED, $a->reasonCode);
    }

    /**
     * #10/#13 THE CENTRAL RULE. A live warranty nobody has itemised says nothing about a gearbox —
     * so the answer is UNKNOWN, and UNKNOWN blocks procurement exactly as hard as COVERED does.
     *
     * If this test ever fails because UNKNOWN stopped blocking, the feature is decorative: an
     * unreviewed maybe and a confirmed yes cost the same if we spend on both.
     */
    public function test_unitemised_cover_is_unknown_and_blocks(): void
    {
        $a = $this->decide([$this->warranty(['duration_months' => 60])], [], $this->subject(), 50000);

        $this->assertSame(WarrantyCoverage::UNKNOWN, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_COVER_NOT_ITEMISED, $a->reasonCode);
        $this->assertTrue($a->blocksProcurement(), 'UNKNOWN must hold procurement, not wave it through');
        $this->assertTrue($a->needsReview());
    }

    /** #11 Explicitly named in the cover → COVERED, and the warranty path opens. */
    public function test_explicitly_covered_part_is_covered(): void
    {
        $w = $this->warranty(['duration_months' => 60, 'covered_catalog_ids' => [7, 9]]);

        $a = $this->decide([$w], [], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_EXPLICITLY_COVERED, $a->reasonCode);
        $this->assertTrue($a->blocksProcurement());
    }

    /** #12 Explicitly excluded → NOT_COVERED, and the normal purchase workflow proceeds. */
    public function test_explicitly_excluded_part_is_not_covered(): void
    {
        $w = $this->warranty(['duration_months' => 60, 'excluded_catalog_ids' => [7]]);

        $a = $this->decide([$w], [], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_EXPLICITLY_EXCLUDED, $a->reasonCode);
        $this->assertFalse($a->blocksProcurement());
    }

    /**
     * THE DELIBERATE ASYMMETRY: one inclusion beats any number of exclusions.
     *
     * Warranty A excluding the gearbox does not un-cover it when warranty B names it. The cost of
     * ringing a dealer who then says no is a phone call; the cost of buying a gearbox they owed us
     * is a gearbox.
     */
    public function test_one_inclusion_outranks_an_exclusion_on_another_warranty(): void
    {
        $excludes = $this->warranty(['id' => 1, 'duration_months' => 60, 'excluded_catalog_ids' => [7]]);
        $includes = $this->warranty(['id' => 2, 'duration_months' => 60, 'covered_catalog_ids' => [7]]);

        $a = $this->decide([$excludes, $includes], [], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::COVERED, $a->verdict);
    }

    /** Fully itemised cover that does not name the part is a real NOT_COVERED — not a shrug. */
    public function test_itemised_cover_that_omits_the_part_is_not_covered(): void
    {
        $w = $this->warranty(['duration_months' => 60, 'covered_catalog_ids' => [11, 12]]);

        $a = $this->decide([$w], [], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_NOT_IN_ITEMISED_COVER, $a->reasonCode);
    }

    // ── 9 · Component-level cover ────────────────────────────────────────────────────────────────

    /**
     * #9 THE PART'S OWN PROMISE, on a car whose manufacturer cover is long gone.
     *
     * Ask only the vehicle and you buy a battery the supplier owed you. This is the case the
     * requirement calls out by name, and it is why the engine checks part warranties before it
     * checks the car's.
     */
    public function test_component_warranty_covers_a_part_on_an_out_of_warranty_car(): void
    {
        $vehicleExpired = $this->warranty([
            'id' => 1, 'kind' => Warranty::KIND_VEHICLE,
            'starts_on' => now()->subYears(4)->toDateString(), 'duration_months' => 36,
        ]);
        $batteryLive = $this->warranty([
            'id' => 2, 'kind' => Warranty::KIND_PART, 'subject' => 'Battery 12V 60Ah',
            'component_catalog_id' => 7, 'vehicle_component_id' => 55,
            'starts_on' => now()->subMonths(3)->toDateString(), 'duration_months' => 12,
        ]);

        $a = $this->decide([$vehicleExpired, $batteryLive], [], $this->subject(7, componentId: 55), 90000);

        $this->assertSame(WarrantyCoverage::COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_COMPONENT_COVER_LIVE, $a->reasonCode);
        $this->assertSame(2, $a->decisive?->id, 'the part warranty is what the verdict rests on');
    }

    /** A live REPAIR warranty on the same fault is checked first — the cheapest cover we have. */
    public function test_a_live_repair_warranty_on_the_same_fault_wins(): void
    {
        $repair = $this->warranty([
            'id' => 3, 'kind' => Warranty::KIND_REPAIR, 'maintenance_task_id' => 42,
            'starts_on' => now()->subMonths(2)->toDateString(), 'duration_months' => 6,
        ]);
        $vehicle = $this->warranty(['id' => 4, 'duration_months' => 60, 'covered_catalog_ids' => [7]]);

        $a = $this->decide([$vehicle, $repair], [], $this->subject(7, taskId: 42), 50000);

        $this->assertSame(WarrantyCoverage::COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_REPAIR_COVER_LIVE, $a->reasonCode);
    }

    // ── The human's answer outranks the engine ───────────────────────────────────────────────────

    /**
     * A RECORDED DECISION BEATS EVERY RULE, including one that contradicts it.
     *
     * Somebody read the actual contract; the engine has read a list of ids. If the recorded answer
     * is wrong it is corrected by recording a new one — never by the engine quietly overruling a
     * named human. This is also what stops the same question being put to the desk twice.
     */
    public function test_a_recorded_decision_overrules_the_rules(): void
    {
        // The rules alone would say COVERED here.
        $w = $this->warranty(['id' => 1, 'duration_months' => 60, 'covered_catalog_ids' => [7]]);

        $decided = (new WarrantyClaim())->forceFill([
            'id' => 900, 'warranty_id' => 1, 'vehicle_id' => 1,
            'component_catalog_id' => 7,
            'stage' => WarrantyClaim::STAGE_NOT_COVERED,
            'coverage_verdict' => WarrantyCoverage::NOT_COVERED,
            'coverage_reason_code' => WarrantyCoverage::R_EXPLICITLY_EXCLUDED,
            'decided_by_name' => 'A Reviewer',
        ]);

        $a = $this->decide([$w], [$decided], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_DECIDED_BY_REVIEW, $a->reasonCode);
        $this->assertFalse($a->blocksProcurement(), 'a recorded "ours to pay" releases procurement');
    }

    /** An open review means the question is already somebody's — do not open a second one. */
    public function test_an_open_review_is_reused_rather_than_duplicated(): void
    {
        $w = $this->warranty(['id' => 1, 'duration_months' => 60]);

        $open = (new WarrantyClaim())->forceFill([
            'id' => 901, 'warranty_id' => 1, 'vehicle_id' => 1,
            'component_catalog_id' => 7,
            'stage' => WarrantyClaim::STAGE_COVERAGE_REVIEW,
        ]);

        $a = $this->decide([$w], [$open], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::UNKNOWN, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_REVIEW_IN_PROGRESS, $a->reasonCode);
        $this->assertSame(901, $a->existingCase?->id);
    }

    /** A case already past review and running with the dealer keeps procurement shut. */
    public function test_a_case_in_flight_keeps_procurement_shut(): void
    {
        $w = $this->warranty(['id' => 1, 'duration_months' => 60]);

        $inFlight = (new WarrantyClaim())->forceFill([
            'id' => 902, 'warranty_id' => 1, 'vehicle_id' => 1,
            'component_catalog_id' => 7,
            'stage' => WarrantyClaim::STAGE_SENT_TO_PROVIDER,
        ]);

        $a = $this->decide([$w], [$inFlight], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::COVERED, $a->verdict);
        $this->assertTrue($a->blocksProcurement());
    }

    /**
     * A subject nobody identified cannot be decided from data — and the engine says so instead of
     * matching on the wording. If this ever starts returning COVERED from a part NAME, the "no
     * inference" property is gone.
     */
    public function test_an_unidentified_part_is_never_decided_from_its_wording(): void
    {
        $w = $this->warranty(['duration_months' => 60, 'covered_catalog_ids' => [7]]);

        $a = $this->decide([$w], [], new CoverageSubject(catalogId: null, partName: 'Transmission'), 50000);

        $this->assertSame(WarrantyCoverage::UNKNOWN, $a->verdict, 'wording is shown to a human, never matched');
    }

    /** An unjudgeable distance leg is UNKNOWN, and says the missing thing is a reading. */
    public function test_unknown_odometer_yields_unknown_not_covered(): void
    {
        $w = $this->warranty([
            'duration_months' => 60, 'start_odometer' => 0, 'duration_km' => 100000,
            'covered_catalog_ids' => [11],
        ]);

        $a = $this->decide([$w], [], $this->subject(7), null);

        $this->assertSame(WarrantyCoverage::UNKNOWN, $a->verdict);
        $this->assertSame(WarrantyCoverage::R_ODOMETER_UNKNOWN, $a->reasonCode);
    }

    /** A voided promise protects nothing, however much of its window is left. */
    public function test_a_voided_warranty_covers_nothing(): void
    {
        $void = $this->warranty([
            'duration_months' => 60, 'status' => Warranty::STATUS_VOID,
            'void_reason' => 'Unauthorised repair', 'covered_catalog_ids' => [7],
        ]);

        $a = $this->decide([$void], [], $this->subject(7), 50000);

        $this->assertSame(WarrantyCoverage::NOT_COVERED, $a->verdict);
        $this->assertFalse($a->blocksProcurement());
    }

    /** The blocking set is COVERED and UNKNOWN — the single definition, asserted once. */
    public function test_the_blocking_set_is_covered_and_unknown(): void
    {
        $this->assertTrue(WarrantyCoverage::blocks(WarrantyCoverage::COVERED));
        $this->assertTrue(WarrantyCoverage::blocks(WarrantyCoverage::UNKNOWN));
        $this->assertFalse(WarrantyCoverage::blocks(WarrantyCoverage::NOT_COVERED));
        $this->assertFalse(WarrantyCoverage::blocks(null));
    }
}
