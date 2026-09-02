<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a price jumped, kept beside the receipt that jumped it.
 *
 * The shelf holds a weighted average, and every priced receipt silently re-weights it. Two AC
 * compressors bought at wildly different prices average to a figure that is true of neither — and
 * that figure is what {@see StoreService::issueToRequest} charges the next car. So a mistyped price
 * does not just look wrong on the shelf, it prices every future job off that shelf.
 *
 * The explanation goes in its own column rather than into `note`, which is optional free text
 * somebody may or may not have filled in. A dedicated column is what makes "show me every receipt
 * that moved a shelf price, and what they said about it" a query instead of a grep.
 *
 * Nullable and additive: every existing movement keeps its meaning, and a receipt at a price nobody
 * had to explain stores NULL rather than an empty string, so "no explanation was required" and
 * "someone typed nothing" stay different facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_movements', function (Blueprint $table) {
            $table->text('price_variance_note')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('store_movements', function (Blueprint $table) {
            $table->dropColumn('price_variance_note');
        });
    }
};
