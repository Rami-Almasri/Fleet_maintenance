<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint — the promised completion the whole progress-tracking system measures against.
 *
 *  - expected_completion_date : the canonical ready-by date. The Checkpoint Scan, ETA gauge, dashboard
 *    indicators and overdue detection all read THIS. Falls back to the legacy `expected_return_date`
 *    (and then the fleet-default window) for tickets predating this column, so nothing goes blind.
 *  - expected_duration_days   : the duration the user typed at intake (e.g. 4). When they enter a
 *    duration we derive the date; if they later edit the date directly, the date wins (source of truth).
 *  - last_checkpoint_at       : when the most recent checkpoint was submitted — the anchor the escalation
 *    uses to decide whether a car still "needs an update" in the current completion window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->date('expected_completion_date')->nullable()->after('expected_return_date');
            $table->unsignedSmallInteger('expected_duration_days')->nullable()->after('expected_completion_date');
            $table->timestamp('last_checkpoint_at')->nullable()->after('expected_duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['expected_completion_date', 'expected_duration_days', 'last_checkpoint_at']);
        });
    }
};
