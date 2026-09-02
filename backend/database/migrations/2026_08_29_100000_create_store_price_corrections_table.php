<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correcting what a shelf costs — the repair path a poisoned average had no way back from.
 *
 * A shelf keeps a weighted average, and every priced receipt blends into it. One AC compressor
 * received at the wrong price left a shelf reading AED 1,161 a unit, and NOTHING in the system could
 * put it right: `adjust()` deliberately carries no unit cost (a count correction moves no money and
 * must never re-price stock), and a receipt only ever blends further. The gate added alongside this
 * stops new damage; this is how existing damage gets undone.
 *
 * WHY ITS OWN TABLE, and not a row in store_movements:
 * the movement ledger is a ledger of UNITS. Every row carries a quantity and a `qty_after`, and
 * everything that sums or replays it assumes those mean something. A re-price moves no units — a
 * zero-quantity row would either break `qty_after` continuity or teach every reader of that ledger
 * to special-case a row that is not a movement. The two facts are different kinds of fact, so they
 * get different homes.
 *
 * Every correction keeps the old figure as well as the new one. "It was 1,161 and someone made it
 * 322 because the second receipt was keyed at ten times the real price" is the whole point; storing
 * only the new value would leave the shelf correct and the reason for it unknowable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_price_corrections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_item_id')->constrained('store_items')->cascadeOnDelete();

            // Both sides of the change. `old_avg_unit_cost` is nullable because a shelf that was
            // never costed can be given its first price this way too.
            $table->decimal('old_avg_unit_cost', 12, 2)->nullable();
            $table->decimal('new_avg_unit_cost', 12, 2);
            $table->string('currency', 3)->default('AED');

            // Required by the service. A price that changed with nobody saying why is
            // indistinguishable from the mistake it was meant to fix.
            $table->text('reason');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            // dateTime, not timestamp: a stored moment MariaDB must never auto-update.
            $table->dateTime('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['store_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_price_corrections');
    }
};
