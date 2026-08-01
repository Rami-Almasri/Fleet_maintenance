<?php

namespace App\Services\Intelligence\Readiness;

use App\Models\CapabilityPromotion;
use App\Models\Maintenance;
use App\Models\RepairInspection;
use App\Services\Intelligence\DecisionEngine;
use App\Services\Intelligence\OperationalIntelligence;
use App\Services\Intelligence\PolicyRegistry;
use App\Services\RepairIntelligence\Query\ProjectionRepairHistoryQuery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The whole platform's state, in one serialisable read — what the Intelligence Center renders.
 *
 * WHY THIS EXISTS AS A SERVICE AND NOT AS A CONTROLLER METHOD.
 *
 * Everything here was previously only reachable through `php artisan intelligence:evidence-health`.
 * That was defensible while the audience was one engineer, and stops being defensible the moment the
 * platform makes a claim inside somebody's workflow: a supervisor shown a card that says "this fault
 * came back three times" is entitled to ask what the platform knows and how sure it is, and "ask an
 * engineer to run a command" is not an answer. The command and this class now read the SAME ledger,
 * so the terminal and the screen cannot drift into telling two different stories.
 *
 * WHAT IS DELIBERATELY NOT HERE. No number in this snapshot is computed here. Every one is read from
 * the ledger, the promotion table, the policy registry or Laravel's own schedule. This class arranges
 * facts; it does not create them. If a figure on the Intelligence Center is wrong, it is wrong at its
 * source, and there is exactly one source to go and fix.
 */
