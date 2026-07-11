<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-Control layer for the closing re-inspection: track, PER FAULT, that a garage returned the
 * car but the fault was NOT actually fixed. When an inspector fails a fault at re-inspection the car
 * goes back to the supervisor (not the same garage), and each still-broken fault carries the blame:
 *   - reinspection_failures  = how many times THIS fault has flunked a re-inspection (blacklist signal)
 *   - last_failed_vendor_id  = the garage that last handed it back unfixed ("Unresolved at Garage X")
 *   - last_failed_at         = when that happened
 * See [[maintenance-workflow-engine]] / [[maintenance-tasks-container-model]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->unsignedInteger('reinspection_failures')->default(0)->after('resolved_by');
            $table->foreignId('last_failed_vendor_id')->nullable()->after('reinspection_failures')
                ->constrained('vendors')->nullOnDelete();
            $table->timestamp('last_failed_at')->nullable()->after('last_failed_vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_failed_vendor_id');
            $table->dropColumn(['reinspection_failures', 'last_failed_at']);
        });
    }
};
