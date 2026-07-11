<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visual Condition Grading (Abu Marouf) — a manual, human-eyeball cosmetic/condition
 * grade that lives ALONGSIDE (never replaces) the OM lifecycle status and the live
 * operational_status:
 *   green  = Perfect — fully available, no issues.
 *   orange = Serviceable — rentable, but has minor scratches/cosmetic issues; ops must
 *            flag them to the customer at handover. STAYS in the rental pool.
 *   red    = Maintenance needed — hidden from the booking/rental interface.
 *
 * Defaults to 'green' so every existing car reads Perfect until someone grades it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('condition_grade', 10)->default('green')->after('transit_destination');
            $table->text('condition_note')->nullable()->after('condition_grade');           // the "Cosmetic Note"
            $table->timestamp('condition_graded_at')->nullable()->after('condition_note');
            $table->string('condition_graded_by')->nullable()->after('condition_graded_at'); // who last graded it
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['condition_grade', 'condition_note', 'condition_graded_at', 'condition_graded_by']);
        });
    }
};
