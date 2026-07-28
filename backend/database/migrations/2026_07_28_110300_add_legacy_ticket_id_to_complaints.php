<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for the one-time backfill of legacy `customer_reported` Maintenance tickets into the new
 * Complaint entity. `legacy_ticket_id` records the origin ticket so the migration command is idempotent
 * (re-running never double-creates). Distinct from `maintenance_id`, which is the LIVE link to a ticket
 * a complaint actually spawned. See the ComplaintsMigrateLegacy command and [[complaint-entity]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_ticket_id')->nullable()->unique()->after('maintenance_id');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropUnique(['legacy_ticket_id']);
            $table->dropColumn('legacy_ticket_id');
        });
    }
};
