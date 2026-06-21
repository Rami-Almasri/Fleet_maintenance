<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the rich car data the OfficeManager API carries (specs, rental defaults,
 * warranty/service due, Salik tag, engine/driver/location) onto `vehicles`, and the
 * car's INSURANCE (insurer no, policy, issue/expiry, deductible, mortgage flag) onto
 * `vehicle_registrations` — the API is now the source of truth for insurance, while the
 * F Insurance sheet keeps only the Mulkiya/registration expiry.
 *
 * make/model/color/registration(Mulkiya) deliberately stay sheet-owned and are NOT added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // --- identity / specs (API) ---
            $table->string('engine_no', 64)->nullable()->after('vin');
            $table->string('driver_no', 32)->nullable()->after('engine_no');     // OM DriverNo (assigned driver)
            $table->unsignedTinyInteger('keys_number')->nullable()->after('driver_no');
            $table->boolean('auto_gear')->nullable()->after('keys_number');
            $table->unsignedTinyInteger('cylinders')->nullable()->after('auto_gear');
            $table->unsignedSmallInteger('horse_power')->nullable()->after('cylinders');
            $table->unsignedTinyInteger('doors')->nullable()->after('horse_power');
            $table->unsignedTinyInteger('seats')->nullable()->after('doors');
            $table->unsignedTinyInteger('passengers')->nullable()->after('seats');
            $table->unsignedTinyInteger('wheel_drive')->nullable()->after('passengers'); // OM WD (2 / 4)
            $table->string('location', 64)->nullable()->after('wheel_drive');     // OM Location code
            $table->string('salik_tag_no', 64)->nullable()->after('location');    // Salik toll tag

            // --- service due (API) ---
            $table->date('service_due_date')->nullable()->after('warranty_end_km');
            $table->unsignedInteger('service_due_km')->nullable()->after('service_due_date');

            // --- standard rental defaults from the car card (API) ---
            $table->decimal('hour_rent_value', 12, 2)->nullable()->after('service_due_km');
            $table->decimal('day_rent_value', 12, 2)->nullable()->after('hour_rent_value');
            $table->decimal('week_rent_value', 12, 2)->nullable()->after('day_rent_value');
            $table->decimal('month_rent_value', 12, 2)->nullable()->after('week_rent_value');
            $table->decimal('year_rent_value', 12, 2)->nullable()->after('month_rent_value');
            $table->unsignedInteger('miles_allowed_pd')->nullable()->after('year_rent_value');
            $table->unsignedInteger('miles_allowed_pm')->nullable()->after('miles_allowed_pd');
            $table->decimal('extra_mile_charge', 10, 2)->nullable()->after('miles_allowed_pm');
            $table->decimal('full_fuel_cost', 10, 2)->nullable()->after('extra_mile_charge');
        });

        Schema::table('vehicle_registrations', function (Blueprint $table) {
            // Insurance now comes from the API. insurance_expiry + insurance_company_id already
            // exist; these add the rest of what the API carries.
            $table->string('insurance_company_no', 32)->nullable()->after('insurance_company_id'); // raw OM InsuranceCompanyNo
            $table->string('insurance_no', 64)->nullable()->after('insurance_company_no');          // policy number
            $table->date('insurance_issue_date')->nullable()->after('insurance_no');
            $table->string('insurance_type', 64)->nullable()->after('insurance_expiry');
            $table->decimal('insurance_bear_amount', 12, 2)->nullable()->after('insurance_type');   // deductible / excess
            $table->boolean('is_mortgaged')->nullable()->after('mortgaged_by');                     // OM IsMortgaged
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'engine_no', 'driver_no', 'keys_number', 'auto_gear', 'cylinders', 'horse_power',
                'doors', 'seats', 'passengers', 'wheel_drive', 'location', 'salik_tag_no',
                'service_due_date', 'service_due_km',
                'hour_rent_value', 'day_rent_value', 'week_rent_value', 'month_rent_value',
                'year_rent_value', 'miles_allowed_pd', 'miles_allowed_pm', 'extra_mile_charge',
                'full_fuel_cost',
            ]);
        });

        Schema::table('vehicle_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_company_no', 'insurance_no', 'insurance_issue_date',
                'insurance_type', 'insurance_bear_amount', 'is_mortgaged',
            ]);
        });
    }
};
