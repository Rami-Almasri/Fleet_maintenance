<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIVE THE CAR BACK — the step after the oil change, and the one most likely to be forgotten.
 *
 * A recall takes a car off a paying customer mid-rental. The moment the oil is changed the job the
 * fleet cares about is finished, and that is exactly when the car goes quiet: it is standing in our
 * parking, the customer is still paying for it, and nobody owns getting it back to them. Every hour
 * it sits there is an hour of a rental the customer cannot use and will remember.
 *
 * So the recall does not end at the oil change. It ends when the car is back with the customer.
 *
 *   returned_to_customer_at/_by_name  the handover itself, stamped by whoever did it.
 *   return_reminder_at                when the chase last rang. The chase re-rings every few
 *                                     minutes until the car is back — deliberately noisy, because
 *                                     the whole point is that this step is otherwise invisible.
 *                                     Storing the LAST ring (rather than a queue of jobs) keeps the
 *                                     sweep stateless: it can be run at any interval, restarted, or
 *                                     run twice, and nobody gets rung twice inside the window.
 *
 * `dateTime`, not `timestamp` — MariaDB attaches ON UPDATE CURRENT_TIMESTAMP to the latter, which
 * would silently rewrite when the car went back every time the row was touched.
 * See [[mariadb-timestamp-autoupdate-trap]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dateTime('returned_to_customer_at')->nullable()->after('oil_change_note');
            $table->string('returned_to_customer_by_name', 120)->nullable()->after('returned_to_customer_at');
            $table->dateTime('return_reminder_at')->nullable()->after('returned_to_customer_by_name');

            // The sweep's own question — "which cars are we still holding?" — asked every minute.
            $table->index(['oil_changed_at', 'returned_to_customer_at'], 'cod_return_chase_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dropIndex('cod_return_chase_idx');
            $table->dropColumn(['returned_to_customer_at', 'returned_to_customer_by_name', 'return_reminder_at']);
        });
    }
};
