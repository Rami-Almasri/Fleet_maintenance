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
        Schema::create('vehicle_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('chasis_no')->nullable()->index();        // raw VIN from the sheet (kept even if unmatched)

            $table->date('expiry_date')->nullable();                 // registration / mulkiya expiry
            $table->integer('valid_days')->nullable();               // registration days left
            $table->string('status')->nullable();                    // Registered / Expired
            $table->integer('fines_count')->nullable();
            $table->decimal('fines_amount', 12, 2)->nullable();
            $table->string('mortgaged_by')->nullable();

            $table->foreignId('insurance_company_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->date('insurance_expiry')->nullable();            // when the insurance runs out

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
        Schema::dropIfExists('vehicle_registrations');
    }
};
