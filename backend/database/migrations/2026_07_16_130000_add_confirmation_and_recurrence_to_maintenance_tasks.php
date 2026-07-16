<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring-Fault Intelligence — two additions to the per-fault table (maintenance_tasks):
 *
 * 1. The WORKSHOP CONFIRMATION GATE. A reported fault is only a claim (customer / driver / inspector)
 *    until the workshop physically inspects the car. At the "In Workshop" (under_repair) stage the
 *    technician reviews EVERY reported fault and records a verdict:
 *      confirmed        — the fault genuinely exists
 *      not_found        — no fault found
 *      different_cause  — a fault exists but the cause differs from what was reported
 *      needs_diagnosis  — cannot yet tell; more diagnosis required
 *    ONLY a `confirmed` verdict is allowed to trigger the recurring-fault intelligence — this is what
 *    stops false duplicate alerts on unconfirmed reports.
 *
 * 2. The REPORT-TIME background flag. When a fault is first reported we run a silent history check and,
 *    if the same fault was previously FIXED on this car, raise `recurrence_flagged` (a soft "possible
 *    recurring fault" marker pointing at the previous fixed fault). It NEVER blocks the workflow and is
 *    only acted on later, once the workshop confirms the fault.
 *
 * See [[maintenance-tasks-container-model]] and the recurring_fault_reviews table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // --- Workshop confirmation gate (set at the under_repair stage) ---
            $table->string('confirmation_status', 20)->nullable()->index()
                ->after('status'); // null (not reviewed) | confirmed | not_found | different_cause | needs_diagnosis
            $table->text('confirmation_note')->nullable()->after('confirmation_status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmation_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');

            // --- Report-time "possible recurring fault" background flag ---
            $table->boolean('recurrence_flagged')->default(false)->after('confirmed_at');
            $table->foreignId('recurrence_previous_task_id')->nullable()->after('recurrence_flagged')
                ->constrained('maintenance_tasks')->nullOnDelete(); // the prior FIXED fault this one resembles
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_previous_task_id');
            $table->dropColumn('recurrence_flagged');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['confirmation_status', 'confirmation_note', 'confirmed_at']);
        });
    }
};
