<?php

namespace Tests\Crud;

use App\Models\PartPurchase;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1, Step 1 validation (blueprint §3d; advances Scenarios 1,2,7; upholds Invariant 6 — cost
 * untouched). Proves the delivery-tracking columns exist, are fillable, cast correctly, and default
 * to null (no backfill, existing behaviour unchanged). The migration adds no logic — this only pins
 * the schema + casts so later steps can DERIVE fulfillment state from these timestamps.
 */
class PartDeliveryTrackingTest extends CrudTestCase
{
    private function vehicle(): Vehicle
    {
        return Vehicle::findOrFail($this->makeVehicle());
    }

    private function makePurchase(array $overrides = []): PartPurchase
    {
        return PartPurchase::create(array_merge([
            'vehicle_id'      => $this->vehicle()->id,
            'part_name'       => 'Brake Pads',
            'part_number'     => 'BP-100',
            'category_key'    => 'brakes',
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'  => 300,
            'currency'        => 'AED',
            'quantity'        => 1,
            'purchased_at'    => now(),
        ], $overrides));
    }

    public function test_delivery_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('part_purchases', 'expected_delivery_date'));
        $this->assertTrue(Schema::hasColumn('part_purchases', 'delivered_at'));
    }

    public function test_columns_default_to_null_leaving_existing_behaviour_unchanged(): void
    {
        $purchase = $this->makePurchase()->fresh();

        $this->assertNull($purchase->expected_delivery_date);
        $this->assertNull($purchase->delivered_at);
    }

    public function test_columns_are_fillable_and_cast(): void
    {
        $purchase = $this->makePurchase([
            'expected_delivery_date' => '2026-08-01',
            'delivered_at'           => '2026-08-02 14:30:00',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $purchase->expected_delivery_date);
        $this->assertInstanceOf(Carbon::class, $purchase->delivered_at);
        $this->assertSame('2026-08-01', $purchase->expected_delivery_date->toDateString());
        $this->assertSame('2026-08-02 14:30:00', $purchase->delivered_at->toDateTimeString());
    }
}
