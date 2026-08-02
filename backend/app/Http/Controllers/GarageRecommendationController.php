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

    /**
     * Free-form recommendation for a {model, brand, faults} query — the ticket-free path.
     *
     * This is what the fault-first finder calls: an inspector choosing symptoms on a car that has no
     * ticket yet still gets the full per-fault report, because nothing in the engine actually needs a
     * ticket — only a model and a set of fault categories.
     *
     * Two optional refinements, both keyed by category so they survive a query string:
     *   symptoms[engine]=Engine noise   the operator's OWN wording, so the report names the fault the
     *                                   way they wrote it rather than as the category label
     *   severities[engine]=critical     lets a fault escalate its criticality tier exactly as it would
     *                                   from a filed report, so a preview cannot rank differently from
     *                                   the assign step it is previewing
     */
    public function index(Request $request)
    {
        try {
            $faults = array_values(array_filter((array) $request->query('faults', [])));
            $symptoms = (array) $request->query('symptoms', []);

            $data = $this->service->recommend([
                'model'  => $request->query('model'),
                'brand'  => $request->query('brand'),
                'fault'  => $request->query('fault'),
                'faults' => $faults,
                // Shaped exactly like ticketFaultsDetail() so the per-fault recommender cannot tell a
                // preview from a real ticket — same code path, same answer.
                'faults_detail' => array_map(fn ($k) => [
                    'category_key' => $k,
                    'symptom'      => is_string($symptoms[$k] ?? null) && $symptoms[$k] !== '' ? $symptoms[$k] : null,
                ], $faults),
                'fault_severities' => (array) $request->query('severities', []),
            ]);

            // What was ASKED, echoed in the same shape forTicket() reports a ticket — so one component
            // renders both without branching. Deliberately not called `ticket`: there is no ticket here,
            // and a payload that claims otherwise is the kind of small lie that outlives the sprint.
            //
            // Labels are keyed off the categories the ENGINE reports back, not the ones the request sent.
            // The two happen to match today, but the engine is free to normalise or drop a category, and
            // a positional lookup would then quietly label one fault with another's name.
            $labels = array_combine(
                $data['criteria']['faults'] ?? [],
                $data['criteria']['fault_labels'] ?? [],
            ) ?: [];

            $data['subject'] = [
                'model_label'   => $request->query('model'),
                'faults_detail' => array_map(fn ($k) => [
                    'category_key' => $k,
                    'symptom'      => is_string($symptoms[$k] ?? null) && $symptoms[$k] !== '' ? $symptoms[$k] : ($labels[$k] ?? $k),
                    'label'        => $labels[$k] ?? $k,
                ], array_values(array_intersect($faults, array_keys($labels)))),
            ];

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

    /**
     * "What the garage will do" for one ticket — the expected work, with no garage scoring attached.
     *
     * A separate endpoint rather than a slice of forTicket()'s payload because it answers a different
     * question for a different reader: anyone opening the ticket wants to know what the car is having
     * done to it, and that must not cost them a full garage comparison (nor the maintenance.delegate
     * permission that comparing garages requires).
     */
    public function outlookForTicket(Maintenance $ticket)
    {
        try {
            return ResponseHelper::SuccessResponse($this->service->outlookForTicket($ticket), 'Ticket repair outlook retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
