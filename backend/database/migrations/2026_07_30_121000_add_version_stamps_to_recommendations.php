<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full provenance on every recommendation: WHICH GENERATION of the platform produced it.
 *
 * `capability_version` and `engine_version` were already stored. These three complete the picture,
 * and they are separate columns rather than one blob because they move independently — a classifier
 * rebuild does not imply new capability logic, and a storage change behind the query layer implies
 * neither.
 *
 * What this buys, and the only reason it exists: "if this recommendation were generated today,
 * would it be different?" Without the stamps that question is unanswerable, because a card from
 * March cannot be told apart from one today's code would produce. With them, two generations can be
 * compared directly — and, crucially, WITHOUT REWRITING HISTORY, which the append-only guards on
 * these models forbid anyway.
 *
 * Nullable and defaulted: rows written before this migration are honestly unknown, and backfilling
 * a guess would be exactly the rewriting of history the design exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            // Semantics of the historical ANSWERS — not the storage behind them.
            $table->string('query_layer_version', 20)->nullable()->after('engine_version');
            // Shape of the evidence block and the confidence rules applied to it.
            $table->string('evidence_schema_version', 20)->nullable()->after('query_layer_version');
            // The vocabulary that labelled the evidence. Null where a capability uses none.
            $table->string('classifier_version', 20)->nullable()->after('evidence_schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn(['query_layer_version', 'evidence_schema_version', 'classifier_version']);
        });
    }
};
