<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * store_movements — the append-only ledger of every unit that entered or left the storehouse.
 *
 * store_items.qty_on_hand is a running total; THIS is the evidence behind it. Every row says how
 * many moved, in which direction, why, what the shelf held afterwards (`qty_after`), and — for an
 * issue — which car, ticket, request and purchase consumed it. That last chain is what makes the
 * question "where did the ten filters go?" answerable a year later, and it is why an issue is never
 * a bare decrement.
 *
 * Rows are never updated and never deleted. A mistake is corrected by an opposing `adjustment`
 * movement carrying its own note, so the correction is part of the history rather than a hole in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_item_id')->constrained('store_items')->cascadeOnDelete();

            $table->string('direction', 3);                  // 'in' | 'out'
            $table->string('reason', 24);
            //   in : opening | receipt | return_from_vehicle | adjustment
            //   out: issue | write_off | adjustment

            // Always POSITIVE. The direction column carries the sign — a negative quantity here
            // would let the same fact be written two ways and break every SUM in the reports.
            $table->decimal('quantity', 12, 2);
            // The shelf level immediately after this movement, stamped inside the locked
            // transaction that wrote it. This is what `store:verify` replays to prove the cache.
            $table->decimal('qty_after', 12, 2);

            // What a unit was worth in THIS movement (receipt price, or the average an issue was
            // priced at). Null for a pure count correction, where no money changed hands.
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');

            // WHERE IT WENT / WHERE IT CAME FROM. All nullable — a stock receipt has a supplier and
            // no car; an issue has a car and no supplier.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('part_request_id')->nullable()->constrained('part_requests')->nullOnDelete();
            $table->foreignId('part_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();
            $table->foreignId('store_stock_request_id')->nullable()->constrained('store_stock_requests')->nullOnDelete();
            $table->foreignId('supplier_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            $table->text('note')->nullable();

            // Audit: the id may vanish with a user, the name never does.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            // A stored moment, not a row timestamp — dateTime(), never timestamp(): MariaDB
            // auto-updates a bare TIMESTAMP column on every write. See the timestamp-autoupdate trap.
            $table->dateTime('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['store_item_id', 'id']);
            $table->index(['direction', 'occurred_at']);
            $table->index(['vehicle_id']);
            $table->index(['part_purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_movements');
    }
};
