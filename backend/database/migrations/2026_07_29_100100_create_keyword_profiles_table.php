<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword PROFILE — the structured engineering metadata behind one findings keyword (1:1).
 *
 * `keyword_terms` answers "what do people call this fault?". This table answers "what IS this
 * fault?" — which vehicle system it belongs to, which components are implicated, what the driver
 * actually reports, what typically causes it, and what a workshop typically does about it.
 *
 * Why a separate table rather than columns on `finding_keywords`: the keyword row is the small,
 * hot, admin-curated menu entry that the inspection picker loads on every ticket. The profile is a
 * fat, AI-generated, occasionally-read knowledge record. Keeping them apart means the picker query
 * never drags a dozen JSON blobs across the wire, and re-running enrichment rewrites exactly one
 * row without touching the curated keyword.
 *
 * `severity_estimate` is deliberately NOT the same field as `finding_keywords.risk`. Risk is the
 * admin's grade — it drives ticket severity and the board's colours, and a human owns it. The
 * estimate is the model's independent opinion, stored beside it so the admin screen can surface a
 * disagreement ("you graded this Routine; OEM documentation treats it as Critical") without ever
 * silently overwriting a human decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_profiles', function (Blueprint $table) {
            $table->id();

            // 1:1 with the concept — unique, so an upsert can key straight off it.
            $table->foreignId('finding_keyword_id')->unique()->constrained()->cascadeOnDelete();

            // Where this fault lives on the car. `vehicle_system` is the coarse system ("Brake
            // System"), `subsystem` the assembly ("Front disc brakes"), `repair_discipline` the
            // trade that fixes it (mechanical / electrical / body / A-C / tyres / diagnostics).
            $table->string('vehicle_system', 80)->nullable()->index();
            $table->string('subsystem', 80)->nullable();
            $table->string('repair_discipline', 40)->nullable()->index();

            // The model's own severity read, on the app's ONE scale (critical / moderate / routine)
            // so it is directly comparable to finding_keywords.risk. Advisory only — see class doc.
            $table->string('severity_estimate', 20)->nullable()->index();

            // Plain-language definition of the fault, for the admin screen and the picker tooltip.
            $table->text('summary_en')->nullable();
            $table->text('summary_ar')->nullable();

            // The knowledge payload. JSON arrays of short strings — deliberately not normalised into
            // their own tables: nothing joins on a component name, they are read as a block, and
            // keeping them here means one write per enrichment instead of five delete-and-reinsert
            // cycles. Promote one out only when something actually needs to query it.
            $table->json('symptoms')->nullable();         // what the driver / inspector reports
            $table->json('components')->nullable();       // parts implicated (pads, rotor, caliper…)
            $table->json('likely_causes')->nullable();    // root causes, most common first
            $table->json('repair_actions')->nullable();   // what the workshop typically does
            $table->json('related_faults')->nullable();   // faults that co-occur or get confused

            // Which bodies of professional documentation the model drew on (values constrained to
            // config('keyword_ai.sources')), and how confident it is in the profile as a whole.
            $table->json('evidence_sources')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);

            // Which run produced this, on which model, and when — so a bad batch can be identified
            // and re-run rather than hand-audited.
            $table->string('model', 60)->nullable();
            $table->timestamp('enriched_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_profiles');
    }
};
