<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard polymorphic notifications table — the durable store behind the
 * in-app notification centre. Every alert the NotificationScanner raises becomes one
 * row per recipient here (delivered via the `database` channel), so the bell badge and
 * history survive page reloads and server restarts. The `data->key` field carries a
 * stable dedup key so the same live condition is never raised twice for a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell polls "unread for this user, newest first" constantly — index it.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
