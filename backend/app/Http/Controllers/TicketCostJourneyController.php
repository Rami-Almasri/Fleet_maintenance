<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Services\FinancialCompletenessService;
use App\Services\FinancialTimelineService;
use App\Services\TicketCostJourneyService;

/**
 * The ticket's cost, read as a journey rather than as a number:
 *
 *     Fault → Required Part → Purchase Source → Invoice → Installation Cost → Final Ticket Cost
 *
 * Read-only, and gated on maintenance.view — it explains figures that already exist rather than creating
 * any. See {@see TicketCostJourneyService} for how each figure is attributed to the document behind it.
 */
class TicketCostJourneyController extends Controller
{
    public function __construct(
        private TicketCostJourneyService $journey,
        private FinancialTimelineService $timeline,
        private FinancialCompletenessService $completeness,
    ) {}

    public function show(Maintenance $ticket)
    {
        try {
            return ResponseHelper::SuccessResponse($this->journey->build($ticket), 'Cost journey retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The whole financial story of a repair in one response: the ordered timeline, what is still owed
     * before the ticket can close, and the audit verdict.
     *
     * Deliberately ONE endpoint. The manager's question — "what happened to the money on this repair?" —
     * is a single question, and answering it from three round trips is how a page ends up showing three
     * views that disagree with each other.
     */
    public function story(Maintenance $ticket)
    {
        try {
            $check = $this->completeness->check($ticket);

            return ResponseHelper::SuccessResponse([
                'timeline'   => $this->timeline->forTicket($ticket),
                'complete'   => $check['complete'],
                'blockers'   => $check['blockers'],
                'warnings'   => $check['warnings'],
                'audit'      => $check['audit'],
                // What closing the ticket would do right now — so the UI can explain the block BEFORE
                // someone presses the button and gets refused.
                'can_close'  => $check['complete'],
            ], 'Financial story retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
