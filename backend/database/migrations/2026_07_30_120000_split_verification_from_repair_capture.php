<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verification becomes its own act, by its own person.
 *
 * The capture form previously collected the repair claim AND the check that confirmed it, from one
 * user in one session. That made every verification self-reported by construction — and a supplier
 * quality figure built on a garage confirming its own work measures nothing.
 *
 * Splitting it needs the verifier recorded separately from the claimer, because the guarantee this
 * whole change exists to provide is that those two are different people. A permission cannot deliver
 * that on its own: the `maintenance` role already holds `inspections.manage`, so the same user could
 * satisfy both gates. The identity comparison is what actually enforces it, and it needs these
 * columns to compare against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // Who independently confirmed the repair, and when. Distinct from claimed_outcome_by,
            // which is whoever said the work was done.
            $table->unsignedBigInteger('verified_by')->nullable()->after('verification_method');
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // The inspector's verdict, which may contradict the workshop's claim — that
            // disagreement is the single most valuable row this table will ever hold.
            $table->string('verification_result', 24)->nullable()->after('verified_at');
            $table->text('verification_note')->nullable()->after('verification_result');

            $table->index('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropIndex(['verified_by']);
            $table->dropColumn(['verified_by', 'verified_at', 'verification_result', 'verification_note']);
        });
    }
};
