<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE DRIVER'S FARE HOME, ON THE MOVEMENT THAT CAUSED IT.
 *
 * A taxi fare in a fleet is almost never a free-floating expense claim: a driver takes a car somewhere
 * and has to get back. That "gets back" is a leg of a movement FleetView already tracks —
 * {@see \App\Models\LogisticsTask} is the canonical home for every vehicle movement
 * ([[logistics-dispatch-canonical]]), and it already knows the car, the driver, the destination and
 * whether the trip was a round trip.
 *
 * So TAXI attaches here rather than becoming a standalone expense form. That is the difference between
 * an integrated workflow and a finance module: the fare is recorded against the journey it belongs to,
 * by the person who made it, and it inherits the audit trail that journey already has.
 *
 * ── WHY THE VEHICLE IS NOT REQUIRED FOR THIS ONE ───────────────────────────────────────────────────
 *
 * A logistics task always names a car, so a fare recorded here always has one. But ExpenseType::TAXI is
 * deliberately NOT in VEHICLE_BOUND, because the cost is the DRIVER's transport, not the car's running
 * cost — the analytic account is genuinely optional. Where a task names a car we still send the
 * analytic account, so the cost of moving that particular vehicle stays visible; where a future
 * producer has no car, nothing blocks. Both are correct and the validator handles both.
 *
 * Additive and nullable — a task with no fare simply leaves them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->decimal('taxi_fare', 10, 2)->nullable()->after('returned_accuracy');
            $table->string('taxi_currency', 8)->nullable()->after('taxi_fare');
            $table->date('taxi_date')->nullable()->after('taxi_currency');
            // Which leg the fare paid for — 'outbound' (getting TO the car) or 'return' (getting back
            // after dropping it). Traceability only, but it is the first thing anyone asks when a fare
            // looks wrong on a one-way move.
            $table->string('taxi_leg', 16)->nullable()->after('taxi_date');
            $table->string('taxi_from')->nullable()->after('taxi_leg');
            $table->string('taxi_to')->nullable()->after('taxi_from');

            // The receipt. An Odoo hr.expense is evidenced by its receipt and by nothing else — there
            // is no supplier-side copy to fall back on — so this is what the validator insists on.
            $table->string('taxi_receipt_disk', 32)->nullable()->after('taxi_to');
            $table->string('taxi_receipt_key')->nullable()->after('taxi_receipt_disk');
            $table->string('taxi_reference', 128)->nullable()->after('taxi_receipt_key');

            $table->unsignedBigInteger('taxi_recorded_by')->nullable()->after('taxi_reference');
            $table->timestamp('taxi_recorded_at')->nullable()->after('taxi_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'taxi_fare', 'taxi_currency', 'taxi_date', 'taxi_leg', 'taxi_from', 'taxi_to',
                'taxi_receipt_disk', 'taxi_receipt_key', 'taxi_reference',
                'taxi_recorded_by', 'taxi_recorded_at',
            ]);
        });
    }
};
