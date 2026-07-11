<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Fix Evidence" on a fault — when a supervisor marks a fault fixed they can attach a repair video and a
 * resolution note. Two small additions:
 *   • maintenance_media.maintenance_task_id — lets a video hang off the specific FAULT, not just the ticket
 *     (nullable, so existing ticket-scoped videos and the QA-gate videos are untouched).
 *   • maintenance_tasks.resolution_note — the "what was done to fix it" note, kept distinct from the fault's
 *     original inspection `notes` so neither overwrites the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_task_id')->nullable()->after('maintenance_id')->index();
        });

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->text('resolution_note')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->dropColumn('maintenance_task_id');
        });

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropColumn('resolution_note');
        });
    }
};
