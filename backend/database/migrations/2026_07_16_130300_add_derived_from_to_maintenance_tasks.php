<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Different fault" link. When the workshop reviews a reported fault as ∅ Not found, the real problem may
 * be a DIFFERENT fault. Rather than mis-mark the original as fixed, the technician raises a new fault and
 * we keep the two linked: the new fault's `derived_from_task_id` points at the not-found original — so the
 * history reads "reported X → not found → turned out to be Y". The original stays closed as "not found".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->foreignId('derived_from_task_id')->nullable()->after('recurrence_previous_task_id')
                ->constrained('maintenance_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derived_from_task_id');
        });
    }
};
