<?php

use App\Models\Maintenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the pre-maintenance APPROVAL GATE — an inspection report now opens a ticket immediately.
 *
 * `recommendation_pending` sat between "the inspector filed his report" and "the supervisor picks a
 * garage". In practice it approved nothing: the supervisor's real decision (which garage, which driver)
 * already happens at inspection_pending, so the gate only delayed the car. Required parts do not need it
 * either — they raise their own Part Requests at report time and procurement runs alongside the repair
 * rather than in front of it. See [[inspection-required-parts-split]].
 *
 * Nothing routes into the state any more, so any ticket still sitting in it is released into the dispatch
 * queue — which is exactly where its supervisor would have sent it. `recommendation_dismissed` rows are
 * left alone: they are terminal history and still render.
 *
 * The recommendation_* columns are deliberately KEPT. They hold the audit of decisions genuinely taken
 * while the gate existed (who reviewed, what they wrote, why something was dismissed), and deleting them
 * would erase that record.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenances')
            ->where('workflow_status', Maintenance::WF_RECOMMENDATION_PENDING)
            ->update(['workflow_status' => Maintenance::WF_INSPECTION_PENDING]);
    }

    public function down(): void
    {
        // Irreversible by design: once released, a ticket is indistinguishable from one that opened
        // straight into the dispatch queue, and re-gating it would strand a car nobody is waiting on.
    }
};
