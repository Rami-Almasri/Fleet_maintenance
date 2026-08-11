<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each oil decision to the inspection FOLLOW-UP request it raised — the reference that lets
 * the inspection-review side resolve the decision → contract → live projection chain instead of
 * carrying a stale copy of the figures. `settled_ticket_id` remains the ticket the decision finally
 * BECAME at return; this is the request that announces the car BEFORE it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->foreignId('inspection_ticket_id')
                ->nullable()
                ->after('settled_ticket_id')
                ->constrained('maintenances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspection_ticket_id');
        });
    }
};
