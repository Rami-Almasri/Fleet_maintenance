<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual-to-Odoo bridge — two small additions that finalise the operational model:
 *
 *  - Path A (Manual Entry): `invoice_requested_at` / `invoice_requested_by` record that we asked the
 *    garage for an itemised invoice (the team then keys it in via the LineItemsEditor). Garages are
 *    Vendors with no login, so the "request" is an internal, auditable flag + a team notification that
 *    carries the garage's contact — not an in-app message to the vendor.
 *
 *  - Path B (OCR-Ready): `entry_source` on each line item records HOW the line was captured
 *    ('manual' today; 'ocr' / 'import' later). The data shape is otherwise unchanged, so an OCR
 *    pipeline just produces the same line-item array and posts it through the existing PUT endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('invoice_requested_at')->nullable()->after('cost_recorded_at');
            $table->unsignedBigInteger('invoice_requested_by')->nullable()->after('invoice_requested_at');
        });

        Schema::table('maintenance_line_items', function (Blueprint $table) {
            // How this line was captured — provenance for the OCR/import roadmap. Defaults to 'manual'.
            $table->string('entry_source', 12)->default('manual')->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['invoice_requested_at', 'invoice_requested_by']);
        });
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->dropColumn('entry_source');
        });
    }
};
