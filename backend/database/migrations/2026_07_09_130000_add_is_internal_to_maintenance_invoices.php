<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "In-House / Internal" maintenance invoices.
 *
 * A routine service (an oil change, a battery swap) is often done BY US, not a third-party garage — so the
 * invoice has no external vendor to name. `vendor_id` was already nullable, but a null there is ambiguous:
 * it could mean "internal job" or just "garage not filled in yet". This adds an explicit boolean so the
 * team can positively mark a bill as an in-house cost. When set, the service forces vendor_id to null; the
 * UI shows an "In-House" badge instead of "Unassigned garage" and hides the garage picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_invoices', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_invoices', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
