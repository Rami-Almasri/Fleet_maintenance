<?php

namespace App\Http\Controllers\Intelligence;

use App\Http\Controllers\Controller;
use App\Helpers\ResponseHelper;
use App\Services\Intelligence\ExecutiveDashboardService;

/**
 * Executive Home (Basem) — one read, one payload.
 *
 * The whole page is a single request on purpose. Six panels fetched separately would render in six
 * different orders on every load, and — worse — could disagree with each other if the nightly rebuild
 * landed between two of them. One payload, one `as_of`, one story.
 */
class ExecutiveDashboardController extends Controller
{
    public function __construct(private ExecutiveDashboardService $service) {}

    public function show()
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->service->report(),
                'Executive dashboard retrieved',
                200,
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
