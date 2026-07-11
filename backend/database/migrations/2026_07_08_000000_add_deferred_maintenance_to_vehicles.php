<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred Maintenance flag — when a car is pulled OUT of the workshop early to go to a customer,
 * we CLOSE its maintenance ticket (no dual open contracts) and raise this standing flag on the
 * vehicle so the shop visit is never forgotten. The flag rides on the vehicle (independent of any
 * contract) and is cleared only when the car is checked back into the workshop, a new maintenance
 * ticket is opened, or a supervisor dismisses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('is_deferred_maintenance')->default(false)->after('condition_graded_by');
            $table->text('deferred_maintenance_reason')->nullable()->after('is_deferred_maintenance');
            $table->timestamp('deferred_maintenance_flagged_at')->nullable()->after('deferred_maintenance_reason');
            $table->string('deferred_maintenance_flagged_by')->nullable()->after('deferred_maintenance_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'is_deferred_maintenance',
                'deferred_maintenance_reason',
                'deferred_maintenance_flagged_at',
                'deferred_maintenance_flagged_by',
            ]);
        });
    }
};
