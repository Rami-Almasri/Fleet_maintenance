<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * store_items — the STOREHOUSE shelf: one row per part TYPE we keep in stock, and how many of it
 * we have right now.
 *
 * This is the fleet's own inventory, and it exists because a part does not have to be bought for a
 * car that is already in the workshop. Someone buys ten oil filters in March; a car needs one in
 * June. Until now the system had no place to put those ten filters, so the only way to fit one was
 * to record a fresh purchase — which invented a supplier, invented a price, and made the March buy
 * look like it evaporated.
 *
 * `qty_on_hand` is a CACHE of the movement ledger, never an independent truth: every change to it
 * is written by StoreService inside a transaction that also writes the matching store_movements
 * row, with this row locked. `php artisan store:verify` replays the ledger and proves the two
 * agree. Nothing else may touch this column.
 *
 * IDENTITY. `stock_key` is what stops the same part from occupying two shelves under two spellings.
 * It is derived (never typed): 'cat:{component_catalog_id}' when the part is in the catalog — the
 * strongest identity anyone can assert — and 'name:{part_name_key}' when it is not. That mirrors
 * PartIdentityService's two rungs exactly, so a shelf found by catalog id and a shelf found by
 * wording can never both exist for a catalogued part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_items', function (Blueprint $table) {
            $table->id();

            // WHICH part this shelf holds. The catalog reference is the identity; part_name is the
            // wording a human reads, and part_name_key is that wording normalised (the fallback
            // identity for a part the catalog does not carry yet).
            $table->foreignId('component_catalog_id')->nullable()->constrained('component_catalog')->nullOnDelete();
            $table->string('stock_key', 191)->unique();      // 'cat:12' | 'name:oil filter'
            $table->string('part_name');
            $table->string('part_name_key', 191)->nullable()->index();
            $table->string('part_number')->nullable();
            $table->string('category_key', 60)->nullable();

            // The stock itself. qty_on_hand is written ONLY by StoreService (see the class note).
            $table->decimal('qty_on_hand', 12, 2)->default(0);
            // The level at which the shelf is "running low" — advisory, drives the Low stock tile.
            // Null means nobody has set one, which is NOT the same as zero and is shown as such.
            $table->decimal('min_qty', 12, 2)->nullable();

            // What a unit off this shelf costs us. avg_unit_cost is the weighted average across
            // everything received (the figure an issue is priced at, so the car is charged what the
            // fleet actually paid); last_unit_cost is the most recent receipt, kept because "what
            // does one cost today" is a different question from "what is the shelf worth".
            $table->decimal('avg_unit_cost', 12, 2)->nullable();
            $table->decimal('last_unit_cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');

            // Where in the storehouse it physically sits — a shelf/bin label, free text on purpose.
            $table->string('location', 120)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['component_catalog_id']);
            $table->index(['is_active', 'qty_on_hand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_items');
    }
};
