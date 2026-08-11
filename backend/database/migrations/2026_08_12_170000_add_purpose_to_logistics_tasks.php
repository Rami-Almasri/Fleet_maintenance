<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT KIND OF MOVE IS THIS — asked as a column, because it was being asked as a string search.
 *
 * A collection FROM A CUSTOMER is not the same job as a run to a garage: the car belongs to somebody
 * else until the driver takes the keys, the odometer reading at the doorstep is the whole point of
 * the trip, and the driver's own queue needs to show it under its own heading ("cars to collect")
 * rather than mixed in with cars we already hold.
 *
 * Until now the only way to recognise one was `notes LIKE 'Oil recall%'` — a rule that breaks the
 * moment anybody edits the note, which the code itself does when a recall is revised. A purpose is a
 * fact about the job; it belongs in a column.
 *
 * Nullable on purpose: every move that existed before this is an ordinary dispatch, and a null
 * purpose reads exactly that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->after('maintenance_id');
            $table->index('purpose');
        });

        // Adopt the collections already in flight, which were raised before the column existed and
        // are recognisable only by the note the dispatcher wrote. Bounded to OPEN moves: a finished
        // trip needs no queue heading, and re-labelling history from a text match would be a guess.
        DB::table('logistics_tasks')
            ->whereNull('completed_at')
            ->where('notes', 'like', 'Oil recall%')
            ->update(['purpose' => 'oil_recall_collection']);
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
