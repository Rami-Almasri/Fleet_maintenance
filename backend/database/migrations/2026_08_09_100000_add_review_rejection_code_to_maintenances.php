<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY a Controller rejected an inspection request, as a CODE rather than a sentence.
 *
 * `review_rejection_reason` (already there) is free text: it reads fine on one card and is useless in
 * aggregate — "car is rented", "customer has it", "on hire" are three spellings of one fact, so nobody
 * could ever answer "how many requests do we reject because the car wasn't available?". The code is the
 * answerable part; the sentence stays as the human detail beside it.
 *
 * Nullable on purpose: every request rejected before this column existed has a sentence and no code, and
 * that is an honest "not recorded" — it must NOT be back-filled by guessing at the English.
 * See Maintenance::REVIEW_REJECTION_REASONS and [[reason-code-contract]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('review_rejection_code', 40)->nullable()->after('review_rejection_reason');
            // The reporting read: "rejections by reason, this month".
            $table->index('review_rejection_code', 'maint_review_reject_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex('maint_review_reject_code_idx');
            $table->dropColumn('review_rejection_code');
        });
    }
};
