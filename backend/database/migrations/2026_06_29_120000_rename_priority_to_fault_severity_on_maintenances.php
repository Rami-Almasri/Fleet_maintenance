<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-engineer the short-lived `priority` tag into a dedicated FAULT SEVERITY assessment.
 *
 * Fault Severity is the inspector's MANDATORY diagnostic grade for every maintenance ticket
 * (🔴 critical / 🟡 moderate / 🟢 routine), assessed at the Decide step. It replaces the
 * never-shipped "priority" flag: same column, a clearer name + a self-documenting value set.
 * Any existing rows are remapped low→routine, medium→moderate, high→critical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->renameColumn('priority', 'fault_severity');
        });

        DB::table('maintenances')->where('fault_severity', 'low')->update(['fault_severity' => 'routine']);
        DB::table('maintenances')->where('fault_severity', 'medium')->update(['fault_severity' => 'moderate']);
        DB::table('maintenances')->where('fault_severity', 'high')->update(['fault_severity' => 'critical']);
    }

    public function down(): void
    {
        DB::table('maintenances')->where('fault_severity', 'routine')->update(['fault_severity' => 'low']);
        DB::table('maintenances')->where('fault_severity', 'moderate')->update(['fault_severity' => 'medium']);
        DB::table('maintenances')->where('fault_severity', 'critical')->update(['fault_severity' => 'high']);

        Schema::table('maintenances', function (Blueprint $table) {
            $table->renameColumn('fault_severity', 'priority');
        });
    }
};
