<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much effort each workflow step costs the person completing it.
 *
 * WHY THIS IS MEASURED AT ALL. A technically perfect capture model that technicians route around is
 * a failed design, and the failure is invisible from the data side: the records look thin, and the
 * obvious conclusion ("staff aren't filling it in") is exactly the wrong one if the real cause is a
 * step that takes four minutes and asks twice for the same thing. Friction has to be measured
 * directly or it gets diagnosed as apathy.
 *
 * NOT IN `domain_events`. This is telemetry about the product, not evidence about a vehicle — a
 * technician taking a long time says nothing about the car. Mixing it into the canonical log would
 * put UX analytics in the same table future models treat as automotive fact.
 *
 * DELIBERATELY NOT PER-USER-PUNITIVE. `user_id` is kept because a step that only one person struggles
 * with is a training question rather than a design one, but the intended reading is always the STEP,
 * not the person. Anyone using this to rank technicians is using it wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capture_friction', function (Blueprint $table) {
            $table->id();

            $table->string('step', 48);                 // submit_report | close | verify …
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // How long the step took, measured client-side from when the form opened. Nullable
            // because a client that does not report it must not block the row: a partial telemetry
            // record still answers the skip questions.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedSmallInteger('fields_offered')->default(0);
            $table->unsignedSmallInteger('fields_filled')->default(0);

            // Which optional fields were passed over. The single most actionable column here: a field
            // skipped by everyone is a field that should be removed or defaulted, and this is the
            // evidence for making that call instead of arguing about it.
            $table->json('skipped_fields')->nullable();

            // Set when a later submission edits what this one recorded — a correction is friction
            // that already cost someone twice.
            $table->boolean('was_corrected')->default(false);
            $table->unsignedInteger('reopened_count')->default(0);

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['step', 'occurred_at']);
            $table->index('maintenance_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_friction');
    }
};
