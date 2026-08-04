<?php

namespace App\Http\Controllers\Intelligence;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Intelligence\EvidenceResource;
use App\Intelligence\Evidence\EvidenceRegistry;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The universal drill-down: any claim the platform makes, opened up to the rows behind it.
 *
 * One endpoint for every metric rather than a bespoke drill-down per surface. A card emits the
 * evidence id for its own number, the drawer asks here, and the UI never needs to know how a
 * particular figure was computed — which is what stops the evidence layer leaking into the frontend
 * and going stale the first time a metric changes shape.
 *
 * Read-only. Gated on `intelligence.view`: the evidence is the same repair history the intelligence
 * pages already show, at a finer grain.
 */
class EvidenceController extends Controller
{
    public function __construct(private EvidenceRegistry $registry)
    {
    }

    public function show(Request $request, string $queryId)
    {
        try {
            $evidence = $this->registry->resolve($queryId);

            $page    = max(1, (int) $request->query('page', 1));
            $perPage = min(200, max(1, (int) $request->query('per_page', 50)));

            return ResponseHelper::SuccessResponse(
                (new EvidenceResource($evidence, $page, $perPage))->toArray($request),
                'Evidence retrieved',
                200,
            );
        } catch (InvalidArgumentException $e) {
            // An unknown id is a 404, not a 500: the caller asked for something that does not exist
            // rather than the server failing. An empty drawer would be worse than either — "no
            // evidence" and "no such claim" look identical to a user and mean very different things.
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
