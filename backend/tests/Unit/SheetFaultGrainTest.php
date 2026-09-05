<?php

namespace Tests\Unit;

use App\Support\FaultVocabulary;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE GRAIN RULE, and the sheet→catalog bridge that sits on top of it.
 *
 * The N-Maintenance log writes one job across two columns of very different specificity: `service_main`
 * is a closed set of 19 tokens, 14 of them bare system words ("Engine", "Electrical", "Tires"), while
 * `service_sup` carries the 92 actual findings ("Battery Weak or Dead", "Engine Oil leak").
 *
 * Reading both as one flat list — which is correct for DISPLAY — was silently wrong for recurrence: the
 * bare word "Engine" types as a fault, resolves to the engine category, and survives on its own. So a
 * row whose only real content was "Oil & Fillter Change" was counted as an engine failure, and two oil
 * changes on one car read as "Engine broke again 2 separate times". Measured on the live corpus when
 * this was written: 4,452 MAIN tokens were bare category words and 2,640 of them had no fault of that
 * category anywhere in their own SUP text. Removing them cut the fleet's repeat-fault chains from 398
 * to 207 — i.e. roughly half of every "this keeps coming back" claim was an artefact of column grain.
 *
 * Each test below is one of those rulings. They are DB-free on purpose: this is vocabulary, it is
 * decided by config, and it must fail on the pull request rather than on the machine that seeds data.
 */
class SheetFaultGrainTest extends TestCase
{
    // ── The grain rule ──────────────────────────────────────────────────────────────────────────

    /**
     * THE BUG THIS EXISTS FOR. 675 rows in the live sheet read exactly like this.
     */
    #[Test]
    public function an_oil_change_under_the_engine_heading_is_not_an_engine_fault(): void
    {
        $found = FaultVocabulary::sheetFaultLabels('Engine', 'Oil & Fillter Change');

        $this->assertSame([], $found, 'the MAIN word is the header for a service, not a failure');
    }

    /**
     * The real shape: several systems on one row, each introducing its own SUP. Only the systems whose
     * SUP names a fault may survive — "Engine" here belongs to the oil change and "Body & Exterior" to
     * a scratch, so neither is a fault this car had.
     */
    #[Test]
    public function only_the_systems_whose_detail_names_a_fault_survive(): void
    {
        $found = FaultVocabulary::sheetFaultLabels(
            'Engine, Electrical, Body & Exterior',
            'Oil & Fillter Change, Dashboard Warning Lights, Minor Surface Scratch',
        );

        $labels = array_column($found, 'label');

        $this->assertContains('Dashboard Warning Lights', $labels);
        $this->assertNotContains('Engine', $labels, 'its detail was a service');
        $this->assertNotContains('Body & Exterior', $labels, 'its detail was cosmetic damage');
    }

    /**
     * The fallback that keeps genuine history: a visit that recorded nothing more specific still counts,
     * at the weaker CATEGORY grain, so a consumer can weight it or show it as thinner evidence.
     */
    #[Test]
    public function a_system_word_alone_still_counts_but_says_it_is_only_a_category(): void
    {
        $found = FaultVocabulary::sheetFaultLabels('Engine', null);

        $this->assertCount(1, $found);
        $this->assertSame('Engine', $found[0]['label']);
        $this->assertSame(FaultVocabulary::GRAIN_CATEGORY, $found[0]['grain']);
        $this->assertNull($found[0]['catalog_slug'], 'a system word names no catalog fault');
    }

    /** One visit, one event: the header must not be counted beside the finding it introduces. */
    #[Test]
    public function the_category_word_is_dropped_when_its_own_detail_covers_it(): void
    {
        $found = FaultVocabulary::sheetFaultLabels('Electrical', 'Battery Weak or Dead');

        $this->assertCount(1, $found);
        $this->assertSame('Battery Weak or Dead', $found[0]['label']);
        $this->assertSame(FaultVocabulary::GRAIN_SPECIFIC, $found[0]['grain']);
    }

