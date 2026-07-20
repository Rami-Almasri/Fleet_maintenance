<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserActivityService;
use Illuminate\Http\Request;

/**
 * Read side of the Workforce Operations Center plus the activity heartbeat.
 *
 * The heartbeat is open to any authenticated user (everyone reports their OWN
 * presence). The dashboard reads reuse the existing `users.manage` gate — this
 * feature adds surfaces, never new permissions or business logic.
 */
class WorkforceController extends Controller
{
    public function __construct(private readonly UserActivityService $activity) {}

    /** Frontend presence ping: a navigation (module change) or a plain heartbeat. */
    public function heartbeat(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'msg' => 'Unauthenticated', 'data' => []], 401);
        }

        $data = $request->validate([
            'path' => 'nullable|string|max:255',
            'page' => 'nullable|string|max:120',
            'navigation' => 'nullable|boolean',
        ]);

        $this->activity->record(
            $user,
            $data['path'] ?? null,
            $data['page'] ?? null,
            (bool) ($data['navigation'] ?? false),
            $request,
        );

        return response()->json(['success' => true, 'msg' => 'OK', 'data' => []]);
    }

    /** KPI row + enriched account list + operational charts for a chosen day. */
    public function overview(Request $request)
    {
        $date = $request->query('date');
        return response()->json([
            'success' => true,
            'msg' => 'OK',
            'data' => $this->activity->overview(is_string($date) ? $date : null),
        ]);
    }

    /** Drawer detail: daily analytics, timeline, module usage, productivity, audit. */
    public function userActivity(Request $request, User $user)
    {
        $date = $request->query('date');
        return response()->json([
            'success' => true,
            'msg' => 'OK',
            'data' => $this->activity->userDetail($user, is_string($date) ? $date : null),
        ]);
    }
}
