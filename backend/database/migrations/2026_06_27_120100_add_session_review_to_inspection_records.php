<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Inspection Engine — Phase 1 schema bridge for inspection_records.
 *
 * Three additive gaps on top of the live pre/post + body_part + S3 + damage-flag store:
 *   - inspection_session_id : groups one capture sitting (a check-in or check-out) so the
 *                             "Condition Timeline" and auto-comparison read 30 photos as ONE event.
 *   - checkpoint_type       : the coarse grouping the controllers asked for (exterior / interior /
 *                             tires / glass / mechanical), derived from the finer body_part zone.
 *   - reviewed_by/at + outcome : lets Controllers (Lin & Marwa) clear an Exception Flag in two
 *                             clicks (accepted | disputed | noted) instead of scrolling every image.
 *
 * All nullable — existing inspection rows and the live capture/presign flow are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_records', function (Blueprint $table) {
            $table->uuid('inspection_session_id')->nullable()->after('vehicle_id')->index();
            $table->string('checkpoint_type', 20)->nullable()->after('body_part')->index(); // exterior|interior|tires|glass|mechanical

            // Controller review of a damage Exception Flag.
            $table->foreignId('reviewed_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_outcome', 20)->nullable()->after('reviewed_at'); // accepted|disputed|noted
        });
    }

    public function down(): void
    {
        Schema::table('inspection_records', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('reviewed_by');
            $table->dropColumn([
                'inspection_session_id',
                'checkpoint_type',
                'reviewed_at',
                'review_outcome',
            ]);
        });
    }
};
