<?php

namespace Tests\Unit;

use App\Support\SheetValue;
use Tests\TestCase;

/**
 * A spreadsheet cell is not a value until it has been proved to be one.
 *
 * The fleet's Daily Warranty Report carries 37 `#VALUE!` cells across 22 cars. The danger is not
 * that they crash anything — it is that they DON'T: `#VALUE!` is a non-empty string that survives
 * `trim()`, passes `!empty()`, and which `Carbon::parse()` happily turns into TODAY. An importer
 * without this guard would stamp today's date onto every car with a broken formula and the result
 * would look like clean data.
 *
 * In-memory, no database — the same idiom as the other pure-rule tests in this suite.
 */
class SheetValueTest extends TestCase
{
    /** Every error literal the sheet can contain is refused, in any casing or padding. */
    public function test_spreadsheet_errors_are_recognised(): void
    {
        foreach (['#VALUE!', '#REF!', '#N/A', '#DIV/0!', '#NAME?', '#NUM!', '#NULL!'] as $err) {
            $this->assertTrue(SheetValue::isError($err), "$err should be an error");
            $this->assertTrue(SheetValue::isError(" $err "), 'padding must not hide it');
            $this->assertTrue(SheetValue::isError(strtolower($err)), 'casing must not hide it');
        }

        $this->assertFalse(SheetValue::isError('50000'));
        $this->assertFalse(SheetValue::isError('ARABIAN AUTOMOBILES'));
    }

    /**
     * THE ONE THAT MATTERS: an error must never become a date.
     *
     * `Carbon::parse('#VALUE!')` returns today rather than throwing, so a naive importer would write
     * today's date on all 37 broken cells and nobody would ever see it.
     */
    public function test_an_error_cell_never_becomes_a_date(): void
    {
        $this->assertNull(SheetValue::date('#VALUE!'));
        $this->assertNull(SheetValue::date('#REF!'));
        $this->assertNull(SheetValue::date(''));
        $this->assertNull(SheetValue::date(null));
        // Not a spreadsheet error, but equally not a date — the same sheet has this typed into a
        // date column on the ALI & SONS rows.
        $this->assertNull(SheetValue::date('NEW CONTRA ONLY'));
    }

    /**
     * Dates are read DAY-first, because the sheet writes 18/02/2028 and Carbon's loose parser would
     * read an ambiguous 03/10/2026 as March 10th — wrong by seven months on every row whose day is
     * 12 or under.
     */
    public function test_dates_are_read_day_first(): void
    {
        $this->assertSame('2028-02-18', SheetValue::date('18/02/2028'));
        // The ambiguous one: 10 March, not 3 October.
        $this->assertSame('2026-03-10', SheetValue::date('10/03/2026'));
        $this->assertSame('2026-03-10', SheetValue::date('2026-03-10'));
    }

    /** An error must never become a number — and `(int) "#VALUE!"` is 0, which reads as a real limit. */
    public function test_an_error_cell_never_becomes_a_number(): void
    {
        $this->assertNull(SheetValue::int('#VALUE!'));
        $this->assertNull(SheetValue::positiveInt('#VALUE!'));
        // "NEW CONTRA ONLY" would cast to 0 — and a 0 km limit reads as "cover ended at zero km".
        $this->assertNull(SheetValue::int('NEW CONTRA ONLY'));
        $this->assertNull(SheetValue::positiveInt('0'), 'zero is not a usable limit');
    }

    /** Thousands separators are formatting, not a different kind of number. */
    public function test_numbers_keep_their_value_through_formatting(): void
    {
        $this->assertSame(47408, SheetValue::int('47,408'));
        $this->assertSame(50000, SheetValue::int('50000'));
        // The report's "finish" columns go negative when a car is over its limit.
        $this->assertSame(-25167, SheetValue::int('-25,167'));
        $this->assertSame(50000, SheetValue::positiveInt('50,000'));
    }

    /** "5 Lube Service/5Yrs" → 5, and anything ambiguous → null rather than a guess. */
    public function test_the_service_count_is_read_only_when_unambiguous(): void
    {
        $this->assertSame(5, SheetValue::serviceCount('5 Lube Service/5Yrs'));
        $this->assertSame(5, SheetValue::serviceCount('5 SERVICE / 5 YRS'));
        // A period-capped contract names no count — inventing one would state a number nobody agreed.
        $this->assertNull(SheetValue::serviceCount('5 YEARS OR 75K KM SERVICE CONTRACT'));
        $this->assertNull(SheetValue::serviceCount('#VALUE!'));
        $this->assertNull(SheetValue::serviceCount(''));
    }

    /** "Service at every 10,000 kms and includes…" → 10000. */
    public function test_the_interval_is_read_from_the_note(): void
    {
        $this->assertSame(
            10000,
            SheetValue::intervalKm('• Service at every 10,000 kms and includes Engine oil, Oil filter, drain nut washer replacement, vehicle washing and 15 Points checkup. • 40% Discount'),
        );
        $this->assertNull(SheetValue::intervalKm('3YEARS UNLIMITED MILEAGE FACTORY WARRANTY'));
        $this->assertNull(SheetValue::intervalKm('#VALUE!'));
    }

    /** Blank and broken collapse to the same answer: we were not told. */
    public function test_blank_and_broken_both_read_as_missing(): void
    {
        foreach (['', '   ', null, '#VALUE!'] as $cell) {
            $this->assertNull(SheetValue::string($cell));
            $this->assertNull(SheetValue::int($cell));
            $this->assertNull(SheetValue::date($cell));
        }
    }
}
