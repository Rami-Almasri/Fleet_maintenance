<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Swap — an operational record that a RENTED car which needs urgent maintenance has had a
 * REPLACEMENT vehicle assigned to cover its rental. It is NOT an OfficeManager contract (OM is the sole
 * source of rental contracts); it's our planning link — "replacement X is attached to original rental Y
 * while X's car is in the shop" — that powers the swap board's "Attached" state and, later, lines up
 * with the real OM replacement rental via the Contract Exchange linker.
 *
 * Self-contained snapshots (plate / car label / tenant / contract no) so a swap stays meaningful even
 * after a vehicle/contract re-sync. Vehicle ids are kept loose (indexed, no FK) for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_swaps', function (Blueprint $table) {
            $table->id();

            // The original (rented) car that needs maintenance.
            $table->unsignedBigInteger('original_vehicle_id')->index();
            $table->string('original_plate')->nullable();
            $table->string('original_car')->nullable();

            // The rental being covered (OM contract — snapshot, no hard FK).
            $table->unsignedBigInteger('original_contract_id')->nullable()->index();
            $table->string('original_contract_no')->nullable();
            $table->string('tenant_name')->nullable();
            $table->string('reason')->nullable();          // why the original went to the shop

            // The replacement assigned from the available pool.
            $table->unsignedBigInteger('replacement_vehicle_id')->index();
            $table->string('replacement_plate')->nullable();
            $table->string('replacement_car')->nullable();

            // Lifecycle: 'active' (replacement attached) → 'released' (original back / swap ended).
            $table->string('status')->default('active');
            $table->string('assigned_by')->nullable();
            $table->string('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['original_vehicle_id', 'status']);
            $table->index(['replacement_vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_swaps');
    }
};
