<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Symptom → Root-Cause knowledge base.
 *
 * Evolves the maintenance workflow from flat keyword tagging into a structured diagnostic: every
 * findings keyword (the "symptom", e.g. "Overheating") maps to a curated short-list of probable
 * root causes (e.g. "Coolant leak", "Water-pump failure"). The inspector / mechanic must pick a
 * cause for each symptom; the chosen `root_cause` label is what we persist on the finding and will
 * later sync to Odoo as part of the maintenance contract data.
 *
 * This table is BOTH the master list AND the review queue:
 *   - `status = approved`  → a curated cause; shown in the picker for its symptom.
 *   - `status = pending`   → a custom cause a user typed in (not in the list yet); hidden from the
 *                            picker, surfaced to an admin who can approve it (→ joins the master list)
 *                            or reject it. This is the "flag for administrative review" loop.
 *   - `status = rejected`  → reviewed and declined; kept for the audit trail, never shown.
 *
 * `usage_count` is the cheap analytics hook ("why do Jetour T2s overheat?" = group findings by
 * symptom + this cause); `odoo_ref` is the placeholder for the eventual external-system id so the
 * mapping is Odoo-sync ready without another migration.
 *
 * Findings themselves stay in the existing `maintenances.findings` JSON — each entry simply gains a
 * `root_cause` (label) + `root_cause_id` (FK here) key, so NO schema change is needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_causes', function (Blueprint $table) {
            $table->id();

            // The symptom this cause explains. `symptom_key` is the normalised (lowercased, trimmed)
            // match key so "Overheating" and "overheating" collapse together; `symptom_label` is the
            // human keyword exactly as it appears in the findings catalog. Capped at 191 so the
            // composite unique index stays within InnoDB's utf8mb4 key-length limit.
            $table->string('symptom_key', 191)->index();
            $table->string('symptom_label', 191);
            // Which findings category the symptom belongs to (engine / brakes / …), carried for
            // richer trend reporting. Nullable — a custom symptom may not map to a known category.
            $table->string('category_key', 60)->nullable();

            // The root cause itself.
            $table->string('root_cause', 191);
            $table->string('description', 500)->nullable(); // optional mechanic-facing hint

            // Lifecycle. Seeded/admin-approved causes are immediately live; user-typed ones land
            // 'pending' for review. Indexed because the picker query is "approved for this symptom".
            $table->string('status', 20)->default('approved')->index();
            $table->string('source', 20)->default('seed'); // seed | user

            // Analytics + Odoo-readiness.
            $table->unsignedInteger('usage_count')->default(0);
            $table->string('odoo_ref', 120)->nullable()->index(); // external id for the eventual sync

            // Review trail.
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOndelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            // One row per (symptom, cause): a custom submission that matches an existing pair reuses
            // the row (firstOrCreate) instead of piling up duplicates.
            $table->unique(['symptom_key', 'root_cause'], 'fault_causes_symptom_cause_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_causes');
    }
};
