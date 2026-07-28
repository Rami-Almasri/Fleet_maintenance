<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * service_due_snoozes — a manager's deliberate "not now" on a service-due vehicle in the
 * Maintenance Operations Center (evolved /service-due). While a snooze is live (snoozed_until in the
 * future) the vehicle drops off the actionable board, with a "Snoozed (N)" pill to un-hide.
 *
 * This is an operational dismissal, NOT a data change: it never touches the odometer, interval or any
 * service record — the km rule (Vehicle::serviceStatus) still holds; the row simply isn't nagged.
 * Latest row per vehicle wins (we don't mutate — a new snooze / un-snooze writes a fresh row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_due_snoozes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // Which service context was snoozed (free-text service_type, null = the whole service-due row).
            $table->string('service_type', 80)->nullable();

            // Live until this day (inclusive). Null = snoozed indefinitely (until manually cleared).
            $table->date('snoozed_until')->nullable();
            $table->string('reason', 500)->nullable();

            // false once a manager un-snoozes (we keep the history rather than delete).
            $table->boolean('active')->default(true);

            $table->foreignId('snoozed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snoozed_by_name', 120)->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_due_snoozes');
    }
};
