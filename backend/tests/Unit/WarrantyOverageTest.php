<?php

namespace Tests\Unit;

use App\Models\ServiceContract;
use App\Models\Warranty;
use Tests\TestCase;

/**
 * How much cover is left — and, once it is gone, how far past the limit the car already is.
 *
 * ── WHY THE OVERAGE IS ITS OWN NUMBER ───────────────────────────────────────────────────────────
 *
 * The fleet's Daily Warranty Report prints it as a negative: a Patrol reading 75,167 km against a
 * 50,000 km limit shows `-25,167`. "Expired" on its own cannot distinguish that car from one that
 * went over two years ago, and the first is still worth a phone call to the dealer.
 *
 * It is reported as a POSITIVE magnitude under its own key rather than as a negative
 * `km_remaining`, so no caller can render "-25,167 km remaining" or add the two together.
 *
 * THE LIMIT IS AN ABSOLUTE ODOMETER READING — the number on the dial (50,000), not a distance from
 * a start point. These tests reproduce the report's own rows to prove the two agree.
 *
 * In-memory, no database: nothing is saved, so each case states its own window explicitly.
 */
class WarrantyOverageTest extends TestCase
{
    /** A warranty whose cover ends AT a given odometer reading, as the report states it. */
    private function coverEndingAtKm(int $endsAtKm, string $startsOn = '-12 months', int $months = 36): Warranty
    {
        $w = (new Warranty())->forceFill([
            'kind'            => Warranty::KIND_VEHICLE,
            'status'          => Warranty::STATUS_ACTIVE,
            'starts_on'       => now()->modify($startsOn)->toDateString(),
            'start_odometer'  => 0,
            'duration_months' => $months,
            'duration_km'     => $endsAtKm,
        ]);
        // What the model derives on save: 0 + the limit = the reading it ends at.
        $w->expires_on    = now()->modify($startsOn)->addMonths($months)->toDateString();
        $w->expires_at_km = $endsAtKm;

        return $w;
    }

    // ── The report's own rows ────────────────────────────────────────────────────────────────────

    /**
     * Reproduces three real rows from the Daily Warranty Report, limit 50,000 in every case.
     *
     * @dataProvider reportRows
     */
    public function test_it_agrees_with_the_fleets_own_warranty_report(int $mileage, string $expectState, ?int $expectRemaining, ?int $expectOver): void
    {
        $verdict = $this->coverEndingAtKm(50000)->evaluate(null, $mileage);

        $this->assertSame($expectState, $verdict['state']);
        $this->assertSame($expectRemaining, $verdict['km_remaining']);
        $this->assertSame($expectOver, $verdict['km_over']);
    }

    public static function reportRows(): array
    {
        return [
            // mileage,  state,     km_remaining, km_over     (sheet's "Warenty finish")
            'under the limit'  => [47408, Warranty::STATE_ACTIVE,  2592, null],   //  2,592
            'just over'        => [61831, Warranty::STATE_EXPIRED, null, 11831],  // -11,831
            'well over'        => [75167, Warranty::STATE_EXPIRED, null, 25167],  // -25,167
        ];
    }

    /**
     * The two numbers are mutually exclusive. A warranty is on one side of its limit or the other,
     * and shipping both would invite a caller to subtract them into nonsense.
     */
    public function test_remaining_and_over_are_never_both_present(): void
    {
        foreach ([10000, 49999, 50000, 50001, 90000] as $mileage) {
            $v = $this->coverEndingAtKm(50000)->evaluate(null, $mileage);
            $this->assertTrue(
                $v['km_remaining'] === null || $v['km_over'] === null,
                "both were set at $mileage km",
            );
        }
    }

    /** Exactly ON the limit is still covered — the cover ends when the reading PASSES it. */
    public function test_exactly_on_the_limit_is_still_covered(): void
    {
        $v = $this->coverEndingAtKm(50000)->evaluate(null, 50000);

        $this->assertSame(Warranty::STATE_ACTIVE, $v['state']);
        $this->assertSame(0, $v['km_remaining']);
        $this->assertNull($v['km_over']);
    }

    /** With no odometer the distance leg is unknowable — and says so rather than reporting zero. */
    public function test_no_odometer_means_no_overage_claim_either_way(): void
    {
        $v = $this->coverEndingAtKm(50000)->evaluate(null, null);

        $this->assertTrue($v['distance_unknown']);
        $this->assertNull($v['km_remaining']);
        $this->assertNull($v['km_over']);
    }

