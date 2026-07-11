<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics Dispatch — an operational order to physically MOVE a vehicle somewhere (e.g. to "Deals on
 * Wheels", the garage, the office), assigned to a person who carries it out. It is NOT an OfficeManager
 * contract; it's our movement record — "move car X to destination Y, assigned to George" — that drives
 * the assignee's My Queue, the system notification and the live "In Transit to Y" status on the grid,
 * answering "where is the car?" for everyone without a WhatsApp relay.
 *
 * Self-contained snapshots (plate / car label / destination / people names) so a task stays meaningful
 * even after a vehicle re-sync or a user rename. Vehicle / user ids are kept loose (indexed, no FK) for
 * the same reason — mirrors the maintenance_swaps table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_tasks', function (Blueprint $table) {
            $table->id();

            // The vehicle being moved (snapshot label + plate so the record stands alone).
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('vehicle_plate')->nullable();
            $table->string('vehicle_label')->nullable();

            // Where it's going — a free-text destination (presets surfaced in the UI).
            $table->string('destination');

            // The person responsible for the move (the dropdown pick) + a name snapshot.
            $table->unsignedBigInteger('assigned_to_id')->nullable()->index();
            $table->string('assigned_to_name')->nullable();

            // Who created the dispatch (the desk/manager), for the audit trail.
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->string('assigned_by_name')->nullable();

            // Lifecycle: 'in_transit' (open — car is on the move) → 'delivered' (arrived) / 'cancelled'.
            $table->string('status')->default('in_transit');
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by_name')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['assigned_to_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_tasks');
    }
};
