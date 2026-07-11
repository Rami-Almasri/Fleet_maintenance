<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Where is the car?" Ping/Reply, folded into the canonical Logistics Dispatch system.
 *
 *  - last_status / last_status_at / last_status_by : the assignee's last one-click reply (At site /
 *    In traffic / Arrived, or free text) to a "Ping location".
 *  - last_pinged_at : when a dispatcher last asked — so the UI can show "asked 3m ago, no reply yet".
 *
 * This replaces the per-maintenance-ticket status fields (dropped in the sibling migration) so a car's
 * location is answered from ONE place for every movement, garage or showroom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->string('last_status')->nullable()->after('notes');
            $table->timestamp('last_status_at')->nullable()->after('last_status');
            $table->foreignId('last_status_by')->nullable()->after('last_status_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_pinged_at')->nullable()->after('last_status_by');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_status_by');
            $table->dropColumn(['last_status', 'last_status_at', 'last_pinged_at']);
        });
    }
};
