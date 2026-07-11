<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "ready entry point" for a system-raised ticket: the exact Findings-catalog keyword(s) the
 * Proactive Diagnostic Monitor already knows are due (e.g. ['Oil Change', 'Battery Replacement']),
 * stamped once at request time from DiagnosticGateService::conditionsDue(). The Decide step reads
 * this to offer one-tap "System flagged" chips instead of making the Inspector hunt the picker for
 * what the agenda note already told him to check. Additive + nullable — every other ticket path
 * (driver request, complaint, breakdown, pickup) simply never sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('suggested_findings')->nullable()->after('customer_complaint');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('suggested_findings');
        });
    }
};
