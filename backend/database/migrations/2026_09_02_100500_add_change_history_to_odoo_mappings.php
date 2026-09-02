<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "WAS THIS MAPPING EVER CHANGED, AND FROM WHAT?"
 *
 * The mapping screen has to show six states, and five of them were already answerable from the existing
 * columns: mapped / unmapped / suggested / stale come from `status`, and accepted-by-whom-and-when
 * comes from `mapped_by` + `mapped_at`. The sixth — CHANGED — was not, because an update simply
 * overwrote `odoo_id` and the previous answer disappeared.
 *
 * That matters more than it looks. A mapping that has been re-pointed is the single most dangerous row
 * on the screen: documents already posted to Odoo were coded against the OLD target, and anyone
 * auditing them needs to see that the mapping moved. (The posted events are safe either way — each
 * carries its own frozen snapshot of what it actually used — but the person reading the mapping screen
 * still has to be told.)
 *
 * So a change now leaves a trace: what it pointed at before, and when it moved. One generation deep,
 * deliberately — this is a "has this been re-pointed, and from what?" flag, not an audit log. The full
 * history of financial decisions lives where it belongs, in the vehicle timeline and in
 * financial_event_attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('previous_odoo_id')->nullable()->after('odoo_name');
            $table->string('previous_odoo_name')->nullable()->after('previous_odoo_id');
            $table->timestamp('changed_at')->nullable()->after('previous_odoo_name');
            // How many times this mapping has been re-pointed. A count answers "is this row volatile?"
            // without needing the full history, which is the question the screen actually asks.
            $table->unsignedInteger('change_count')->default(0)->after('changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('odoo_mappings', function (Blueprint $table) {
            $table->dropColumn(['previous_odoo_id', 'previous_odoo_name', 'changed_at', 'change_count']);
        });
    }
};
