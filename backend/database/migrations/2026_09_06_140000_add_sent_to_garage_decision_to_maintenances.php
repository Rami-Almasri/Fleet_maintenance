<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "IT DOESN'T NEED TESTING — IT NEEDS A GARAGE." The decision, written down.
 *
 * A car can reach the dispatch queue two ways that skip the test drive: born there on the garage door
 * (openDirectDispatch), or a request already in flight converted at the review gate
 * (dispatchInsteadOfTest). Both are somebody OVERRULING a test — the system asked for one, or a person
 * did, and another person said it was unnecessary — and until now that decision left nothing behind but
 * a stage change. "Who decided this car did not need to be driven, and why?" had no answer, and the
 * question is asked every time a car comes back for the same fault a fortnight later.
 *
 * FOUR FACTS, four columns, because each is asked on its own:
 *   - sent_to_garage_at        WHEN — and, being the only nullable-or-set flag of the four, the thing
 *                              every filter keys off: "show me the cars that skipped a test this month".
 *   - sent_to_garage_by        WHO. A person, always: this is never a system decision (see
 *                              [[system-suggestion-is-not-a-human-decision]] — the scanner may ask for
 *                              a test, it may never wave one away).
 *   - sent_to_garage_reason_code   WHY, as a CODE from the dispatch reason list (request_reasons, door
 *                              = dispatch), never English — the same contract request_reason_code
 *                              keeps. Its own column rather than reusing request_reason_code, which
 *                              answers a DIFFERENT question: why the car was sent in at all. On a
 *                              converted request those two genuinely differ ("a warning light is on"
 *                              vs "the parts are in"), and collapsing them would lose the first.
 *   - sent_to_garage_transport HOW it will physically travel: a company driver, or a recovery truck for
 *                              a car nobody can drive. Stated at the moment of the decision by the
 *                              person who knows the car's condition, so the supervisor picking the
 *                              garage inherits it instead of guessing. NOT transfer_transport_method —
 *                              that one belongs to a garage-to-garage transfer and is deliberately
 *                              cleared at dispatch; this is the intake leg and must survive it.
 *
 * Nullable throughout: every ticket that ever went in the ordinary way has nothing to say here, and a
 * default would be a claim about history nobody made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // dateTime(), never timestamp() — MariaDB auto-updates a bare TIMESTAMP column on every row
            // write, which would silently re-stamp this decision each time the ticket moved on.
            // See [[mariadb-timestamp-autoupdate-trap]].
            $table->dateTime('sent_to_garage_at')->nullable()->after('reviewed_at');
            $table->unsignedBigInteger('sent_to_garage_by')->nullable()->after('sent_to_garage_at');
            $table->string('sent_to_garage_reason_code', 64)->nullable()->after('sent_to_garage_by');
            $table->string('sent_to_garage_transport', 20)->nullable()->after('sent_to_garage_reason_code');

            // The filter's index: "every car sent straight to a garage, newest first". Ordered so the
            // WHERE (is it set?) and the ORDER BY (when?) are the same read.
            $table->index('sent_to_garage_at', 'maintenances_sent_to_garage_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex('maintenances_sent_to_garage_at_idx');
            $table->dropColumn([
                'sent_to_garage_at',
                'sent_to_garage_by',
                'sent_to_garage_reason_code',
                'sent_to_garage_transport',
            ]);
        });
    }
};
