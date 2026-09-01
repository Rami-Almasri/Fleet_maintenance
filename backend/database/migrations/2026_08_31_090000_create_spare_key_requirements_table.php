<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spare_key_requirements — "this car NEEDS a spare key", as a business record rather than a note.
 *
 * WHY A TABLE AND NOT A part_requests ROW. The three things this lifecycle has to keep apart are the
 * NEED, the BUY and the KEY, and part_requests is already the middle one: it is the intent to fit a
 * part, it carries approval stamps, and it is what the procurement board works. A need that has not
 * yet been turned into a purchase request is not a part request — it has no price, no approver and
 * nothing to buy against — and folding it in would have meant inventing a "pre-request" status on a
 * status machine four other surfaces read.
 *
 * So the need lives here, the buy stays on part_requests / part_purchases, and the physical key is a
 * vehicle_components row like any other asset. The chain is:
 *
 *     spare_key_requirements → part_requests → part_purchases → vehicle_components
 *
 * and every link is a real foreign key, so "why was this purchase made?" is answerable by a join
 * rather than by a guess.
 *
 * `status` on this row is a PROJECTION of that chain, kept for queryability the same way
 * vehicle_check_requirements projects its event log. SpareKeyProjection is the only writer of it.
 *
 * DUPLICATE PROTECTION IS A DATABASE CONSTRAINT, not a check-then-insert. `open_vehicle_id` holds the
 * vehicle id while the requirement is open and NULL once it closes; the unique index on it therefore
 * permits exactly one OPEN requirement per car, and any number of closed ones. MySQL allows repeated
 * NULLs in a unique index, which is what makes this work without a partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spare_key_requirements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // required → purchase_requested → approved → ordered → received → completed,
            // with rejected / cancelled off-ramps. @see App\Models\SpareKeyRequirement
            $table->string('status', 24)->default('required')->index();

            /**
             * The open-requirement lock. Mirrors vehicle_id while the requirement is open, NULL once
             * it is completed/cancelled. UNIQUE, so a second "this car needs a spare key" cannot be
             * raised while the first is still outstanding — enforced by the database, not by a race.
             */
            $table->unsignedBigInteger('open_vehicle_id')->nullable()->unique();

            // HOW MANY keys are owed, and how many have physically arrived. The pair is what lets a
            // requirement for 2 keys sit at `received` after the first one lands instead of jumping
            // to completed on a half-delivery.
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedTinyInteger('received_quantity')->default(0);

            // WHY a key is needed — a code, never English. @see SpareKeyRequirement::REASONS
            $table->string('reason_code', 24)->default('missing');
            $table->text('notes')->nullable();

            // Provenance: raised in the app, or imported from the "NEED SPARE KEY" sheet history.
            $table->string('source', 16)->default('app')->index();
            // The sheet row this was imported from, so the import is idempotent and re-runnable.
            $table->string('external_ref', 120)->nullable()->unique();

            /**
             * The sheet's own Starting / Finishing dates. Kept SEPARATE from requested_at and
             * completed_at deliberately: those two are stamps this system made, and overwriting them
             * with dates typed into a spreadsheet would make imported history indistinguishable from
             * history the workflow actually produced.
             */
            $table->date('started_on')->nullable();
            $table->date('finished_on')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->dateTime('requested_at')->nullable();

            $table->dateTime('completed_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        Schema::table('part_requests', function (Blueprint $table) {
            /**
             * WHY this purchase request exists, when the answer is "because a car needed a spare key".
             *
             * On part_requests rather than a part_request_id on the requirement, because the
             * relationship is genuinely one-to-many: a rejected purchase request does not end the
             * need, so the next attempt is a SECOND request against the SAME requirement, and both
             * must stay visible in the history.
             */
            $table->foreignId('spare_key_requirement_id')
                ->nullable()
                ->after('maintenance_task_id')
                ->constrained('spare_key_requirements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('part_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spare_key_requirement_id');
        });

        Schema::dropIfExists('spare_key_requirements');
    }
};
