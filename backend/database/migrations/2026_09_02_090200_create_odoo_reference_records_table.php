<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LOCAL CACHE OF ODOO MASTER DATA — SO THE MAPPING SCREEN HAS SOMETHING TO PICK FROM.
 *
 * Odoo is authoritative for accounts, products, partners and analytic accounts (§26, §42). This table
 * is NOT a second copy of the chart of accounts and is never read as an accounting source. Its only job
 * is that a person mapping a vehicle to an analytic account needs a searchable list, and hitting Odoo's
 * RPC on every keystroke of a picker is not a design.
 *
 * Everything here is therefore DISPOSABLE. `odoo:pull-master-data` truncates-and-rewrites per model;
 * losing it costs one command. Nothing downstream may depend on a row being present — the validator
 * reads {@see odoo_mappings}, never this table, precisely so that an empty or stale cache can never be
 * the reason a correct mapping stops working.
 *
 * `active` mirrors Odoo's own archive flag, and `seen_at` is when the last pull confirmed the record.
 * Together they are how a mapping goes stale visibly: a pull that no longer returns an id leaves the
 * cached row un-refreshed, and OdooMasterDataService marks the mappings that point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_reference_records', function (Blueprint $table) {
            $table->id();

            // 'account.account' | 'product.product' | 'res.partner' | 'account.analytic.account'
            $table->string('odoo_model', 64);
            $table->unsignedBigInteger('odoo_id');

            $table->string('name');
            // default_code (product) / ref (partner) / code (account, analytic account).
            $table->string('code', 128)->nullable();
            // Anything else worth showing in the picker that differs per model (account type, partner
            // supplier flag, product uom). Kept as JSON rather than as columns because it is display
            // detail for a picker, not something anything joins on.
            $table->json('payload')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('seen_at')->nullable();

            $table->timestamps();

            $table->unique(['odoo_model', 'odoo_id'], 'odoo_ref_unique');
            $table->index(['odoo_model', 'active'], 'odoo_ref_model_active_idx');
            $table->index(['odoo_model', 'code'], 'odoo_ref_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_reference_records');
    }
};
