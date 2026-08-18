<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE THE BILLED PART CAME FROM.
 *
 * A part line already says WHICH part was fitted (component_catalog_id) and WHAT it cost. It could not
 * say where that part came from — so a bill could name a price nobody recorded buying, and the parts
 * ledger and the invoice could disagree with no way to tell which one was wrong.
 *
 * These two columns close that: every billed part points back at the record it was billed FROM —
 * the purchase that was paid for, the request that was raised, or the inspector's required-part line.
 * That is what lets the invoice form fill the price in instead of asking for it, what lets the garage /
 * supplier split be enforced (a part bought from a supplier is refused on a garage's bill), and what
 * lets the page show a Data Origin for every dirham rather than a number with nothing behind it.
 *
 * Deliberately NOT a foreign key: the three sources live in three tables, so the pair is a polymorphic
 * reference. Nullable because history written before the sources existed has no answer, and inventing
 * one would be worse than admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->string('part_source', 16)->nullable()->after('catalog_matched_by');
            $table->unsignedBigInteger('part_source_id')->nullable()->after('part_source');
            $table->index(['part_source', 'part_source_id'], 'mli_part_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->dropIndex('mli_part_source_idx');
            $table->dropColumn(['part_source', 'part_source_id']);
        });
    }
};
