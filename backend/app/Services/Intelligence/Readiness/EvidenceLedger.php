<?php

namespace App\Services\Intelligence\Readiness;

use App\Models\Maintenance;
use App\Models\RepairInspection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The intelligence platform's operating KPI: what every capability is waiting on, in numbers.
 *
 * This is deliberately ONE place. The alternative — each capability reporting its own readiness —
 * produces a set of measures that cannot be compared, which is how a platform ends up believing the
 * capability with the loudest advocate is the closest to ready.
 *
 * Planned capabilities appear alongside shipped ones. A capability blocked by data is not a gap in
 * the roadmap; it is a fact about the fleet's record-keeping, and it belongs on the same page as the
 * ones that work.
 *
 * RETIRED TICKETS ARE INCLUDED, DELIBERATELY. `maintenances` is soft-deleted; the raw queries below do
 * not inherit the model's scope and are not meant to. This class measures WHAT HAPPENED, and a retired
 * ticket is still a repair that occurred — excluding it would let history change whenever somebody
 * tidied the board, and would move a denominator without its numerator. Live operational surfaces take
 * the opposite rule and filter `deleted_at` explicitly. See docs/Maintenance-Deletion-Model.md.
 */
class EvidenceLedger
{
    /** Verdicts needed before the comeback card's outcome measure can be reconsidered. */
    public const COMEBACK_VERDICT_FLOOR = 30;

    /**
     * Per-instance memo.
     *
     * Not an optimisation detail — a correctness one as much as a speed one. Every consumer treats
     * this ledger as a SNAPSHOT: the health table, the readiness table and the freshness table are
     * read as three views of one moment. Recomputing between them would let a row arrive mid-report
     * and produce a page whose sections disagree with each other by one observation.
     *
     * It also stops the report costing what it used to. `all()` was called four times per run and
     * every call re-counted `maintenances` (26k), `maintenance_signatures` (49k) and every median —
     * roughly 160 queries to render one screen. That was survivable in a nightly command and is not
     * survivable on a page load.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /** @return EvidenceRequirement[] */
    public function all(): array
    {
        return $this->remember('all', fn () => [
            $this->comeback(),
            $this->garageRecommendation(),
            $this->partsRecommendation(),
            $this->etaPrediction(),
        ]);
    }

    /**
     * Drop the snapshot and read the world again.
     *
     * Exists for the one caller that legitimately needs it: a long-running process that has just
     * written evidence and wants to see its own effect.
     */
    public function refresh(): static
    {
        $this->memo = [];

        return $this;
    }

    /** @template T @param callable():T $compute @return T */
    private function remember(string $key, callable $compute): mixed
    {
        return array_key_exists($key, $this->memo)
            ? $this->memo[$key]
            : $this->memo[$key] = $compute();
    }

    /** `hasTable` is itself a round trip, and it is asked a dozen times per report. */
    private function hasTable(string $table): bool
    {
        return $this->remember("has:{$table}", fn () => DB::getSchemaBuilder()->hasTable($table));
    }

    /**
     * The triage view: one health score per capability, worst first.
     *
     * Deliberately derived from `all()` rather than computed independently — the score must never be
     * able to disagree with the tables it summarises.
     *
     * @return CapabilityHealth[]
     */
    public function health(): array
    {
        $health = array_map(
            fn (EvidenceRequirement $r) => CapabilityHealth::for($r, $this->promotionState($r->capabilityId)),
            $this->all(),
        );

        usort($health, fn ($a, $b) => $a->score <=> $b->score);

        return $health;
    }