    /**
     * THE FALSE-RECURRENCE GUARD, stated directly. A category-grain event is keyed by its category and a
     * specific finding is keyed by its own identity, so the two can never compare equal — which is what
     * stops every future Engine visit reading as a recurrence of every past engine issue.
     */
    #[Test]
    public function a_bare_system_word_can_never_be_the_same_fault_as_a_specific_finding(): void
    {
        $category = FaultVocabulary::sheetFaultLabels('Engine', null)[0];
        $specific = FaultVocabulary::sheetFaultLabels(null, 'Engine Oil leak')[0];

        $this->assertNotSame($category['key'], $specific['key']);
        $this->assertSame($category['category_key'], $specific['category_key'], 'same system, though');
    }

    // ── The sheet → catalog bridge ──────────────────────────────────────────────────────────────

    /**
     * The two vocabularies share no wording — only 4 of the sheet's 92 labels match a catalog name — so
     * the bridge is the ONLY thing that can recognise one fault across the two ledgers.
     */
    #[Test]
    public function the_sheet_and_the_catalog_name_one_fault_with_one_slug(): void
    {
        $pairs = [
            ['Battery Weak or Dead', "Battery / won't start", 'elec_battery_failure'],
            ['Brake Pad Wear',       'Worn pads / discs',     'brake_worn_pads'],
            ['AC Not Cooling',       'A/C not cooling',       'ac_not_cooling'],
            ['Gearbox Not Engaging', 'Cannot select gear',    'trans_cannot_select'],
        ];

        foreach ($pairs as [$sheet, $ticket, $slug]) {
            $this->assertSame($slug, FaultVocabulary::catalogSlugOf($sheet), "sheet wording: {$sheet}");
            $this->assertSame($slug, FaultVocabulary::catalogSlugOf($ticket), "ticket wording: {$ticket}");
        }
    }

    /**
     * Ambiguity is answered with null, not with a plausible guess. "Squeaking or Grinding Noise" is
     * brakes or suspension depending on the car, and claiming it is `brake_noise` would merge two
     * different faults on the strength of a hunch. It still gets a system, which is a weaker and
     * defensible claim.
     */
    #[Test]
    public function an_ambiguous_label_gets_a_system_but_never_a_catalog_identity(): void
    {
        $this->assertNull(FaultVocabulary::catalogSlugOf('Squeaking or Grinding Noise'));
        $this->assertSame('brakes', FaultVocabulary::resolveCategory('Squeaking or Grinding Noise')['key']);
    }

    /**
     * The bridge's other job: 26 sheet labels resolve to NO category under the keyword resolver, so each
     * became its own isolated bucket that could never join the ticket fault it names.
     */
    #[Test]
    public function labels_the_keyword_resolver_cannot_place_still_get_their_system(): void
    {
        $this->assertNull(FaultVocabulary::categoryOf('Thermostat Failure'), 'unplaceable by keyword');
        $this->assertSame('fluids', FaultVocabulary::resolveCategory('Thermostat Failure')['key']);

        $this->assertSame('lights', FaultVocabulary::resolveCategory('Headlights / Taillights Fault')['key']);
        $this->assertSame('safety', FaultVocabulary::resolveCategory('Seatbelt Malfunction')['key']);
        $this->assertSame(
            FaultVocabulary::resolveCategory('Seatbelts')['key'],
            FaultVocabulary::resolveCategory('Seatbelt Malfunction')['key'],
            'one fault, not two buckets',
        );
    }

    /** A typo in the bridge must not mint an identity that matches nothing. */
    #[Test]
    public function every_bridged_slug_exists_in_the_fault_catalog(): void
    {
        $slugs = array_column(config('fault_catalog', []), 'slug');

        foreach (config('sheet_fault_bridge.catalog', []) as $label => $slug) {
            $this->assertContains($slug, $slugs, "bridge row '{$label}' points at an unknown slug");
        }
    }

    /** Same, for the category fallback map. */
    #[Test]
    public function every_bridged_category_is_a_real_category(): void
    {
        $keys = array_column(config('maintenance_findings.categories', []), 'key');

        foreach (config('sheet_fault_bridge.category', []) as $label => $key) {
            $this->assertContains($key, $keys, "bridge row '{$label}' points at an unknown category");
        }
    }
}
