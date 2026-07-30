<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Services\Intelligence\OperationalIntelligence;
use Illuminate\Http\Request;

/**
 * Decision Cards on a maintenance ticket, and the human's answer to them.
 *
 * Two endpoints, because the loop has two halves: what the platform said, and what the user did
 * about it. Without the second one the platform recommends but never learns, which is the failure
 * mode this whole architecture exists to avoid.
 *
 * The controller knows nothing about capabilities, evidence or ranking — it hands a ticket to the
 * delivery layer and returns what comes back.
 */
class MaintenanceIntelligenceController extends Controller
{
    public function __construct(private readonly OperationalIntelligence $intelligence) {}

    /**
     * What the platform thinks the user should know before deciding, at this ticket's current state.
     *
     * Returns [] far more often than not, and that is correct: a card that fires on every ticket is
     * noise, and noise is what stops people reading the one that matters.
     */
    public function cards(Maintenance $ticket)
    {
        return ResponseHelper::SuccessResponse(
            $this->intelligence->forTicket($ticket, request()->user()),
            'Decision cards retrieved successfully',
            200,
        );
    }

    /**
     * The user answered — accepted, overrode, or dismissed.
     *
     * Recorded against the recommendation AS IT WAS SHOWN. The frozen text is why an override can
     * still be explained months later, after the capability that produced it has been rewritten.
     */
    public function respond(Request $request, Maintenance $ticket, Recommendation $recommendation)
    {
        try {
            // A recommendation belongs to the ticket it was raised on. Answering one through another
            // ticket's URL would corrupt the outcome trail rather than merely 404.
            if ($recommendation->subject_type !== Maintenance::class || (int) $recommendation->subject_id !== $ticket->id) {
                return ResponseHelper::FailureResponse(null, 'This recommendation does not belong to this ticket', 404);
            }

            $data = $request->validate([
                'response' => ['required', 'string', 'in:'.implode(',', RecommendationEvent::RESPONSES)],
                // Optional by design: demanding a reason teaches people to type "n/a", which is worse
                // than an honest blank. It is still the most valuable field in the loop.
                'reason'   => ['nullable', 'string', 'max:2000'],
                'payload'  => ['nullable', 'array'],
            ]);

            $event = $this->intelligence->respond(
                recommendation: $recommendation,
                event: $data['response'],
                actor: $request->user(),
                reason: $data['reason'] ?? null,
                payload: $data['payload'] ?? [],
            );

            return ResponseHelper::SuccessResponse(
                ['recommendation_id' => $recommendation->id, 'event' => $event->event, 'occurred_at' => $event->occurred_at?->toIso8601String()],
                'Response recorded',
                200,
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
