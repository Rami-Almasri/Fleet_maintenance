<?php

namespace Tests\Unit;

use App\Support\PartSpecs;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The spec dictionary's contract. DB-free — this is pure vocabulary and formatting, and it is the
 * layer every other spec surface trusts.
 *
 * What is locked here is the reason the feature holds together: ONE stored form per fact, however
 * it was typed. A garage portal posting "5W-30", a form posting the option key, and an Arabic
 * keyboard posting "٦٠" must all land on the same value, or "how many cars run 5W-30" quietly
 * becomes three answers.
 *
 * The framework is booted (rather than this being a plain PHPUnit case) only because a rejected
 * value throws a ValidationException, which reaches for the translator. Nothing here touches the
 * database.
 */
class PartSpecsTest extends TestCase
{
    /** Two part types, hand-built so the test does not depend on the shipped catalog's contents. */
    private array $battery = ['spec_fields' => ['voltage', 'capacity_ah', 'terminal_layout', 'cca']];
    private array $oil     = ['spec_fields' => ['viscosity', 'oil_base', 'capacity_l']];
    private array $tyre    = ['spec_fields' => ['tyre_size']];

    protected function setUp(): void
    {
        parent::setUp();

        // Drop the memo so the dictionary is read fresh from config in each test.
        PartSpecs::flush();
    }

    protected function tearDown(): void
    {
        PartSpecs::flush();
        parent::tearDown();
    }

    public function test_a_part_type_declares_only_its_own_fields(): void
    {
        $this->assertSame(
            ['voltage', 'capacity_ah', 'cca', 'terminal_layout'],  // dictionary order, not declaration order
            array_keys(PartSpecs::fieldsFor($this->battery))
        );

        $this->assertSame([], PartSpecs::fieldsFor(['spec_fields' => []]));
        $this->assertFalse(PartSpecs::hasSpecs(null));
    }

    /** A field key retired from the dictionary must drop out, not reach a form as a dead input. */
    public function test_an_unknown_field_key_is_skipped_rather_than_thrown_on(): void
    {
        $fields = PartSpecs::fieldsFor(['spec_fields' => ['voltage', 'a_field_that_was_renamed']]);

        $this->assertSame(['voltage'], array_keys($fields));
    }

    /**
     * The core promise: however it was typed, it stores the same way.
     *
     * @dataProvider viscosityWordings
     */
    public function test_every_wording_of_a_value_stores_identically(string $typed): void
    {
        $this->assertSame(['viscosity' => '5w-30'], PartSpecs::validate($this->oil, ['viscosity' => $typed]));
    }

    public static function viscosityWordings(): array
    {
        return [
            'option key'  => ['5w-30'],
            'the label'   => ['5W-30'],
            'no dash'     => ['5W30'],
            'a slash'     => ['5W/30'],
            'with spaces' => [' 5w 30 '],
        ];
    }

    public function test_arabic_digits_and_typed_units_are_understood(): void
    {
        $stored = PartSpecs::validate($this->battery, [
            'voltage'     => '12V',
            'capacity_ah' => '٦٠',       // an Arabic keyboard
            'cca'         => '640 CCA',  // the unit typed into the number
        ]);

        $this->assertSame(['voltage' => '12v', 'capacity_ah' => '60', 'cca' => 640.0], $stored);
    }

    /**
     * Unanswered is ABSENT, never null or ''. "Nobody recorded the terminal side" and "the terminal
     * side is empty" are different facts and only absence states the first honestly.
     */
    public function test_a_blank_answer_is_absent_not_empty(): void
    {
        $stored = PartSpecs::validate($this->battery, [
            'voltage'         => '12v',
            'capacity_ah'     => '',
            'terminal_layout' => null,
            'cca'             => '   ',
        ]);

        $this->assertSame(['voltage' => '12v'], $stored);
        $this->assertArrayNotHasKey('capacity_ah', $stored);
    }

    /** A field this part type does not carry is dropped; a bad VALUE is a loud 422. */
    public function test_a_foreign_field_is_dropped_but_a_bad_value_is_refused(): void
    {
        $this->assertSame(
            ['viscosity' => '5w-30'],
            PartSpecs::validate($this->oil, ['viscosity' => '5w-30', 'tyre_size' => '225/65R17'])
        );

        $this->expectException(ValidationException::class);
        PartSpecs::validate($this->oil, ['viscosity' => 'banana']);
    }

