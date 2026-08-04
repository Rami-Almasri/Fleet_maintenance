<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `repair_visits` — one row per real workshop visit, collapsed from maintenance EVENTS.
 *
 * The legacy sheet recorded one trip to a garage as several rows (OUT → Follow up → IN → Change).
 * Only 23% of (vehicle, garage, out_date) groups are a single row, so counting `maintenances` rows
 * as repairs overstates volume by roughly 2×. Every per-repair metric in Fleet Intelligence reads
 * this table instead.
 *
 * DERIVED AND DISPOSABLE. Never a source of truth: it is rebuilt in full, nightly, by
 * `intelligence:rebuild-visits`, and can be dropped and regenerated from `maintenances` at any time.
 * That is also why there are no foreign keys — a FK to a soft-deletable parent buys no integrity
 * here and creates rebuild-ordering failures.
 *
 * No CHECK constraints: they pass on MariaDB locally and fail on MySQL 8 in production.
 *
 * See docs/Fleet-Intelligence-Execution-Plan.md §1.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('repair_visits')) {
            return;
        }

        Schema::create('repair_visits', function (Blueprint $table) {
            $table->id();

            // Nullable on purpose: 1,738 tickets carry no garage and 646 no vehicle. Those repairs
            // still happened, so they become visits — counted fleet-wide, attributed to nobody.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();

            $table->date('started_at');
            $table->date('ended_at')->nullable();               // null = still open (74% of tickets)
            $table->smallInteger('duration_days')->nullable();  // null when open, or when negative
            $table->boolean('is_open')->default(true);
            $table->boolean('is_cancelled')->default(false);
            $table->boolean('has_close_date')->default(false);

            $table->unsignedSmallInteger('event_row_count')->default(1);
            $table->json('maintenance_ids');                    // the audit trail back to source rows
            $table->unsignedBigInteger('primary_maintenance_id');
            $table->string('origin_mix', 60)->default('');

            // The same car recorded at two garages on one day — 2,325 cases. Flagged, never merged:
            // it may be a real transfer or a data error, and the flag is how we find out.
            $table->boolean('multi_vendor_day')->default(false);

            // Stored per row so a future change to the collapsing rule is auditable rather than
            // silently retroactive. Ships at 0 (same-day) — see the Execution Plan §0.2.
            $table->unsignedTinyInteger('grouping_window_days')->default(0);

            $table->json('signature_set')->nullable();
            $table->timestamp('built_at')->nullable();

            $table->index(['vehicle_id', 'started_at']);
            $table->index(['vendor_id', 'started_at']);
            $table->index('started_at');
            $table->index('is_open');
            $table->unique('primary_maintenance_id');
        });
    }

    public function down(): void
    {
        // Lossless: fully reproducible from `maintenances`.
        Schema::dropIfExists('repair_visits');
    }
};
