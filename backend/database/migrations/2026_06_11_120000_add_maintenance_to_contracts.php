<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance support for contracts (contract_type = 'U').
 *
 * Purely ADDITIVE: new nullable columns on `contracts` + a child `maintenance_items`
 * table. No existing column is changed, so the Google-Sheets contract sync keeps
 * matching by (contract_no, contract_type) exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // garage / workshop doing the work (reuses the Vendors list)
            $table->foreignId('vendor_id')->nullable()->after('customer_id')
                ->constrained('vendors')->nullOnDelete();
            // free-form issue tags/keywords for search & filtering (e.g. ["Engine","Brakes"])
            $table->json('maintenance_tags')->nullable()->after('vendor_id');
            // who is in charge / who approved the visit
            $table->string('responsible')->nullable()->after('maintenance_tags');
            $table->string('approved_by')->nullable()->after('responsible');
            // when the car is expected back from the garage
            $table->date('expected_return_date')->nullable()->after('approved_by');
            $table->text('maintenance_notes')->nullable()->after('expected_return_date');
        });

        Schema::create('maintenance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('service_name');                 // e.g. "Oil Change", "Brake Pads", "Labor"
            $table->decimal('cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_items');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn([
                'maintenance_tags', 'responsible', 'approved_by',
                'expected_return_date', 'maintenance_notes',
            ]);
        });
    }
};
