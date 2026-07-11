<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification tracking for Service Reminders — the "active communication" workflow.
 *
 * The Action column shifts from "Mark done" (that lives in the ticket) to alerting the fleet team.
 * We record WHEN a reminder was last alerted out, WHO fired it, and HOW MANY times, so the UI can show
 * a "Notified · 2h ago" chip and disable the button once an alert has gone out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reminders', function (Blueprint $table) {
            $table->timestamp('last_notified_at')->nullable()->after('active');   // when the last alert was sent
            $table->foreignId('notified_by')->nullable()->after('last_notified_at')
                ->constrained('users')->nullOnDelete();                            // who fired it
            $table->unsignedInteger('notified_count')->default(0)->after('notified_by'); // how many alerts sent
        });
    }

    public function down(): void
    {
        Schema::table('service_reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notified_by');
            $table->dropColumn(['last_notified_at', 'notified_count']);
        });
    }
};
