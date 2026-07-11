<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidation: "where is the car?" location/status now lives on the canonical Logistics Dispatch
 * task (logistics_tasks.last_status…), not per maintenance ticket. Drop the redundant per-ticket
 * status columns. `assigned_driver_id` is KEPT — the Supervisor delegation overlay reuses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            if (Schema::hasColumn('maintenances', 'last_status_by')) {
                $table->dropConstrainedForeignId('last_status_by');
            }
            $drop = array_values(array_filter(
                ['last_status', 'last_status_at'],
                fn ($c) => Schema::hasColumn('maintenances', $c)
            ));
            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('last_status')->nullable()->after('assigned_driver_id');
            $table->timestamp('last_status_at')->nullable()->after('last_status');
            $table->foreignId('last_status_by')->nullable()->after('last_status_at')->constrained('users')->nullOnDelete();
        });
    }
};
