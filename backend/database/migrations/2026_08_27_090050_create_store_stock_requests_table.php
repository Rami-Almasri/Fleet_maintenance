<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * store_stock_requests — "we need this part ON THE SHELF", with no car attached.
 *
 * The second door into procurement, and the reason this table is not part_requests. A PartRequest is
 * the intent to fit a part TO A VEHICLE: vehicle_id is required on it, its duplicate engine asks
 * "has THIS CAR had this part before", and its install step bills a ticket. None of that is true of
 * stocking the storehouse — there is no car, there is no fault, and there is nothing to bill until
 * the part is later issued to a job. Forcing a stock buy through part_requests would have meant
 * inventing a vehicle for it, and every duplicate warning and cost roll-up downstream would then be
 * describing a car that was never involved.
 *
 * Lifecycle: requested → approved → ordered → received, with rejected/cancelled off-ramps. RECEIVED
 * is the only step that moves stock: it is the moment the part is physically on the shelf, and it is
 * what StoreService turns into a `receipt` movement and a +qty on the store_item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_stock_requests', function (Blueprint $table) {
            $table->id();

            $table->string('status', 12)->default('requested')->index();

            // The shelf this will land on. Null until it is known — a request for a part we have
            // never stocked has no store_item yet, and one is created at receipt.
            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->nullOnDelete();

            // WHAT is being asked for. Same identity pair as everywhere else: the catalog reference
            // is the identity, the wording is the evidence of what was asked for.
            $table->foreignId('component_catalog_id')->nullable()->constrained('component_catalog')->nullOnDelete();
            $table->string('part_name');
            $table->string('part_name_key', 191)->nullable()->index();
            $table->string('part_number')->nullable();
            $table->string('category_key', 60)->nullable();

            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('estimated_price', 12, 2)->nullable();     // per unit, at request time
            $table->string('currency', 3)->default('AED');
            $table->text('reason');                                    // WHY the shelf needs it
            $table->text('notes')->nullable();

            // The buy, filled in at receipt.
            $table->foreignId('supplier_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('supplier_name')->nullable();               // free text for a walk-in
            $table->decimal('unit_cost', 12, 2)->nullable();           // what a unit ACTUALLY cost
            $table->decimal('received_quantity', 12, 2)->nullable();   // may differ from what was asked

            // ── audit stamps (who + when at each step) ──
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->dateTime('requested_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_by_name')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->dateTime('received_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['component_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_stock_requests');
    }
};
