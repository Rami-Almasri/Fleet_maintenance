<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle historical event log — a discrete AUDIT TRAIL for the Maintenance Workflow.
 *
 * Deliberately SEPARATE from `maintenances`: a workflow ticket is a single `maintenances`
 * row (origin = 'manual') that stays the one source of truth for the visit, its cost and
 * its place on the /maintenance board. We do NOT spawn extra maintenances rows per
 * transition — that would phantom-multiply the visit and double-count cost / utilisation.
 *
 * Instead each lifecycle transition (test drive → dispatch → under repair → ready → close …)
 * appends one append-only row HERE: who did what, when, tagged inspector vs garage, and the
 * vehicle's active maintenance contract resolved at that exact moment. The visit's financials
 * never move; this table only narrates the history alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_log_events', function (Blueprint $table) {
            $table->id();

            // The car this event belongs to — the timeline is anchored to the vehicle.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // The maintenance VISIT (ticket) that produced the event. Nullable so the log can
            // outlive a deleted ticket and so non-ticket events could be appended later.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();

            // The vehicle's OPEN type-'U' maintenance contract at the moment of the event, resolved
            // by VehicleLogService per-event (best-effort, null when none is open — OM owns contracts;
            // we link, never create). Captured per event so the audit reflects the contract as it was.
            $table->foreignId('linked_contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            // WHAT happened (the lifecycle action: diagnostic_started, dispatched, ready, closed …).
            $table->string('event_type', 40)->index();

            // Audit bucket: 'inspector' (Abu Maroof's test drive / diagnosis / re-inspection) vs
            // 'garage' (dispatch + repair). Mirrors Maintenance::FINDING_SOURCES so the two streams
            // are cleanly separable in any report.
            $table->string('source_tag', 20)->index();

            // The workflow_status the ticket landed in after this transition (a snapshot for the trail).
            $table->string('workflow_status', 30)->nullable();

            // Human-readable one-liner ("Dispatched to Al Quoz Auto · odometer 84,120 km").
            $table->string('description', 255)->nullable();

            // Event-specific snapshot (odometer, garage, cost, severity …) — never read for money,
            // only for display. Money stays on the maintenances row.
            $table->json('meta')->nullable();

            // WHO triggered it (the actor that advanced the ticket).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // When it happened (set by the service, = the transition time).
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            // The vehicle's timeline, newest first, is the dominant read.
            $table->index(['vehicle_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_log_events');
    }
};
