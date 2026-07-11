<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delegate DISPUTE of an inspector-selected fault. While the car is In Workshop (under_repair), a
 * supervisor/delegate (Waleed / Abdullah) can rule that a fault Abo Marouf flagged at inspection was
 * NOT a real fault — a mis-diagnosis. That drops the fault out of the "must-fix / all-resolved" gate
 * (status → cancelled), but unlike a plain cancel it stamps WHO overruled the inspector and WHY, so the
 * override stays auditable (and countable against the inspector's diagnostic accuracy later):
 *   - marked_incorrect_by  = the delegate who overruled the inspector's call
 *   - marked_incorrect_at  = when
 *   - incorrect_reason     = why it's not a real fault (mandatory)
 * See [[maintenance-tasks-container-model]] / [[fault-severity-feature]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->foreignId('marked_incorrect_by')->nullable()->after('last_failed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('marked_incorrect_at')->nullable()->after('marked_incorrect_by');
            $table->string('incorrect_reason', 2000)->nullable()->after('marked_incorrect_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marked_incorrect_by');
            $table->dropColumn(['marked_incorrect_at', 'incorrect_reason']);
        });
    }
};
