<?php

namespace Tests\Unit;

use App\Services\Knowledge\ConfidenceScorer;
use App\Services\Knowledge\RepairCohortStats;
use App\Services\Knowledge\RepairHistoryQueryService;
use App\Services\Knowledge\RepairRecommendationService;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure retrieval helpers (tier assignment, display bucketing) and the recurrence
 * risk band. The Eloquent scan itself is covered by feature tests; these lock the tier logic + ranking.
 */
class RepairRetrievalTest extends TestCase
{
    // ── assignTier ────────────────────────────────────────────────────────────────────────────────

    public function test_same_vehicle_is_tier_1(): void
    {
        $this->assertSame(1, RepairHistoryQueryService::assignTier(7, 'Jetour', 'T2', 7, 'Jetour', 'T2'));
    }

    public function test_same_model_is_tier_2(): void
    {
        $this->assertSame(2, RepairHistoryQueryService::assignTier(7, 'Jetour', 'T2', 9, 'Jetour', 'T2'));
        // model match with make unknown on one side still counts as model tier
        $this->assertSame(2, RepairHistoryQueryService::assignTier(7, null, 'T2', 9, null, 'T2'));
    }

    public function test_same_make_different_model_is_tier_3(): void
    {
        $this->assertSame(3, RepairHistoryQueryService::assignTier(7, 'Jetour', 'T2', 9, 'Jetour', 'X70'));
    }

    public function test_coincidental_model_name_different_make_is_not_model_tier(): void
    {
        // Same model STRING but a different manufacturer → not tier 2, and makes differ → fleet.
        $this->assertSame(4, RepairHistoryQueryService::assignTier(7, 'Jetour', 'T2', 9, 'Toyota', 'T2'));
    }

    public function test_unrelated_is_tier_4(): void
    {
        $this->assertSame(4, RepairHistoryQueryService::assignTier(7, 'Jetour', 'T2', 9, 'Nissan', 'Patrol'));
    }

    // ── bucketize ─────────────────────────────────────────────────────────────────────────────────

    public function test_bucketize_groups_sorts_and_caps(): void
    {
        $flat = [
            ['tier' => 1, 'resolved_at' => '2026-01-01'],
            ['tier' => 1, 'resolved_at' => '2026-03-01'],
            ['tier' => 2, 'resolved_at' => '2025-12-01'],
            ['tier' => 4, 'resolved_at' => '2026-02-01'],
        ];
        $b = RepairHistoryQueryService::bucketize($flat, 10);

        $this->assertCount(2, $b['vehicle']);
        $this->assertSame('2026-03-01', $b['vehicle'][0]['resolved_at']);  // recency desc
        $this->assertCount(1, $b['model']);
        $this->assertCount(0, $b['make']);
        $this->assertCount(1, $b['fleet']);
    }

    public function test_bucketize_respects_per_tier_limit(): void
    {
        $flat = array_map(fn ($i) => ['tier' => 1, 'resolved_at' => "2026-01-" . str_pad((string) $i, 2, '0', STR_PAD_LEFT)], range(1, 9));
        $b = RepairHistoryQueryService::bucketize($flat, 3);
        $this->assertCount(3, $b['vehicle']);
        $this->assertSame('2026-01-09', $b['vehicle'][0]['resolved_at']);  // newest kept
    }

    // ── recurrence risk band ──────────────────────────────────────────────────────────────────────

    public function test_recurrence_risk_bands(): void
    {
        $svc = new RepairRecommendationService(
            new RepairHistoryQueryService(), new RepairCohortStats(), new ConfidenceScorer()
        );
        $this->assertSame('low', $svc->recurrenceRisk(0.0));
        $this->assertSame('low', $svc->recurrenceRisk(0.09));
        $this->assertSame('medium', $svc->recurrenceRisk(0.2));
        $this->assertSame('high', $svc->recurrenceRisk(0.5));
    }
}
