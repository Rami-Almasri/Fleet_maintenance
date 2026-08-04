<?php

namespace Tests\Unit\Intelligence;

use App\Intelligence\Coverage;
use App\Kpi\Kpi;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The Kpi extension is ADDITIVE, and this file is the proof.
 *
 * Four consumers already depend on this object (KpiSnapshot, GarageOutcomeForecaster,
 * GarageScorecardService, MaintenanceDeletionLifecycleTest). The backward-compatibility tests below
 * are what let the Fleet Intelligence work proceed without a coordinated change to any of them.
 */
class KpiTest extends TestCase
{
    // ── Backward compatibility ──────────────────────────────────────────────────────────────────

    public function test_the_original_nine_keys_are_still_present_and_unchanged(): void
    {
        $array = Kpi::measured('k', 'Label', 12.345, 'percent', 100)->toArray();

        foreach (['key', 'label', 'value', 'unit', 'sample_size', 'direction', 'available', 'blocked_reason', 'context'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }

        $this->assertSame('k', $array['key']);
        $this->assertSame(12.35, $array['value'], 'still rounded to 2dp');
        $this->assertSame(100, $array['sample_size']);
        $this->assertTrue($array['available']);
        $this->assertNull($array['blocked_reason']);
        $this->assertSame([], $array['context']);
    }

    public function test_existing_factories_still_work_with_their_original_arguments(): void
    {
        $measured = Kpi::measured('a', 'A', 50.0, 'percent', 40, Kpi::LOWER_BETTER, ['note' => 'x']);
        $this->assertSame(Kpi::LOWER_BETTER, $measured->direction);
        $this->assertSame(['note' => 'x'], $measured->context);

        $unavailable = Kpi::unavailable('b', 'B', 'no data yet');
        $this->assertFalse($unavailable->available);
        $this->assertSame('no data yet', $unavailable->blockedReason);
        $this->assertNull($unavailable->value);
        $this->assertSame(0, $unavailable->sampleSize);

        $insufficient = Kpi::insufficient('c', 'C', 12, 30);
        $this->assertFalse($insufficient->available);
        $this->assertSame(12, $insufficient->sampleSize);
        $this->assertStringContainsString('12', $insufficient->blockedReason);
        $this->assertStringContainsString('30', $insufficient->blockedReason);
    }

    public function test_defaults_keep_untouched_kpis_verified(): void
    {
        $kpi = Kpi::measured('k', 'K', 1.0, 'count', 100);

        $this->assertSame(Kpi::CONFIDENCE_VERIFIED, $kpi->confidence);
        $this->assertNull($kpi->coverage);
        $this->assertNull($kpi->asOf);
        $this->assertNull($kpi->evidenceQueryId);
    }

    // ── The additions ───────────────────────────────────────────────────────────────────────────

    public function test_the_four_new_keys_are_appended(): void
    {
        $kpi = Kpi::measured(
            'k', 'K', 2.5, 'days', 6881,
            Kpi::LOWER_BETTER, [],
            new Coverage(6881, 26942),
            new DateTimeImmutable('2026-03-31'),
            'garage.recurrences',
        );

        $array = $kpi->toArray();

        $this->assertSame(25.5, $array['coverage']['percent']);
        $this->assertSame(20061, $array['coverage']['missing']);
        $this->assertSame('2026-03-31', $array['as_of']);
        $this->assertSame('garage.recurrences', $array['evidence_query_id']);
        $this->assertArrayHasKey('confidence', $array);
    }

    public function test_partial_coverage_downgrades_confidence_automatically(): void
    {
        // The real case: duration is measured on the 25.7% of tickets that record a return date.
        $kpi = Kpi::measured('d', 'Duration', 2.5, 'days', 6881, Kpi::LOWER_BETTER, [], new Coverage(6881, 26942));

        $this->assertSame(Kpi::CONFIDENCE_PARTIAL, $kpi->confidence);
    }

    public function test_complete_coverage_stays_verified(): void
    {
        $kpi = Kpi::measured('v', 'Vehicles', 438.0, 'count', 438, Kpi::NEUTRAL, [], Coverage::complete(438));

        $this->assertSame(Kpi::CONFIDENCE_VERIFIED, $kpi->confidence);
    }

    public function test_stale_data_downgrades_confidence_even_when_coverage_is_complete(): void
    {
        // The expense ledger stops at 2026-03-31; a complete count of stale rows is still stale.
        $kpi = Kpi::measured(
            's', 'Spend', 6_200_000.0, 'currency', 13285,
            Kpi::LOWER_BETTER, [], Coverage::complete(13285), new DateTimeImmutable('2020-01-01'),
        );

        $this->assertSame(Kpi::CONFIDENCE_PARTIAL, $kpi->confidence);
    }

    public function test_estimated_is_a_type_not_a_label(): void
    {
        // G17 rework cost: recurrences × fleet-average category cost, because expenses carry no garage.
        $kpi = Kpi::estimated('rework', 'Cost of rework', 120000.0, 'currency', 473, Kpi::LOWER_BETTER);

        $this->assertSame(Kpi::CONFIDENCE_ESTIMATED, $kpi->confidence);
        $this->assertTrue($kpi->available);
        $this->assertSame(120000.0, $kpi->value);
    }

    public function test_estimated_stays_estimated_even_with_complete_coverage(): void
    {
        $kpi = Kpi::estimated('r', 'R', 1.0, 'currency', 100, Kpi::NEUTRAL, [], Coverage::complete(100));

        $this->assertSame(Kpi::CONFIDENCE_ESTIMATED, $kpi->confidence);
    }

    // ── Coverage ────────────────────────────────────────────────────────────────────────────────

    public function test_coverage_reports_what_the_metric_could_not_see(): void
    {
        $coverage = new Coverage(1259, 6300);

        $this->assertSame(20.0, $coverage->percent());
        $this->assertSame(5041, $coverage->missing());
        $this->assertFalse($coverage->isComplete());
    }

    public function test_coverage_of_an_empty_population_is_zero_not_a_division_error(): void
    {
        $coverage = new Coverage(0, 0);

        $this->assertSame(0.0, $coverage->percent());
        $this->assertSame(0, $coverage->missing());
        $this->assertFalse($coverage->isComplete());
    }
}
