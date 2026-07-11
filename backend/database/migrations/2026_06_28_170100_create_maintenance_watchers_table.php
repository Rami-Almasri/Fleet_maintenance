<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watchers on a maintenance ticket — the people kept in the loop on it.
 *
 * When the inspector tags a ticket with a priority, every active Supervisor is auto-added here
 * (reason = 'priority_tagged') and notified; a delegated driver is added too (reason = 'delegated').
 * A pivot (not a JSON column) so "tickets I'm watching" is a cheap, indexable query and each watch
 * carries who added it and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 30)->nullable(); // priority_tagged | delegated | manual
            $table->timestamps();

            // One watch per (ticket, user) — re-tagging never duplicates a watcher.
            $table->unique(['maintenance_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_watchers');
    }
};
