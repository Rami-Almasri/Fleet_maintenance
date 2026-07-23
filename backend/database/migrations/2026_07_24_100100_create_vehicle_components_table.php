<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vehicle_components — ONE physical asset instance (Asset Layer, Phase 1).
 *
 * Created once when the part enters our world; NEVER deleted and never moved to another table.
 * The install and removal legs are filled in place; `status` + `location` say where the part is
 * NOW ("physical truth"):
 *
 *   status:   in_stock | active | retired          (retired is absorbing)
 *   location: on_vehicle | warehouse | refurb | supplier | scrapped | sold
 *
 * Invariants (enforced in the service layer, model guard as last line of defense — Phase 2):
 *   - active <=> location=on_vehicle and vehicle_id set; in_stock/retired => vehicle_id NULL.
 *   - at most ONE active row per (vehicle, catalog, position) — predecessor is closed under
 *     lockForUpdate inside the install transaction.
 *   - the removal leg (reason + disposition + odometer) is written atomically or not at all:
 *     a removed part must always say WHY it came off and WHERE it went.
 *
 * This table is the ASSET ledger — billing stays on maintenance_line_items untouched; the two
 * link via source_line_item_id / source_part_purchase_id (provenance + backfill idempotency keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_components', function (Blueprint $table) {
            $table->id();

            // What kind of thing this is. restrictOnDelete: a catalog entry with instances is
            // retired via is_active=false, never deleted.
            $table->foreignId('component_catalog_id')->constrained('component_catalog')->restrictOnDelete();

            // Which car it is on NOW. NULL = warehouse / disposed. nullOnDelete: destroying a
            // vehicle row must not destroy asset history.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // Identity.
            $table->string('serial_no', 80)->nullable()->index();   // required for serialized catalogs (service-level guard)
            $table->string('part_number', 80)->nullable()->index();
            $table->string('brand', 80)->nullable()->index();
            $table->string('model', 120)->nullable();
            $table->string('label', 160)->nullable();               // assembled display string
            $table->decimal('quantity', 8, 2)->default(1);          // batch mode; serialized always 1
            $table->string('position', 20)->nullable();             // slot on the car, validated vs catalog position_scheme

            // Where it is now.
            $table->string('status', 20);                           // in_stock | active | retired
            $table->string('location', 30);                         // on_vehicle | warehouse | refurb | supplier | scrapped | sold

            // ---- Install leg ----
            $table->dateTime('installed_at')->nullable();
            $table->unsignedInteger('installed_odometer')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('installed_by_name', 120)->nullable();
            $table->string('technician_name', 120)->nullable();     // PHYSICAL fitter (free text until a technician entity exists)
            $table->foreignId('installer_vendor_id')->nullable()->constrained('vendors')->nullOnDelete(); // workshop that fitted it
            $table->foreignId('supplier_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();  // who sold it to us
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->date('warranty_until')->nullable()->index();    // derived in the model: installed_at + warranty_months

            // ---- Provenance ----
            $table->foreignId('source_part_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();
            $table->foreignId('source_line_item_id')->nullable()->constrained('maintenance_line_items')->nullOnDelete();
            $table->string('source', 20);                           // workflow | manual | legacy_backfill

            // ---- Removal leg (filled in place — the row never moves) ----
            $table->dateTime('removed_at')->nullable();
            $table->unsignedInteger('removed_odometer')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removed_by_name', 120)->nullable();
            $table->string('removal_reason', 30)->nullable()->index();  // WHY it came off
            $table->text('removal_note')->nullable();
            $table->string('disposition', 30)->nullable()->index();     // WHERE it went — mandatory whenever removed
            $table->foreignId('removal_maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();

            $table->timestamps();

            // Hot paths.
            $table->index(['vehicle_id', 'status']);                                      // "current components" read
            $table->index(['component_catalog_id', 'vehicle_id', 'position', 'status'], 'vc_slot_lookup_idx'); // predecessor lookup at install
            $table->index(['status', 'location']);                                        // warehouse inventory
        });

        // Successor chain (self-FK) — added after create so down() stays clean.
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->foreignId('replaced_by_component_id')->nullable()
                ->constrained('vehicle_components')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_component_id');
        });

        Schema::dropIfExists('vehicle_components');
    }
};
