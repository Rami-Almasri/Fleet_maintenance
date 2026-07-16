<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-Maintenance Recommendation queue. A recommendation is NOT a commitment to repair: the Inspector's
 * in-shop "requires maintenance" report now lands a ticket in the fenced `recommendation_pending` state
 * (see Maintenance::WF_RECOMMENDATION_STATES) — a lightweight review queue the Supervisor triages — instead
 * of jumping straight into the active dispatch pipeline (inspection_pending). These columns record that
 * triage. All are additive + nullable; existing rows and the existing workflow are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // "Schedule for later" — review this recommendation again on/after this date; stays in the queue.
            $table->timestamp('recommendation_scheduled_for')->nullable()->after('awaiting_invoice_since');
            // How a DISMISSED recommendation was closed: 'rejected' | 'not_required' (Maintenance::RECO_*).
            $table->string('recommendation_disposition', 20)->nullable()->after('recommendation_scheduled_for');
            // Free-text reason for a dismissal / a parts note for a waiting-for-parts recommendation.
            $table->text('recommendation_note')->nullable()->after('recommendation_disposition');
            // The spare part arrived — a waiting-for-parts recommendation is ready to start maintenance.
            $table->boolean('recommendation_parts_ready')->default(false)->after('recommendation_note');
            // Audit: who last triaged the recommendation (approve / reject / schedule / parts) and when.
            $table->foreignId('recommendation_reviewed_by')->nullable()->after('recommendation_parts_ready')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('recommendation_reviewed_at')->nullable()->after('recommendation_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recommendation_reviewed_by');
            $table->dropColumn([
                'recommendation_scheduled_for',
                'recommendation_disposition',
                'recommendation_note',
                'recommendation_parts_ready',
                'recommendation_reviewed_at',
            ]);
        });
    }
};
