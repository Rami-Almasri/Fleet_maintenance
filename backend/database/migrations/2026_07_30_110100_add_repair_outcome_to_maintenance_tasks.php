<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The technician's claimed outcome, and how the repair was verified.
 *
 * EVERY COLUMN IS NULLABLE WITH NO DEFAULT, AND THAT IS THE WHOLE POINT. 26,838 historical tickets
 * have no claimed outcome and never will. Defaulting them to 'complete' — or to anything — would
 * make legacy data look like it carries information it does not, and every effectiveness figure
 * computed afterwards would be quietly wrong in the flattering direction. NULL here means UNKNOWN,
 * permanently and correctly.
 *
 * CLAIMED, NOT OBSERVED. This is what the person who did the work says happened. It is a JUDGEMENT
 * and it is stamped with who and when. The OBSERVED outcome — did the fault actually stay fixed — is
 * derived from recurrence months later and is deliberately NOT stored here: it belongs to a
 * projection that can be recomputed when the definition changes.
 *
 * The gap between the two is the most valuable signal this table enables. A workshop that claims
 * `complete` on repairs that return in thirty days is measurable only because both numbers exist
 * separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // complete | partial | temporary | no_improvement — never defaulted; absent means unknown.
            $table->string('claimed_outcome', 24)->nullable()->after('resolution_note');
            $table->unsignedBigInteger('claimed_outcome_by')->nullable()->after('claimed_outcome');
            $table->timestamp('claimed_outcome_at')->nullable()->after('claimed_outcome_by');

            // road_test | scan_tool | pressure_test | visual | customer_confirm
            $table->string('verification_method', 24)->nullable()->after('claimed_outcome_at');

            // "No fault found" is an OUTCOME, not a verification method — the workshop looked and the
            // reported fault was not there. Separated from `status = not_found` because that is a
            // workflow state set by anyone, while this is the technician's own conclusion after
            // inspecting, and the two are answering different questions.
            $table->boolean('no_fault_found')->nullable()->after('verification_method');

            $table->index('claimed_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropIndex(['claimed_outcome']);
            $table->dropColumn([
                'claimed_outcome', 'claimed_outcome_by', 'claimed_outcome_at',
                'verification_method', 'no_fault_found',
            ]);
        });
    }
};
