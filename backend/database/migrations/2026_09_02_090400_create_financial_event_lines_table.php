<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE POSTABLE LINE OF A FINANCIAL EVENT — A POINTER, NOT A COPY.
 *
 * A vendor bill in Odoo has lines, so an event that becomes one needs lines. The question §21 forces is
 * whether those lines are new data. They are not: for a garage bill every line already exists as a
 * {@see \App\Models\MaintenanceLineItem} with its part, quantity and unit price, and re-typing that into
 * a finance table would create two versions of one truth that drift the first time somebody edits an
 * invoice.
 *
 * So a line here is (origin_type, origin_id) → the operational row, plus the three things the operational
 * row does NOT know:
 *
 *   1. WHICH ODOO PRODUCT it posts as. Resolved through odoo_mappings from the line's catalog part, and
 *      snapshotted here at send time so the posted document stays explainable after a re-mapping.
 *   2. THE FROZEN FIGURES. quantity/unit_price/line_total are refreshed from the origin on every
 *      validation and frozen when the event is sent — for exactly the reason financial_events.amount is
 *      (see that migration): what Odoo was told is history and may not be silently rewritten.
 *   3. THE LABEL that was posted. A garage's own wording is evidence; Odoo shows the product name. The
 *      description sent is recorded so the two can be compared later.
 *
 * A line whose origin is null is legitimate and is not a loophole: a recovery tow or a registration fee
 * has a cost and no part. Those events carry a SINGLE line describing the service, with the expense
 * account standing in for the product — which is exactly how Odoo records a bill for a service that is
 * not in the product catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_event_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('financial_event_id');

            // ── The operational row this line IS (null for a service with no catalogued part) ────────
            $table->string('origin_type', 64)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();

            // The catalog part, when there is one — this is what the Odoo product mapping hangs off.
            $table->unsignedBigInteger('component_catalog_id')->nullable();

            // part | labor | service | vat | discount — mirrors MaintenanceLineItem::KINDS plus the
            // 'service' kind used by events that bill a service rather than work on a fault.
            $table->string('kind', 24)->default('service');

            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('uom', 24)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            // ── The mapping snapshot for THIS line, written at send time ─────────────────────────────
            $table->unsignedBigInteger('odoo_product_id')->nullable();
            $table->string('odoo_product_ref', 128)->nullable();

            $table->timestamps();

            $table->index('financial_event_id', 'fel_event_idx');
            $table->index(['origin_type', 'origin_id'], 'fel_origin_idx');
            $table->index('component_catalog_id', 'fel_catalog_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_event_lines');
    }
};
