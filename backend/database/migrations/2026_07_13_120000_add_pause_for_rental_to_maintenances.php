<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pause Maintenance & Return to Service — an OPERATIONAL PAUSE on a live workflow ticket. When a
 * customer urgently needs a car that is mid-repair, the company interrupts the maintenance and
 * releases the car back into service (Available), WITHOUT closing/cancelling the ticket: all its
 * progress, notes, parts, photos, technician assignments and audit history are preserved in place.
 *
 * These columns remember exactly where the ticket was when it paused so "Resume Maintenance" can
 * continue from the identical stage (nothing restarts):
 *   - paused_from_status : the workflow_status the ticket held before the pause (restored on resume)
 *   - paused_at          : when it was paused
 *   - paused_by          : the user who paused it (nullable — a rental-form pull is a system side-effect)
 *   - paused_reason      : why (e.g. "Customer X needs this car today")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('paused_from_status', 40)->nullable()->after('last_state_change_at');
            $table->timestamp('paused_at')->nullable()->after('paused_from_status');
            $table->unsignedBigInteger('paused_by')->nullable()->after('paused_at');
            $table->text('paused_reason')->nullable()->after('paused_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['paused_from_status', 'paused_at', 'paused_by', 'paused_reason']);
        });
    }
};
