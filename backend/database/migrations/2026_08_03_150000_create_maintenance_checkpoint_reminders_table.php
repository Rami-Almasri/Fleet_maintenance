<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint Reminders — the DELIVERY LOG behind the daily chase. Until now a reminder was
 * fire-and-forget: `checkpoints:scan` pushed a notification and kept no record, so nobody could answer the
 * one question an admin actually asks — "we told the supervisor about this car; did they ever answer?"
 *
 * One row per (ticket, supervisor, DAY) the reminder fired. When that supervisor files a checkpoint, the
 * row is closed (responded_checkpoint_id + responded_at). A row that stays open past a day IS the
 * accountability finding: the notification went out, and no reason came back.
 *
 * Loose indexes (no hard FK), mirroring maintenance_checkpoints / maintenance_media.
 * See [[maintenance-checkpoint-feature]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_checkpoint_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            // Denormalised so a car's chase history reads without joining through the ticket.
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            // WHO was actually notified — the accountability anchor ("it went to this supervisor").
            $table->unsignedBigInteger('user_id')->index();

            // How loud the ask was: request | reminder | due_today | overdue.
            $table->string('level', 24);

            // The promised completion date in force when this reminder fired (the window it belongs to).
            $table->date('expected_on')->nullable();
            // The DAY it fired — the daily cadence key; one reminder per ticket per user per day.
            $table->date('sent_on');
            $table->timestamp('sent_at')->nullable();

            // Closed the moment that user files a checkpoint on the ticket. NULL = still unanswered.
            $table->unsignedBigInteger('responded_checkpoint_id')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // One reminder per ticket per user per day — makes the scan idempotent at the database level
            // however many times a day it runs.
            $table->unique(['maintenance_id', 'user_id', 'sent_on'], 'mcr_ticket_user_day_unique');
            // The oversight query: "every reminder still unanswered, oldest first".
            $table->index(['responded_at', 'sent_on'], 'mcr_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_checkpoint_reminders');
    }
};
