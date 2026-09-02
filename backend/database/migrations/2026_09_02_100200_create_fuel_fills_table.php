<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A FUEL FILL — AN OPERATIONAL EVENT THAT HAPPENS TO COST MONEY.
 *
 * This is a new table because the ACTION had no record, not because finance needed somewhere to put a
 * number. FleetView tracked fuel only as an analytics input (FuelMileageService reconciles contract
 * mileage against fuel) and as a category in the imported expense ledger; nothing recorded that a
 * specific car was filled on a specific day with a specific number of litres at a specific odometer.
 *
 * That gap is why this row is worth its own table and why it is NOT a finance table:
 *
 *   litres + odometer_km   are what make consumption (L/100km) computable per car
 *   filled_at              is what puts the fill on the vehicle's timeline
 *   cost                   is only one of its facts, and the last one added
 *
 * Its financial side then follows the same path as everything else — it implements
 * {@see \App\Contracts\FinancialEventSource}, the builder raises one obligation per fill, and the same
 * validator, mapping layer and idempotent pusher take it to Odoo. No parallel pipeline.
 *
 * ── odometer_km IS RECORDED, NOT WRITTEN THROUGH ──────────────────────────────────────────────────
 *
 * A fill's odometer reading is deliberately NOT pushed into `vehicles.odometer` from here. That column
 * has a documented set of writers and an ordering rule (highest reading wins, source stamped) —
 * [[odometer-source-race]] — and quietly adding an eighth writer through a fuel form is how that
 * invariant gets broken. The reading is kept as evidence of this fill; advancing the car's odometer
 * stays with the services that own it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_fills', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vehicle_id');
            // The ticket this fill belongs to, when it was fuel bought FOR a maintenance visit (which is
            // what the Odoo account "Fuel Expenses | Fuel for Maintenance" is actually for). Nullable:
            // an ordinary operational fill has no ticket.
            $table->unsignedBigInteger('maintenance_id')->nullable();

            $table->dateTime('filled_at');
            // The operational facts. Litres and odometer are what make this more than a receipt.
            $table->decimal('litres', 10, 2)->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->string('fuel_grade', 32)->nullable();

            // Who sold it — a station is a supplier like any other and needs an Odoo partner.
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('station_name')->nullable();

            $table->decimal('cost', 12, 2)->default(0);
            $table->string('currency', 8)->default('AED');

            $table->string('receipt_no', 128)->nullable();
            $table->date('receipt_date')->nullable();
            $table->string('receipt_disk', 32)->nullable();
            $table->string('receipt_key')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'filled_at'], 'fuel_vehicle_date_idx');
            $table->index('maintenance_id', 'fuel_maintenance_idx');
            $table->index('vendor_id', 'fuel_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_fills');
    }
};
