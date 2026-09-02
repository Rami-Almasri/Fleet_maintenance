<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SECOND pair of eyes on a supplier bill.
 *
 * Keying a bill and checking a bill are two jobs for two people. Until now they were one: whoever
 * bought the part typed the figures, and the only comparison the system ever made was between two
 * numbers that same person had typed — so a total misread off the paper agreed with itself and
 * passed. The photo has always been stored; nobody was ever asked to look at it.
 *
 * This is that stage, recorded rather than assumed:
 *
 *   matched_at / matched_by   WHO looked at the photo, and when. Null = still waiting.
 *   match_result              'matches' or 'disputed' — a check that can only ever pass is not a
 *                             check, so disagreeing has to be a first-class outcome.
 *   match_note                Required on 'disputed', optional on 'matches'.
 *
 * NOT a `bool verified`: "nobody has looked yet" and "somebody looked and it was wrong" are
 * different facts and must never collapse into the same false.
 *
 * The rule that the matcher cannot be `recorded_by` lives in PartInvoiceController, not in a
 * constraint, because it is a policy about people rather than a shape the data must hold — a bill
 * imported by a job has no recorder to be different from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_invoices', function (Blueprint $table) {
            // dateTime, not timestamp: a stored moment that must never be touched by MariaDB's
            // auto-update behaviour on row change.
            $table->dateTime('matched_at')->nullable()->after('recorded_at');
            $table->foreignId('matched_by')->nullable()->after('matched_at')
                ->constrained('users')->nullOnDelete();
            $table->string('matched_by_name')->nullable()->after('matched_by');
            $table->string('match_result', 16)->nullable()->after('matched_by_name');
            $table->text('match_note')->nullable()->after('match_result');

            // The queue this feeds: "every bill nobody has checked yet, oldest first".
            $table->index(['matched_at', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::table('part_invoices', function (Blueprint $table) {
            $table->dropIndex(['matched_at', 'invoice_date']);
            $table->dropForeign(['matched_by']);
            $table->dropColumn(['matched_at', 'matched_by', 'matched_by_name', 'match_result', 'match_note']);
        });
    }
};