    /**
     * Is the QC verdict pipeline actually running?
     *
     * The whole intelligence layer now depends on one habit: an inspector recording an outcome before
     * a repaired car closes. That habit can stop for two very different reasons which look IDENTICAL
     * from a verdict count — the queue is being skipped, or the rule was switched off under workload
     * pressure. Distinguishing them is the entire point of this check: one is a conversation with the
     * workshop, the other is a configuration change nobody announced.
     *
     * Deliberately compares the trailing fortnight against the four weeks before it, rather than
     * against a fixed target. A fleet's repair volume moves for legitimate reasons, and an alert that
     * fires every quiet week is one nobody reads by the second month.
     *
     * @return array{severity:string, headline:string, detail:string, recent:int, prior_rate:float,
     *               recent_rate:float, closed_without_verdict:int, gate_enabled:bool}|null
     */
    public function verdictPipelineAlert(): ?array
    {
        $gateOn = (bool) config('features.maintenance.require_qc_verdict', true);

        $recent = DB::table('repair_inspections')->where('created_at', '>=', now()->subDays(14))->count();
        $prior  = DB::table('repair_inspections')
            ->where('created_at', '>=', now()->subDays(42))
            ->where('created_at', '<', now()->subDays(14))
            ->count();

        $recentRate = $recent / 2;          // per week over the trailing fortnight
        $priorRate  = $prior / 4;           // per week over the four weeks before that

        // Repaired cars that closed in the window with nothing recorded — evidence already lost.
        // The same measure the coverage section calls "leaking", read from one place so the alert and
        // the dashboard can never disagree about whether the gap is still growing.
        $missed = $this->lostSince(now()->subDays(14));

        return self::judgePipeline([
            'recent'                 => $recent,
            'prior_rate'             => $priorRate,
            'recent_rate'            => $recentRate,
            'closed_without_verdict' => $missed,
            'gate_enabled'           => $gateOn,
        ]);
    }

    /**
     * The alert decision itself — pure, so it can be exercised at any pipeline state without a
     * database, including states this fleet has not reached yet.
     *
     * @param  array{recent:int, prior_rate:float, recent_rate:float, closed_without_verdict:int, gate_enabled:bool} $s
     * @return array{severity:string, headline:string, detail:string}|null
     */
    public static function judgePipeline(array $s): ?array
    {
        $base    = $s;
        $gateOn  = $s['gate_enabled'];
        $recent  = $s['recent'];
        $missed  = $s['closed_without_verdict'];
        $recentRate = $s['recent_rate'];
        $priorRate  = $s['prior_rate'];

        // THE SMOKING GUN, checked first. A disabled gate explains a falling rate completely, and
        // reporting it as "capture has slowed" would send someone to talk to inspectors who are doing
        // nothing wrong.
        if (! $gateOn) {
            return $base + [
                'severity' => 'critical',
                'headline' => 'QC verdict capture is DISABLED',
                'detail'   => 'MAINT_REQUIRE_QC_VERDICT is off, so repaired cars close without a verdict. '
                    .'Every ticket that closes this way is a repair outcome nobody can reconstruct — the car has gone. '
                    .'The comeback card cannot be promoted off proxy evidence while this is off.',
            ];
        }

        if ($recent === 0 && $missed > 0) {
            return $base + [
                'severity' => 'critical',
                'headline' => 'No QC verdicts recorded in 14 days',
                'detail'   => sprintf(
                    '%d repaired ticket%s closed in that window with no verdict. The gate is enabled, so the '
                    .'queue is being passed over rather than bypassed in config.',
                    $missed, $missed === 1 ? '' : 's',
                ),
            ];
        }

        // Nothing arrived AND nothing closed: the fleet simply had a quiet fortnight. Warning here
        // would page someone about the absence of work rather than the absence of capture, and an
        // alert that fires when nothing is wrong is one nobody reads by the second month.
        $somethingClosed = $recent > 0 || $missed > 0;

        // A halving is the threshold: smaller swings are ordinary repair-volume noise.
        if ($somethingClosed && $priorRate > 0 && $recentRate < $priorRate * 0.5) {
            return $base + [
                'severity' => 'warning',
                'headline' => 'QC verdict capture is slowing',
                'detail'   => sprintf(
                    'Down to %.1f/week from %.1f/week. %d repaired ticket%s closed without a verdict in the last 14 days.',
                    $recentRate, $priorRate, $missed, $missed === 1 ? '' : 's',
                ),
            ];
        }

        return null;
    }

