<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\InspectionEngineService;
use Illuminate\Http\Request;

/**
 * Inspection Intelligence Center — the read-only Mission Control endpoint for the automatic inspection
 * engine. One call returns the whole picture: engine health, the live trigger queue, the rule catalog
 * with tallies, today's timeline, the skipped-vehicle list with reasons, and the recent engine log.
 *
 * Everything is derived live from the real backend (DiagnosticGateService + the maintenances/audit rows
 * the Proactive Diagnostic Monitor produced). Nothing here writes — it is a monitoring & debugging
 * surface, not an action page.
 */
class InspectionEngineController extends Controller
{
    public function monitor(InspectionEngineService $engine)
    {
        try {
            return response()->json(['data' => $engine->monitor()]);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Idle Fleet Watch — active-fleet cars sitting idle (no rental, unused) for at least ?min_days=N
     * days (default 15). The exact feed behind the Post-Downtime Safety Check rule.
     */
    public function idleWatch(Request $request, InspectionEngineService $engine)
    {
        try {
            $minDays = (int) $request->query('min_days', 15);

            return response()->json(['data' => $engine->idleWatch($minDays)]);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
