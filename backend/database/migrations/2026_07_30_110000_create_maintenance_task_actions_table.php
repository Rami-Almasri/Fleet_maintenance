<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually DONE to fix a fault — the dataset gap the whole intelligence layer was blocked on.
 *
 * Before this table the corpus recorded what broke (49,473 classified events) and who fixed it
 * (25,130 with a vendor) but never the repair itself: `findings` was populated on 22 of 26,838
 * tickets and `spare_part` on none. That is why repair effectiveness, first-time-fix quality,
 * supplier performance and recommendation accuracy were all unmeasurable — not for want of
 * algorithms.
 *
 * ONE ROW PER ACTION, ORDERED. "Machined the rotor and replaced the caliper" is two facts, and the
 * ORDER matters: machining alone then returning is a different outcome from doing both, and only a
 * sequenced list can tell those apart afterwards.
 *
 * NO BACKFILL, BY DESIGN. Historical repairs get no rows here, and no migration invents any. An
 * empty history is the honest representation of a fleet that never recorded this — inferring actions
 * from free-text notes would produce a dataset that LOOKS complete and quietly teaches every future
 * model from guesses. Unknown beats invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_task_actions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('maintenance_task_id');
            // Denormalised so fleet-wide questions ("how often is this action performed?") never need
            // a three-table join, and so an action survives being read independently of its fault.
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();

            $table->unsignedBigInteger('action_catalog_id');
            $table->unsignedInteger('sequence')->default(1);

            // Who did the work, and where. Both nullable: an on-site job has no vendor, and an
            // external garage submitting through the portal has no user account here.
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();

            $table->timestamp('performed_at')->nullable();
            $table->text('note')->nullable();

            // Links the action to the part it consumed, so `requires_part` becomes a check that runs
            // itself rather than a report someone has to build.
            $table->unsignedBigInteger('line_item_id')->nullable();

            // Who recorded it, which is NOT who performed it — a garage's claim relayed by our
            // inspector is a different piece of evidence from the inspector's own observation, and
            // the trust model needs to tell them apart.
            $table->string('recorded_via', 24)->default('workflow'); // workflow | garage_portal | import

            $table->timestamps();

            $table->index(['maintenance_task_id', 'sequence']);
            $table->index(['action_catalog_id', 'performed_at']);
            $table->index(['vehicle_id', 'performed_at']);
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_task_actions');
    }
};
