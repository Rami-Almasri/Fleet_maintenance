<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Handover Comparison Report — generated once per resume, ALWAYS (clean or not), diffing the
 * pause handover against the resume handover. This is the permanent record the user asked to have
 * "attached to maintenance history" — see HandoverComparisonService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_handover_comparisons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->unsignedBigInteger('pause_handover_id');
            $table->unsignedBigInteger('resume_handover_id');
            $table->integer('mileage_delta')->nullable();
            $table->integer('fuel_delta')->nullable(); // signed level-index delta on the fuel_scale (e.g. -2)
            $table->json('new_damages')->nullable();
            $table->json('missing_accessories')->nullable();
            $table->json('condition_changes')->nullable();
            $table->boolean('exceeds_threshold')->default(false);
            $table->json('threshold_breaches')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_handover_comparisons');
    }
};
