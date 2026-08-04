<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-reported mileage readings taken DURING a rental.
 *
 * These are a deliberately SEPARATE fact stream from the odometer chain (E6). When Leen or
 * Marwa phone a customer mid-rental and get a number, that number is hearsay: it is neither
 * a branch handover reading nor a workshop capture, and it must never move
 * `vehicles.odometer` (it would trip the continuity guard, need an approval record, and
 * pollute the baseline chain MileageBaselineService heals from).
 *
 * It is trusted for exactly one purpose: re-anchoring the oil-change projection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_mileage_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // The odometer the customer reported, and the day it refers to. `reported_on` (not
            // created_at) is the projection anchor date: ops may enter yesterday's number today.
            $table->unsignedInteger('odometer');
            $table->date('reported_on');

            // Provenance: who inside the company recorded it, and who outside gave the number.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reported_by')->nullable();
            $table->string('source', 32)->default('customer_reported');
            $table->text('note')->nullable();

            $table->timestamps();

            // The projection always wants "the newest reading for this contract".
            $table->index(['contract_id', 'reported_on']);
            $table->index(['vehicle_id', 'reported_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_mileage_readings');
    }
};
