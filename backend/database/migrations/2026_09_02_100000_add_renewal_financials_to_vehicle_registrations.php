<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE MONEY SIDE OF A REGISTRATION RENEWAL, ON THE REGISTRATION THAT ALREADY EXISTS.
 *
 * `vehicle_registrations` already holds what the RTA says about a car — its expiry, its insurance, its
 * fines. What it never held was what the renewal COST us, because the record is synced from
 * OfficeManager and OM does not carry our renewal fee. So a registration expense had nowhere to live,
 * and REGISTRATION was an expense type with no producer.
 *
 * These columns give it one, on the record that already represents the thing being paid for. Same
 * decision as the recovery leg (see add_recovery_financials_to_maintenances): the financial layer stays
 * a READER, and the operation stays the source of truth (§21).
 *
 * ── WHY THIS IS SAFE AGAINST THE OM SYNC ───────────────────────────────────────────────────────────
 *
 * VehicleRegistrationImporter writes `updateOrCreate(['chasis_no' => $vin], $data)` where `$data` is an
 * explicit, closed list of the fields OM supplies. A column that is not in that list is never touched
 * by a sync — so a renewal fee keyed by our finance team survives every re-import. That is checked, not
 * assumed: adding these to the importer's `$data` would silently null them on the next run, which is
 * the one change to avoid here.
 *
 * ── ONE RENEWAL AT A TIME ──────────────────────────────────────────────────────────────────────────
 *
 * A registration row is the CURRENT registration of a car, and it is renewed periodically. These
 * columns describe the renewal that produced the current expiry — the financial event keyed on this row
 * is that renewal. When the next renewal happens the figures are overwritten and the event is rebuilt,
 * unless it has already reached Odoo, in which case FinancialEventBuilder refuses to touch it and the
 * posted document stands. A fleet that needs the full renewal HISTORY as separate bills would need a
 * renewals table; today the business asks for the current one, and inventing history we do not have
 * would be worse than not having it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_registrations', function (Blueprint $table) {
            $table->decimal('renewal_cost', 12, 2)->nullable()->after('fines_amount');
            $table->string('renewal_currency', 8)->nullable()->after('renewal_cost');
            $table->date('renewal_date')->nullable()->after('renewal_currency');
            // Who we paid — a typing centre, a broker, or the authority itself. Its own column rather
            // than reusing insurance_company_id, which is the INSURER and a different business.
            $table->unsignedBigInteger('renewal_vendor_id')->nullable()->after('renewal_date');

            $table->string('renewal_reference', 128)->nullable()->after('renewal_vendor_id');
            // Same disk/key convention as maintenance_invoices.receipt_photo_* (§29).
            $table->string('renewal_receipt_disk', 32)->nullable()->after('renewal_reference');
            $table->string('renewal_receipt_key')->nullable()->after('renewal_receipt_disk');
            $table->unsignedBigInteger('renewal_recorded_by')->nullable()->after('renewal_receipt_key');
            $table->timestamp('renewal_recorded_at')->nullable()->after('renewal_recorded_by');

            $table->index('renewal_vendor_id', 'vreg_renewal_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_registrations', function (Blueprint $table) {
            $table->dropIndex('vreg_renewal_vendor_idx');
            $table->dropColumn([
                'renewal_cost', 'renewal_currency', 'renewal_date', 'renewal_vendor_id',
                'renewal_reference', 'renewal_receipt_disk', 'renewal_receipt_key',
                'renewal_recorded_by', 'renewal_recorded_at',
            ]);
        });
    }
};
