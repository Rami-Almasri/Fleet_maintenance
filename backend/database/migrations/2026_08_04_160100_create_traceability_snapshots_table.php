<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * traceability_snapshots — the progress metric for the legacy cleanup.
 *
 * "0% of our cost is traceable" is a fact, but it is not a metric: it says nothing about whether anyone is
 * fixing it. A snapshot taken on a schedule turns it into a trend management can actually manage —
 * verified money going up, legacy money going down, week after week.
 *
 * Each row is one measurement of the WHOLE fleet at a moment:
 *
 *     verified            money whose every amount traces to a document
 *     legacy_unverified   money recorded before the documents existed (a known, shrinking backlog)
 *     unverified          money recorded AFTER the rules, with no document — should be zero, and any
 *                         non-zero value is a real problem rather than a historical one
 *
 * Kept deliberately separate from kpi_snapshots: this measures the honesty of the financial record, not
 * an operational outcome, and mixing them would invite someone to average the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traceability_snapshots', function (Blueprint $table) {
            $table->id();

            $table->date('taken_on')->unique();   // one measurement per day; re-running replaces it
            $table->timestamp('taken_at');

            $table->unsignedInteger('tickets_total')->default(0);
            $table->unsignedInteger('tickets_verified')->default(0);
            $table->unsignedInteger('tickets_legacy')->default(0);

            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('verified_cost', 14, 2)->default(0);
            $table->decimal('legacy_cost', 14, 2)->default(0);
            $table->decimal('unverified_cost', 14, 2)->default(0);

            // Verified ÷ total, stored so a chart never has to recompute it from three columns.
            $table->decimal('coverage_pct', 5, 2)->default(0);

            // The split by document type, so "where is our documented money coming from" is answerable
            // historically and not only right now.
            $table->json('by_source')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traceability_snapshots');
    }
};
