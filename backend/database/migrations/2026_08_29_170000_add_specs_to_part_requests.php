<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spec travels with the part from the FIRST moment it is named.
 *
 * The specification layer gave `specs` to everything that records a real part, but the part REQUEST
 * — the intent to buy, raised before any of it exists — was left out, and that is the earliest and
 * most useful place to state one. "Get a battery" sends the buyer to the counter to guess; "get a
 * 12V 60Ah battery, positive on the right" does not, and the guess is exactly what produces the
 * wrong-size returns this layer exists to end.
 *
 * It also completes the chain the variant report reads: requested → bought → fitted, one shape at
 * every step. @see \App\Support\PartSpecs
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('part_requests', 'specs')) {
            return;
        }

        Schema::table('part_requests', function (Blueprint $table) {
            $table->json('specs')->nullable()->after('part_number');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('part_requests', 'specs')) {
            Schema::table('part_requests', fn (Blueprint $table) => $table->dropColumn('specs'));
        }
    }
};
