<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `maintenances` able to hold the standalone "N-Maintenance & Repair" sheet log
 * (one row per maintenance EVENT for a car), not just per-contract headers:
 *   - contract_id becomes nullable (sheet events aren't tied to a contract).
 *   - vehicle_id links the event straight to the car (matched by plate).
 *   - the rich sheet columns are added; existing vendor_id/approved_by/responsible/
 *     expected_return_date/maintenance_notes are reused.
 *   - row_hash gives idempotency since the sheet has no row id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // drop the FK so contract_id can become nullable (the UNIQUE index stays —
        // MySQL allows many NULLs under a unique index, so contract headers remain 1:1).
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
        });

        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->change();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();

            $table->foreignId('vehicle_id')->nullable()->after('contract_id')->constrained('vehicles')->nullOnDelete();

            $table->string('origin')->default('contract')->after('vehicle_id');
            $table->string('row_hash')->nullable()->unique()->after('origin');

            $table->string('car_label')->nullable()->after('row_hash'); // raw "MAKE - Color - Year - PLATE"
            $table->string('plate')->nullable()->after('car_label');
            $table->string('event_status')->nullable()->after('plate'); // OUT / IN / Follow up

            $table->date('out_date')->nullable()->after('event_status');       // Fixed OUT Date
            $table->date('follow_date')->nullable()->after('out_date');        // Follow Date
            $table->date('actual_in_date')->nullable()->after('follow_date');  // Actual IN Date

            $table->string('base_on')->nullable()->after('actual_in_date');
            $table->string('driver')->nullable()->after('base_on');
            $table->string('liable_party')->nullable()->after('driver');
            $table->string('charge_to')->nullable()->after('liable_party');    // Customer / Staff Charge
            $table->string('garage')->nullable()->after('charge_to');          // raw garage text (vendor_id = matched vendor)

            $table->string('maintenance_type')->nullable()->after('garage');
            $table->string('service_main')->nullable()->after('maintenance_type'); // "Main"
            $table->string('service_sup')->nullable()->after('service_main');      // "Sup"
            $table->string('damage_location')->nullable()->after('service_sup');
            $table->string('severity')->nullable()->after('damage_location');

            $table->text('spare_part')->nullable()->after('severity');
            $table->string('invoice_no')->nullable()->after('spare_part');
            $table->decimal('cost', 12, 2)->nullable()->after('invoice_no');
            $table->text('cost_notes')->nullable()->after('cost');

            $table->index(['vehicle_id', 'out_date']);
            $table->index('origin');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropIndex(['vehicle_id', 'out_date']);
            $table->dropIndex(['origin']);
            $table->dropColumn([
                'vehicle_id', 'origin', 'row_hash', 'car_label', 'plate', 'event_status',
                'out_date', 'follow_date', 'actual_in_date', 'base_on', 'driver', 'liable_party',
                'charge_to', 'garage', 'maintenance_type', 'service_main', 'service_sup',
                'damage_location', 'severity', 'spare_part', 'invoice_no', 'cost', 'cost_notes',
            ]);
        });
    }
};
