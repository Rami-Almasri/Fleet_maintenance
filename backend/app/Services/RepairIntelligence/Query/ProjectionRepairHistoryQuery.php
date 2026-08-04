<?php

namespace App\Services\RepairIntelligence\Query;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use App\Models\MaintenanceSignature;
use App\Models\RepairInspection;
use App\Services\Intelligence\Evidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The `maintenance_signatures` projection behind the query interface.
 *
 * This is the ONLY class in the intelligence platform that knows the projection's table, columns or
 * indexes exist. Everything above it asks questions. Replacing this with a materialised-view or
 * warehouse-backed implementation is a one-line container binding.
 *
 * Caching lives here for the same reason. A capability that memoised its own lookups would produce
 * a cache nobody else could reuse or invalidate; centralising it means one policy and one flush.
 *
 * PERFORMANCE. The per-vehicle lookup is a single index hit on `maint_sig_vehicle_lookup`
 * (~0.4ms measured over 49,473 rows). The fleet base rate is a correlated-subquery scan and is
 * cached for a day — it is an aggregate that belongs in a scheduled projection, and when that
 * exists it slots in behind this same method.
 */
class ProjectionRepairHistoryQuery implements RepairHistoryQuery
{
    /** The window that defines a comeback. 90 days is the fleet's measured quality clock. */
    public const WINDOW_DAYS = 90;

    /**
     * @see RepairHistoryQuery::version() — the semantics of the answers, not the storage.
     *
     * v2: recurrence answers now come from the canonical, DEDUPLICATED dataset. v1 counted label
     * rows, so one episode could be reported as many as fifty-four. Any stored answer stamped v1 was
     * computed on a different population and must not be compared with a v2 one.
     */
    public const VERSION = 'v2';

    /** Signature labels the corpus carries a human-written original for. Measured on the projection. */
    private const HUMAN_LABEL_SHARE = 0.787;

