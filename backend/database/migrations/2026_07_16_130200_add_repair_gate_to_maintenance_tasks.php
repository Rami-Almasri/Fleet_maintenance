<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring-Fault REPAIR GATE. When the workshop confirms a fault that recurred within the recurrence
 * window, the repair must NOT start until it is explicitly approved — this is the money guard against
 * paying twice for the same recently-fixed fault. The gate lives on the fault:
 *   pending  — confirmed + recurring → repair is BLOCKED (setStatus refuses in_progress/completed)
 *   approved — a manager cleared it → repair may proceed
 *   rejected — a manager refused → the fault is cancelled (not repaired again on this ticket)
 * null = no gate (no recurrence, or not yet confirmed). See RecurringFaultService / RecurringFaultReview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->string('repair_gate', 20)->nullable()->index()
                ->after('recurrence_previous_task_id'); // null | pending | approved | rejected
            $table->foreignId('repair_gate_by')->nullable()->after('repair_gate')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('repair_gate_at')->nullable()->after('repair_gate_by');
            $table->text('repair_gate_note')->nullable()->after('repair_gate_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repair_gate_by');
            $table->dropColumn(['repair_gate', 'repair_gate_at', 'repair_gate_note']);
        });
    }
};
