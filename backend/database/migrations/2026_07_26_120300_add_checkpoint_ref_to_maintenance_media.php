<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint — pin an uploaded photo/video to the specific checkpoint it evidences. Nullable
 * and purely additive (mirrors the Asset-Layer component refs added in 2026_07_24_100400): a ticket-scoped
 * or fault-scoped video simply leaves it null. Lets the checkpoint timeline show the exact media captured
 * at each progress update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_checkpoint_id')->nullable()->after('maintenance_task_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->dropColumn('maintenance_checkpoint_id');
        });
    }
};
