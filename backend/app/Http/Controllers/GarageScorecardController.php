<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\Garage\GarageScorecardService;

/**
 * The garage scorecard — "what is each garage good at, and where does it have a problem?"
 *
 * A separate endpoint from `Maintenance/garages` (the workload directory) rather than a fatter
 * payload on it, for two reasons. The directory is live operational state — cars in the garage right
 * now, overdue returns — and must not be slowed by a corpus-wide recurrence pass. The scorecard is
 * historical judgement over ~37k repairs and is cached for fifteen minutes. Merging them would tie a
 * page's freshness to its slowest half.
 *
 * Pure read. Nothing here routes a car: the assign step and the Garage Finder do that, with the car,
 * the fault severity and the queue in hand. See [[garage-recommendation-engine]].
 */
class GarageScorecardController extends Controller
{
    public function __construct(private GarageScorecardService $service)
    {
    }

    public function index()
    {
        try {
            return ResponseHelper::SuccessResponse($this->service->report(), 'Garage scorecards retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
