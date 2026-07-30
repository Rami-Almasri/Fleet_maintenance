<?php

namespace App\Services\Intelligence\Readiness;

use App\Models\Maintenance;
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
 */
class EvidenceLedger
{
    /** Verdicts needed before the comeback card's outcome measure can be reconsidered. */
    public const COMEBACK_VERDICT_FLOOR = 30;

    /** @return EvidenceRequirement[] */
    public function all(): array
    {
        return [
            $this->comeback(),
            $this->garageRecommendation(),
            $this->partsRecommendation(),
            $this->etaPrediction(),
        ];
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
        if (! DB::getSchemaBuilder()->hasTable('capability_promotions')) {
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
        $closed = Maintenance::whereNotNull('workflow_status')
            ->whereIn('workflow_status', [Maintenance::WF_CLOSED, Maintenance::WF_AWAITING_INVOICE])
            ->count();

        $withVerdict = DB::table('repair_inspections')->distinct()->count('maintenance_id');

        return [
            'closed'       => $closed,
            'with_verdict' => $withVerdict,
            'coverage'     => $closed > 0 ? $withVerdict / $closed : 0.0,
            'lost'         => max($closed - $withVerdict, 0),
        ];
    }

    private function comeback(): EvidenceRequirement
    {
        $total = DB::table('repair_inspections')->count();
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
        );
    }

    private function partsRecommendation(): EvidenceRequirement
    {
        $parts = DB::table('part_purchases')->count()
            + DB::table('part_requests')->count()
            + (DB::getSchemaBuilder()->hasTable('maintenance_line_items')
                ? DB::table('maintenance_line_items')->where('kind', 'part')->count()
                : 0);

        return new EvidenceRequirement(
            capabilityId: 'parts-recommendation',
            label: 'Parts Recommendation (not built)',
            evidence: 'part lines attached to repairs',
            current: $parts,
            threshold: 2000,
            weeklyRate: $this->weeklyRate('part_purchases'),
            quality: EvidenceRequirement::QUALITY_MEASURED,
            blocker: $parts < 200 ? 'parts are not recorded against repairs at all yet' : null,
            medianAgeDays: $this->ageStats('part_purchases')['median_days'],
            oldestAt: $this->ageStats('part_purchases')['oldest'],
            newestAt: $this->ageStats('part_purchases')['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
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
            medianAgeDays: $this->ageStats('maintenances', 'out_date',
                fn ($q) => $q->whereNotNull('actual_in_date'))['median_days'],
            oldestAt: $this->ageStats('maintenances', 'out_date',
                fn ($q) => $q->whereNotNull('actual_in_date'))['oldest'],
            newestAt: $this->ageStats('maintenances', 'out_date',
                fn ($q) => $q->whereNotNull('actual_in_date'))['newest'],
            datasetAgeDays: $this->datasetAgeDays(),
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
    private function ageStats(string $table, string $column = 'created_at', ?callable $constrain = null): array
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
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
        $last = DB::table('maintenance_signatures')->max('occurred_at');

        return $last !== null ? (int) Carbon::parse($last)->diffInDays(now()) : null;
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
        if (! DB::getSchemaBuilder()->hasTable('capability_promotions')) {
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
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0.0;
        }

        $total = DB::table($table)->count();
        $first = DB::table($table)->min($column);

        if ($total === 0 || $first === null) {
            return 0.0;
        }

        $days = max(Carbon::parse($first)->diffInDays(now()), 1);

        return $total / $days * 7;
    }
}
