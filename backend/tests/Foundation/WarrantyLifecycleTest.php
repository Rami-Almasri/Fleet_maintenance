<?php

namespace Tests\Foundation;

use App\Models\Vehicle;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\WarrantyService;
use Illuminate\Validation\ValidationException;

/**
 * The warranty rules, in the order they matter.
 *
 * A warranty is a promise with a counterparty and TWO ends — months and kilometres, whichever comes
 * first. In a rental fleet the distance leg is usually the binding one, so every test that only
 * checked dates would pass while the system granted cover it was never given.
 */
class WarrantyLifecycleTest extends FoundationTestCase
{
    private WarrantyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WarrantyService::class);
    }

    private function vehicleAt(int $odometer): Vehicle
    {
        return $this->makeVehicle(['odometer' => $odometer]);
    }

    /** A part warranty needs a car and an anchor; component_catalog_id alone is enough to describe it. */
    private function partWarranty(Vehicle $v, array $overrides = []): Warranty
    {
        return Warranty::create(array_merge([
            'kind'           => Warranty::KIND_PART,
            'vehicle_id'     => $v->id,
            'subject'        => 'AC Compressor',
            'starts_on'      => now()->subMonths(3)->toDateString(),
            'start_odometer' => 100000,
            'duration_months' => 12,
            'duration_km'    => 20000,
        ], $overrides));
    }

    // ── derived expiry ───────────────────────────────────────────────────────────────────────────

    public function test_both_expiry_legs_are_derived_on_save(): void
    {
        $w = $this->partWarranty($this->vehicleAt(100000), [
            'starts_on' => '2026-01-01', 'start_odometer' => 50000,
            'duration_months' => 12, 'duration_km' => 20000,
        ]);

        $this->assertSame('2027-01-01', $w->expires_on->toDateString());
        $this->assertSame(70000, $w->expires_at_km);
    }

    public function test_editing_the_promise_recomputes_the_expiry(): void
    {
        $w = $this->partWarranty($this->vehicleAt(100000));
        $w->update(['duration_months' => 24]);

        $this->assertSame(
            now()->subMonths(3)->addMonths(24)->toDateString(),
            $w->fresh()->expires_on->toDateString(),
            'a stale expiry would outlive the promise it was derived from'
        );
    }

    public function test_a_warranty_with_no_limits_never_expires(): void
    {
        $w = $this->partWarranty($this->vehicleAt(999999), [
            'duration_months' => null, 'duration_km' => null,
        ]);

        $this->assertNull($w->expires_on);
        $this->assertNull($w->expires_at_km);
        $this->assertSame(Warranty::STATE_ACTIVE, $w->evaluate(null, 999999)['state']);
    }

    // ── whichever comes first ────────────────────────────────────────────────────────────────────

    public function test_it_is_live_when_inside_both_legs(): void
    {
        $w = $this->partWarranty($this->vehicleAt(105000));

        $this->assertSame(Warranty::STATE_ACTIVE, $w->evaluate(null, 105000)['state']);
    }

    /** THE CASE THE OLD DATE-ONLY COLUMN GOT WRONG: time fine, distance gone. */
    public function test_distance_expiry_ends_it_while_the_date_leg_still_looks_healthy(): void
    {
        $w = $this->partWarranty($this->vehicleAt(125000)); // 25,000 km on a 20,000 km cover

        $verdict = $w->evaluate(null, 125000);

        $this->assertSame(Warranty::STATE_EXPIRED, $verdict['state']);
        $this->assertSame(Warranty::BY_DISTANCE, $verdict['ended_by']);
        $this->assertStringContainsString('25,000 of 20,000 km', $verdict['evidence']);
    }

    public function test_time_expiry_ends_it_while_the_distance_leg_is_untouched(): void
    {
        $w = $this->partWarranty($this->vehicleAt(101000), [
            'starts_on' => now()->subMonths(18)->toDateString(),
            'duration_months' => 12, 'duration_km' => 20000,
        ]);

        $verdict = $w->evaluate(null, 101000);

        $this->assertSame(Warranty::STATE_EXPIRED, $verdict['state']);
        $this->assertSame(Warranty::BY_TIME, $verdict['ended_by']);
    }

    /**
     * A distance-bounded warranty judged with no odometer is NOT reported as live. Treating unknown
     * as fine is how a fleet talks itself into claims it has already lost.
     */
    public function test_an_unknown_odometer_is_flagged_not_assumed_to_be_within_cover(): void
    {
        $verdict = $this->partWarranty($this->vehicleAt(105000))->evaluate(null, null);

        $this->assertTrue($verdict['distance_unknown']);
        $this->assertStringContainsString('odometer unknown', $verdict['evidence']);
    }

    public function test_a_voided_warranty_reports_void_regardless_of_its_window(): void
    {
        $w = $this->partWarranty($this->vehicleAt(100500));
        $this->service->void($w, 'Unauthorised repair by a third party', $this->admin);

        $this->assertSame(Warranty::STATE_VOID, $w->fresh()->evaluate(null, 100500)['state']);
    }

    public function test_voiding_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->void($this->partWarranty($this->vehicleAt(100500)), '   ', $this->admin);
    }

    // ── anchors ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_part_warranty_without_an_anchor_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'kind' => Warranty::KIND_PART,
            'vehicle_id' => $this->vehicleAt(100000)->id,
            'subject' => 'Something', 'starts_on' => now()->toDateString(), 'duration_months' => 12,
        ], $this->admin);
    }

    /** A repair warranty without the fault cannot be matched to a comeback, so it is unenforceable. */
    public function test_a_repair_warranty_without_a_fault_or_ticket_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'kind' => Warranty::KIND_REPAIR,
            'vehicle_id' => $this->vehicleAt(100000)->id,
            'subject' => 'AC not cooling', 'starts_on' => now()->toDateString(), 'duration_months' => 6,
        ], $this->admin);
    }

    public function test_a_warranty_without_a_car_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'kind' => Warranty::KIND_PART, 'vehicle_component_id' => null, 'part_purchase_id' => null,
            'subject' => 'Orphan', 'starts_on' => now()->toDateString(), 'duration_months' => 12,
        ], $this->admin);
    }

    /** Re-pointing a warranty at a different part is a different warranty, not an edit. */
    public function test_update_cannot_move_the_anchor_or_the_kind(): void
    {
        $v = $this->vehicleAt(100000);
        $w = $this->partWarranty($v);
        $other = $this->vehicleAt(200000);

        $this->service->update($w, [
            'kind' => Warranty::KIND_REPAIR,
            'vehicle_id' => $other->id,
            'notes' => 'edited',
        ], $this->admin);

        $w->refresh();
        $this->assertSame(Warranty::KIND_PART, $w->kind);
        $this->assertSame($v->id, $w->vehicle_id);
        $this->assertSame('edited', $w->notes);
    }

    // ── claims ───────────────────────────────────────────────────────────────────────────────────

    /**
     * The verdict is FROZEN at claim time. It must not drift once the car keeps being driven, or
     * "was it in date when it failed" changes its answer a year later.
     */
    public function test_a_claim_freezes_the_window_verdict(): void
    {
        $v = $this->vehicleAt(105000);
        $w = $this->partWarranty($v);

        $claim = $this->service->fileClaim($w, [
            'failure_description' => 'Compressor seized',
            'claimed_on'          => now()->toDateString(),
            'claim_odometer'      => 105000,
        ], $this->admin);

        $this->assertTrue($claim->was_in_window);
        $frozen = $claim->window_evidence;
        $this->assertStringContainsString('5,000 of 20,000 km', $frozen);

        // The car keeps being driven; the warranty is now finished on distance...
        $v->update(['odometer' => 200000]);
        $this->assertSame(Warranty::STATE_EXPIRED, $w->fresh()->evaluate(null, 200000)['state']);

        // ...but the claim still says what was true when it was filed.
        $this->assertTrue($claim->fresh()->was_in_window);
        $this->assertSame($frozen, $claim->fresh()->window_evidence);
    }

    /** Filing outside the window is allowed and recorded as such — a lost claim is evidence too. */
    public function test_a_claim_outside_the_window_is_recorded_not_blocked(): void
    {
        $v = $this->vehicleAt(130000);
        $claim = $this->service->fileClaim($this->partWarranty($v), [
            'failure_description' => 'Failed after cover ended',
            'claim_odometer'      => 130000,
        ], $this->admin);

        $this->assertFalse($claim->was_in_window);
        $this->assertSame(WarrantyClaim::OUTCOME_PENDING, $claim->outcome);
    }

    public function test_the_claim_falls_back_to_the_cars_current_odometer(): void
    {
        $v = $this->vehicleAt(104000);
        $claim = $this->service->fileClaim($this->partWarranty($v), [
            'failure_description' => 'No reading given',
        ], $this->admin);

        $this->assertSame(104000, $claim->claim_odometer);
        $this->assertTrue($claim->was_in_window);
    }

    /** A refusal without their reason teaches us nothing about the supplier. */
    public function test_rejecting_a_claim_requires_the_reason_they_gave(): void
    {
        $claim = $this->service->fileClaim($this->partWarranty($this->vehicleAt(105000)), [
            'failure_description' => 'Failed',
        ], $this->admin);

        $this->expectException(ValidationException::class);
        $this->service->resolveClaim($claim, ['outcome' => WarrantyClaim::OUTCOME_REJECTED], $this->admin);
    }

    /** Money only exists where something was recovered, or every "recovered" total inflates. */
    public function test_a_rejected_claim_cannot_carry_a_recovered_amount(): void
    {
        $claim = $this->service->fileClaim($this->partWarranty($this->vehicleAt(105000)), [
            'failure_description' => 'Failed',
        ], $this->admin);

        $resolved = $this->service->resolveClaim($claim, [
            'outcome'          => WarrantyClaim::OUTCOME_REJECTED,
            'outcome_reason'   => 'They refuse corrosion claims after six months',
            'recovered_amount' => 900,
        ], $this->admin);

        $this->assertNull($resolved->recovered_amount);
        $this->assertSame('none', $resolved->remedy);
    }

    public function test_an_accepted_claim_keeps_its_recovery(): void
    {
        $claim = $this->service->fileClaim($this->partWarranty($this->vehicleAt(105000)), [
            'failure_description' => 'Failed',
        ], $this->admin);

        $resolved = $this->service->resolveClaim($claim, [
            'outcome'          => WarrantyClaim::OUTCOME_ACCEPTED,
            'recovered_amount' => 1250.50,
            'remedy'           => 'replacement',
        ], $this->admin);

        $this->assertEquals(1250.50, $resolved->recovered_amount);
        $this->assertNotNull($resolved->resolved_on);
    }

    /** One cover, two failures — collapsing claims onto the warranty would erase the first. */
    public function test_one_warranty_can_carry_several_claims(): void
    {
        $w = $this->partWarranty($this->vehicleAt(105000));

        foreach (['First failure', 'Second failure'] as $desc) {
            $this->service->fileClaim($w, ['failure_description' => $desc], $this->admin);
        }

        $this->assertSame(2, $w->claims()->count());
    }

    // ── API surface ──────────────────────────────────────────────────────────────────────────────

    public function test_the_api_ships_the_verdict_beside_the_dates(): void
    {
        $v = $this->vehicleAt(125000);
        $this->partWarranty($v);

        $row = $this->getJson("/api/warranties?vehicle_id={$v->id}")->assertSuccessful()
            ->json('data.warranties.0');

        $this->assertSame('expired', $row['verdict']['state']);
        $this->assertSame('distance', $row['verdict']['ended_by']);
        $this->assertNotNull($row['expires_on'], 'the date leg is still shown — it just is not the answer');
    }

    public function test_the_register_can_be_filtered_by_kind_and_vehicle(): void
    {
        $v = $this->vehicleAt(105000);
        $this->partWarranty($v);

        $this->getJson("/api/warranties?kind=part&vehicle_id={$v->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson("/api/warranties?kind=repair&vehicle_id={$v->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_the_store_endpoint_rejects_a_km_cover_with_no_starting_odometer(): void
    {
        $this->postJson('/api/warranties', [
            'kind' => Warranty::KIND_PART,
            'vehicle_id' => $this->vehicleAt(100000)->id,
            'vehicle_component_id' => null,
            'subject' => 'X', 'starts_on' => now()->toDateString(), 'duration_km' => 20000,
        ])->assertStatus(422)->assertJsonValidationErrors('start_odometer');
    }

    public function test_the_store_endpoint_rejects_a_warranty_with_no_window(): void
    {
        $this->postJson('/api/warranties', [
            'kind' => Warranty::KIND_PART,
            'vehicle_id' => $this->vehicleAt(100000)->id,
            'subject' => 'X', 'starts_on' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('duration_months');
    }
}
