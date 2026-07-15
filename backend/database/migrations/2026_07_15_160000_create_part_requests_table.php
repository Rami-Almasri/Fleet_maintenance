<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * part_requests — the lifecycle spine of the Parts Purchase + Repair Intelligence workflow.
 *
 * A part request is the INTENT to fit a part to a vehicle, tracked from Requested → … → Completed with a
 * per-action audit trail. It has two origins (see `source`): a CUSTOMER walk-in asking for a part, or a
 * GARAGE diagnosis that identifies a required part during a maintenance ticket. It deliberately does NOT
 * store money — the actual buy + cost lives on `part_purchases` (one request → potentially several
 * purchase attempts), and installed-part cost still flows through the existing maintenance_line_items
 * roll-up so nothing is double-counted.
 *
 * `repair_location` mirrors the ticket's existing in_shop/on_site vocabulary (surfaced as Garage / On-site)
 * so the same part can be requested whether the car is in the workshop or the technician goes to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_requests', function (Blueprint $table) {
            $table->id();

            // Origin: a customer walk-in (Case A) or a garage diagnosis on a ticket (Case B).
            $table->string('source', 12);                        // 'customer' | 'garage'
            $table->string('status', 20)->default('requested')->index();
            //   requested → under_review → approved → purchased → installed → completed
            //   off-ramps: rejected, cancelled

            // The asset the part is for. Required — a part is always for a vehicle.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // Only set for source=customer. nullOnDelete keeps the request's history if the customer row goes.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // The garage ticket + specific fault this request serves (source=garage). nullOnDelete so the
            // request survives a ticket/fault cleanup and stays part of the vehicle's history.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // The part being requested.
            $table->string('part_name');                         // free text, e.g. "Engine mount"
            $table->string('part_number')->nullable();           // SKU/OEM — the stable identity duplicate detection keys on
            $table->string('category_key', 60)->nullable();      // findings-catalog category → drives classification
            $table->string('part_class', 12)->nullable();        // 'consumable' | 'standard' | 'major' (classifier at create)
            $table->string('repair_location', 10)->nullable();   // 'garage' | 'onsite' (garage-source inherits the ticket)
            $table->decimal('quantity', 10, 2)->default(1);

            $table->text('reason');                              // WHY the part is requested
            $table->decimal('estimated_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            $table->text('notes')->nullable();

            // --- per-action audit stamps (who + when at each lifecycle step) ---
            // actor ids are nullable + we snapshot the name into *_by_name so a later-disabled/removed
            // user never breaks history rendering.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->timestamp('requested_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_by_name')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['part_number', 'vehicle_id']);
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_requests');
    }
};
