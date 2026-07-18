<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-drift repair for the `contracts` table.
 *
 * The OfficeManager sync (App\Services\OfficeManagerSync + ContractImporter) writes these six
 * columns, and they are in Contract::$fillable — but NO migration ever created them. They existed
 * only in the original development database (added directly, outside version control), so a freshly
 * migrated server rejects the insert with: "Unknown column 'out_date_hijri' in 'field list'".
 *
 * All are nullable. Types match the sync mapping (Hijri dates + free text stored as strings).
 * hasColumn() guards make this a safe no-op on any DB that already has them (e.g. the old dev DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Hijri out/in dates come from the API as strings (not Gregorian dates).
            if (! Schema::hasColumn('contracts', 'out_date_hijri')) {
                $table->string('out_date_hijri')->nullable();
            }
            if (! Schema::hasColumn('contracts', 'in_date_hijri')) {
                $table->string('in_date_hijri')->nullable();
            }
            // Free-text note — can be long, so text().
            if (! Schema::hasColumn('contracts', 'remarks')) {
                $table->text('remarks')->nullable();
            }
            // Salesman codes + contract source (strOrNull/idStr in the sync → strings).
            if (! Schema::hasColumn('contracts', 'sales_man1')) {
                $table->string('sales_man1')->nullable();
            }
            if (! Schema::hasColumn('contracts', 'sales_man2')) {
                $table->string('sales_man2')->nullable();
            }
            if (! Schema::hasColumn('contracts', 'source')) {
                $table->string('source')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['out_date_hijri', 'in_date_hijri', 'remarks', 'sales_man1', 'sales_man2', 'source'] as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
