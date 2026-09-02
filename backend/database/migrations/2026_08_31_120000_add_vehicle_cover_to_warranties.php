<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The THIRD kind of promise: the one the MANUFACTURER or DEALER made about the whole car.
 *
 * The warranties table already held two kinds — a supplier's promise about a part, a garage's
 * promise about a repair. Both are promises we obtained AFTER something went wrong. What was
 * missing is the promise the car arrived with, and it behaves differently in exactly one way that
 * matters operationally: it is the reason NOT to spend our own money in the first place.
 *
 * WHY THE SAME TABLE AND NOT A NEW ONE. Everything a vehicle warranty is — a counterparty, a start,
 * two expiry legs, a reference number, a void reason, claims against it — is already here, and the
 * question every surface asks ("is it still live, judged against this car's odometer today?") is
 * already answered by Warranty::evaluate(). A parallel `vehicle_warranties` table would have
 * duplicated the whole-first-leg/second-leg rule, which is the one piece of logic in this feature
 * that must never exist twice. So kind='vehicle' joins kind='part' and kind='repair', and the only
 * thing that changes is the ANCHOR RULE: a vehicle warranty points at nothing but the car.
 *
 * MULTIPLE COVERAGES PER CAR come free and always did — a warranty is a row, and a car may have as
 * many as it was given: 3 years bumper-to-bumper from the dealer, 5 years on the powertrain, a
 * separate battery warranty on the battery that was fitted last month (kind='part', anchored to the
 * component). Nothing here is a per-car column, so nothing here caps the count at one.
 *
 * ── WHAT THIS MIGRATION ACTUALLY ADDS ──────────────────────────────────────────────────────────
 *
 * 1. HOW TO REACH THE COUNTERPARTY. A part warranty is claimed by walking back into the shop you
 *    bought it from. A vehicle warranty is claimed by telephoning a dealer's service department and
 *    quoting a contract number, and the person doing that is not the person who typed the warranty
 *    in. `provider_kind` + the three contact columns are what make the claim actionable by someone
 *    other than its author.
 *
 * 2. WHAT IS AND IS NOT COVERED, as DATA rather than as an opinion. This is the load-bearing part.
 *
 *    `covered_catalog_ids`  — the part types this promise explicitly names.  NULL ⇒ not itemised.
 *    `excluded_catalog_ids` — the part types it explicitly excludes.         NULL ⇒ none recorded.
 *
 *    Both are lists of component_catalog ids, so "is the transmission covered?" is a set membership
 *    test against the same parts vocabulary the request form, the purchase and the fitted component
 *    already speak. No text matching, no keyword guessing.
 *
 *    THE DEFAULT IS DELIBERATELY "I DON'T KNOW". A live vehicle warranty that names neither list
 *    yields UNKNOWN for every part — never COVERED. Read that again, because the temptation to make
 *    it COVERED is the single most expensive mistake this feature could make in either direction:
 *    guess COVERED and we sit waiting for a dealer who was never going to pay; guess NOT_COVERED and
 *    we buy a gearbox the manufacturer owed us. UNKNOWN is the honest answer, and UNKNOWN is what
 *    routes the decision to a human instead of to a default. See WarrantyCoverageEngine.
 *
 *    Each decision that human takes is then WRITTEN DOWN on the case, so the same question is only
 *    ever asked once per (warranty, part type) — the lists above are what a recorded decision
 *    eventually populates. The system learns by being told, not by inferring.
 *
 * Evidence classes: every column here is FACT — copied off a warranty booklet or a dealer contract.
 * Nothing is derived and nothing is a judgement; the judgements live on warranty_claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            /**
             * WHO the counterparty is, as a category: manufacturer | dealer | supplier | garage | other.
             *
             * Not the same question as provider_vendor_id (WHICH one) and not derivable from `kind`:
             * a kind='part' warranty can be honoured by the manufacturer rather than by the shop that
             * sold it, and the escalation path differs. Used to word the notification ("Authorized
             * Dealer") and to group the register by who actually pays.
             */
            $table->string('provider_kind', 16)->nullable()->after('provider_name');

            // The service department, in the form somebody can act on at 8am without hunting for it.
            $table->string('contact_name', 160)->nullable()->after('provider_kind');
            $table->string('contact_phone', 60)->nullable()->after('contact_name');
            $table->string('contact_email', 160)->nullable()->after('contact_phone');

            /**
             * The itemised cover, as component_catalog ids. NULL on both ⇒ the promise was recorded
             * without an inventory of what it covers, which is the normal state of a warranty booklet
             * nobody has read line by line — and which yields UNKNOWN, not COVERED. See the header.
             */
            $table->json('covered_catalog_ids')->nullable()->after('void_reason');
            $table->json('excluded_catalog_ids')->nullable()->after('covered_catalog_ids');

            // The sentence off the booklet that the two lists above are a structured reading of.
            // Kept so a reviewer can check the structure against the original wording.
            $table->text('coverage_notes')->nullable()->after('excluded_catalog_ids');
        });

        /**
         * "Which cars are under a manufacturer/dealer warranty right now?" — the vehicle list's
         * filter and the fleet dashboard's first tile. Without this it is a full scan of every
         * warranty ever recorded, most of which are part warranties on parts nobody is asking about.
         */
        Schema::table('warranties', function (Blueprint $table) {
            $table->index(['kind', 'vehicle_id', 'status', 'expires_on'], 'warranties_cover_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropIndex('warranties_cover_lookup_idx');
            $table->dropColumn([
                'provider_kind', 'contact_name', 'contact_phone', 'contact_email',
                'covered_catalog_ids', 'excluded_catalog_ids', 'coverage_notes',
            ]);
        });
    }
};