    /**
     * The capability someone should actually act on today.
     *
     * NOT simply the lowest score. A capability blocked by data — parts that are not recorded,
     * durations that run backwards, a garage record that does not persist — will always sit at the
     * bottom of the table, and pointing at it every week trains people to ignore the line. Nothing
     * can be done about it until the fleet's record-keeping changes, which is a different kind of
     * work from "this shipped capability needs 24 more verdicts".
     *
     * So the attention line skips blockers and names the worst capability with a fixable gap.
     */
    public function attentionFirst(): ?CapabilityHealth
    {
        foreach ($this->health() as $h) {      // already sorted worst-first
            if ($h->blocker === null) {
                return $h;
            }
        }

        return null;
    }

    /**
     * Whether a capability was promoted — null when no comparison has ever run.
     *
     * The three-way answer matters: "refused" and "never tested" are different states, and a proxy
     * knowingly kept after examination deserves more trust than one nobody has looked at.
     */
    private function promotionState(string $capabilityId): ?bool
    {
        if (! $this->hasTable('capability_promotions')) {
            return null;
        }

        $row = DB::table('capability_promotions')
            ->where('capability_id', $capabilityId)
            ->whereNotNull('proxy_metrics')
            ->orderByDesc('decided_at')
            ->first(['promoted']);

        return $row === null ? null : (bool) $row->promoted;
    }

    public function for(string $capabilityId): ?EvidenceRequirement
    {
        foreach ($this->all() as $r) {
            if ($r->capabilityId === $capabilityId) {
                return $r;
            }
        }

        return null;
    }

    /**
     * QC verdict coverage — the share of repaired cars whose outcome a human actually recorded.
     *
     * @return array{closed:int, with_verdict:int, coverage:float, lost:int}
     */
    public function qcCoverage(): array
    {
        return $this->remember('qc', fn () => $this->computeQcCoverage());
    }

    /** @return array{closed:int, with_verdict:int, unverifiable:int, coverage:float, lost:int} */
    private function computeQcCoverage(): array
    {
        // The denominator is tickets a verdict COULD have been recorded for — not every closed
        // ticket. A ticket that never reached a workshop, or whose faults were all cancelled, has no
        // repair outcome to judge; counting it as a missing verdict understates coverage and makes a
        // gate that is working correctly look like it is leaking. Same scope the gate itself applies.
        $closed = Maintenance::closedOut()->verdictEligible()->count();

        // NUMERATOR AND DENOMINATOR MUST DESCRIBE THE SAME TICKETS.
        //
        // This counted every inspection ever recorded, against a denominator of eligible closed
        // tickets — so inspections on tickets that were still open, or not verdict-eligible, inflated
        // coverage and deflated the loss. It produced the arithmetically impossible result of losing
        // more evidence in the last fortnight (6) than in all of history (5), which is what exposed
        // it. A ratio whose halves are drawn from different populations is not a low-quality
        // measurement; it is not a measurement.
        $hasInspection = fn ($q) => $q->select(DB::raw(1))->from('repair_inspections')
            ->whereColumn('repair_inspections.maintenance_id', 'maintenances.id');

        // COVERAGE counts every inspection, including `unable_to_verify`: the inspector attended and
        // recorded an honest answer, so the workflow step is complete and the evidence is not "lost".
        // It is simply not usable as a statistic — which the threshold, not this number, enforces.
        $withVerdict = Maintenance::closedOut()->verdictEligible()
            ->whereExists($hasInspection)->count();

        $unverifiable = Maintenance::closedOut()->verdictEligible()
            ->whereExists(fn ($q) => $hasInspection($q)
                ->where('repair_inspections.result', RepairInspection::RESULT_UNABLE_TO_VERIFY))
            ->count();

        // IS THE GAP STILL GROWING? The single most important distinction on this page, and the one
        // a lifetime percentage cannot make.
        //
        // Lifetime coverage can never recover. Every ticket that closed before the verdict gate
        // existed — and every seeded row that never passed through the workflow at all — drags it
        // down permanently. So a platform reporting only the lifetime figure shows the same alarming
        // number forever, whether the process was fixed yesterday or is still haemorrhaging evidence.
        // Those need opposite responses, and after a month of the number never moving, nobody reads
        // it at all.
        //
        // Debt is a fact to state once. A leak is something to go and stop.
        $lostRecently = $this->lostSince(now()->subDays(14));

        return self::assertCoherent([
            'closed'        => $closed,
            'with_verdict'  => $withVerdict,
            'unverifiable'  => $unverifiable,
            'coverage'      => $closed > 0 ? $withVerdict / $closed : 0.0,
            'lost'          => max($closed - $withVerdict, 0),
            'lost_recently' => $lostRecently,
            'leaking'       => $lostRecently > 0,
        ]);
    }

