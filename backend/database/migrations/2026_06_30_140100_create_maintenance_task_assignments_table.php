<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_task_assignments — one garage "STINT" per fault.
 *
 * This is the table that makes Garage Transfer first-class. A fault is never "at a garage" as a flat
 * field; it is at the garage of its OPEN stint (released_at IS NULL). Transferring a fault to another
 * garage = close the current stint (released_at + outcome = 'transferred_out') and open a new one.
 * The parent ticket is untouched, so a single fault can hop garages without closing the container.
 *
 * Because every stint records assigned_at / released_at it doubles as the per-fault TIMELINE and the
 * per-garage PERFORMANCE feed: mean repair time per garage, transfer-out rate, how long fault #12 sat
 * at Garage A before it moved — all a single grouped scan, no JSON parsing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_task_id')->constrained('maintenance_tasks')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete(); // the garage for this stint

            // useCurrent() pins an explicit DEFAULT CURRENT_TIMESTAMP so MySQL does NOT silently attach
            // its legacy "ON UPDATE CURRENT_TIMESTAMP" to the first timestamp column — which would rewrite
            // the stint's start time on every row update (e.g. when a transfer stamps released_at) and
            // wreck per-garage duration tracking. The value is always set explicitly in code anyway.
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('released_at')->nullable();         // null = the car is here NOW (the open stint)
            // How the stint ended: resolved (fixed here) | transferred_out (sent elsewhere) |
            // unable (garage declined) | cancelled (fault dropped). Null while still open.
            $table->string('outcome', 20)->nullable();
            $table->text('reason')->nullable();                   // "can't do bodywork, send to X"

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short name — the default would exceed MySQL's 64-char identifier limit.
            $table->index(['maintenance_task_id', 'assigned_at'], 'mta_task_assigned_idx');  // the fault's stint timeline, in order
            $table->index(['vendor_id', 'released_at']);            // open stints at a garage (workload) + history
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_task_assignments');
    }
};
