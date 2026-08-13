<?php

namespace Tests\Unit;

use App\Support\FaultPhrase;
use PHPUnit\Framework\TestCase;

/**
 * The fault sentence — "2 scratches — rims and body".
 *
 * DB-free (like BookingReadinessWorkingDaysTest): this is the pure presentation rule that every
 * surface renders through, so it is locked here rather than re-asserted per screen. What is being
 * defended is that the structured facts (type + quantity + locations) are the source, the sentence
 * is only ever WRITTEN from them, and nothing about it is specific to any one fault type.
 */
class FaultPhraseTest extends TestCase
{
    // ── The shape the feature exists for ─────────────────────────────────────────────────────────

    public function test_one_occurrence_one_location(): void
    {
        $this->assertSame('Scratch — front bumper', FaultPhrase::render('Scratch', 1, ['front bumper']));
    }

    public function test_two_occurrences_two_locations(): void
    {
        $this->assertSame('2 scratches — rims and body', FaultPhrase::render('Scratch', 2, ['rims', 'body']));
    }

    public function test_three_locations_use_an_oxford_free_list(): void
    {
        $this->assertSame(
            '3 scratches — front bumper, hood and left rear door',
            FaultPhrase::render('Scratch', 3, ['front bumper', 'hood', 'left rear door']),
        );
    }

    /**
     * THE POINT OF THE WHOLE DESIGN: nothing above is about scratches. The same call renders every
     * other fault type, including ones that do not exist yet.
     */
    public function test_the_same_rule_renders_every_other_type(): void
    {
        $this->assertSame('Dent — right front door', FaultPhrase::render('Dent', 1, ['right front door']));
        $this->assertSame('2 dents — rear bumper and left rear door', FaultPhrase::render('Dent', 2, ['rear bumper', 'left rear door']));
        $this->assertSame('Crack — windshield', FaultPhrase::render('Crack', 1, ['windshield']));
        $this->assertSame('2 tyre damages — front left and rear right', FaultPhrase::render('Tyre damage', 2, ['front left', 'rear right']));
        $this->assertSame('Broken mirror — left mirror', FaultPhrase::render('Broken mirror', 1, ['left mirror']));
        $this->assertSame('2 leaks — engine bay and underbody', FaultPhrase::render('Leak', 2, ['engine bay', 'underbody']));
    }

    // ── Degrading to what the system said before ──────────────────────────────────────────────────

    /**
     * A fault with neither a count nor a place — every record that existed before this feature —
     * renders as its plain type, byte-for-byte what those screens printed already.
     */
    public function test_a_fault_with_no_quantity_and_no_location_is_unchanged(): void
    {
        $this->assertSame('Scratch', FaultPhrase::render('Scratch'));
        $this->assertSame('Overheating', FaultPhrase::render('Overheating', 1, []));
    }

    public function test_a_count_without_a_place_still_counts(): void
    {
        $this->assertSame('2 scratches', FaultPhrase::render('Scratch', 2));
    }

    public function test_quantity_is_floored_at_one(): void
    {
        $this->assertSame('Scratch', FaultPhrase::render('Scratch', 0));
        $this->assertSame('Scratch', FaultPhrase::render('Scratch', -3));
    }

    public function test_blank_locations_are_dropped_not_printed_as_gaps(): void
    {
        $this->assertSame('2 scratches — rims', FaultPhrase::render('Scratch', 2, ['rims', '', '   ']));
    }

    // ── Pluralisation: correct, or unmistakable ───────────────────────────────────────────────────

    /**
     * A curated fault name is a human phrase, not a noun. Anything the simple rule cannot inflect
     * takes the `×` form rather than emitting "2 Rough idle / misfires", which reads as a bug.
     */
    public function test_unpluralisable_labels_fall_back_to_the_times_form(): void
    {
        $this->assertSame('2 × Rough idle / misfire', FaultPhrase::render('Rough idle / misfire', 2));
        $this->assertSame('2 × Wiper / washer fault', FaultPhrase::render('Wiper / washer fault', 2));
        $this->assertSame('2 × Brake noise (squeal / grind)', FaultPhrase::render('Brake noise (squeal / grind)', 2));
    }

    public function test_mass_nouns_are_never_pluralised(): void
    {
        $this->assertSame('2 × Rust', FaultPhrase::render('Rust', 2));
        $this->assertSame('2 × Corrosion', FaultPhrase::render('Corrosion', 2));
    }

    public function test_a_singular_reading_never_inflects(): void
    {
        $this->assertSame('Rough idle / misfire', FaultPhrase::render('Rough idle / misfire', 1));
    }

    // ── Arabic ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Arabic has no productive plural rule for an arbitrary noun, so it renders count + singular and
     * joins with "و" — see the class docblock. What is asserted here is that it is NOT English.
     */
    public function test_arabic_uses_count_plus_singular_and_the_arabic_joiner(): void
    {
        $this->assertSame('2 خدش — الجنوط والهيكل', FaultPhrase::render('خدش', 2, ['الجنوط', 'الهيكل'], 'ar'));
        $this->assertSame('خدش — الجنوط', FaultPhrase::render('خدش', 1, ['الجنوط'], 'ar'));
    }

    public function test_arabic_three_item_list_uses_the_arabic_comma(): void
    {
        $this->assertSame(
            '3 خدش — الصدام الأمامي، غطاء المحرك والسقف',
            FaultPhrase::render('خدش', 3, ['الصدام الأمامي', 'غطاء المحرك', 'السقف'], 'ar'),
        );
    }

    // ── Edges ─────────────────────────────────────────────────────────────────────────────────────

    public function test_a_missing_type_never_produces_a_leading_dash(): void
    {
        $this->assertSame('rims and body', FaultPhrase::render('', 2, ['rims', 'body']));
        $this->assertSame('', FaultPhrase::render('', 1, []));
    }

    public function test_from_parts_is_the_same_rule(): void
    {
        $this->assertSame(
            '2 scratches — rims and body',
            FaultPhrase::fromParts(['type' => 'Scratch', 'quantity' => 2, 'locations' => ['rims', 'body']]),
        );
        // A parts array carrying nothing but a type is the legacy record, and reads as it always did.
        $this->assertSame('Scratch', FaultPhrase::fromParts(['type' => 'Scratch']));
    }

    public function test_join_list_alone(): void
    {
        $this->assertSame('', FaultPhrase::joinList([]));
        $this->assertSame('a', FaultPhrase::joinList(['a']));
        $this->assertSame('a and b', FaultPhrase::joinList(['a', 'b']));
        $this->assertSame('a, b and c', FaultPhrase::joinList(['a', 'b', 'c']));
    }
}
