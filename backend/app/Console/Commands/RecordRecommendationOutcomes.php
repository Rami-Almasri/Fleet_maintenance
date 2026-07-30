<?php

namespace App\Console\Commands;

use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Services\Intelligence\RecommendationRecorder;
use App\Services\RepairIntelligence\Query\ProjectionRepairHistoryQuery;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The step that closes the learning loop: what ACTUALLY happened.
 *
 * Everything else in the platform runs while a human is watching. This runs ninety days later, long
 * after everyone has stopped thinking about the case, and it is the only reason the platform can
 * ever say whether it was right. Without it there is a recommendation engine and a feedback log, but
 * no learning — acceptance rate would be the only measurable number, and acceptance is not success.
 *
 * ONE RULE PER CAPABILITY, INLINE AND ON PURPOSE. There is exactly one capability today, so a
 * dedicated resolver abstraction would be speculative — an interface with a single implementation,
 * invented before the second case is known. When a second capability needs an outcome rule, the
 * shape of both will be visible and extracting it will be a five-minute change. Until then this is
 * a `match` on the card id, clearly marked.
 *
 * Idempotent: a recommendation that already carries an outcome is skipped, and outcomes are events,
 * so nothing is ever rewritten.
 */
class RecordRecommendationOutcomes extends Command
{
    protected $signature = 'intelligence:record-outcomes
                            {--window=90 : days after the recommendation in which a recurrence counts}
                            {--dry-run : report what would be recorded, write nothing}';

    protected $description = 'Judge past recommendations against what actually happened (closes the learning loop)';

    public function handle(RepairHistoryQuery $history, RecommendationRecorder $recorder): int
    {
        $window = (int) $this->option('window');
        $dry    = (bool) $this->option('dry-run');

        // Only recommendations old enough for the verdict to be knowable. Judging one at 30 days
        // would systematically score the platform as "right" simply because time had not passed.
        $due = Recommendation::query()
            ->where('presented_at', '<=', now()->subDays($window))
            ->whereDoesntHave('events', fn ($q) => $q->where('event', RecommendationEvent::OUTCOME_RECORDED))
            ->orderBy('presented_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due — no recommendation is both old enough to judge and still unjudged.');

            return self::SUCCESS;
        }

        $this->info("{$due->count()} recommendation(s) due for an outcome (window {$window}d).");

        $counts = [];

        foreach ($due as $recommendation) {
            $verdict = match ($recommendation->card_id) {
                'comeback-warning' => $this->judgeComebackWarning($recommendation, $history, $window),
                default            => null, // no rule yet for this capability — leave it unjudged
            };

            if ($verdict === null) {
                $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;

                continue;
            }

            [$result, $payload] = $verdict;
            $counts[$result] = ($counts[$result] ?? 0) + 1;

            $this->line(sprintf(
                '  #%d %s (%s) → %s — %s',
                $recommendation->id,
                $recommendation->card_id,
                $recommendation->response()?->event ?? 'unanswered',
                strtoupper($result),
                $payload['why'],
            ));

            if (! $dry) {
                $recorder->recordOutcome(
                    recommendation: $recommendation,
                    result: $result,
                    outcomeType: $recommendation->subject_type,
                    outcomeId: $recommendation->subject_id,
                    payload: $payload,
                );
            }
        }

        foreach ($counts as $key => $n) {
            $this->line("  {$key}: {$n}");
        }

        if ($dry) {
            $this->warn('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }

    /**
     * Was the comeback warning borne out?
     *
     * The warning's claim is "this fault is recurring, and dispatching without re-diagnosing risks
     * another visit". So the question history answers is: did the same signature recur AGAIN in the
     * window after this recommendation? Crossed with what the human did, that gives four honest
     * verdicts — and one of them is deliberately not a win:
     *
     *   overridden/dismissed + recurred     → CORRECT      warned, ignored, it happened
     *   overridden/dismissed + no recurrence→ INCORRECT    warned, ignored, nothing happened
     *   accepted + recurred                 → INCONCLUSIVE advice taken, fault returned anyway:
     *                                                      the warning was right, the remedy was not
     *   accepted + no recurrence            → CORRECT      but see the caveat below
     *
     * THE CAVEAT, RECORDED RATHER THAN GLOSSED: the last row cannot be proven. We never observe what
     * would have happened had the supervisor ignored the advice, so "followed it and nothing broke"
     * is consistent with the warning having been unnecessary all along. It is flagged
     * `counterfactual_unverifiable` in the payload so any accuracy figure built from these can
     * exclude them. Counting them silently as wins is how a platform convinces itself it is working.
     *
     * @return array{0:string,1:array}|null
     */
    private function judgeComebackWarning(Recommendation $recommendation, RepairHistoryQuery $history, int $window): ?array
    {
        $signature = $recommendation->evidence['facts']['signature'] ?? null;

        if ($signature === null || $recommendation->vehicle_id === null) {
            return null; // nothing to check it against
        }

        $from = Carbon::parse($recommendation->presented_at);

        $recurrence = $history->occurrencesBetween(
            vehicleId: $recommendation->vehicle_id,
            signatures: [$signature],
            from: $from->toDateString(),
            to: $from->copy()->addDays($window)->toDateString(),
            excludeTicketId: $recommendation->subject_id,
        );

        $recurred = ! $recurrence->isEmpty();
        $followed = $recommendation->response()?->event === RecommendationEvent::ACCEPTED;

        $payload = [
            'signature'     => $signature,
            'window_days'   => $window,
            'recurred'      => $recurred,
            'recurrence_ids' => $recurrence->sourceIds,
            'response'      => $recommendation->response()?->event,
            'query_layer_version' => ProjectionRepairHistoryQuery::VERSION,
        ];

        if ($followed) {
            return $recurred
                ? ['inconclusive', $payload + ['why' => 'advice followed, fault returned anyway — the warning was right, the remedy was not']]
                : ['correct', $payload + [
                    'why' => 'advice followed, no recurrence',
                    // We cannot observe the road not taken. Say so in the data.
                    'counterfactual_unverifiable' => true,
                ]];
        }

        return $recurred
            ? ['correct', $payload + ['why' => 'warning not taken, and the fault did return']]
            : ['incorrect', $payload + ['why' => 'warning not taken, and the fault did not return']];
    }
}
