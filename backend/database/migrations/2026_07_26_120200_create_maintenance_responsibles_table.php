<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint — the per-ticket "responsible users" list (the follow-up owners, e.g. Waleed &
 * Abdullah). These are the ONLY people the Checkpoint Scan notifies for a ticket. Editable per ticket;
 * when a ticket has no explicit rows the system falls back to the default supervisors (see
 * MaintenanceCheckpointService::recipientsFor). Mirrors the maintenance_watchers pivot shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_responsibles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();

            $table->unique(['maintenance_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_responsibles');
    }
};
