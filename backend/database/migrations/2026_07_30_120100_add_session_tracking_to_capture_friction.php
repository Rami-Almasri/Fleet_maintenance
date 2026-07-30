<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture STARTS, so abandonment becomes measurable.
 *
 * Rows were previously written only on success, which meant the population that would prove
 * abandonment was exactly the one missing from the table — and the first attempt to compute it
 * reported 100% by counting a skipped optional field as a walk-out. A friction metric that can only
 * see the people who finished is not a friction metric.
 *
 * The row is now opened when the form opens and closed when it resolves, so an abandoned attempt
 * leaves a `started` row that never completed. That also makes "which step do people leave at"
 * answerable, which is two of the owner's five feedback-loop questions.
 *
 * MUTABLE ON PURPOSE, AND CORRECTLY SO. Unlike domain_events these rows are updated in place — this
 * is UX telemetry about the product, not evidence about a vehicle, and it stays outside the
 * canonical log exactly as designed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_friction', function (Blueprint $table) {
            // Correlates the start with whatever ended it.
            $table->uuid('session_id')->nullable()->after('step');

            // started → completed | abandoned. A row still 'started' long after the fact is itself
            // the signal: the tab was closed, or the app was.
            $table->string('status', 16)->default('completed')->after('session_id');

            // The furthest step reached. The direct answer to "where do users leave?".
            $table->unsignedTinyInteger('last_step')->nullable()->after('status');

            $table->unsignedBigInteger('maintenance_task_id')->nullable()->after('maintenance_id');

            $table->index('session_id');
            $table->index(['step', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('capture_friction', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropIndex(['step', 'status']);
            $table->dropColumn(['session_id', 'status', 'last_step', 'maintenance_task_id']);
        });
    }
};
