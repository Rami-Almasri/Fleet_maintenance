<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_items — the Technical Service Log lines. Each row is a part replaced or a service
 * performed (e.g. "Oil Filter", "Brake Pads"). Deliberately money-FREE: the financial ledger is
 * decoupled; these rows build a searchable per-vehicle technical history and let us compare what
 * was done across garages. The car / date / garage live on the parent invoice (see the columns
 * added by add_service_log_to_invoices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');                       // the work item / part / service
            $table->string('category_key', 40)->nullable()->index(); // optional Findings-catalog key
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
