<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no')->nullable(); // match key (together with contract_type)
            $table->string('contract_type')->nullable();
            $table->unique(['contract_no', 'contract_type']);

            // universal movement classification (every car movement = a contract)
            $table->enum('category', ['rent', 'maintenance', 'test_drive', 'transfer', 'sale_prep'])->nullable();
            $table->enum('state', ['open', 'closed'])->default('open');

            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // pricing
            $table->decimal('day_price', 12, 2)->nullable();
            $table->decimal('week_price', 12, 2)->nullable();
            $table->decimal('month_price', 12, 2)->nullable();
            $table->decimal('hour_price', 12, 2)->nullable();
            $table->decimal('year_price', 12, 2)->nullable();

            // out (handover)
            $table->date('out_date')->nullable();
            $table->string('out_time')->nullable();
            $table->unsignedInteger('out_milage')->nullable();
            $table->string('out_fuel')->nullable();
            $table->string('opened_by')->nullable();

            // in (return)
            $table->date('in_date')->nullable();
            $table->string('in_time')->nullable();
            $table->unsignedInteger('in_milage')->nullable();
            $table->string('in_fuel')->nullable();
            $table->string('closed_by')->nullable();

            $table->integer('days')->nullable();
            $table->integer('km')->nullable();                          // total km driven

            // debit breakdown
            $table->decimal('rents_debit', 12, 2)->nullable();
            $table->decimal('breachs_debit', 12, 2)->nullable();
            $table->decimal('salik_debit', 12, 2)->nullable();          // road tolls
            $table->decimal('damages_debit', 12, 2)->nullable();
            $table->decimal('extra_charges_debit', 12, 2)->nullable();
            $table->decimal('co_driver_debit', 12, 2)->nullable();
            $table->decimal('km_debit', 12, 2)->nullable();
            $table->decimal('fuel_debit', 12, 2)->nullable();
            $table->decimal('gps_debit', 12, 2)->nullable();
            $table->decimal('cdw_debit', 12, 2)->nullable();            // collision damage waiver
            $table->decimal('extra_driver_debit', 12, 2)->nullable();
            $table->decimal('vat_debit', 12, 2)->nullable();
            $table->decimal('deposit_debit', 12, 2)->nullable();

            // credit breakdown (what was actually paid per category)
            $table->decimal('rents_credit', 12, 2)->nullable();
            $table->decimal('breachs_credit', 12, 2)->nullable();
            $table->decimal('salik_credit', 12, 2)->nullable();
            $table->decimal('damages_credit', 12, 2)->nullable();
            $table->decimal('extra_charges_credit', 12, 2)->nullable();
            $table->decimal('co_driver_credit', 12, 2)->nullable();
            $table->decimal('km_credit', 12, 2)->nullable();
            $table->decimal('fuel_credit', 12, 2)->nullable();
            $table->decimal('gps_credit', 12, 2)->nullable();
            $table->decimal('cdw_credit', 12, 2)->nullable();
            $table->decimal('extra_driver_credit', 12, 2)->nullable();
            $table->decimal('vat_credit', 12, 2)->nullable();
            $table->decimal('deposit_credit', 12, 2)->nullable();

            // totals
            $table->decimal('contract_debit', 12, 2)->nullable();
            $table->decimal('contract_credit', 12, 2)->nullable();
            $table->decimal('contract_balance', 12, 2)->nullable();
            $table->decimal('contract_refunds', 12, 2)->nullable();
            $table->decimal('contract_discount', 12, 2)->nullable();
            $table->decimal('contract_bad_debts', 12, 2)->nullable();
            $table->decimal('contract_deposit', 12, 2)->nullable();
            $table->decimal('contract_commissions', 12, 2)->nullable();
            $table->decimal('contract_income', 12, 2)->nullable();

            // rental terms
            $table->decimal('miles_allowed_pd', 12, 2)->nullable();     // per day
            $table->decimal('miles_allowed_pm', 12, 2)->nullable();     // per month
            $table->decimal('extra_mile_charge', 12, 2)->nullable();
            $table->decimal('cdw_rate', 12, 2)->nullable();
            $table->decimal('pai_rate', 12, 2)->nullable();             // personal accident insurance
            $table->decimal('authorization_amount', 12, 2)->nullable();
            $table->string('insurance_type')->nullable();
            $table->string('trip_direction')->nullable();
            $table->boolean('under_claim')->nullable();
            $table->string('guarantor_no')->nullable();

            // status & references
            $table->string('contract_status_no')->nullable();
            $table->string('reference')->nullable();
            $table->string('contract_serial')->nullable();

            // extra drivers
            $table->string('driver2')->nullable();
            $table->string('driver3')->nullable();
            $table->string('driver_out')->nullable();
            $table->string('driver_in')->nullable();

            // charge / rate config
            $table->decimal('co_driver_cost', 12, 2)->nullable();
            $table->decimal('extra_driver_charge', 12, 2)->nullable();
            $table->decimal('gps_charge', 12, 2)->nullable();
            $table->decimal('fuel_charge', 12, 2)->nullable();
            $table->decimal('ra_vat_percentage', 8, 4)->nullable();

            // salesman commissions
            $table->string('salesman_commission_no1')->nullable();
            $table->decimal('salesman_commission_value1', 12, 2)->nullable();
            $table->string('salesman_commission_no2')->nullable();
            $table->decimal('salesman_commission_value2', 12, 2)->nullable();

            // tax / insurance flags
            $table->boolean('tax_inclusive')->nullable();
            $table->boolean('cdw_on_contract')->nullable();

            // credit card / authorization
            $table->string('credit_card_no')->nullable();
            $table->string('credit_card_expiry')->nullable();
            $table->date('authorization_date')->nullable();



            $table->string('external_id')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->enum('origin', ['web', 'sheet'])->default('web');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
