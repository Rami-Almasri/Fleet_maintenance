<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\TraceabilitySnapshot;
use App\Services\CostVerificationService;
use Illuminate\Http\Request;

/**
 * The financial record's honesty, as a report: how much of the fleet's cost can be proved, how much is
 * legacy backlog, and whether the gap is closing.
 *
 * Read-only. It measures; the cleanup itself happens on the tickets, through the two paths that leave a
 * document behind (attach the real invoice, or record an approved adjustment).
 */
class CostVerificationController extends Controller
{
    public function __construct(private CostVerificationService $verification) {}

    /** Verified / legacy / unverified across the fleet, plus the trend if snapshots have been taken. */
    public function summary()
    {
        return $this->run(function () {
            $summary = $this->verification->fleetSummary();

            $latest = TraceabilitySnapshot::orderByDesc('taken_on')->first();
            $previous = TraceabilitySnapshot::orderByDesc('taken_on')->skip(1)->first();

            return ResponseHelper::SuccessResponse([
                'summary' => $summary,
                'trend'   => [
                    'latest'   => $latest,
                    'delta'    => $latest?->deltaFrom($previous),
                    'history'  => TraceabilitySnapshot::orderBy('taken_on')->limit(90)->get(
                        ['taken_on', 'total_cost', 'verified_cost', 'legacy_cost', 'unverified_cost', 'coverage_pct']
                    ),
                ],
                // Said plainly, because the number is going to look bad for a while and the reason matters.
                'note' => 'Legacy cost was recorded before financial documents existed. It is not being '
                    . 'back-filled with invented invoices — it is migrated ticket by ticket, by attaching '
                    . 'the real document or recording an approved adjustment that explains the figure.',
            ], 'Traceability summary retrieved');
        });
    }

    /** The worklist: legacy tickets ordered by how much undocumented money they carry. */
    public function queue(Request $request)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->verification->migrationQueue(min((int) $request->query('limit', 50), 200)),
            'Migration queue retrieved',
        ));
    }

    /**
     * Spend by category, split by documented vs undocumented — the report the structured origin exists
     * for ("how much did we spend on brakes this year, and how much of it can we prove?").
     */
    public function spendByCategory(Request $request)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->verification->spendByCategory($request->query('from'), $request->query('to')),
            'Spend by category retrieved',
        ));
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