    /**
     * A coverage ratio whose halves are drawn from different populations is not a low-quality
     * measurement — it is not a measurement, and it is worse than no number because it looks like one.
     *
     * This is a guard rather than a test because the failure was invisible by inspection: the code
     * read perfectly sensibly, and the only thing that gave it away was `lost_recently` exceeding
     * `lost` — losing more evidence in a fortnight than in all of history. Four cheap invariants catch
     * every version of that mistake, including the ones nobody has made yet.
     *
     * It THROWS. The snapshot catches it and shows "this section could not be read", which is an
     * honest answer. A plausible wrong percentage is not, and a QC coverage figure is exactly the kind
     * of number people quote in meetings without re-deriving.
     *
     * @param  array<string, mixed> $qc
     * @return array<string, mixed>
     */
    public static function assertCoherent(array $qc): array
    {
        $violations = array_keys(array_filter([
            'with_verdict exceeds the eligible population' => $qc['with_verdict'] > $qc['closed'],
            'unverifiable exceeds with_verdict'            => $qc['unverifiable'] > $qc['with_verdict'],
            'recent loss exceeds lifetime loss'            => $qc['lost_recently'] > $qc['lost'],
            'lost does not reconcile'                      => $qc['lost'] !== max($qc['closed'] - $qc['with_verdict'], 0),
        ]));

        if ($violations !== []) {
            throw new \RuntimeException(
                'QC coverage is incoherent — its numerator and denominator describe different tickets: '
                .implode('; ', $violations).'. Refusing to report a ratio that cannot be true.'
            );
        }

        return $qc;
    }

