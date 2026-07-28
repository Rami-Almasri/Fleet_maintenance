<?php

namespace App\Services\State;

use App\Models\Maintenance;
use Illuminate\Support\Collection;

/**
 * Read-model loader (blueprint Step 4, DP1-B): the ONE place that eager-loads the ticket graph the
 * operational-state resolvers consume. It exists so orchestration layers (CarStatusService) compose
 * over already-loaded data and never construct this query themselves — and so the whole board is one
 * batched load, never N+1.
 *
 * SCOPE: only the operational-row graph. It is NOT a general replacement for the many other queries
 * inside CarStatusService (widgets, per-vehicle detail) — those remain there under DEBT-3 until a
 * dedicated repository-extraction workstream. Loading only; no derivation, no writes.
 */
class OperationalStateLoader
{
    /**
     * Open workflow tickets with everything the row needs: the display relations CarStatusService
     * already used, PLUS the resolver graph (tasks → partRequests → purchases → sourceVendor) and the
     * ticket checkpoints. One query per relation — constant regardless of ticket count.
     *
     * @return Collection<int, Maintenance>
     */
    public function openTickets(int $limit = 800): Collection
    {
        return Maintenance::openWorkflow()
            ->with([
                // Display relations (unchanged from the previous inline query).
                'vehicle:id,plate_no,make,model,vin,operational_status,condition_grade',
                'vendor:id,name',
                'transferToVendor:id,name',
                'assignedDriver:id,name',
                'inspector:id,name',
                'activeMove',
                'activeTemporaryRelease',
                'tasks:id,maintenance_id,status,severity,category_key,symptom',
                // Resolver graph: WorkflowStateResolver + MaintenanceDelayResolver read these.
                'tasks.partRequests',
                'tasks.partRequests.purchases',
                'tasks.partRequests.purchases.sourceVendor:id,name',
                'checkpoints',
            ])
            ->orderByDesc('last_state_change_at')
            ->limit($limit)
            ->get();
    }
}
