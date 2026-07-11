<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet Maintenance Workflow — Driver-initiated request + follow-up log.
 *
 * Two formalisations of the role-based lifecycle:
 *
 *  1. A Driver (Logistics) can now OPEN the workflow by REQUESTING an inspection before any ticket
 *     or diagnostic exists (state `inspection_requested`). `requested_by` / `requested_at` stamp who
 *     raised it and when, so the inspector (Abu Maroof) is alerted and the request is attributable.
 *
 *  2. `follow_ups` is the Driver's running log while a car is in transit / under repair — every
 *     follow-up note the design calls for is appended here (never overwritten), so the back-and-forth
 *     that used to live on WhatsApp is recorded against the ticket.
 *
 * All three columns are nullable and additive — legacy rows and existing reports are untouched.
 *
 *   follow_ups: [{ "text": "Garage says parts arrive tomorrow", "by": "Driver", "by_id": 7, "at": "..." }, ...]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // Who raised the inspection request (the Driver), and when — the new entry point.
            $table->foreignId('requested_by')->nullable()->after('wf_closed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');

            // The Driver's follow-up log while the car is out (in transit / under repair).
            $table->json('follow_ups')->nullable()->after('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('requested_by');
            $table->dropColumn(['requested_at', 'follow_ups']);
        });
    }
};
