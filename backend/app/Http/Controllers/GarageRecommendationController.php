<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Services\GarageRecommendationService;
use Illuminate\Http\Request;

/**
 * Garage Recommendation — the data-driven "where should this vehicle go?" engine, exposed to the app.
 *
 * Two pure-read endpoints backed by GarageRecommendationService (learned from maintenance history, the
 * complement to the rules-based GarageRoutingService):
 *   • index()    — free-form query by ?model=&brand=&fault= (the standalone / shareable recommender).
 *   • forTicket() — recommendations for a specific ticket (its vehicle + faults), used in the assign step.
 *
 * Nothing here mutates a ticket. Persisting the chosen garage + reason is the assign-dispatch action's job.
 */
class GarageRecommendationController extends Controller
{
    public function __construct(private GarageRecommendationService $service)
    {
    }

    /** Free-form recommendation for a {model, brand, fault} query. */
    public function index(Request $request)
    {
        try {
            $data = $this->service->recommend([
                'model'  => $request->query('model'),
                'brand'  => $request->query('brand'),
                'fault'  => $request->query('fault'),
                'faults' => (array) $request->query('faults', []),
            ]);
            return ResponseHelper::SuccessResponse($data, 'Garage recommendations retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Recommendations for one ticket — derived from its vehicle model + fault categories. */
    public function forTicket(Maintenance $ticket)
    {
        try {
            return ResponseHelper::SuccessResponse($this->service->forTicket($ticket), 'Ticket garage recommendations retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
