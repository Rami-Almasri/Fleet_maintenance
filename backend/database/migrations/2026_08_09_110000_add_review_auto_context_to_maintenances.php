<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The FACTS behind a system withdrawal of an inspection request.
 *
 * When OfficeManager opens a maintenance contract (type U) on a car that still has a request sitting in
 * the Controllers' review queue, the system withdraws the request itself — the car is already in the
 * workshop, so approving a test drive for it is deciding about a car that has already gone.
 *
 * A rejection code alone ("already in maintenance") would leave the Controller staring at a card that
 * changed under them with no way to check it. This column carries the evidence: WHICH contract, its
 * number, when it opened, which customer, and when we noticed. It is the difference between the system
 * saying "trust me" and the system showing its work (see [[traceability-visibility-requirement]]).
 *
 * Nullable and written only by the withdrawal path — a human rejection leaves it empty, which is exactly
 * how the queue tells the two apart. See MaintenanceWorkflowService::withdrawRequestsForMaintenanceContracts()
 * and Maintenance::REVIEW_SYSTEM_WITHDRAWAL_REASONS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('review_auto_context')->nullable()->after('review_rejection_code');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('review_auto_context');
        });
    }
};