    /**
     * Repaired tickets that closed in the window with nothing recorded — evidence lost since then.
     *
     * Dated by `last_state_change_at`, not `updated_at`. For a terminal state the last state change
     * IS the close, whereas `updated_at` moves for any reason at all — an invoice attached, a note
     * edited, a nightly sync touching the row. Dating a close by `updated_at` makes old unverified
     * tickets keep re-entering the recent window every time anything touches them, so a gap that
     * stopped growing months ago reports itself as today's leak.
     *
     * `wf_closed_at` would be the exact field and is not usable: it is stamped on only 4 of 17 closed
     * tickets, because the `awaiting_invoice` transition never sets it. That gap is reported as its
     * own data-quality issue rather than papered over here.
     */
    private function lostSince(Carbon $since): int
    {
        return Maintenance::closedOut()->verdictEligible()
            ->where('last_state_change_at', '>=', $since)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('repair_inspections')
                ->whereColumn('repair_inspections.maintenance_id', 'maintenances.id'))
            ->count();
    }

    private function comeback(): EvidenceRequirement
    {
        // The THRESHOLD counts conclusive verdicts only — those are what a model can learn from.
        // Coverage below counts every inspection, including "could not verify", because the workflow
        // step genuinely happened. Two different questions, two different denominators.
        $total = DB::table('repair_inspections')
            ->whereIn('result', RepairInspection::CONCLUSIVE_RESULTS)
            ->count();
        $qc    = $this->qcCoverage();
        $age   = $this->ageStats('repair_inspections');
        $last  = $this->lastEvaluation('comeback-warning');

        return new EvidenceRequirement(
            capabilityId: 'comeback-warning',
            label: 'Comeback Warning (shipped)',
            evidence: 'post-repair QC verdicts (fixed | still_exists)',
            current: $total,
            threshold: self::COMEBACK_VERDICT_FLOOR,
            weeklyRate: $this->weeklyRate('repair_inspections'),
            // Shipped and running, but reasoning from return rate — a car coming back is not proof the
            // repair failed. Only the QC verdict changes that, and only via the promotion gate.
            quality: EvidenceRequirement::QUALITY_PROXY,
            coverage: $qc['coverage'],
            medianAgeDays: $age['median_days'],
            oldestAt: $age['oldest'],
            newestAt: $age['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
            singleDayShare: $this->singleDayShare('repair_inspections'),
            lastEvaluatedAt: $last['at'],
            evidenceAtLastEvaluation: $last['evidence'],
            datasetMoved: $last['dataset'] !== null && $last['dataset'] !== $this->datasetVersion(),
        );
    }

    /**
     * Blocked by a measured fact, not by build effort: a garage's past record does not predict its
     * future record (r = −0.36 / +0.02 / +0.21 at three split points), and picking a top-third garage
     * was worth −2.6 points in the held-out period.
     *
     * It stays on the ledger rather than being deleted because the failure may belong to the OUTCOME
     * MEASURE, not to the garages — return rate conflates a botched repair with an unrelated second
     * fault. That is a re-test with a condition, not a dead end.
     */
    private function garageRecommendation(): EvidenceRequirement
    {
        $verdicts = DB::table('repair_inspections')->count();
        $age      = $this->ageStats('repair_inspections');

        return new EvidenceRequirement(
            capabilityId: 'garage-recommendation',
            label: 'Garage Recommendation (not built)',
            evidence: 'QC verdicts, to re-test persistence with a cleaner outcome measure',
            current: $verdicts,
            threshold: 200,
            weeklyRate: $this->weeklyRate('repair_inspections'),
            quality: EvidenceRequirement::QUALITY_PROXY,
            blocker: $verdicts < 200
                ? 'past O/E does not predict future O/E (r≈0) using return-rate as the outcome'
                : null,
            medianAgeDays: $age['median_days'],
            oldestAt: $age['oldest'],
            newestAt: $age['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
            singleDayShare: $this->singleDayShare('repair_inspections'),
        );
    }

    private function partsRecommendation(): EvidenceRequirement
    {
        $parts = DB::table('part_purchases')->count()
            + DB::table('part_requests')->count()
            + ($this->hasTable('maintenance_line_items')
                ? DB::table('maintenance_line_items')->where('kind', 'part')->count()
                : 0);

        $age = $this->ageStats('part_purchases');

        return new EvidenceRequirement(
            capabilityId: 'parts-recommendation',
            label: 'Parts Recommendation (not built)',
            evidence: 'part lines attached to repairs',
            current: $parts,
            threshold: 2000,
            weeklyRate: $this->weeklyRate('part_purchases'),
            quality: EvidenceRequirement::QUALITY_MEASURED,
            blocker: $parts < 200 ? 'parts are not recorded against repairs at all yet' : null,
            medianAgeDays: $age['median_days'],
            oldestAt: $age['oldest'],
            newestAt: $age['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
            singleDayShare: $this->singleDayShare('part_purchases'),
        );
    }

    private function etaPrediction(): EvidenceRequirement
    {
        $d = DB::selectOne(
            'SELECT SUM(actual_in_date IS NOT NULL AND out_date IS NOT NULL) usable,
                    SUM(DATEDIFF(out_date, actual_in_date) < 0) invalid
             FROM maintenances'
        );

        $usable  = (int) ($d->usable ?? 0);
        $invalid = (int) ($d->invalid ?? 0);

        $age = $this->ageStats('maintenances', 'out_date',
            fn ($q) => $q->whereNotNull('actual_in_date'), 'with_in_date');

        return new EvidenceRequirement(
            capabilityId: 'eta-prediction',
            label: 'ETA Prediction (not built)',
            evidence: 'in/out timestamps that survive a sanity check',
            current: max($usable - $invalid, 0),
            threshold: 3000,
            weeklyRate: 0.0,
            quality: EvidenceRequirement::QUALITY_MEASURED,
            // Volume is not the problem; direction of time is.
            blocker: $invalid > 0
                ? sprintf('%s of %s durations are negative (out_date before in_date)',
                    number_format($invalid), number_format($usable))
                : null,
            coverage: $usable > 0 ? ($usable - $invalid) / $usable : 0.0,
            // Duration evidence is dated by the repair itself, not by when the row was written.
            medianAgeDays: $age['median_days'],
            oldestAt: $age['oldest'],
            newestAt: $age['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
            singleDayShare: $this->singleDayShare('maintenances', 'out_date'),
        );
    }

    /**
     * Age of the evidence itself: oldest, newest, and the middle observation.
     *
     * The median is the one that matters. A mean is dragged around by a long tail of old rows, and
     * "average age 8 months" can describe either a steady feed or a pile of history with a trickle on
     * top. The median says where the bulk of the evidence actually sits.
     *
     * @return array{median_days:?int, oldest:?Carbon, newest:?Carbon}
     */
    private function ageStats(string $table, string $column = 'created_at', ?callable $constrain = null, string $variant = ''): array
    {
        return $this->remember("age:{$table}:{$column}:{$variant}",
            fn () => $this->computeAgeStats($table, $column, $constrain));
    }

    /** @return array{median_days:?int, oldest:?Carbon, newest:?Carbon} */
    private function computeAgeStats(string $table, string $column, ?callable $constrain): array
    {
        if (! $this->hasTable($table)) {
            return ['median_days' => null, 'oldest' => null, 'newest' => null];
        }

        $base = fn () => DB::table($table)->whereNotNull($column)->when($constrain, $constrain);

        $count = $base()->count();

        if ($count === 0) {
            return ['median_days' => null, 'oldest' => null, 'newest' => null];
        }

        // Median by offset rather than by pulling the column — cheap on a large table and exact.
        $middle = $base()->orderBy($column)->offset(intdiv($count, 2))->limit(1)->value($column);
        $oldest = $base()->min($column);
        $newest = $base()->max($column);

        return [
            'median_days' => $middle !== null ? (int) Carbon::parse($middle)->diffInDays(now()) : null,
            'oldest'      => $oldest !== null ? Carbon::parse($oldest) : null,
            'newest'      => $newest !== null ? Carbon::parse($newest) : null,
        ];
    }

    /** Days since the corpus last observed anything at all. */
    private function datasetAgeDays(): ?int
    {
        return $this->remember('dataset_age', function () {
            $last = DB::table('maintenance_signatures')->max('occurred_at');

            return $last !== null ? (int) Carbon::parse($last)->diffInDays(now()) : null;
        });
    }

    /**
     * The last evaluation that actually RAN a comparison.
     *
     * A "not-yet" is a recorded outcome but not an evaluation of the models — treating it as one would
     * make a capability look freshly assessed when nothing was ever measured.
     *
     * @return array{at:?Carbon, evidence:?int, dataset:?string}
     */
    private function lastEvaluation(string $capabilityId): array
    {
        if (! $this->hasTable('capability_promotions')) {
            return ['at' => null, 'evidence' => null, 'dataset' => null];
        }

        $row = DB::table('capability_promotions')
            ->where('capability_id', $capabilityId)
            ->whereNotNull('proxy_metrics')
            ->orderByDesc('decided_at')
            ->first(['decided_at', 'evidence_count', 'dataset_version']);

        return [
            'at'       => $row?->decided_at ? Carbon::parse($row->decided_at) : null,
            'evidence' => $row?->evidence_count,
            'dataset'  => $row?->dataset_version,
        ];
    }

    /** The corpus fingerprint as it stands — compared against what an evaluation was run on. */
    public function datasetVersion(): string
    {
        return $this->remember('dataset_version', fn () => $this->computeDatasetVersion());
    }

    private function computeDatasetVersion(): string
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) n, MAX(occurred_at) last_at, MIN(classifier_version) cv FROM maintenance_signatures'
        );

        return sprintf('proj/%s:%d:%s', $row->cv ?? 'unknown', (int) ($row->n ?? 0),
            $row->last_at ? substr((string) $row->last_at, 0, 10) : 'empty');
    }

    /**
     * Arrivals per week, measured from the first row to now.
     *
     * Carbon 3 returns a SIGNED diff, so now()->diffInDays($past) is negative — and max($that, 1)
     * silently collapses the window to one day. That turned 6 verdicts in a fortnight into
     * "42 per week" with a readiness date four days out. Parse forward.
     */
    private function weeklyRate(string $table, string $column = 'created_at'): float
    {
        return $this->remember("rate:{$table}:{$column}", fn () => $this->computeWeeklyRate($table, $column));
    }

    /** How far back an arrival rate is measured. Long enough to smooth a quiet fortnight, short
     *  enough that last year's volume cannot vouch for this month's. */
    private const RATE_WINDOW_WEEKS = 8;

    private function computeWeeklyRate(string $table, string $column): float
    {
        if (! $this->hasTable($table)) {
            return 0.0;
        }

        // MEASURED OVER A TRAILING WINDOW, NOT OVER ALL TIME.
        //
        // A lifetime average answers "how fast has evidence arrived since records began", which is
        // not the question. Readiness needs "how fast is it arriving NOW", and the two diverge
        // violently on exactly the data that matters: 468 of 478 part purchases landed in a single
        // afternoon's backfill, and the lifetime figure turned that one event into "1,397 per week",
        // an arrival rate no fleet has ever had. That projected a readiness date days away and moved
        // a capability from BLOCKED to READY without a single new repair being recorded.
        $since = now()->subWeeks(self::RATE_WINDOW_WEEKS);
        $first = DB::table($table)->min($column);

        if ($first === null) {
            return 0.0;
        }

        // A feed younger than the window is measured over its own life, so a genuinely new and
        // healthy feed is not punished for having no history.
        $from  = Carbon::parse($first)->max($since);
        $count = DB::table($table)->where($column, '>=', $from)->count();
        $days  = max(Carbon::parse($from)->diffInDays(now()), 1);

        return $count / $days * 7;
    }

    /**
     * The share of evidence that arrived on its single busiest day.
     *
     * The number that distinguishes a feed from an import. A capability sitting on thousands of rows
     * that all appeared in one afternoon is not well-evidenced; it has been handed a file. Volume,
     * freshness and arrival rate all look excellent in that state — this is the only measure that
     * does not.
     */
    private function singleDayShare(string $table, string $column = 'created_at'): ?float
    {
        return $this->remember("burst:{$table}:{$column}", function () use ($table, $column) {
            if (! $this->hasTable($table)) {
                return null;
            }

            $total = DB::table($table)->count();

            if ($total === 0) {
                return null;
            }

            // `->value('n')` is wrong here and fails silently: on a query whose select is already set
            // it hands back the row's FIRST column, so this returned the DATE. `(int) '2026-07-30'`
            // is 2026, and a nine-row table reported that 22,511% of its evidence arrived in one day.
            // Read the row and name the field.
            $busiest = DB::table($table)
                ->selectRaw("DATE({$column}) d, COUNT(*) n")
                ->whereNotNull($column)
                ->groupBy('d')->orderByDesc('n')->limit(1)->first();

            return $busiest === null ? null : min((int) $busiest->n / $total, 1.0);
        });
    }
}
