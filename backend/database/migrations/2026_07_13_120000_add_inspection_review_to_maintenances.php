<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection Request Review Gate — a mandatory Controller (Lin & Marwa) sign-off inserted between
 * a Driver/system-generated inspection request and the moment it is sent to the Inspector (Abu
 * Maroof). See Maintenance::WF_PENDING_REVIEW / WF_REVIEW_REJECTED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->text('review_rejection_reason')->nullable()->after('review_notes');
            // Stamped the moment an approved request is actually sent to the Inspector — doubles as
            // the "was this already sent" guard against double-send.
            $table->timestamp('review_sent_at')->nullable()->after('review_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('reviewed_by');

            $table->dropColumn([
                'reviewed_at',
                'review_notes',
                'review_rejection_reason',
                'review_sent_at',
            ]);
        });
    }
};