    public function test_a_number_outside_its_range_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        PartSpecs::validate($this->oil, ['capacity_l' => 900]);
    }

    public function test_tyre_sizes_normalise_to_one_written_form(): void
    {
        foreach (['225/65R17', '225 65 r17', '225-65-17', '225/65 R17'] as $typed) {
            $this->assertSame(
                ['tyre_size' => '225/65R17'],
                PartSpecs::validate($this->tyre, ['tyre_size' => $typed]),
                "failed on: $typed"
            );
        }

        // An off-road size does not match the three-two-two shape and must survive UNMANGLED —
        // storing it unevenly beats rewriting it into something the tyre does not say.
        $this->assertSame(
            ['tyre_size' => '33X12.50R15'],
            PartSpecs::validate($this->tyre, ['tyre_size' => '33x12.50R15'])
        );
    }

    // ── Rendering ───────────────────────────────────────────────────────────────────────────────

    public function test_the_one_line_reads_in_the_order_the_part_is_spoken_about(): void
    {
        $specs = ['capacity_ah' => '60', 'voltage' => '12v', 'cca' => 640];

        // voltage first even though capacity was listed first — summary_order, not input order.
        // cca is absent because it is not a summary field, though it IS stored.
        $this->assertSame('12V · 60 Ah', PartSpecs::summary($this->battery, $specs));
    }

    public function test_an_unspecced_part_summarises_to_nothing_at_all(): void
    {
        // '' rather than a placeholder, so a caller can render it inline without a stray separator.
        $this->assertSame('', PartSpecs::summary($this->battery, null));
        $this->assertSame('', PartSpecs::summary($this->battery, []));
        $this->assertSame('', PartSpecs::summary(null, ['voltage' => '12v']));
    }

    public function test_numbers_drop_trailing_zeros_because_they_read_as_false_precision(): void
    {
        $this->assertSame('4.5 L', PartSpecs::summary($this->oil, ['capacity_l' => 4.50]));
        $this->assertSame('4 L', PartSpecs::summary($this->oil, ['capacity_l' => 4.00]));
    }

    /** A value stored before its option was retired still shows, as its raw key. */
    public function test_a_retired_option_renders_as_its_key_rather_than_disappearing(): void
    {
        $field = PartSpecs::field('viscosity');

        $this->assertSame('0w-8', PartSpecs::renderValue($field, '0w-8'));
    }

    public function test_the_detail_list_names_what_was_not_recorded(): void
    {
        $detail = collect(PartSpecs::describe($this->battery, ['voltage' => '12v']))->keyBy('key');

        $this->assertSame('12V', $detail['voltage']['value']);
        $this->assertTrue($detail['voltage']['recorded']);

        // The gap is REPORTED, not hidden — it is the prompt to go and look at the part.
        $this->assertNull($detail['terminal_layout']['value']);
        $this->assertFalse($detail['terminal_layout']['recorded']);
        $this->assertTrue($detail['terminal_layout']['critical']);
    }

    // ── The mismatch rule ───────────────────────────────────────────────────────────────────────

    public function test_only_critical_fields_raise_a_mismatch(): void
    {
        $expected = ['viscosity' => '5w-30', 'oil_base' => 'full_synthetic'];
        $actual   = ['viscosity' => '5w-30', 'oil_base' => 'mineral'];

        // Wrong base oil costs money; wrong viscosity damages the engine. Only the second is a banner.
        $this->assertSame([], PartSpecs::conflicts($this->oil, $expected, $actual));

        $conflicts = PartSpecs::conflicts($this->oil, $expected, ['viscosity' => '20w-50']);
        $this->assertCount(1, $conflicts);
        $this->assertSame('viscosity', $conflicts[0]['key']);
        $this->assertSame('5W-30', $conflicts[0]['expected']);
        $this->assertSame('20W-50', $conflicts[0]['actual']);
    }

    /**
     * Absence NEVER raises a conflict. "We don't know what this car takes" is not evidence that the
     * part is wrong, and a warning raised on missing data teaches people to dismiss warnings.
     */
    public function test_missing_knowledge_on_either_side_raises_nothing(): void
    {
        $this->assertSame([], PartSpecs::conflicts($this->oil, null, ['viscosity' => '20w-50']));
        $this->assertSame([], PartSpecs::conflicts($this->oil, ['viscosity' => '5w-30'], null));
        $this->assertSame([], PartSpecs::conflicts($this->oil, ['oil_base' => 'mineral'], ['viscosity' => '20w-50']));
    }

    public function test_the_same_value_typed_two_ways_is_not_a_mismatch(): void
    {
        $this->assertSame(
            [],
            PartSpecs::conflicts($this->oil, ['viscosity' => '5w-30'], ['viscosity' => '5W-30'])
        );
    }
}
