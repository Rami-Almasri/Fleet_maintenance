<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE + REVERSIBLE. Adds a canonical plate key to vehicles so the app can find every
 * car that has ever carried a given plate (plates get reused after a sale). The column holds
 * the plate's digits (leading zeros stripped) today; it is named generically so it can later
 * hold an emirate-qualified key without a rename. Nullable, indexed, and deliberately NOT
 * unique — a plate legitimately appears on more than one vehicle row over time.
 *
 * Touches no existing column and no data; down() removes it cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('plate_key', 40)->nullable()->after('plate_no')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['plate_key']);
            $table->dropColumn('plate_key');
        });
    }
};
