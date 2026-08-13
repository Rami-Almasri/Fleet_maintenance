<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE a specific event is — the per-fault link to the shared location vocabulary.
 *
 * A CHILD TABLE, not a column, and not a comma-joined string. "2 scratches — rims and body" is two
 * places, and the only reason to record them structurally rather than as text is so the questions
 * that follow can actually be asked: how many scratches did this model take on rear bumpers, which
 * garage keeps returning cars with the same corner still damaged, does this driver kerb the left side.
 * A `location = "rims, body"` column answers none of those, so it is not what this is.
 *
 * ONE ROW PER (task, location). `sort_order` preserves the order the inspector picked them in, so the
 * rendered sentence reads back the way he said it rather than in database order. The unique index is
 * the duplicate guard: tapping "rims" twice is one place, not two, and that is enforced here rather
 * than trusted to every writer.
 *
 * BACKWARD COMPATIBILITY. Every historical fault simply has no rows here, which reads as "we do not
 * know where it was" — the truthful answer. Nothing is backfilled and no location is ever invented
 * for a past record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_task_locations')) {
            Schema::create('maintenance_task_locations', function (Blueprint $table) {
                $table->id();

                // The fault/damage/service/inspection event this place belongs to. Cascades: the places
                // are part of the event, and an event that is gone has no places.
                $table->foreignId('maintenance_task_id')
                    ->constrained('maintenance_tasks')
                    ->cascadeOnDelete();

                // The catalog row. restrictOnDelete — a location that is in use may not be deleted out
                // from under the history that references it; retire it with is_active=false instead.
                $table->foreignId('vehicle_location_id')
                    ->constrained('vehicle_locations')
                    ->restrictOnDelete();

                // The order the picker recorded them in — "rims and body", not "body and rims".
                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->timestamps();

                $table->unique(['maintenance_task_id', 'vehicle_location_id'], 'task_location_unique');
                $table->index('vehicle_location_id', 'task_location_by_place');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_task_locations');
    }
};
