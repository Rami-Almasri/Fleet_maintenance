<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Complaint — a FIRST-CLASS entity, no longer a Maintenance ticket in disguise. A complaint is
 * an active problem a renter reports WHILE using the car (AC dead, warning light, won't start, strong
 * vibration). It has its own customer-support lifecycle — notify Abu Maroof → contact the customer →
 * record the conversation → decide (keep driving / bring in / replace / roadside) → resolve → close —
 * and that whole story lives on this row + its complaint_events, INDEPENDENT of maintenance.
 *
 * A complaint may resolve with no repair at all (a misunderstanding, a usage explanation, a temporary
 * glitch). Only when the decision needs real work does it SPAWN an inspection / maintenance ticket, and
 * the link is stored here in `maintenance_id`. This is deliberately separate from driver_observations,
 * which is the lightweight internal-note path with no customer follow-up. See [[complaint-entity]].
 *
 * Loose links (unsignedBigInteger + index, no hard FK), mirroring the codebase convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();

            // Who/what the complaint is about — captured at intake, durable even if the rental rotates.
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();

            // How it arrived: `ops` = Operations logged a customer call/message directly; `driver_relayed`
            // = a driver passed on what the renter told them (still a customer complaint, richer than a
            // handover observation).
            $table->string('source', 20)->default('ops');

            // The authoritative lifecycle stage — SET explicitly at every step (never parsed from text):
            // new | notified | contacted | in_maintenance | resolved | closed.
            $table->string('status', 20)->default('new')->index();

            // Optional triage severity hint (minor | major | critical) — mirrors the ticket vocabulary.
            $table->string('severity', 20)->nullable();

            // The complaint itself, in the customer's terms.
            $table->text('description');

            // The triage decision once Abu Maroof has spoken to the customer: continue_driving |
            // bring_for_inspection | replace_vehicle | roadside_assistance. Null until decided.
            $table->string('decision', 30)->nullable();

            // Denormalised customer contact captured at intake so the inspector can call without a join
            // (the open rental may close before triage).
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('contract_no', 60)->nullable();

            // The maintenance ticket this complaint SPAWNED, if it needed real work. Null while the
            // complaint is handled purely as customer support. This is the ONLY tie to the workshop.
            $table->unsignedBigInteger('maintenance_id')->nullable()->index();

            // Who logged it, and who currently owns the triage (the inspector who acted).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
