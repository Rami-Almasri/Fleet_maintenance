<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for "Rental-First" policy overrides. Under the policy we never open a
 * maintenance (type-U) contract while a car is on an active rental — staff are hard-blocked.
 * A manager can knowingly override that block; every override lands here so the owner can
 * see WHO allowed a maintenance contract over a live rental, WHY (reason code), and WHEN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_override_audits', function (Blueprint $table) {
            $table->id();

            // who pulled the trigger (snapshot the name so it survives a user deletion)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();

            // which car
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('plate')->nullable();

            // what kind of override (room for future policy gates)
            $table->string('action')->default('maintenance_over_active_rental');

            // why — a controlled reason code + its human label + optional free-text notes
            $table->string('reason_code');
            $table->string('reason_label')->nullable();
            $table->text('notes')->nullable();

            // the rental that was overridden (closed by the maintenance contract)
            $table->foreignId('rental_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('rental_contract_no')->nullable();

            // the maintenance contract the override produced
            $table->foreignId('result_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('result_contract_no')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->index(['action', 'created_at']);
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_override_audits');
    }
};
