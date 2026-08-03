<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Write-action audit — the second half of the workforce trail.
//
// `user_activity_events` already answers "which pages did this employee open,
// and when". It could not answer "and what did he actually DO there" — every
// insert / change / delete was invisible. These columns let the SAME append-only
// log carry a write action (type = create|update|delete) alongside the page
// views, so one timeline shows navigation and mutation in true time order.
//
// All columns are nullable: existing page/login/logout rows keep their meaning
// untouched, and nothing here can be backfilled — actions accrue from deploy on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_activity_events', function (Blueprint $table) {
            $table->string('method', 8)->nullable()->after('type');          // POST | PUT | PATCH | DELETE
            $table->string('entity', 80)->nullable()->after('page');         // resource touched, e.g. "Vehicle"
            $table->string('entity_id', 64)->nullable()->after('entity');    // record id when the URL carries one
            $table->string('description', 255)->nullable()->after('entity_id'); // plain sentence for the UI
            $table->unsignedSmallInteger('status_code')->nullable()->after('description'); // HTTP result (only 2xx is logged)

            // Drives "what did he insert/delete on day X" without scanning pages.
            $table->index(['user_id', 'type', 'created_at'], 'uae_user_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_activity_events', function (Blueprint $table) {
            $table->dropIndex('uae_user_type_created_idx');
            $table->dropColumn(['method', 'entity', 'entity_id', 'description', 'status_code']);
        });
    }
};
