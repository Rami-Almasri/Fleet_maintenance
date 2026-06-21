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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['garage', 'fuel_station', 'parts_supplier', 'insurance', 'service_center', 'other'])->default('other');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // $table->unsignedInteger('sla_days')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            // $table->string('payment_terms')->nullable();
            $table->boolean('active')->default(true);
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
        Schema::dropIfExists('vendors');
    }
};
