<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE OIL WAS ACTUALLY CHANGED — the far end of the follow-up, recorded as a fact.
 *
 * Everything before this row's columns is a plan: the projection says the car will pass its oil
 * point, a Controller decides, Sales agree, a driver collects. None of that changes the car's oil
 * life. The single moment that does is a workshop reading off the dash after the change and saying
 * "done, at 20,000 km" — from then on the car's next service is that reading + its interval, and the
 * follow-up has nothing left to ask.
 *
 * What each column is for:
 *   oil_changed_at            the moment the change was RECORDED (not the projection, not the
 *                             decision). Non-null is the whole flag: this recall/defer is finished.
 *   oil_changed_odometer      the reading the oil was changed at. This is the number that becomes
 *                             `vehicles.last_service_odometer` through Vehicle::recordOilService(),
 *                             and it is stored here too so the follow-up can always show the figure
 *                             it settled on even after the car has driven on.
 *   oil_changed_by/_by_name   who recorded it. A real authenticated actor, same discipline as the
 *                             Sales stamp — a service nobody signed for is not evidence.
 *   oil_change_note           optional colour ("changed at Al Quoz, filter too").
 *
 * `dateTime` (not `timestamp`) on purpose — MariaDB silently attaches ON UPDATE CURRENT_TIMESTAMP to
 * the latter, which would let an unrelated save rewrite when the oil was changed.
 * See [[mariadb-timestamp-autoupdate-trap]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dateTime('oil_changed_at')->nullable()->after('test_required');
            $table->unsignedInteger('oil_changed_odometer')->nullable()->after('oil_changed_at');
            $table->foreignId('oil_changed_by')->nullable()->after('oil_changed_odometer')
                ->constrained('users')->nullOnDelete();
            $table->string('oil_changed_by_name', 120)->nullable()->after('oil_changed_by');
            $table->string('oil_change_note', 1000)->nullable()->after('oil_changed_by_name');

            // The board's one hot question — "which follow-ups are still owed an oil change?" — is
            // answered by this column, so it is indexed rather than scanned.
            $table->index('oil_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oil_changed_by');
            $table->dropIndex(['oil_changed_at']);
            $table->dropColumn([
                'oil_changed_at',
                'oil_changed_odometer',
                'oil_changed_by_name',
                'oil_change_note',
            ]);
        });
    }
};
