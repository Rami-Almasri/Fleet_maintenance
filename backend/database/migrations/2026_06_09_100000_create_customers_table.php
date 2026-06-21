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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_no')->nullable()->unique(); // CustomerNo from the sheet (match key)
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('nationality')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('mobile1')->nullable();
            $table->string('mobile2')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('sex')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('po_box')->nullable();

            // identity documents
            $table->string('passport_no')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('license_no')->nullable();        // driving license
            $table->date('license_expiry')->nullable();
            $table->string('id_no')->nullable();             // Emirates ID
            $table->date('id_expiry')->nullable();
            $table->string('residency_no')->nullable();
            $table->date('residency_expiry')->nullable();
            $table->string('traffic_file_no')->nullable();
            $table->string('vat_number')->nullable();

            // risk flags
            $table->boolean('blacklisted')->nullable();
            $table->boolean('wanted')->nullable();
            $table->boolean('is_caution')->nullable();

            // location
            $table->string('makani')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();

            $table->decimal('debit', 12, 2)->nullable();
            $table->decimal('credit', 12, 2)->nullable();
            $table->decimal('balance', 12, 2)->nullable();
            $table->decimal('deposit', 12, 2)->nullable();
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
        Schema::dropIfExists('customers');
    }
};
