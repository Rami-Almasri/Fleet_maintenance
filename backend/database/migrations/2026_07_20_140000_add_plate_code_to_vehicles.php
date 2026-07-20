<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE + REVERSIBLE. A UAE plate is code + number — two cars can share the same digits
 * under different codes and be entirely different plates (e.g. "P 76722" vs "U 76722"). We
 * only ever stored the digits, so plate resolution could wrongly merge them.
 *
 * This adds the plate's CODE, sourced from OM's `PlateColorNo` (which maps to the EmNo →
 * letter dictionary in `plate_codes`). Stored as the raw numeric code; the human plate letter
 * (F / H / P / CC …) is looked up for display. Nullable + indexed; touches no existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('plate_code', 8)->nullable()->after('plate_key')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['plate_code']);
            $table->dropColumn('plate_code');
        });
    }
};
