<?php

use App\Models\Maintenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the recommendation queue's PARTS branch — it duplicated the part-request lifecycle.
 *
 * The queue used to hold its own waiting-for-parts state: "Order Parts First" parked a recommendation in
 * `awaiting_parts`, and "Parts Ready" (the `recommendation_parts_ready` flag) released it. That was a
 * second, parallel answer to "are we waiting on a part?" — the real one lives on `part_requests`
 * (Requested → Approved → Purchased → Delivered → Installed), which knows what the part is, who is buying
 * it and what it costs. The recommendation flag knew none of that.
 *
 * There is now exactly ONE parts lifecycle. A ticket waits in `recommendation_pending` for the
 * coordinator's decision; whether a part is on its way is answered by the ticket's part requests.
 * See [[inspection-required-parts-split]].
 *
 * Any ticket still parked in `awaiting_parts` returns to `recommendation_pending` — it is back in the
 * coordinator's queue, exactly where it belongs, and nothing is lost: those tickets keep their
 * `recommendation_note` (which is where the parts note was written).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenances')
            ->where('workflow_status', 'awaiting_parts')
            ->update(['workflow_status' => Maintenance::WF_RECOMMENDATION_PENDING]);

        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('recommendation_parts_ready');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->boolean('recommendation_parts_ready')->default(false)->after('recommendation_note');
        });
        // The tickets themselves are deliberately NOT moved back: `recommendation_pending` is a valid,
        // strictly safer state for every one of them, and we cannot know which were parked for parts.
    }
};
