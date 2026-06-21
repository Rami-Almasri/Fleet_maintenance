<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the customer flags (blacklisted / wanted / is_caution) — no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['blacklisted', 'wanted', 'is_caution']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('blacklisted')->nullable();
            $table->boolean('wanted')->nullable();
            $table->boolean('is_caution')->nullable();
        });
    }
};
