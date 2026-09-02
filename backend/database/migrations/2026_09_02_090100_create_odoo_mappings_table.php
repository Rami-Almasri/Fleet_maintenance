<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE EXPLICIT ANSWER TO "IS THIS THE SAME THING IN ODOO?".
 *
 * One table, polymorphic over the three things that have to be recognised across the two systems:
 *
 *   Vehicle         → account.analytic.account   (the per-asset account that rolls up cost of ownership)
 *   ComponentCatalog→ product.product            (the part)
 *   Vendor          → res.partner                (the supplier)
 *
 * WHY ONE TABLE AND NOT THREE. They differ in nothing that matters: each says "our row N is Odoo's row
 * M in model X", each needs the same provenance (who decided, when, on what evidence), each needs the
 * same staleness handling, and each is read by the same validator. Three tables would be three copies
 * of one idea, three migrations to keep in step, and three mapping screens. The polymorphic key is
 * (mappable_type, mappable_id, odoo_model), and the unique index on it is the invariant: one row in
 * FleetView maps to at most ONE Odoo record per Odoo model.
 *
 * WHY EXPLICIT MAPPING AND NOT NAME MATCHING. §18 is the reason this table exists at all. "Brake Pad
 * Front" and "Front Brake Pads" are the same part, and no amount of string comparison makes that a fact
 * that may be posted to a ledger. So a name never resolves anything. `matched_by` records HOW the
 * mapping was arrived at — a human choosing it, an external reference matching exactly, or a suggestion
 * a human then confirmed — and a suggestion that nobody has confirmed is not a mapping: it sits at
 * status `suggested` and the validator treats it as absent. Fuzzy matching may PROPOSE; only a person
 * or an exact stable reference may DECIDE.
 *
 * WHY odoo_id IS NOT A FOREIGN KEY TO ANYTHING. It is a row in another database. It is stored with its
 * code and name alongside so that a mapping remains readable even after Odoo master data is re-pulled,
 * and so a mapping pointing at a record that has since vanished from Odoo can be DETECTED (the pull
 * marks it) rather than discovered at push time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_mappings', function (Blueprint $table) {
            $table->id();

            // ── Our side ────────────────────────────────────────────────────────────────────────────
            // Morph columns, matching the convention used elsewhere in the schema (source_type/source_id).
            $table->string('mappable_type', 64);
            $table->unsignedBigInteger('mappable_id');

            // ── Odoo's side ─────────────────────────────────────────────────────────────────────────
            // The Odoo model name, e.g. 'account.analytic.account', 'product.product', 'res.partner'.
            $table->string('odoo_model', 64);
            $table->unsignedBigInteger('odoo_id')->nullable();
            // The stable human reference (default_code for a product, ref for a partner, code for an
            // analytic account). Carried so a mapping can be re-resolved against a different Odoo
            // database (staging → production) without being re-keyed by hand.
            $table->string('odoo_ref', 128)->nullable();
            $table->string('odoo_name')->nullable();

            // ── How much this mapping can be trusted ─────────────────────────────────────────────────
            // mapped     — decided. The validator accepts it.
            // suggested  — proposed by matching, NOT yet confirmed. The validator treats it as absent.
            // unmapped   — explicitly recorded as having no counterpart (keeps it out of the backlog).
            // stale      — the last master-data pull could not find odoo_id any more.
            $table->string('status', 24)->default('mapped');

            // manual | external_ref | vin | plate | suggested — see App\Services\Odoo\OdooMappingService.
            $table->string('matched_by', 32)->nullable();

            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestamp('mapped_at')->nullable();
            // When the mapped Odoo record was last CONFIRMED to still exist by a master-data pull.
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // The invariant: one FleetView row → at most one Odoo record per Odoo model.
            $table->unique(['mappable_type', 'mappable_id', 'odoo_model'], 'odoo_map_unique');
            // The reverse lookup the mappings screen needs ("which car is this analytic account?").
            $table->index(['odoo_model', 'odoo_id'], 'odoo_map_target_idx');
            $table->index(['odoo_model', 'status'], 'odoo_map_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_mappings');
    }
};
