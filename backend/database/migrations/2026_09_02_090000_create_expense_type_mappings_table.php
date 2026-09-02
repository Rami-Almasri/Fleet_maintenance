<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE PLACE AN EXPENSE TYPE MEETS ODOO.
 *
 * One row per canonical expense type (REPAIR, ROUTINE, RECOVERY, FUEL, REGISTRATION, CAR_WASH, TAXI).
 * Each row answers two INDEPENDENT questions, and the whole reason this is a table rather than a match
 * statement in a service is that Finance must be able to change either answer without a deploy:
 *
 *   WHAT is it charged to?   → odoo_account_id / odoo_account_code   (an Odoo account.account)
 *   HOW is it recorded?      → odoo_document_type                    (VENDOR_BILL | EXPENSE)
 *
 * `odoo_account_name` is DISPLAY ONLY and is never matched on. §2 is explicit about this: account names
 * are how humans recognise an account, and they get renamed. The identifier is the id (or, where an
 * environment prefers portable configuration across Odoo databases, the account code). Storing the name
 * as well means the mappings screen can show what was chosen without a round trip, and means a stale
 * mapping can be spotted by eye when the pulled master data no longer contains that name.
 *
 * The account and document columns are NULLABLE on purpose. Seeding them with invented Odoo ids would
 * be exactly the fabrication §6/§26 forbid, so the seeder writes the type, its label and the DEFAULT
 * document type from config/odoo.php, and leaves the account for Finance to resolve against real pulled
 * master data. Until an account is resolved, every event of that type reports the blocking reason
 * `expense_account_unresolved` — which is the honest state, and is visible on the sync dashboard.
 *
 * `active` switches a whole category off without deleting its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_type_mappings', function (Blueprint $table) {
            $table->id();

            // The canonical FleetView code — see App\Support\ExpenseType. Unique: one mapping per type.
            $table->string('expense_type', 32)->unique();
            $table->string('label')->nullable();

            // ── WHAT it is charged to (Odoo account.account) ────────────────────────────────────────
            // id is the primary identifier; code is the portable fallback for environments that
            // configure by chart-of-accounts code. Name is display only, never matched on.
            $table->unsignedBigInteger('odoo_account_id')->nullable();
            $table->string('odoo_account_code', 64)->nullable();
            $table->string('odoo_account_name')->nullable();

            // ── HOW it is recorded (App\Support\OdooDocumentType) ───────────────────────────────────
            $table->string('odoo_document_type', 32)->nullable();

            // Optional: the journal a vendor bill is booked into. Left null = Odoo picks its default
            // purchase journal, which is correct for most single-company setups.
            $table->unsignedBigInteger('odoo_journal_id')->nullable();

            $table->boolean('active')->default(true);

            // Provenance of the mapping itself, so "who pointed REPAIR at this account?" is answerable.
            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestamp('mapped_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['active', 'expense_type'], 'etm_active_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_type_mappings');
    }
};
