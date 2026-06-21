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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->string('vin', 32)->unique();
            $table->string('plate_no')->nullable()->index(); // NOT unique: plates transfer between cars in the UAE
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->string('category')->nullable();
            $table->enum('status', ['active', 'sold', 'for_sale', 'insurance_claim', 'personal', 'under_process', 'exported', 'office'])->default('active');
            $table->enum('operational_status', ['available', 'rented', 'maintenance', 'test', 'transfer', 'sale_prep'])->default('available'); // current movement (from the open contract)
            $table->unsignedInteger('odometer')->default(0);
            $table->decimal('engine_hours', 10, 1)->nullable();
            $table->enum('source', ['new', 'used', 'auction', 'accident'])->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_end_date')->nullable();
            $table->unsignedInteger('warranty_end_km')->nullable();
            $table->date('replacement_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->enum('origin', ['web', 'sheet'])->default('web'); // web = added on the website, sheet = imported
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
