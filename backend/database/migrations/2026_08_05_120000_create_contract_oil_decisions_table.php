<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The human call taken when a rental is projected to finish OUTSIDE the oil tolerance.
 *
 * The projection itself is stateless — every number on the Oil Follow-up board is recomputed on
 * read. This table stores the one thing that cannot be recomputed: what a person decided to do
 * about a car that will run past `oil limit + tolerance` before the customer brings it back.
 *
 * There are exactly two decisions (the fleet's own words):
 *   • recall — get the car back before it exceeds the safe tolerance;
 *   • defer  — accept the overrun and service it the moment the contract closes.
 *
 * Both mean the same thing on the other side of the return ("this car owes an oil change"), which
 * is why the settle step is shared: when the rental closes, the owed change becomes a real routine
 * ticket and the row is stamped `settled_at` so it can never mint a second one.
 *
 * Every figure the decision was made against is SNAPSHOTTED here. The projection moves with each
 * new customer reading, so a bare "Marwa chose recall" would be unauditable a week later — the
 * board would show today's arithmetic beside yesterday's decision and they would not agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_oil_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // 'recall' | 'defer'. Plain string, not an enum: MySQL 8 (prod) and MariaDB (local)
            // disagree about altering enums, and this vocabulary belongs to the model anyway.
            $table->string('decision', 16);

            // The anchor the decision was made on. A LATER customer reading that still busts the
            // tolerance re-opens a `defer` (the car turned out to be driven harder than the number
            // the decision was based on); a `recall` is terminal and is never re-asked.
            $table->foreignId('anchor_reading_id')->nullable()
                ->constrained('contract_mileage_readings')->nullOnDelete();

            // Snapshot of the arithmetic at decision time — this is the audit trail.
            $table->unsignedInteger('oil_limit')->nullable();
            $table->unsignedInteger('allowed_max')->nullable();
            $table->unsignedInteger('expected_return_odometer')->nullable();
            $table->unsignedSmallInteger('remaining_days')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->text('note')->nullable();

            // Raised by the settle sweep rather than a person: the rental closed past the oil limit
            // with nobody ever having been asked. The change is still owed, so it is still recorded.
            $table->boolean('is_auto')->default(false);

            // Stamped when the owed oil change became a real ticket. Non-null = closed out; it is
            // also the idempotency guard for the settle sweep.
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_ticket_id')->nullable()
                ->constrained('maintenances')->nullOnDelete();

            $table->timestamps();

            // "the newest decision for this contract" + "everything still owed".
            $table->index(['contract_id', 'id']);
            $table->index(['vehicle_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_oil_decisions');
    }
};
