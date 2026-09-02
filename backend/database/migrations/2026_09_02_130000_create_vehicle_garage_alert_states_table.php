<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per vehicle: the car's LAST COMPUTED garage-behaviour reading, plus the severity each of
 * its two signals was last ALERTED at.
 *
 * Why this table exists at all — the alert rule is "tell somebody when the level goes UP", and that
 * question cannot be answered from the notification feed. A notification says a level was announced;
 * it does not say the level is still current, and a car that recovers and deteriorates again must be
 * able to alert a second time. So the two `notified_*` columns hold the comparison state, and the
 * rest of the row is the read model the vehicle profile renders — computed once, on the event that
 * changed it, instead of on every page load.
 *
 * The readings themselves are NOT a new source of truth: every number here is produced by
 * FleetUtilizationService (visits = type-'U' maintenance contracts, downtime = true off-road shop
 * seconds with rental time excluded). This table caches that answer and remembers what was said
 * about it — nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_garage_alert_states', function (Blueprint $table) {
            $table->id();

            // One row per car. Deleting the car takes its reading with it — the reading is meaningless
            // without the vehicle and must never outlive it as an orphan.
            $table->foreignId('vehicle_id')->unique()->constrained()->cascadeOnDelete();

            // The window the reading was taken over, stored alongside it so a row measured under an
            // older configuration is self-describing rather than silently mis-read.
            $table->unsignedSmallInteger('window_days');

            // ── The reading ────────────────────────────────────────────────────────────────────
            $table->unsignedSmallInteger('visits')->default(0);
            $table->unsignedBigInteger('downtime_seconds')->default(0);
            $table->decimal('downtime_pct', 6, 2)->default(0);

            // ── The grades ─────────────────────────────────────────────────────────────────────
            // normal | warning | high | critical  (App\Support\GarageSeverity)
            $table->string('visit_severity', 16)->default('normal');
            $table->string('downtime_severity', 16)->default('normal');
            $table->string('severity', 16)->default('normal');   // the worse of the two — the car's headline

            // ── What has already been said ─────────────────────────────────────────────────────
            // Per signal, because the two escalate independently: a car can be told about its
            // downtime without that silencing a later visit-frequency climb.
            $table->string('notified_visit_severity', 16)->default('normal');
            $table->string('notified_downtime_severity', 16)->default('normal');
            $table->timestamp('last_notified_at')->nullable();

            // ── Context the card shows ─────────────────────────────────────────────────────────
            $table->boolean('currently_in_garage')->default(false);
            // dateTime, NOT timestamp: a stored moment that must never be rewritten by MariaDB's
            // auto-update behaviour on the first TIMESTAMP column. See [[mariadb-timestamp-autoupdate-trap]].
            $table->dateTime('last_entry_at')->nullable();
            $table->json('reasons')->nullable();   // the plain-sentence "why", already assembled
            $table->dateTime('evaluated_at')->nullable();

            $table->timestamps();

            // The admin read: "show me every car currently asking for attention, worst first."
            $table->index(['severity', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_garage_alert_states');
    }
};