    /** Time runs out too, and "how long ago" is as useful as "how far over". */
    public function test_days_over_is_reported_once_the_date_has_passed(): void
    {
        $w = (new Warranty())->forceFill([
            'kind' => Warranty::KIND_VEHICLE, 'status' => Warranty::STATUS_ACTIVE,
            'starts_on' => now()->subMonths(40)->toDateString(), 'duration_months' => 36,
        ]);
        $w->expires_on = now()->subMonths(4)->toDateString();

        $v = $w->evaluate();

        $this->assertSame(Warranty::STATE_EXPIRED, $v['state']);
        $this->assertNotNull($v['days_over']);
        $this->assertGreaterThan(100, $v['days_over'], 'roughly four months past');
        $this->assertNull($v['days_remaining']);
    }

    // ── Service contracts: the same question, plus a third leg ──────────────────────────────────

    private function contract(array $attrs = []): ServiceContract
    {
        return (new ServiceContract())->forceFill(array_merge([
            'status'         => ServiceContract::STATUS_ACTIVE,
            'coverage_label' => '5 Lube Service/5Yrs',
            'services_total' => 5,
            'services_used'  => 0,
            'interval_km'    => 10000,
            'ends_on'        => now()->addYears(4)->toDateString(),
            'ends_at_km'     => 50000,
        ], $attrs));
    }

    /** The same absolute-odometer arithmetic as a warranty. */
    public function test_a_service_contract_reports_remaining_and_overage_the_same_way(): void
    {
        $this->assertSame(2592, $this->contract()->evaluate(null, 47408)['km_remaining']);

        $over = $this->contract()->evaluate(null, 61831);
        $this->assertSame(ServiceContract::STATE_EXPIRED, $over['state']);
        $this->assertSame(11831, $over['km_over']);
        $this->assertSame(ServiceContract::BY_DISTANCE, $over['ended_by']);
    }

    /**
     * THE THIRD LEG, and the one people actually hit. A contract with four years and 30,000 km left
     * on paper is finished the moment its fifth service is used — a system checking only date and
     * distance would keep telling a supervisor the car is covered.
     */
    public function test_a_contract_ends_when_its_services_are_used_up(): void
    {
        $v = $this->contract(['services_used' => 5])->evaluate(null, 10000);

        $this->assertSame(ServiceContract::STATE_EXPIRED, $v['state']);
        $this->assertSame(ServiceContract::BY_SERVICES, $v['ended_by'], 'the count is the honest cause');
        $this->assertSame(0, $v['services_remaining']);
    }

    /** Services used up is reported ahead of distance when both have finished — it is less arguable. */
    public function test_the_service_count_is_reported_before_distance(): void
    {
        $v = $this->contract(['services_used' => 5])->evaluate(null, 90000);

        $this->assertSame(ServiceContract::BY_SERVICES, $v['ended_by']);
    }

    /** A contract capped by period alone has NO count — and one is never invented for it. */
    public function test_a_period_capped_contract_has_no_service_count(): void
    {
        $c = $this->contract(['services_total' => null, 'coverage_label' => '5 YEARS OR 75K KM SERVICE CONTRACT']);

        $this->assertNull($c->servicesRemaining());
        $this->assertSame(ServiceContract::STATE_ACTIVE, $c->evaluate(null, 10000)['state']);
    }

    /** One service left counts as running out — a count of one is nearly nothing. */
    public function test_one_service_left_reads_as_running_out(): void
    {
        $this->assertTrue($this->contract(['services_used' => 4])->evaluate(null, 1000)['running_out']);
        $this->assertFalse($this->contract(['services_used' => 1])->evaluate(null, 1000)['running_out']);
    }

    /** "Next due at" is last change + the CONTRACT's interval — not the fleet's own schedule. */
    public function test_the_next_covered_service_is_due_at_the_contracts_interval(): void
    {
        $c = $this->contract(['last_service_odometer' => 46747, 'interval_km' => 10000]);

        $this->assertSame(56747, $c->nextServiceDueAtKm());
    }

    /** Without both facts it refuses to answer rather than guessing from the car's schedule. */
    public function test_next_due_is_null_when_it_cannot_be_known(): void
    {
        $this->assertNull($this->contract(['last_service_odometer' => null])->nextServiceDueAtKm());
        $this->assertNull($this->contract(['interval_km' => null])->nextServiceDueAtKm());
    }

    /** A contract ended by hand is ended, whatever its legs say. */
    public function test_an_ended_contract_is_ended(): void
    {
        $v = $this->contract(['status' => ServiceContract::STATUS_ENDED])->evaluate(null, 1000);

        $this->assertSame(ServiceContract::STATE_ENDED, $v['state']);
    }
}
