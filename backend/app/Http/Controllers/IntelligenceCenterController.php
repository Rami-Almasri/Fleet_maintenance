<?php

namespace App\Http\Controllers;

use App\Services\Intelligence\Readiness\IntelligenceSnapshot;
use App\Services\Intelligence\Readiness\PromotionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Intelligence Center's read API — the whole platform's state in one call.
 *
 * CACHED, and for a reason that is about honesty rather than speed. The snapshot is a coherent
 * moment: the health scores, the readiness table and the freshness table are three views of one
 * reading of the world. Serving them from one cached build guarantees a user cannot refresh into a
 * page whose sections were computed seconds apart and disagree by an observation.
 *
 * Five minutes, because nothing here moves faster than a repair. `?refresh=1` forces a rebuild for
 * the operator who has just recorded a verdict and wants to watch the number move.
 */
class IntelligenceCenterController extends Controller
{
    private const TTL_SECONDS = 300;
    private const CACHE_KEY   = 'intel:center:snapshot';

    public function show(Request $request, IntelligenceSnapshot $snapshot): JsonResponse
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::has(self::CACHE_KEY);

        $payload = Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn () => $snapshot->build());

        // A degraded read is never cached. Sections fail for transient reasons — a migration in
        // flight, a lock, a table briefly absent — and freezing that for five minutes would turn a
        // momentary fault into a page that stays wrong long after the cause has gone.
        if (! empty($payload['failed_sections'])) {
            Cache::forget(self::CACHE_KEY);
            $cached = false;
        }

        // The page states its own age. A dashboard that cannot say how old it is invites being read
        // as live, which is precisely the failure it is meant to catch in everything else.
        return response()->json($payload + [
            'cached'     => $cached,
            'ttl_seconds' => self::TTL_SECONDS,
        ]);
    }

    /**
     * Run the promotion gate on demand — the one write this surface offers.
     *
     * DRY-RUN BY DEFAULT. Recording a decision is appending to an immutable history, and it should
     * take a deliberate act rather than a curious click; a promotion table full of exploratory runs
     * is a promotion table nobody trusts. `persist=1` is the deliberate act.
     */
    public function evaluate(Request $request, PromotionGate $gate): JsonResponse
    {
        $data = $request->validate([
            'capability_id' => ['nullable', 'string', 'max:64'],
            'force'         => ['nullable', 'boolean'],
            'persist'       => ['nullable', 'boolean'],
        ]);

        $result = $gate->evaluate(
            capabilityId: $data['capability_id'] ?? 'comeback-warning',
            force: (bool) ($data['force'] ?? false),
            persist: (bool) ($data['persist'] ?? false),
        );

        if ($result['promoted'] || ($data['persist'] ?? false)) {
            Cache::forget(self::CACHE_KEY);
        }

        return response()->json($result + ['persisted' => (bool) ($data['persist'] ?? false)]);
    }
}
