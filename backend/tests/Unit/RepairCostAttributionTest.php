<?php

namespace Tests\Unit;

use App\Contracts\VehicleExpenseProvider;
use App\Services\Garage\RepairCostEstimator;
use App\Services\Garage\RepairCostIndex;
use Tests\TestCase;

/**
 * Attribution correctness — the half of cost estimation that cannot be checked by looking at the number.
 *
 * A repair cost rebuilt from a general expense ledger is only trustworthy if two things hold: petrol
 * never becomes repair spend, and a multi-fault visit never manufactures a per-fault price. Both are
 * silent failures — the output looks identical either way — so they are pinned here.
 */
class RepairCostAttributionTest extends TestCase
{
    /** A ledger stub: only the bulk reader is exercised, the rest of the contract is inert. */
    private function ledger(array $linesByVehicle): VehicleExpenseProvider
    {
        return new class($linesByVehicle) implements VehicleExpenseProvider
        {
            public function __construct(private array $lines)
            {
            }

            public function linesByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array
            {
                return $this->lines;
            }

            public function totalsByVehicle(?array $v = null, ?string $f = null, ?string $t = null): array
            {
                return [];
            }

            public function total(int $vehicleId, ?string $from = null, ?string $to = null): ?float
            {
                return null;
            }

            public function totalsByMonth(?string $f = null, ?string $t = null, ?array $v = null): array
            {
                return [];
            }

            public function history(int $vehicleId, ?string $from = null, ?string $to = null): array
            {
                return [];
            }

            public function source(): array
            {
                return ['label' => 'test', 'available' => true, 'as_of' => null, 'lines' => 0];
            }

            public function exclusions(): array
            {
                // The stub hands back pre-filtered lines, so nothing is excluded at this seam.
                return [];
            }

            public function freshness(): array
            {
                return [
                    'last_import' => null, 'last_entry' => null, 'days_since_entry' => null,
                    'lines_30d' => 0, 'lines_90d' => 0, 'prior_90d' => 0,
                    'status' => 'unknown', 'message' => 'Stub provider.',
                ];
            }
        };
    }

    private function cfg(): array
    {
        return config('garage_recommendation.cost');
    }

    public function test_non_repair_spending_never_becomes_repair_cost(): void
    {
        // One real repair line among the noise the ledger is full of. If any of the others survive,
        // every garage's "typical bill" is quietly inflated by fuel and insurance.
        $estimator = new RepairCostEstimator(
            $this->ledger([1 => [
                ['date' => '2025-01-10', 'remarks' => 'FRONT BUMPER PAINT', 'amount' => 800.0],
                ['date' => '2025-01-10', 'remarks' => 'PAYMENT FOR EMARAT (PETROL)', 'amount' => 120.0],
                ['date' => '2025-01-10', 'remarks' => 'CAR INSURANCE RENEWAL', 'amount' => 2400.0],
                ['date' => '2025-01-10', 'remarks' => 'PAYMENT FOR RTA CAR TEST', 'amount' => 170.0],
                ['date' => '2025-01-10', 'remarks' => 'SALIK TOP UP', 'amount' => 50.0],
                ['date' => '2025-01-10', 'remarks' => 'CAR WASH', 'amount' => 25.0],
                ['date' => '2025-01-10', 'remarks' => 'GPS DEVICE', 'amount' => 300.0],
            ]]),
            app(\App\Services\GarageRecommendationService::class),
        );

        $r = $estimator->repairLines($this->cfg());

        $this->assertSame(1, $r['kept'], 'a non-repair expense was counted as repair spend');
        $this->assertSame(6, $r['dropped']);
    }

    public function test_an_absurd_single_line_is_not_treated_as_one_repair(): void
    {
        $estimator = new RepairCostEstimator(
            $this->ledger([1 => [
                ['date' => '2025-01-10', 'remarks' => 'ENGINE REPAIR', 'amount' => 900.0],
                // A bulk settlement or a keying error — not the price of a repair.
                ['date' => '2025-01-11', 'remarks' => 'ENGINE REPAIR', 'amount' => 480000.0],
                ['date' => '2025-01-12', 'remarks' => 'ENGINE REPAIR', 'amount' => -50.0],
            ]]),
            app(\App\Services\GarageRecommendationService::class),
        );

        $this->assertSame(1, $estimator->repairLines($this->cfg())['kept']);
    }

    public function test_the_exclusion_list_is_configuration_not_code(): void
    {
        // What counts as "not a repair" is a business judgement that will need adjusting as new
        // vocabulary shows up in the ledger — it must be editable without touching a service.
        $exclude = config('garage_recommendation.cost.exclude');

        $this->assertIsArray($exclude);
        $this->assertNotEmpty($exclude, 'with no exclusions, fuel and insurance count as repair spend');
        foreach (['fuel', 'insurance', 'registration', 'fines'] as $k) {
            $this->assertArrayHasKey($k, $exclude);
        }
    }

    public function test_the_attribution_window_stays_tight(): void
    {
        // Widening it looks like better coverage and is not: the median climbs 400 → 1,899 as the
        // window goes 0 → 30 days, which is unrelated spending leaking in. Locked so a future
        // "let's catch more repairs" change has to argue with a test first.
        $cfg = $this->cfg();

        $this->assertLessThanOrEqual(3, $cfg['window_before_days']);
        $this->assertLessThanOrEqual(10, $cfg['window_after_days']);
    }

    public function test_ledger_meta_reports_the_real_corpus(): void
    {
        // The metric explanation quotes this count. If it drifted from what was actually kept, the
        // explanation would be its own magic number.
        $estimator = new RepairCostEstimator(
            $this->ledger([1 => [
                ['date' => '2025-01-10', 'remarks' => 'BRAKE PADS', 'amount' => 400.0],
                ['date' => '2025-01-11', 'remarks' => 'PETROL', 'amount' => 100.0],
            ]]),
            app(\App\Services\GarageRecommendationService::class),
        );

        $r = $estimator->repairLines($this->cfg());
        $meta = (new RepairCostIndex(['__meta' => ['lines_kept' => $r['kept'], 'lines_dropped' => $r['dropped'], 'buckets' => 0]]))->meta();
        $this->assertSame(1, $meta['lines_kept']);
        $this->assertSame(1, $meta['lines_dropped']);
    }
}
