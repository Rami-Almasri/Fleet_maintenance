<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices from the OfficeManager API — the charges billed on a contract.
 * Linked to a contract by RaContractSerial -> contracts.contract_serial.
 * The sum of a contract's invoices (TotalAfterVat) is its debit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_no')->unique();
            $table->date('invoice_date')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->unsignedBigInteger('contract_serial')->nullable()->index();
            $table->string('car_no')->nullable();
            $table->decimal('total_value', 14, 2)->nullable();   // before VAT
            $table->decimal('vat_value', 14, 2)->nullable();
            $table->decimal('total_after_vat', 14, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('origin')->default('api');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
