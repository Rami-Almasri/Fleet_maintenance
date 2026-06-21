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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');

            $table->string('license_no')->nullable();

            $table->date('license_expiry')->nullable();
            $table->string('phone')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
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
        Schema::dropIfExists('drivers');
    }
};
