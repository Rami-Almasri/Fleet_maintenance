<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet "Status" sheet (the human-maintained master tab) carries a human "Category" column —
 * Premium Sedan / Premium SUV / Economy / … — that describes the car's rental segment. Our existing
 * `category` column holds the OfficeManager API's opaque group CODE ("101" for the whole fleet), so we
 * keep the readable sheet value in its own field rather than clobber the API code. Cost Intelligence
 * rolls expense up by this segment ("which category costs us the most").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('sheet_category')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('sheet_category');
        });
    }
};
