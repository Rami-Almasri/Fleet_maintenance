<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_line_items — the structured Parts + Labor breakdown of a maintenance ticket's cost.
 *
 * Until now all repair money was a single scalar `maintenances.cost`. This table sits UNDERNEATH
 * that scalar: each row is one billable line — a replaced part (with quantity, unit price and the
 * install date that powers durability/warranty tracking) or a labor charge (hours × rate). The
 * ticket's `cost` becomes the sum of these lines (parts_total + labor_total) once it is itemised,
 * so every existing reader of `maintenances.cost` (dashboard, profitability, RealProfitService,
 * the closing summary) keeps working with no change.
 *
 * The shape is deliberately Odoo-friendly: a part line maps to a BOM component / vendor-bill line,
 * a labor line to an expense / service product, `category_key` to a product category / analytic
 * account, and `vehicle_id` to the per-asset analytic account that drives total-cost-of-ownership.
 * The `odoo_*` columns are left empty for a later sync job — no re-migration needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Denormalised so the per-vehicle TCO and part-lifespan reports are a single grouped
            // scan, never a join back through maintenances. nullOnDelete keeps the line's history
            // even if the vehicle row is later removed.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            $table->string('kind', 12);                          // 'part' | 'labor'
            // Optional link to the specific finding this line repairs (matches a findings[].text,
            // the same convention repair_hours uses) — lets a report tie spend back to a fault.
            $table->string('finding_text')->nullable();
            $table->string('category_key', 40)->nullable();      // Findings-catalog key → Odoo category / analytic acct

            // --- description ---
            $table->string('description');                       // part name OR labor description ("Front brake job")
            $table->string('part_number')->nullable();           // SKU / OEM ref — the stable identity lifespan tracking keys on

            // --- money (line_total is stored = round(quantity * unit_price, 2)) ---
            $table->decimal('quantity', 10, 2)->default(1);      // parts: count · labor: hours
            $table->string('uom', 16)->default('unit');          // 'unit' | 'hour' | 'litre' … (Odoo unit of measure)
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            // --- durability / warranty (parts only) ---
            $table->date('installed_on')->nullable();            // the install date — anchors "how long did this part last"
            $table->unsignedInteger('installed_odometer')->nullable(); // km at install → wear-per-km lifespan
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->date('warranty_until')->nullable();          // derived = installed_on + warranty_months

            // --- Odoo sync bookkeeping (filled by a later export job) ---
            $table->string('odoo_product_ref')->nullable();      // → product.product / BOM component
            $table->string('odoo_external_id')->nullable();
            $table->timestamp('odoo_synced_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['maintenance_id', 'kind']);           // per-ticket totals
            $table->index(['vehicle_id', 'category_key']);       // lifespan / spend by part category
            $table->index(['part_number', 'vehicle_id']);        // "how long did THIS part last" on this car
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_line_items');
    }
};
