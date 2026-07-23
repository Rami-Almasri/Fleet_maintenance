<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * component_events — the append-only ledger of everything that ever happened to a component
 * (Asset Layer, Phase 1). The component's biography.
 *
 * Rows are NEVER updated or deleted by any code path (no service exposes an update). `at` is
 * business time — backfill writes historical dates there, created_at stays the insert moment.
 * A cross-vehicle transfer is ONE `transferred` event carrying both from_vehicle_id and
 * to_vehicle_id, so both cars' timelines see it.
 *
 * Every event is also mirrored into vehicle_log_events (EVENT_COMPONENT_*) by the writing
 * service, so the existing Vehicle Timeline shows asset movements with no new frontend joins.
 * This table stays the asset-side source of truth; the log row is display only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_events', function (Blueprint $table) {
            $table->id();

            // Whose biography. Cascade: an event cannot outlive its component (and policy forbids
            // deleting components anyway).
            $table->foreignId('vehicle_component_id')->constrained('vehicle_components')->cascadeOnDelete();

            // purchased | stored | installed | removed | transferred | disposed |
            // returned_supplier | warranty_claimed | sold
            $table->string('event', 30)->index();

            // Transfers/removals: which car it left. Installs/transfers: which car it joined.
            $table->foreignId('from_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('to_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // Reading of the vehicle involved at event time.
            $table->unsignedInteger('odometer')->nullable();

            // The causing ticket/fault — nullable: warehouse moves and stock intakes have none.
            // Asset operations are ticket-independent by design.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 120)->nullable();

            // Business time (backfill writes historical dates here).
            $table->dateTime('at');

            $table->text('note')->nullable();
            $table->json('meta')->nullable();  // disposition detail, warranty-claim ref, transfer counterpart, photo ids

            $table->timestamps();

            $table->index(['vehicle_component_id', 'at']);
            $table->index(['from_vehicle_id', 'at']);
            $table->index(['to_vehicle_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_events');
    }
};
