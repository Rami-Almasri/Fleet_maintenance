<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * service_contracts — "we have paid for the next N services", which is NOT a warranty.
 *
 * ── WHY THIS IS ITS OWN TABLE AND NOT A THIRD WARRANTY `kind` ───────────────────────────────────
 *
 * A warranty is a promise to FIX WHAT BREAKS. A service contract is a prepaid allowance of SCHEDULED
 * WORK. They expire differently, they are consumed differently, and the question each answers is
 * different:
 *
 *   warranty         "if the gearbox fails, who pays?"          → a yes/no about a fault
 *   service contract "how many free services are left?"          → a COUNT that goes down
 *
 * The fleet's own Daily Warranty Report keeps them in separate column groups for exactly this
 * reason, and one car routinely has one without the other — six ALI & SONS cars carry a service
 * contract with the warranty columns blank, because their factory warranty is unlimited-mileage and
 * tracked elsewhere. Folding this into `warranties` would have meant a `services_total` column that
 * is null on every part and repair warranty in the table, and a consumption counter on rows that
 * cannot be consumed.
 *
 * ── WHAT THE SHEET ACTUALLY SAYS, AND WHAT IS MODELLED ──────────────────────────────────────────
 *
 * Two real shapes, both stored here without either being privileged:
 *
 *   "5 Lube Service/5Yrs"  ARABIAN AUTOMOBILES — 5 services over 5 years, every 10,000 km,
 *                          including engine oil, oil filter, drain nut washer, wash + 15-point check.
 *   "5 YEARS OR 75K KM"    ALI & SONS — a period/distance cap rather than a service count.
 *
 * So `services_total` is NULLABLE: a contract capped by time and distance alone is a real contract,
 * and inventing a count for it would be fabricating a number nobody agreed.
 *
 * ── THE LIMIT IS AN ABSOLUTE ODOMETER READING ───────────────────────────────────────────────────
 *
 * `ends_at_km` is a number on the dial (50,000), not a distance from a start — the same correction
 * already made to warranties, and the way the report states it. "Remaining" is therefore
 * `ends_at_km − the car's odometer today`, which is exactly the sheet's own subtraction.
 *
 * ⚠ The sheet's DERIVED columns are not imported and are deliberately not mirrored: `NEW service
 * finish` computes `100,000 − mileage` on ARABIAN rows and `50,000 − mileage` on ALI rows, and 37
 * cells across the tab are `#VALUE!`. Only facts a human typed are stored here; everything else is
 * computed once, consistently, from those facts. @see \App\Support\SheetValue
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_contracts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            /**
             * The contract as the paperwork names it — "5 Lube Service/5Yrs". Kept verbatim and
             * shown verbatim: it is what the dealer and the driver both call it, and any parse of it
             * (below) is a convenience that must never replace the words on the document.
             */
            $table->string('coverage_label', 200)->nullable();

            // WHO honours it. Free text for the same reason the warranty's provider is: a main
            // dealer is not a vendor we buy from, and requiring a vendor row would block the record.
            $table->string('provider_name', 200)->nullable();
            $table->string('contact_phone', 60)->nullable();

            /**
             * HOW MANY services the contract covers, and how many have been used.
             *
             * `services_total` is NULLABLE on purpose — see the header: a "5 years or 75,000 km"
             * contract caps by period, not by count, and a fabricated 5 would be read as fact.
             *
             * `services_used` is a COUNTER maintained by whoever records a service against the
             * contract, not a derivation from service_records. The two would disagree the first time
             * a service was done outside the contract (a puncture at a roadside garage is a service
             * record and is not one of the five), and a counter that silently drifts is worse than
             * one somebody owns.
             */
            $table->unsignedSmallInteger('services_total')->nullable();
            $table->unsignedSmallInteger('services_used')->default(0);

            /**
             * The service INTERVAL — "every 10,000 km". Distinct from the car's own
             * `vehicles.service_interval_km`, which is what the fleet's maintenance schedule uses:
             * this is what the CONTRACT pays for, and the two can legitimately differ (we may service
             * at 8,000 while the dealer only covers every 10,000).
             */
            $table->unsignedInteger('interval_km')->nullable();

            // ── The two limits, whichever comes first ────────────────────────────────────────────
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable()->index();
            /** An ABSOLUTE odometer reading, exactly as the report states it (50000). Not a distance. */
            $table->unsignedInteger('ends_at_km')->nullable();

            /**
             * The odometer at the last service DONE UNDER THIS CONTRACT — the report's "Last change".
             * What makes "the next one is due at 56,747" answerable, and kept on the contract rather
             * than read off the car because a car can hold a contract it is not being serviced under.
             */
            $table->unsignedInteger('last_service_odometer')->nullable();
            $table->date('last_service_on')->nullable();

            /**
             * active — live or expired by the clock/odometer (never written as 'expired': that is
             *          computed, and it moves every time the car is driven).
             * ended  — deliberately terminated: the car was sold, the contract was cancelled.
             */
            $table->string('status', 10)->default('active')->index();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();

            $table->timestamps();
            // A contract is evidence of what was paid for; deleting one must not lose the record.
            $table->softDeletes();

            // "What does this car have?" — the card's question, one indexed read.
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_contracts');
    }
};
