<?php

namespace App\Services\RepairIntelligence\Query;

use App\Models\MaintenanceSignature;
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

    /** @see RepairHistoryQuery::version() — the semantics of the answers, not the storage. */
    public const VERSION = 'v1';

    /** Signature labels the corpus carries a human-written original for. Measured on the projection. */
    private const HUMAN_LABEL_SHARE = 0.787;

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

        $rows = MaintenanceSignature::query()
            ->qualityRelevant()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('signature', $signatures)
            ->whereNotNull('occurred_at')
            // STRICTLY EARLIER, never same-day. Two signature rows sharing a date are far more
            // likely to be one event recorded on two tickets than a repair that failed and returned
            // within hours — counting them would inflate every card and produce the nonsense
            // "a comeback 0 days after the last one". This also keeps the answer aligned with the
            // methodology behind the 46.7% / 56.9% baselines it will be quoted against, which
            // required a positive day gap; a card must never cite a statistic it was not computed
            // the same way as.
            ->where('occurred_at', '>=', $pivot->copy()->subDays($window)->toDateString())
            ->where('occurred_at', '<', $pivot->toDateString())
            ->when($excludeTicketId, fn ($q) => $q->where('maintenance_id', '!=', $excludeTicketId))
            ->orderByDesc('occurred_at')
            ->get(['id', 'maintenance_id', 'signature', 'occurred_at', 'source']);

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
            "repair-intel:return-rate:{$signature}:{$window}",
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
     * @return array{n:int, returned:int, rate:float}
     */
    private function computeReturnRate(string $signature, int $window): array
    {
        $sql = 'SELECT COUNT(*) AS n,
                       SUM(CASE WHEN EXISTS (
                             SELECT 1 FROM maintenance_signatures b
                             WHERE b.vehicle_id = a.vehicle_id
                               AND b.signature  = a.signature
                               AND b.occurred_at >  a.occurred_at
                               AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                           ) THEN 1 ELSE 0 END) AS returned
                FROM maintenance_signatures a
                WHERE a.signature = ?
                  AND a.vehicle_id IS NOT NULL
                  AND a.occurred_at IS NOT NULL';

        $row = DB::selectOne($sql, [$window, $signature]);

        $n        = (int) ($row->n ?? 0);
        $returned = (int) ($row->returned ?? 0);

        return ['n' => $n, 'returned' => $returned, 'rate' => $n > 0 ? $returned / $n : 0.0];
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
