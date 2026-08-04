<?php

namespace App\Http\Controllers\Intelligence;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Garage\GarageScorecardService;
use Illuminate\Http\Request;

/**
 * The garage profile and the comparison view.
 *
 * ── NO SECOND CALCULATION PATH. THAT IS THE WHOLE DESIGN. ────────────────────────────────────────
 * Both endpoints read the SAME cached report the /garages list is built from and select out of it.
 * They do not re-query, re-aggregate or re-score anything.
 *
 * The obvious alternative — a leaner per-garage query — would have been faster and would have
 * reintroduced exactly the failure this platform spent a convergence eliminating: a profile page
 * quietly disagreeing with the list page it was opened from. A garage's number must be the same
 * number wherever it appears, and the cheapest way to guarantee that is to make it literally the
 * same number.
 *
 * The report is cached for fifteen minutes, so selecting from it costs an array filter.
 */
class GarageIntelligenceController extends Controller
{
    public function __construct(private GarageScorecardService $service)
    {
    }

    /**
     * One garage: its score, both axes, every domain it works in, and its strengths and problems.
     *
     * Ships the fleet baselines and provenance alongside, because a garage's rate is meaningless
     * without what it is being compared against — and a page that grades a supplier has to be able
     * to show its working without the reader navigating elsewhere for it.
     */
    public function show(int $vendorId)
    {
        try {
            $report = $this->service->report();

            $card = collect($report['garages'])->firstWhere('vendor_id', $vendorId);

            if ($card === null) {
                return ResponseHelper::FailureResponse(null, 'This garage has no measured repair history.', 404);
            }

            // Where this garage sits among those that could be measured at all. Rank over the SCORED
            // population, not over every vendor: being 12th of 33 measured garages is a fact, being
            // 12th of 170 mostly-unmeasured ones is not.
            $scored = collect($report['garages'])->filter(fn ($g) => $g['score']['value'] !== null)->values();
            $rank   = $scored->search(fn ($g) => $g['vendor_id'] === $vendorId);

            return ResponseHelper::SuccessResponse([
                'garage'     => $card,
                'rank'       => $rank === false ? null : $rank + 1,
                'ranked_of'  => $scored->count(),
                'fleet'      => $report['fleet'],
                'domains'    => $report['domains'],
                'coverage'   => $report['coverage'] ?? null,
                'as_of'      => $report['as_of'] ?? null,
                'provenance' => $report['provenance'],
            ], 'Garage profile retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Two to four garages side by side.
     *
     * ⚠ CASE-MIX IS ONLY APPROXIMATE BETWEEN GARAGES. The adjustment is indirect standardisation,
     * which is rigorous for garage-vs-fleet and only approximate for garage-vs-garage when their
     * work mixes differ materially. So every comparison ships each garage's DOMAIN MIX alongside its
     * ratio, and the frontend is expected to show it: without the mix, this page would make a claim
     * the statistics do not support.
     */
    public function compare(Request $request)
    {
        try {
            $ids = collect(explode(',', (string) $request->query('ids', '')))
                ->map(fn ($v) => (int) trim($v))
                ->filter()
                ->unique()
                ->take(4)
                ->values();

            if ($ids->count() < 2) {
                return ResponseHelper::FailureResponse(null, 'Pick at least two garages to compare.', 422);
            }

            $report  = $this->service->report();
            $garages = collect($report['garages'])->whereIn('vendor_id', $ids->all())->values();

            if ($garages->count() < 2) {
                return ResponseHelper::FailureResponse(null, 'At least two of those garages have no measured history.', 404);
            }

            return ResponseHelper::SuccessResponse([
                'garages' => $garages->map(fn ($g) => [
                    ...$g,
                    // The share of this garage's work each domain represents. The reader needs it to
                    // judge whether the two garages are comparable at all.
                    'mix' => collect($g['domains'])
                        ->sortByDesc('jobs')
                        ->take(6)
                        ->map(fn ($d) => [
                            'key'       => $d['key'],
                            'label'     => $d['label'],
                            'share_pct' => $d['share_pct'],
                            'jobs'      => $d['jobs'],
                        ])->values(),
                ])->all(),
                'fleet'      => $report['fleet'],
                'domains'    => $report['domains'],
                'as_of'      => $report['as_of'] ?? null,
                'provenance' => $report['provenance'],
                'caveat'     => 'case_mix_approximate_between_garages',
            ], 'Garage comparison retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
