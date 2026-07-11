<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual findings keywords — an Arabic label alongside the English one.
 *
 * The keyword string saved onto maintenances.findings stays ENGLISH (the stable, analytics-friendly
 * key); `keyword_ar` / `category_label_ar` are purely display translations so the picker and the
 * Keyword Risk Library read natively in Arabic when the UI is flipped to RTL. Both nullable — a new
 * keyword can be added English-only and translated later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finding_keywords', function (Blueprint $table) {
            $table->string('keyword_ar', 191)->nullable()->after('keyword');
            $table->string('category_label_ar', 80)->nullable()->after('category_label');
        });
    }

    public function down(): void
    {
        Schema::table('finding_keywords', function (Blueprint $table) {
            $table->dropColumn(['keyword_ar', 'category_label_ar']);
        });
    }
};
