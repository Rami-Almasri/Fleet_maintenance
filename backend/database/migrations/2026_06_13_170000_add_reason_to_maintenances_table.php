<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each maintenance to its situation in the controlled vocabulary
 * (maintenance_reasons), so its priority/status comes from the reason rather
 * than re-classifying free text every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('maintenance_reason_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('maintenance_reasons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_reason_id');
        });
    }
};
