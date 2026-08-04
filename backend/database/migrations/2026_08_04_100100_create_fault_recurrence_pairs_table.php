<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fault_recurrence_pairs` — for every fault event on a car, when did that same fault next appear?
 *
 * This table powers the platform's highest-value metric: "faults sent to this garage come back after
 * N days, against a fleet norm of M". It is read by G1, G2, G3, E6, F5, F6 and the vehicle health
 * score, which is why it is materialised rather than self-joined at request time.
 *
 * ── THE UNIQUE INDEX IS A CORRECTNESS GUARD, NOT AN OPTIMISATION ─────────────────────────────────
 * `maintenance_signatures` holds 2–8 rows for the same fault on the same car on the same day
 * (33,026 raw fault rows → 12,608 distinct events). Building recurrence pairs without collapsing
 * those first counts one real recurrence several times over, which inflated the first published
 * garage figures by 2.5–3×. `unique(vehicle_id, signature, occurred_at)` makes that mistake
 * impossible to reintroduce: a build that forgets to deduplicate fails on insert instead of
 * quietly doubling the numbers.
 *
 * DERIVED AND DISPOSABLE — rebuilt in full nightly by `intelligence:rebuild-recurrence`, which must
 * run after `intelligence:rebuild-visits`. No foreign keys, no CHECK constraints (see the visits
 * migration for both reasons).
 *
 * See docs/Fleet-Intelligence-Execution-Plan.md §1.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fault_recurrence_pairs')) {
            return;
        }

        Schema::create('fault_recurrence_pairs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vehicle_id');
            $table->string('signature', 24);
            $table->date('occurred_at');

            $table->unsignedBigInteger('first_maintenance_id')->nullable();
            $table->unsignedBigInteger('first_vendor_id')->nullable();

            // Null = no recurrence yet. These OPEN CHAINS are kept deliberately: they are the
            // denominator, and a garage whose repairs never come back must be visible as exactly that.
            $table->date('next_occurred_at')->nullable();
            $table->unsignedBigInteger('next_maintenance_id')->nullable();
            $table->unsignedBigInteger('next_vendor_id')->nullable();

            $table->unsignedSmallInteger('days_to_return')->nullable(); // always >= 1 after dedupe
            $table->boolean('returned_30')->default(false);
            $table->boolean('returned_60')->default(false);
            $table->boolean('returned_90')->default(false);
            $table->boolean('same_vendor')->nullable();

            $table->string('label_source', 12)->default('derived'); // derived | human | both

            // How many raw signature rows collapsed into this event — the audit trail for the
            // deduplication, and the number that proves it happened.
            $table->unsignedSmallInteger('source_row_count')->default(1);

            $table->boolean('multi_vendor_day')->default(false);
            $table->unsignedSmallInteger('chain_position')->default(1);
            $table->unsignedSmallInteger('chain_length')->default(1);

            $table->timestamp('built_at')->nullable();

            $table->unique(['vehicle_id', 'signature', 'occurred_at'], 'frp_event_unique');
            $table->index(['first_vendor_id', 'signature']);
            $table->index(['signature', 'occurred_at']);
            $table->index('days_to_return');
        });
    }

    public function down(): void
    {
        // Lossless: fully reproducible from `maintenance_signatures` + `maintenances`.
        Schema::dropIfExists('fault_recurrence_pairs');
    }
};
