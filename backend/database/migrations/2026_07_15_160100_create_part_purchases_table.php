<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * part_purchases — the actual money event, plus the bridge that lands a purchased part's cost on the
 * existing maintenance cost chain.
 *
 * A purchase records WHO bought WHAT, from WHERE (a garage that provides the part, OR an external
 * supplier — the system never assumes suppliers), for how much, and when. On INSTALL against a fault it
 * generates a maintenance_line_items row (kind=part) and stores its id here, so cost rolls up through the
 * proven line_item → task → ticket path — no parallel ledger, no double-counting. A purchase with no
 * ticket (a pure customer request) simply carries its own price and never creates a line item.
 *
 * Duplicate detection runs at insert time (same vehicle + same part within a class-dependent window);
 * a tripped purchase is recorded but flagged (`requires_review`) and linked to the earlier one, never
 * silently accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_purchases', function (Blueprint $table) {
            $table->id();

            // The request this buy fulfils. Nullable so an ad-hoc purchase can still be logged, but normally set.
            $table->foreignId('part_request_id')->nullable()->constrained('part_requests')->nullOnDelete();

            // Denormalised anchors — kept even if the source rows are later removed, so history/TCO survives.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // Immutable snapshot of what was bought (a request can be edited; the purchase record is history).
            $table->string('part_name');
            $table->string('part_number')->nullable();
            $table->string('category_key', 60)->nullable();
            $table->string('part_class', 12)->nullable();        // consumable | standard | major (snapshot)

            // WHERE the part came from. The system supports both — a garage can provide & sell the part,
            // or it comes from an external supplier. source_vendor_id points at the vendor either way
            // (a 'garage'-type vendor for a garage buy, a 'parts_supplier'-type for a supplier buy);
            // source_name is a free-text fallback when there's no vendor row.
            $table->string('purchase_source', 12);               // 'garage' | 'supplier'
            $table->foreignId('source_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('source_name')->nullable();

            // Where the repair happens — a purchase inherits its request's location, or is captured directly.
            $table->string('repair_location', 10)->nullable();   // 'garage' | 'onsite'

            // Money (price is REQUIRED at the API layer — "cannot mark purchased without a price").
            $table->decimal('purchase_price', 12, 2);
            $table->string('currency', 3)->default('AED');
            $table->decimal('quantity', 10, 2)->default(1);

            // Responsible person + date (the price history the intelligence layer reads).
            $table->foreignId('purchased_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purchased_by_name')->nullable();
            $table->timestamp('purchased_at')->nullable();

            // Install leg — a part cannot be installed unless it was purchased (this row must exist first).
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('installed_by_name')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->unsignedInteger('installed_odometer')->nullable();

            // Repair outcome — feeds recurrence intelligence ("did the fix hold?").
            $table->string('result', 12)->default('pending');    // 'pending' | 'success' | 'failed'

            // The cost bridge: the line item this install generated (null until installed against a ticket).
            $table->foreignId('maintenance_line_item_id')->nullable()->constrained('maintenance_line_items')->nullOnDelete();

            // Duplicate-detection outcome.
            $table->boolean('requires_review')->default(false);
            $table->foreignId('duplicate_of_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'part_number']);
            $table->index(['part_request_id']);
            $table->index(['purchased_at']);
            $table->index(['purchase_source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_purchases');
    }
};
