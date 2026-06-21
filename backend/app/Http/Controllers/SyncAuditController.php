<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\SyncRun;

/**
 * Sync Audit — read-only visualisation of the logs the CMD sync writes (sync_runs +
 * sync_corrections). Lets staff run the CMD sync, then refresh the dashboard to see what
 * each execution scanned, updated, and auto-corrected (e.g. stale dates cleared).
 */
class SyncAuditController extends Controller
{
    /** Last ~20 sync executions, newest first, with the headline counts. */
    public function index()
    {
        try {
            $runs = SyncRun::query()
                ->withCount('corrections')
                ->orderByDesc('started_at')
                ->limit(20)
                ->get()
                ->map(fn (SyncRun $r) => $this->summary($r));

            return ResponseHelper::SuccessResponse(['runs' => $runs], 'Sync history retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** One run + the exact corrections it made (capped), for the details view. */
    public function show(SyncRun $syncRun)
    {
        try {
            $corrections = $syncRun->corrections()
                ->orderBy('field')->orderBy('contract_no')
                ->limit(500)
                ->get(['contract_id', 'contract_no', 'external_id', 'field', 'old_value', 'action']);

            return ResponseHelper::SuccessResponse([
                'run'              => $this->summary($syncRun->loadCount('corrections')),
                'corrections'      => $corrections,
                'corrections_shown' => $corrections->count(),
            ], 'Sync run detail retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** Flatten a run + its contracts-phase counts into the shape the table needs. */
    private function summary(SyncRun $r): array
    {
        $c = $r->result['contracts'] ?? [];

        return [
            'id'           => $r->id,
            'action'       => $r->action,
            'status'       => $r->status,
            'started_at'   => optional($r->started_at)->toIso8601String(),
            'finished_at'  => optional($r->finished_at)->toIso8601String(),
            'error'        => $r->error,
            // contracts-phase headline numbers (null when the run didn't sync contracts)
            'scanned'      => $c['scanned'] ?? null,
            'created'      => $c['created'] ?? null,
            'updated'      => $c['updated'] ?? null,
            // prefer the live row count; fall back to the number stored in the result JSON
            'corrections'  => $r->corrections_count ?? ($c['corrections'] ?? 0),
            'preserved'    => $c['null_overwrites_prevented'] ?? null,
        ];
    }
}
