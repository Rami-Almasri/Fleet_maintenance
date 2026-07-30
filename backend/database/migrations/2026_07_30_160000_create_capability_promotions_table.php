<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every promotion decision the platform has made about its own evidence — including the refusals.
 *
 * A capability moves from proxy reasoning to measured reasoning only when a backtest says the
 * measured outcome predicts at least as well. That decision is a claim about the world, so it is
 * recorded like every other claim here: append-only, with the numbers that produced it, and never
 * edited. A later re-run that reaches the opposite conclusion writes a NEW row.
 *
 * REFUSALS ARE THE POINT. "We had enough verdicts and the measured model was worse" is the single
 * most valuable row this table can hold, and it is exactly the row a system that only logged
 * successes would throw away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_promotions', function (Blueprint $table) {
            $table->id();

            $table->string('capability_id', 64)->index();
            $table->string('from_basis', 20);          // proxy
            $table->string('to_basis', 20);            // measured

            // The verdict on the promotion itself.
            $table->boolean('promoted');
            $table->string('reason', 500);

            // Both sides of the comparison, frozen — so the decision stays auditable after the data
            // and the thresholds have both moved on.
            $table->json('proxy_metrics')->nullable();
            $table->json('measured_metrics')->nullable();

            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('evidence_threshold')->default(0);

            // Which generation of the platform ran the comparison.
            $table->string('capability_version', 20)->nullable();
            $table->string('query_layer_version', 20)->nullable();

            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['capability_id', 'promoted', 'decided_at'], 'cap_promo_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_promotions');
    }
};
