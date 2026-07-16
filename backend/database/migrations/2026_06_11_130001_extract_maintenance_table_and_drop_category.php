<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize maintenance off the contracts table:
 *   - The maintenance-only columns (vendor/tags/responsible/approved_by/
 *     expected_return_date/notes) were NULL on every rent contract. Move them into
 *     a dedicated `maintenances` table (one row per maintenance contract).
 *   - Drop `category` — it's now derived from `contract_type` (C=rent, U=maintenance,
 *     R=registration) via a model accessor, so the stored column is redundant.
 *
 * Existing maintenance data is copied across before the columns are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->json('maintenance_tags')->nullable();
            $table->string('responsible')->nullable();
            $table->string('approved_by')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->text('maintenance_notes')->nullable();
            $table->timestamps();
        });

        // Move existing maintenance header data off contracts (only rows that have any). The
        // timestamp is bound as a literal (not NOW()) so this raw SQL stays portable to the
        // SQLite in-memory connection the test suite runs against.
        $now = now()->toDateTimeString();
        DB::statement("
            INSERT INTO maintenances
                (contract_id, vendor_id, maintenance_tags, responsible, approved_by, expected_return_date, maintenance_notes, created_at, updated_at)
            SELECT id, vendor_id, maintenance_tags, responsible, approved_by, expected_return_date, maintenance_notes, '{$now}', '{$now}'
            FROM contracts
            WHERE vendor_id IS NOT NULL
               OR maintenance_tags IS NOT NULL
               OR responsible IS NOT NULL
               OR approved_by IS NOT NULL
               OR expected_return_date IS NOT NULL
               OR maintenance_notes IS NOT NULL
        ");

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn([
                'maintenance_tags', 'responsible', 'approved_by',
                'expected_return_date', 'maintenance_notes', 'category',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('customer_id')->constrained('vendors')->nullOnDelete();
            $table->json('maintenance_tags')->nullable()->after('vendor_id');
            $table->string('responsible')->nullable()->after('maintenance_tags');
            $table->string('approved_by')->nullable()->after('responsible');
            $table->date('expected_return_date')->nullable()->after('approved_by');
            $table->text('maintenance_notes')->nullable()->after('expected_return_date');
            $table->enum('category', ['rent', 'maintenance', 'test_drive', 'transfer', 'sale_prep'])->nullable()->after('contract_type');
        });

        DB::statement("
            UPDATE contracts c
            JOIN maintenances m ON m.contract_id = c.id
            SET c.vendor_id = m.vendor_id,
                c.maintenance_tags = m.maintenance_tags,
                c.responsible = m.responsible,
                c.approved_by = m.approved_by,
                c.expected_return_date = m.expected_return_date,
                c.maintenance_notes = m.maintenance_notes
        ");

        Schema::dropIfExists('maintenances');
    }
};
