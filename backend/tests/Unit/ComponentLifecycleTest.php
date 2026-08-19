<?php

namespace Tests\Unit;

use App\Services\Components\ComponentLifecycle;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Vehicle Installed Components — the pure lifecycle rules (no DB / no app boot).
 *
 * These lock the thresholds every component badge, card and count is computed from, and that the
 * frontend mirrors in VehicleComponentsPanel.js / ComponentsDashboard.js. If a threshold moves here,
 * it must move there too.
 */
class ComponentLifecycleTest extends TestCase
{
    /** A fixed "now" so the boundary cases below cannot drift with the calendar. */
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-08-01 10:00:00');
    }

    // ───────────────────────── warranty ─────────────────────────

    public function test_no_warranty_date_is_none_not_expired(): void
    {
        $w = ComponentLifecycle::warranty(null, null, $this->now);

        $this->assertSame('none', $w['status']);
        $this->assertNull($w['days_remaining']);
    }

    public function test_a_warranty_far_out_is_active(): void
    {
        $w = ComponentLifecycle::warranty($this->now->copy()->addDays(200), 12, $this->now);

        $this->assertSame('active', $w['status']);
        $this->assertSame(200, $w['days_remaining']);
        $this->assertSame(12, $w['months']);
    }

    public function test_a_past_warranty_is_expired_with_a_negative_day_count(): void
    {
        $w = ComponentLifecycle::warranty($this->now->copy()->subDays(5), 6, $this->now);

        $this->assertSame('expired', $w['status']);
        $this->assertSame(-5, $w['days_remaining']);
    }

    /** The 60-day window is inclusive on both edges — the card must not drop a part on day 60. */
    public function test_warranty_window_boundaries(): void
    {
        $this->assertSame('expiring_soon', ComponentLifecycle::warranty($this->now->copy()->addDays(60), 12, $this->now)['status']);
        $this->assertSame('active', ComponentLifecycle::warranty($this->now->copy()->addDays(61), 12, $this->now)['status']);
        $this->assertSame('expiring_soon', ComponentLifecycle::warranty($this->now->copy()->addDays(0), 12, $this->now)['status']);
    }

    // ───────────────────────── service life ─────────────────────────

    public function test_no_catalog_expectation_is_unknown_never_a_guess(): void
    {
        $life = ComponentLifecycle::serviceLife(null, null, 900, 80000);

        $this->assertSame('unknown', $life['status']);
        $this->assertNull($life['life_used_pct']);
        $this->assertNull($life['basis']);
    }

    public function test_distance_within_expectation_is_within(): void
    {
        $life = ComponentLifecycle::serviceLife(40000, null, 300, 10000);

        $this->assertSame('within', $life['status']);
        $this->assertSame(25, $life['life_used_pct']);
        $this->assertSame('distance', $life['basis']);
    }

    public function test_eighty_percent_is_the_due_soon_boundary(): void
    {
        $this->assertSame('within', ComponentLifecycle::serviceLife(40000, null, null, 31_600)['status']);   // 79%
        $this->assertSame('due_soon', ComponentLifecycle::serviceLife(40000, null, null, 32_000)['status']); // 80%
        $this->assertSame('overdue', ComponentLifecycle::serviceLife(40000, null, null, 40_000)['status']);  // 100%
    }

    /**
     * The core rule: a part is due when EITHER clock runs out, so the harsher of distance and age
     * decides. A barely-driven car must still be told its two-year-old filter is finished.
     */
    public function test_the_harsher_of_distance_and_age_wins(): void
    {
        // Hardly driven (5% of its km) but 2 years old against a 12-month expectation.
        $life = ComponentLifecycle::serviceLife(20000, 12, 730, 1000);

        $this->assertSame('overdue', $life['status']);
        $this->assertSame('age', $life['basis']);
        $this->assertGreaterThan(100, $life['life_used_pct']);
    }

    public function test_distance_wins_when_it_is_the_harsher_clock(): void
    {
        // Three months old (25% of a 12-month life) but already 30k km on a 20k expectation.
        $life = ComponentLifecycle::serviceLife(20000, 12, 91, 30000);

        $this->assertSame('overdue', $life['status']);
        $this->assertSame('distance', $life['basis']);
        $this->assertSame(150, $life['life_used_pct']);
    }

    public function test_a_missing_clock_does_not_suppress_the_other(): void
    {
        // No odometer reading yet, but the age expectation still applies.
        $life = ComponentLifecycle::serviceLife(20000, 12, 400, null);

        $this->assertSame('overdue', $life['status']);
        $this->assertSame('age', $life['basis']);
    }

    // ───────────────────────── which limit judges the part ─────────────────────────

    /**
     * The whole point of the snapshot: a part removed long ago must keep being judged against the
     * limit it was FITTED under, even after somebody edits the catalog on /parts-catalog.
     */
    public function test_the_limit_recorded_at_fitting_beats_the_catalog(): void
    {
        // Row says 12 mo / 20,000 km; the catalog has since been corrected down to 6 mo / 10,000.
        $limit = ComponentLifecycle::limitInForce(20000, 12, 10000, 6);

        $this->assertSame(20000, $limit['km']);
        $this->assertSame(12, $limit['months']);
        $this->assertSame('recorded', $limit['source']);
    }

    /**
     * The snapshot wins WHOLE, never field-by-field. A row that recorded a distance and no time
     * limit meant exactly that — inheriting the catalog's months would invent a clock nobody set
     * at fitting, and could flip the part to 'overdue' on it.
     */
    public function test_a_partial_snapshot_does_not_inherit_the_missing_half(): void
    {
        $limit = ComponentLifecycle::limitInForce(20000, null, 10000, 6);

        $this->assertSame(20000, $limit['km']);
        $this->assertNull($limit['months']);
        $this->assertSame('recorded', $limit['source']);

        // And the verdict must not be driven by a months clock that was never in force.
        $life = ComponentLifecycle::serviceLife($limit['km'], $limit['months'], 900, 1000, $limit['source']);
        $this->assertSame('within', $life['status']);
        $this->assertSame('distance', $life['basis']);
    }

    /** Rows written before the snapshot columns existed fall back — and SAY that they fell back. */
    public function test_rows_with_no_snapshot_fall_back_to_the_catalog_and_are_labelled(): void
    {
        $limit = ComponentLifecycle::limitInForce(null, null, 10000, 6);

        $this->assertSame(10000, $limit['km']);
        $this->assertSame(6, $limit['months']);
        $this->assertSame('catalog', $limit['source']);

        $life = ComponentLifecycle::serviceLife($limit['km'], $limit['months'], 30, 1000, $limit['source']);
        $this->assertSame('catalog', $life['limit_source']);
    }

    /** No expectation anywhere is 'none' — never a zero, which would read as "due immediately". */
    public function test_no_expectation_anywhere_is_none(): void
    {
        $limit = ComponentLifecycle::limitInForce(null, null, null, null);

        $this->assertNull($limit['km']);
        $this->assertNull($limit['months']);
        $this->assertSame('none', $limit['source']);

        // And it must still resolve to 'unknown' rather than a guessed verdict.
        $life = ComponentLifecycle::serviceLife($limit['km'], $limit['months'], 900, 80000, $limit['source']);
        $this->assertSame('unknown', $life['status']);
        $this->assertSame('none', $life['limit_source']);
    }

    // ───────────────────────── cost per km ─────────────────────────

    public function test_cost_per_km_is_null_for_a_part_that_has_not_moved(): void
    {
        // A part fitted this morning has zero distance; dividing would report an infinite cost/km.
        $this->assertNull(ComponentLifecycle::costPerKm(900.0, 0));
        $this->assertNull(ComponentLifecycle::costPerKm(900.0, null));
        $this->assertNull(ComponentLifecycle::costPerKm(null, 5000));
    }

    public function test_cost_per_km_divides_purchase_cost_by_distance_delivered(): void
    {
        $this->assertSame(0.05, ComponentLifecycle::costPerKm(2000.0, 40000));
    }
}