class IntelligenceSnapshot
{
    public function __construct(
        private readonly EvidenceLedger $ledger,
        private readonly PromotionGate $gate,
        private readonly PolicyRegistry $policies,
        private readonly DecisionEngine $engine,
        private readonly OperationalIntelligence $delivery,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'alert'        => $this->section('alert', fn () => $this->ledger->verdictPipelineAlert()),
            'headline'     => $this->section('headline', fn () => $this->headline()),
            'qc'           => $this->section('qc', fn () => $this->qc()),
            'capabilities' => $this->section('capabilities', fn () => $this->capabilities(), []),
            'promotions'   => $this->section('promotions', fn () => $this->promotions(), []),
            'flags'        => $this->section('flags', fn () => $this->flags(), []),
            'jobs'         => $this->section('jobs', fn () => $this->jobs(), []),
            'data_quality' => $this->section('data_quality', fn () => $this->dataQuality(), []),
            'versions'     => $this->section('versions', fn () => $this->versions()),
            'failed_sections' => $this->failures,
        ];
    }

    /** @var array<string, string> section key → the error that stopped it */
    private array $failures = [];

    /**
     * Each section stands or falls alone.
     *
     * The platform's standing rule is that intelligence is advisory and must never break the thing it
     * advises. That rule applies to this page too, and rather more sharply than it looks: the reader
     * who most needs the Intelligence Center is the one investigating something that is already going
     * wrong, and a page that returns 500 because one table is mid-migration tells them nothing at the
     * exact moment it is supposed to tell them everything.
     *
     * The failure is REPORTED, never swallowed. A section quietly rendering empty would be worse than
     * the 500 it replaced — "no data-quality problems" and "the data-quality check crashed" look
     * identical on screen and mean opposite things.
     *
     * @template T
     * @param  callable():T $compute
     * @param  T|null       $fallback
     * @return T|null
     */
    private function section(string $key, callable $compute, mixed $fallback = null): mixed
    {
        try {
            return $compute();
        } catch (\Throwable $e) {
            report($e);

            $this->failures[$key] = $e->getMessage();

            return $fallback;
        }
    }

    /**
     * The single line at the top: what someone should do about this platform today.
     *
     * Ordered by what can actually be acted on, not by severity of feeling. A stalled verdict pipeline
     * outranks everything because it is the only failure that destroys evidence irrecoverably — a
     * ticket that closes unverified cannot be re-verified, the car has gone.
     *
     * @return array{severity:string, text:string}
     */
    private function headline(): array
    {
        if ($alert = $this->ledger->verdictPipelineAlert()) {
            return ['severity' => $alert['severity'], 'text' => $alert['headline']];
        }

        if ($first = $this->ledger->attentionFirst()) {
            return ['severity' => $first->band() === 'healthy' ? 'ok' : 'info', 'text' => $first->label.' — '.$first->attention];
        }

        // Every capability is blocked by data the fleet does not record. Saying "all healthy" here
        // would be the most misleading thing this page could do.
        return [
            'severity' => 'info',
            'text'     => 'Nothing engineering can act on: every capability is waiting on evidence the fleet does not yet record.',
        ];
    }

    /** @return array<string, mixed> */
    private function qc(): array
    {
        $qc = $this->ledger->qcCoverage();
        $conclusive = $qc['with_verdict'] - ($qc['unverifiable'] ?? 0);

        return $qc + [
            'conclusive'        => $conclusive,
            'unverifiable_share' => $qc['with_verdict'] > 0 ? ($qc['unverifiable'] ?? 0) / $qc['with_verdict'] : 0.0,
            'throughput'        => $this->verdictThroughput(),
        ];
    }

    /**
     * Verdicts recorded per week for the last eight weeks — the shape behind the alert's single number.
     *
     * A rate is a bad way to see a pipeline stopping: 3.0/week reads identically whether it is three
     * every week or twenty-four followed by five weeks of silence. The series shows which.
     *
     * @return array<int, array{week:string, count:int, conclusive:int}>
     */
    private function verdictThroughput(): array
    {
        $rows = DB::table('repair_inspections')
            ->where('created_at', '>=', now()->subWeeks(8)->startOfWeek())
            ->selectRaw('YEARWEEK(created_at, 3) yw, COUNT(*) n')
            ->groupBy('yw')
            ->pluck('n', 'yw');

        $conclusive = DB::table('repair_inspections')
            ->where('created_at', '>=', now()->subWeeks(8)->startOfWeek())
            ->whereIn('result', RepairInspection::CONCLUSIVE_RESULTS)
            ->selectRaw('YEARWEEK(created_at, 3) yw, COUNT(*) n')
            ->groupBy('yw')
            ->pluck('n', 'yw');

        // Every week is emitted, including the empty ones. A chart that silently omits zero weeks
        // draws a flat healthy line through a two-month outage.
        $series = [];
        for ($i = 7; $i >= 0; $i--) {
            $week = now()->subWeeks($i)->startOfWeek();
            $key  = (int) $week->format('oW');

            $series[] = [
                'week'       => $week->toDateString(),
                'count'      => (int) ($rows[$key] ?? 0),
                'conclusive' => (int) ($conclusive[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * One row per capability, health and readiness and freshness joined.
     *
     * The command prints these as three separate tables because a terminal cannot show fifteen columns.
     * A screen can, and splitting them there would only invite reading one without the others — which
     * is exactly how a capability with a great volume number and year-old evidence gets called ready.
     *
     * @return array<int, array<string, mixed>>
     */
    private function capabilities(): array
    {
        $health = [];
        foreach ($this->ledger->health() as $h) {
            $health[$h->capabilityId] = $h;
        }

        $rows = [];
        foreach ($this->ledger->all() as $r) {
            $h = $health[$r->capabilityId] ?? null;

            $rows[] = [
                'id'        => $r->capabilityId,
                'label'     => $r->label,
                'evidence'  => $r->evidence,
                'shipped'   => $this->policies->has($r->capabilityId),

                'score'     => $h?->score,
                'band'      => $h?->band(),
                'dimensions' => $h?->dimensions,
                'weakest'   => $h?->weakest,
                'attention' => $h?->attention,

                'status'    => $r->status(),
                'current'   => $r->current,
                'threshold' => $r->threshold,
                'remaining' => $r->remaining(),
                'weekly_rate' => round($r->weeklyRate, 2),
                'ready_at'  => $r->readyAt()?->toDateString(),
                'ready_label' => $r->readinessLabel(),
                'coverage'  => $r->coverage,
                'quality'   => $r->quality,
                'blocker'   => $r->blocker,

                'median_age_days' => $r->medianAgeDays,
                'oldest_at'  => $r->oldestAt?->toDateString(),
                'newest_at'  => $r->newestAt?->toDateString(),
                'dataset_age_days' => $r->datasetAgeDays,
                'last_evaluated_at' => $r->lastEvaluatedAt?->toDateString(),
                'reevaluation_reason' => $r->reevaluationReason(),
                'evidence_stale' => $r->isEvidenceStale(),
                'feed_quiet'  => $r->feedIsQuiet() && $r->current > 0,
            ];
        }

        // Worst first, matching the command. The blocked ones sink because their score is capped.
        usort($rows, fn ($a, $b) => ($a['score'] ?? 0) <=> ($b['score'] ?? 0));

        return $rows;
    }

    /**
     * The decision history, refusals included — deliberately, because they are the informative rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function promotions(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('capability_promotions')) {
            return [];
        }

        $current = $this->gate->provenance();

        return CapabilityPromotion::orderByDesc('decided_at')->limit(20)->get()
            ->map(fn (CapabilityPromotion $p) => [
                'id'            => $p->id,
                'capability_id' => $p->capability_id,
                'decided_at'    => $p->decided_at?->toIso8601String(),
                'promoted'      => (bool) $p->promoted,
                'reason'        => $p->reason,
                'evidence_count' => $p->evidence_count,
                'evidence_threshold' => $p->evidence_threshold,
                'proxy_metrics' => $p->proxy_metrics,
                'measured_metrics' => $p->measured_metrics,
                'provenance'    => $p->provenance(),
                // Not "this decision was wrong" — "this decision answered a question about a
                // different corpus, and should be re-run rather than trusted".
                'superseded'    => $p->isSupersededBy($current),
            ])->all();
    }

    /**
     * Feature flags that change what the platform does, each with the consequence of flipping it.
     *
     * A flag list without consequences is a settings screen. The reason `require_qc_verdict` is on
     * this page at all is that switching it off is invisible from every other surface: the cards keep
     * appearing, the dashboards keep rendering, and the only evidence the platform can learn from
     * quietly stops being collected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function flags(): array
    {
        return [
            [
                'key'     => 'MAINT_REQUIRE_QC_VERDICT',
                'label'   => 'Require a QC verdict before a repaired car closes',
                'enabled' => (bool) config('features.maintenance.require_qc_verdict', true),
                'expected' => true,
                'impact'  => 'OFF means repaired cars close unverified. Every such ticket is a repair outcome '
                    .'nobody can reconstruct — the car has gone. This is the only input that can ever move a '
                    .'capability off proxy evidence.',
            ],
            [
                'key'     => 'FEATURE_INTEL_COMEBACK',
                'label'   => 'Show the Comeback Warning card in the workflow',
                'enabled' => (bool) config('features.intelligence.comeback_detection', false),
                'expected' => false,
                'impact'  => 'ON shows supervisors a card at Decide when a fault has returned before. Held off '
                    .'until the measured evidence is in: at 1.45× lift on proxy evidence it is useful but not '
                    .'yet worth the interruption budget.',
            ],
        ];
    }

    /**
     * Background job health — reported as a PROXY, and labelled as one.
     *
     * There is no run log. Rather than invent a job-tracking table for four commands, each job is
     * judged by the trace it leaves in the data, which is the thing anyone actually cares about: a
     * scheduler that runs perfectly and writes nothing is not healthy.
     *
     * The cadence is not hardcoded here — it is read back out of Laravel's own registered schedule,
     * so a job whose schedule is edited in routes/console.php cannot be described wrongly on this page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jobs(): array
    {
        $registered = $this->registeredSchedule();

        $jobs = [
            [
                'command'  => 'intelligence:record-outcomes',
                'purpose'  => 'Judges past recommendations against what actually happened, 90 days on.',
                'evidence' => 'the most recent recorded outcome',
                'last_effect' => $this->maxDate('recommendation_events', 'created_at',
                    fn ($q) => $q->whereNotNull('outcome_result')),
                'tolerance_days' => 7,
            ],
            [
                'command'  => 'intelligence:evidence-health --promote --alert',
                'purpose'  => 'Runs the promotion gate and alerts if QC verdict capture has stalled.',
                'evidence' => 'the most recent recorded promotion decision',
                'last_effect' => $this->maxDate('capability_promotions', 'decided_at'),
                'tolerance_days' => 14,
            ],
            [
                'command'  => 'intelligence:rebuild-signatures',
                'purpose'  => 'Rebuilds the historical corpus every capability reads from.',
                'evidence' => 'the most recent signature row written',
                'last_effect' => $this->maxDate('maintenance_signatures', 'created_at'),
                'tolerance_days' => 30,
            ],
        ];

        return array_map(function (array $job) use ($registered) {
            $last = $job['last_effect'];
            $age  = $last !== null ? (int) $last->diffInDays(now()) : null;
            $sched = $registered[$this->scheduleKey($job['command'])] ?? null;

            return $job + [
                'scheduled'   => $sched['expression'] ?? null,
                'next_due_at' => $sched['next'] ?? null,
                'registered'  => $sched !== null,
                'last_effect_at' => $last?->toIso8601String(),
                'age_days'    => $age,
                'basis'       => 'proxy',   // effect observed, not a run recorded
                'status'      => self::judgeJob($sched !== null, $age, $job['tolerance_days']),
            ];
        }, $jobs);
    }

    /**
     * The job verdict — pure, so every state can be exercised without arranging a database.
     *
     * The order is the point. UNSCHEDULED outranks everything: a job producing fresh output while
     * absent from the scheduler is being run by hand, and reporting that as "ok" is how a platform
     * discovers on the day someone goes on holiday that a nightly rebuild was never nightly. That is
     * not hypothetical here — it is exactly what this page found about the corpus rebuild on its
     * first run.
     *
     * `never produced output` is kept distinct from `stale` because they need different responses:
     * one is a job that may be working perfectly on a corpus with nothing due yet, the other is a job
     * that used to work and stopped.
     */
    public static function judgeJob(bool $scheduled, ?int $ageDays, int $toleranceDays): string
    {
        return match (true) {
            ! $scheduled            => 'unscheduled',
            $ageDays === null       => 'never produced output',
            $ageDays > $toleranceDays => 'stale',
            default                 => 'ok',
        };
    }

    /** The command as registered, so the reported cadence can never disagree with routes/console.php. */
    private function scheduleKey(string $command): string
    {
        return explode(' ', $command)[0];
    }

    /**
     * Laravel's own view of what is scheduled — the real cadence, not a copy of it.
     *
     * @return array<string, array{expression:string, next:?string}>
     */
    private function registeredSchedule(): array
    {
        $out = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (! preg_match('/artisan[\'"]?\s+(?:[\'"])?([a-z0-9:_-]+)/i', (string) $event->command, $m)) {
                continue;
            }

            $out[$m[1]] = [
                'expression' => $event->expression,
                'next'       => rescue(
                    fn () => Carbon::instance(
                        (new \Cron\CronExpression($event->expression))->getNextRunDate(now())
                    )->toIso8601String(),
                    null,
                    false,
                ),
            ];
        }

        return $out;
    }

    private function maxDate(string $table, string $column, ?callable $constrain = null): ?Carbon
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $max = DB::table($table)->when($constrain, $constrain)->max($column);

        return $max !== null ? Carbon::parse($max) : null;
    }

    /**
     * Problems in the data that no capability can work around.
     *
     * Each entry names what is wrong, how much of it there is, and — the part that makes this
     * actionable rather than decorative — what has to change in the fleet's record-keeping to fix it.
     * None of these is an engineering task.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dataQuality(): array
    {
        $issues = [];

        // Durations that run backwards. The single fact blocking ETA prediction.
        $negative = (int) (DB::selectOne(
            'SELECT COUNT(*) n FROM maintenances WHERE actual_in_date IS NOT NULL AND out_date IS NOT NULL AND out_date < actual_in_date'
        )->n ?? 0);

        if ($negative > 0) {
            $issues[] = [
                'key'      => 'negative-durations',
                'severity' => 'high',
                'title'    => 'Repair durations that run backwards',
                'count'    => $negative,
                'detail'   => 'Tickets whose out_date precedes actual_in_date. Duration cannot be learned from these, '
                    .'and they are the reason ETA Prediction is blocked rather than merely under-evidenced.',
                'remedy'   => 'These come from the historical sheet, where in/out were often typed as one date. '
                    .'Only workflow-native timestamps (repair_started_at → ready_at) can replace them.',
            ];
        }

        // Evidence already lost. Not recoverable, which is why it is reported as a count and not a queue.
        $qc = $this->ledger->qcCoverage();

        if ($qc['lost'] > 0) {
            $issues[] = [
                'key'      => 'verdicts-lost',
                'severity' => $qc['coverage'] < 0.5 ? 'high' : 'medium',
                'title'    => 'Repaired tickets that closed with no verdict',
                'count'    => $qc['lost'],
                'detail'   => sprintf('QC coverage is %.0f%%. A verdict missed at close cannot be recovered later.', $qc['coverage'] * 100),
                'remedy'   => 'Keep MAINT_REQUIRE_QC_VERDICT on. The gate is what stops this number growing.',
            ];
        }

        // Cars leaving before QC can reach them — a scheduling failure, not a workshop one.
        if ($qc['with_verdict'] > 0 && ($qc['unverifiable'] ?? 0) / $qc['with_verdict'] > 0.25) {
            $issues[] = [
                'key'      => 'unverifiable-share',
                'severity' => 'medium',
                'title'    => 'A quarter of inspections could not verify the repair',
                'count'    => $qc['unverifiable'],
                'detail'   => 'The queue is being worked, but cars are leaving before anyone can check them.',
                'remedy'   => 'A scheduling fix — hold the car until re-inspection, or re-inspect before release.',
            ];
        }

        // A corpus that has stopped growing looks exactly like a healthy one on a row count.
        $corpusAge = $this->maxDate('maintenance_signatures', 'occurred_at');

        if ($corpusAge !== null && $corpusAge->diffInDays(now()) > 30) {
            $issues[] = [
                'key'      => 'corpus-stale',
                'severity' => 'high',
                'title'    => 'The historical corpus has stopped growing',
                'count'    => (int) $corpusAge->diffInDays(now()),
                'detail'   => sprintf('Newest observation is %s. Every capability reads from this corpus.', $corpusAge->toDateString()),
                'remedy'   => 'Run intelligence:rebuild-signatures — workflow-native tickets are not reaching the corpus.',
            ];
        }

        // More than one classifier version in the corpus means rows are not comparable to each other.
        $classifiers = DB::table('maintenance_signatures')->distinct()->pluck('classifier_version')->filter()->values();

        if ($classifiers->count() > 1) {
            $issues[] = [
                'key'      => 'mixed-classifier',
                'severity' => 'medium',
                'title'    => 'The corpus was built by more than one classifier version',
                'count'    => $classifiers->count(),
                'detail'   => 'Versions present: '.$classifiers->implode(', ').'. Rows classified by different rules are not '
                    .'directly comparable, and a backtest across them measures the classifier as much as the fleet.',
                'remedy'   => 'Rebuild the corpus in full so every row carries the current classifier version.',
            ];
        }

        return $issues;
    }

    /**
     * Every version that participates in a claim.
     *
     * The question this answers is the one the owner set as the test of the versioning work: "if this
     * recommendation was generated today, would it be different?" — unanswerable unless the versions
     * that produced it are visible next to the versions in force now.
     *
     * @return array<string, mixed>
     */
    private function versions(): array
    {
        // Read from the delivery layer rather than recomposed here. `versions()` is the exact bundle
        // frozen onto every recommendation at the moment it is shown, so what this page reports and
        // what a stored recommendation is compared against are guaranteed to be the same thing.
        $capabilities = [];

        foreach (array_keys($this->engine->capabilities()) as $capabilityId) {
            $capabilities[$capabilityId] = $this->delivery->versions($capabilityId);
        }

        return [
            'query_layer'  => ProjectionRepairHistoryQuery::VERSION,
            'dataset'      => $this->ledger->datasetVersion(),
            'capabilities' => $capabilities,
            'evaluation'   => $this->gate->provenance(),
        ];
    }
}
