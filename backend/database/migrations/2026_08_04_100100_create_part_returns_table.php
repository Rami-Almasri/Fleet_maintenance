<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * part_returns — a part sent back, recorded as an EVENT rather than as an erasure.
 *
 * A purchased part is never deleted. When it goes back (wrong part, faulty, no longer needed), the buy
 * stays on the record and a return row is written next to it, so the ticket reads:
 *
 *     Brake Pad Set        purchase   +400.00
 *     Returned (wrong part) credit     -400.00
 *     Net                                 0.00
 *
 * The credit reaches the ticket total the same way the purchase did — through maintenance_line_items. The
 * return writes a NEGATIVE part line (credit_line_item_id) against the same fault, so cost still rolls up
 * line_item → task → ticket with no parallel ledger and no special-casing anywhere downstream.
 *
 * Partial returns are supported (`quantity`), and the refund is separate from the purchase price because
 * they are genuinely different numbers: a restocking fee is money we do not get back and must stay in the
 * ticket cost. refund_amount is what actually comes back; restocking_fee is what the supplier keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('part_purchase_id')->constrained('part_purchases')->cascadeOnDelete();

            // Denormalised anchors, matching part_purchases — history survives if the source rows go.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // How much of the buy went back (≤ the purchased quantity).
            $table->decimal('quantity', 10, 2)->default(1);

            // WHY. A code so the pattern is countable ("this supplier keeps sending the wrong part"),
            // plus free text for the detail. See PartReturn::REASON_CODES.
            $table->string('reason_code', 24);
            $table->text('reason_note')->nullable();

            // Money back vs money kept by the supplier. refund + fee normally equals the returned value.
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('restocking_fee', 12, 2)->default(0);
            $table->string('currency', 3)->default('AED');

            // requested → sent → refunded, with rejected as the off-ramp (supplier refused the return).
            $table->string('status', 12)->default('requested');
            $table->text('rejection_reason')->nullable();

            // The negative maintenance line this return generated (null until it settles, and always null
            // for a return with no ticket — a pure customer buy carries no line to credit).
            $table->foreignId('credit_line_item_id')->nullable()
                ->constrained('maintenance_line_items')->nullOnDelete();

            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('returned_by_name')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('settled_by_name')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['part_purchase_id', 'status']);
            $table->index(['maintenance_id', 'status']);
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_returns');
    }
};
