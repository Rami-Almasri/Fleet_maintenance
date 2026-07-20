<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Workforce Operations Center — real activity tracking for the Users page.
// This is ADDITIVE and reversible: it never touches permissions or existing
// auth logic. It gives every account a denormalized "last known" activity
// snapshot (for cheap list rendering) plus an append-only event log that the
// backend sessionizes into durations, timelines, heat-maps and module usage.
// Nothing here can be backfilled — metrics only start accruing once deployed,
// and the UI degrades to "—" for accounts with no recorded activity yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Denormalized "last known" snapshot — kept current by UserActivityService
            // so the account list never has to scan the event log per row.
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->timestamp('last_logout_at')->nullable()->after('last_login_at');
            $table->timestamp('last_seen_at')->nullable()->after('last_logout_at');
            $table->string('last_page')->nullable()->after('last_seen_at');       // friendly module name
            $table->string('last_path')->nullable()->after('last_page');          // route path
            $table->string('last_ip', 45)->nullable()->after('last_path');
            $table->text('last_user_agent')->nullable()->after('last_ip');

            // Security / audit trail — captured going forward.
            $table->unsignedInteger('failed_login_count')->default(0)->after('last_user_agent');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_count');
            $table->timestamp('last_password_change_at')->nullable()->after('last_failed_login_at');
            $table->timestamp('last_role_change_at')->nullable()->after('last_password_change_at');
            $table->foreignId('created_by')->nullable()->after('last_role_change_at')->constrained('users')->nullOnDelete();
        });

        // Append-only activity log. One row per login, logout or page view
        // (heartbeats coalesce into the last page row rather than spamming).
        Schema::create('user_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);            // login | logout | page
            $table->string('page')->nullable();    // friendly module name (e.g. "Workflow")
            $table->string('path')->nullable();    // route path (e.g. /maintenance-workflow)
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'last_login_at', 'last_logout_at', 'last_seen_at', 'last_page', 'last_path',
                'last_ip', 'last_user_agent', 'failed_login_count', 'last_failed_login_at',
                'last_password_change_at', 'last_role_change_at',
            ]);
        });
    }
};
