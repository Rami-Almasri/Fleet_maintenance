<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complaint Event — one entry in a complaint's timeline: the "record of truth" of exactly what happened
 * after the complaint was received. Every triage step appends one immutable row (created → notified →
 * contacted → conversation logged → decision → inspection spawned → resolved → closed), so we always know
 * the full story. This is the complaint's OWN log — distinct from vehicle_log_events (which stays the
 * cross-vehicle audit trail); the two are only bridged when a complaint spawns a maintenance ticket.
 * See [[complaint-entity]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('complaint_id')->index();

            // The step this row records: created | notified | contacted | decision | inspection_requested |
            // maintenance_opened | resolved | closed | note.
            $table->string('event_type', 40);

            // Free-text detail for the step (e.g. what the customer said on the call).
            $table->text('notes')->nullable();

            // Structured payload for the step (decision value, spawned maintenance_id, etc.).
            $table->json('meta')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();

            $table->timestamps();

            $table->index(['complaint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_events');
    }
};
