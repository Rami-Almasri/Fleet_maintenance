<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen operational baselines.
 *
 * The whole point of this table is the measurement you cannot go back and take. Once a capture step
 * ships, "what was the comeback rate before?" has no honest answer unless somebody wrote it down
 * first — and the reconstructed version is always the flattering one.
 *
 * The full metric list is stored as JSON rather than as columns, deliberately: the KPI set will
 * change, and a snapshot must record what was measurable AT THE TIME, including which metrics were
 * blocked and why. A fixed column layout would quietly rewrite history every time the list evolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('label');            // "before-capture-workflow"
            $table->json('metrics');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
    }
};