    public function __construct(
        private ?RecurrenceRepository $recurrence = null,
    ) {
        $this->recurrence ??= app(RecurrenceRepository::class);
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function findPreviousEpisodes(
        int $vehicleId,
        array $signatures,
        ?int $excludeTicketId = null,
        ?string $asOf = null,
        ?int $windowDays = null,
    ): HistoricalAnswer {
        $signatures = array_values(array_filter($signatures));
        $window     = $windowDays ?? self::WINDOW_DAYS;

        if ($signatures === []) {
            return HistoricalAnswer::empty();
        }

        $pivot = $asOf ? Carbon::parse($asOf) : now();

        // ── From the CANONICAL dataset — one row per EPISODE, not per label ──────────────────────
        //
        // This used to read `maintenance_signatures` directly, which returns one row per LABEL. On
        // the live corpus a single episode reaches 54 rows (vehicle 1743, ENGINE_MECH, 2025-04-10),
        // and since `sampleSize` below is the row count, a card built on this reported fifty-four
        // prior episodes for a fault that happened once. That is not an imprecise number, it is a
        // false sentence — and it was being shown to the person deciding what to do about the car.
        //
        // The strictly-earlier rule is preserved and now enforced by the dataset's own grain: one
        // fault, on one car, on one day, is one row. There is no same-day pair left to exclude.
        //
        // Exposure is excluded when the dataset is built, which is what `qualityRelevant()` did here.
        $rows = $this->recurrence->episodesBefore(
            vehicleId:            $vehicleId,
            signatures:           $signatures,
            from:                 $pivot->copy()->subDays($window)->toDateString(),
            before:               $pivot->toDateString(),
            excludeMaintenanceId: $excludeTicketId,
        );

        if ($rows->isEmpty()) {
            return HistoricalAnswer::empty();
        }

        return new HistoricalAnswer(
            value: $rows->groupBy('signature')->all(),
            // The sample IS the episodes found: this is a lookup on one car, not a statistic about
            // a population. One prior case is a complete answer to the question asked.
            sampleSize: $rows->count(),
            sourceIds: $rows->pluck('maintenance_id')->all(),
            labelSource: $this->labelSourceFor($rows),
            // Dates and vehicle come straight off the ticket — nothing is reconstructed here.
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            facts: [
                'window_days' => $window,
                'as_of'       => $pivot->toDateString(),
            ],
            asOf: now(),
        );
    }

    public function signatureReturnRate(string $signature, ?int $windowDays = null): HistoricalAnswer
    {
        $window = $windowDays ?? self::WINDOW_DAYS;

        $stats = Cache::remember(
            // The VERSION and the metric contract version are both in the key. Without them a
            // deploy would keep serving day-old answers computed under the retired definition, and
            // the platform would disagree with itself for exactly as long as the TTL — the hardest
            // kind of divergence to diagnose, because it heals on its own before anyone looks.
            sprintf(
                'repair-intel:return-rate:%s:%d:%s:m%s',
                $signature,
                $window,
                self::VERSION,
                config('metrics.recurrence.version', 'unknown'),
            ),
            now()->addDay(),
            fn () => $this->computeReturnRate($signature, $window),
        );

        return new HistoricalAnswer(
            value: $stats,
            sampleSize: $stats['n'],
            labelSource: Evidence::LABEL_MIXED,
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            completeness: self::HUMAN_LABEL_SHARE,
            // THE PLATFORM'S LOAD-BEARING CAVEAT. A car coming back is not proof the repair failed;
            // eleven years of history record no outcome verdict, so this stands in for one. It caps
            // every card built on it at moderate confidence, and it stops being a proxy the day the
            // QC gate starts writing a real verdict.
            isProxy: true,
            proxyNote: 'return rate, not verified repair success',
            facts: ['signature' => $signature, 'window_days' => $window],
            asOf: now(),
        );
    }

    /**
     * The measured verdict, not the proxy. Reads `repair_inspections` — the per-fault QC result
     * recorded at the re-inspection gate.
     *
     * Signature filtering goes through the projection, because the verdict table stores a fault id
     * rather than a signature; joining via `maintenance_signatures` keeps one vocabulary across both.
     */
    public function verifiedFailureRate(?string $signature = null): HistoricalAnswer
    {
        $key = 'repair-intel:verified-failure:'.($signature ?? '_all');

        $stats = Cache::remember($key, now()->addHours(6), function () use ($signature) {
            $q = DB::table('repair_inspections as ri')
                // CONCLUSIVE ONLY. An `unable_to_verify` inspection says the workflow ran, not whether
                // the repair held — counting it as a success would inflate quality, and as a failure
                // would defame a garage. It belongs in coverage and nowhere near a rate.
                ->whereIn('ri.result', RepairInspection::CONCLUSIVE_RESULTS);

            if ($signature !== null) {
                $q->join('maintenance_signatures as s', function ($j) use ($signature) {
                    $j->on('s.maintenance_id', '=', 'ri.maintenance_id')
                      ->where('s.signature', '=', $signature);
                });
            }

            $row = $q->selectRaw("COUNT(*) n, SUM(CASE WHEN ri.result = 'still_exists' THEN 1 ELSE 0 END) failed")
                ->first();

            $n = (int) ($row->n ?? 0);
            $failed = (int) ($row->failed ?? 0);

            return ['n' => $n, 'failed' => $failed, 'rate' => $n > 0 ? $failed / $n : 0.0];
        });

        return new HistoricalAnswer(
            value: $stats,
            sampleSize: $stats['n'],
            // A human inspected the car and wrote the verdict down. This is the strongest label the
            // platform has, and the only place isProxy is false.
            labelSource: Evidence::LABEL_HUMAN,
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            isProxy: false,
            facts: ['signature' => $signature, 'basis' => 'post-repair QC verdict'],
            asOf: now(),
        );
    }

    public function repairVerdictsFor(array $ticketIds): HistoricalAnswer
    {
        $ticketIds = array_values(array_filter($ticketIds));

        if ($ticketIds === []) {
            return HistoricalAnswer::empty();
        }

        $rows = DB::table('repair_inspections')
            ->whereIn('maintenance_id', $ticketIds)
            // Same rule: "we could not check" is not a claim about the prior repair, so it must not
            // become "the last repair was signed off as fixed" in a card's observation.
            ->whereIn('result', RepairInspection::CONCLUSIVE_RESULTS)
            ->orderBy('inspection_date')
            ->get(['maintenance_id', 'result', 'inspection_date']);

        if ($rows->isEmpty()) {
            return HistoricalAnswer::empty();
        }

        return new HistoricalAnswer(
            value: $rows->pluck('result', 'maintenance_id')->all(),
            sampleSize: $rows->count(),
            sourceIds: $rows->pluck('maintenance_id')->all(),
            labelSource: Evidence::LABEL_HUMAN,
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            isProxy: false,
            asOf: now(),
        );
    }

    public function occurrencesBetween(
        int $vehicleId,
        array $signatures,
        string $from,
        string $to,
        ?int $excludeTicketId = null,
    ): HistoricalAnswer {
        $signatures = array_values(array_filter($signatures));

        if ($signatures === []) {
            return HistoricalAnswer::empty();
        }

        // ── CANONICAL: one row per EPISODE ───────────────────────────────────────────────────
        //
        // This is comeback detection, and its answer decides whether outcome learning records a
        // recommendation as having succeeded. Reading raw labels meant a single comeback could be
        // counted many times over — on this corpus one episode reaches 54 rows — so a repair that
        // failed once could be judged as having failed repeatedly.
        //
        // The strictly-after rule is preserved and is now inherent to the dataset's grain.
        $rows = $this->recurrence->episodesBetween(
            vehicleId:            $vehicleId,
            signatures:           $signatures,
            after:                Carbon::parse($from)->toDateString(),
            to:                   Carbon::parse($to)->toDateString(),
            excludeMaintenanceId: $excludeTicketId,
        );

        if ($rows->isEmpty()) {
            return HistoricalAnswer::empty();
        }

        return new HistoricalAnswer(
            value: $rows,
            sampleSize: $rows->count(),
            sourceIds: $rows->pluck('maintenance_id')->all(),
            labelSource: $this->labelSourceFor($rows),
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            facts: ['from' => $from, 'to' => $to],
            asOf: now(),
        );
    }

    public function vehicleHistory(int $vehicleId, ?string $asOf = null, int $limit = 100): HistoricalAnswer
    {
        $pivot = $asOf ? Carbon::parse($asOf) : now();

        $rows = MaintenanceSignature::query()
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '<=', $pivot->toDateString())
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'maintenance_id', 'signature', 'occurred_at', 'source', 'is_exposure']);

        if ($rows->isEmpty()) {
            return HistoricalAnswer::empty();
        }

        return new HistoricalAnswer(
            value: $rows,
            sampleSize: $rows->count(),
            sourceIds: $rows->pluck('maintenance_id')->unique()->values()->all(),
            labelSource: $this->labelSourceFor($rows),
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            facts: [
                // Exposure rows are RETURNED here — chronic-vehicle and repair-vs-replace questions
                // legitimately care about accident damage. Only quality judgements must exclude it,
                // and findPreviousEpisodes() does that for them.
                'exposure_rows' => $rows->where('is_exposure', true)->count(),
                'truncated'     => $rows->count() === $limit,
            ],
            asOf: now(),
        );
    }

    /**
     * The fleet-wide return rate for one fault, from the CANONICAL repository.
     *
     * ── WHAT THIS USED TO BE ─────────────────────────────────────────────────────────────────────
     * Its own `EXISTS` self-join over raw signatures — the fifth implementation of one business
     * question. It differed from the other four in two further ways nobody had noticed: it applied
     * NO observation horizon, and it did not filter `is_exposure`, relying on the signature name to
     * do that implicitly.
     *
     * Both are now the contract's business, not this class's. The rate this returns is the same
     * measurement the Executive dashboard and /garages publish, narrowed to one signature.
     *
     * @return array{n:int, returned:int, rate:float}
     */
    private function computeReturnRate(string $signature, int $window): array
    {
        $stats = $this->recurrence->bySignature(
            RecurrenceWindow::fromContract($window),
            $signature,
        );

        return [
            'n'        => $stats->n,
            'returned' => $stats->returned,
            // A proportion, not a percentage — the caller's existing contract. Null rate on an empty
            // sample collapses to 0.0 here because HistoricalAnswer carries the sample separately
            // and every consumer already gates on it.
            'rate'     => $stats->n > 0 ? $stats->returned / $stats->n : 0.0,
        ];
    }

    /**
     * Human-written labels are stronger evidence than ones this platform inferred, and a mixed set
     * is only as good as the derived half. The classifier agrees with human labels 80.4% of the
     * time, which is good enough to act on and not good enough to call human-grade.
     *
     * @param \Illuminate\Support\Collection $rows
     */
    private function labelSourceFor($rows): string
    {
        $human = $rows->contains(fn ($r) => in_array(
            $r->source,
            [MaintenanceSignature::SOURCE_HUMAN, MaintenanceSignature::SOURCE_CONFIRMED],
            true,
        ));

        $derived = $rows->contains(fn ($r) => $r->source === MaintenanceSignature::SOURCE_DERIVED);

        return match (true) {
            $human && $derived => Evidence::LABEL_MIXED,
            $human             => Evidence::LABEL_HUMAN,
            default            => Evidence::LABEL_DERIVED,
        };
    }
}
