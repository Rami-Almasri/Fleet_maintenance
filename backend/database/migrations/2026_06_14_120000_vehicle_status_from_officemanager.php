<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle status now mirrors the OfficeManager AssetStatusNo (the real source of truth):
 *   1 office_use · 2 ready · 3 rented · 4 out_of_order · 5 under_maintenance
 *   6 suspended · 7 disposed · 8 sold · 9 returned
 * "For sale" is a SEPARATE API boolean (ForSale), not a status — so it becomes its own
 * column. The old made-up enum is converted to a string and legacy values are remapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->unsignedTinyInteger('status_no')->nullable()->after('status'); // raw API AssetStatusNo
            $t->boolean('for_sale')->default(false)->after('status_no');       // API ForSale flag
        });

        // enum -> string so it can hold the OfficeManager slugs
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('status', 32)->default('ready')->change();
        });

        // preserve the old "for_sale" status as the new boolean flag
        DB::table('vehicles')->where('status', 'for_sale')->update(['for_sale' => true]);

        // remap legacy made-up statuses to the OfficeManager set
        $map = [
            'active'          => 'ready',
            'for_sale'        => 'ready',
            'insurance_claim' => 'out_of_order',
            'personal'        => 'office_use',
            'under_process'   => 'suspended',
            'exported'        => 'disposed',
            'office'          => 'office_use',
        ];
        foreach ($map as $old => $new) {
            DB::table('vehicles')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropColumn(['status_no', 'for_sale']);
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('status')->default('active')->change();
        });
    }
};
