<?php

namespace App\Intelligence\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records and reports the health of the derived-table rebuilds.
 *
 * ── THE FAILURE THIS MAKES VISIBLE ───────────────────────────────────────────────────────────────
 * Every recurrence figure in the platform now reads one table. If the nightly rebuild stops, nothing
 * breaks: the tables keep serving, the pages keep rendering, and the whole product answers
 * confidently out of a corpus that stopped growing on whatever night the scheduler died. There is no
 * error, no empty state, no alert — just numbers that are quietly a month old and look exactly as
 * authoritative as they did yesterday.
 *
 * That is the specific failure this ledger exists to make loud. It is not a nice-to-have: the
 * scheduler is already dead on dev machines and unverified on the server.
 *
 * Append-only by design. A run's row is what happened; rewriting it would destroy the only trail
 * that explains why a number is stale.
 */
class RebuildLedger
{
    public const STATUS_RUNNING           = 'running';
    public const STATUS_SUCCESS           = 'success';
    public const STATUS_FAILED            = 'failed';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    private const TABLE = 'intelligence_rebuild_runs';

    /**
     * Open a run. Returns its id, or null if the ledger table is not present yet.
     *
     * Never throws: a ledger problem must not be able to stop a rebuild. Losing the health record of
     * a successful rebuild is a small loss; failing the rebuild because we could not write a log row
     * would be a self-inflicted outage.
     */
    public function start(string $command, string $targetTable): ?int
    {
        try {
            return (int) DB::table(self::TABLE)->insertGetId([
                'command'        => $command,
                'target_table'   => $targetTable,
                'status'         => self::STATUS_RUNNING,
                'started_at'     => now(),
                'metric_version' => (string) config('metrics.recurrence.version', 'unknown'),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function succeed(?int $runId, array $stats, ?string $corpusMax = null): void
    {
        $this->close($runId, self::STATUS_SUCCESS, $stats, $corpusMax, null);
    }

    /** Validation aborted before the swap — the previous good table is still serving. */
    public function validationFailed(?int $runId, array $stats, string $reason): void
    {
        $this->close($runId, self::STATUS_VALIDATION_FAILED, $stats, null, $reason);
    }

    public function fail(?int $runId, string $reason, array $stats = []): void
    {
        $this->close($runId, self::STATUS_FAILED, $stats, null, $reason);
    }

    private function close(?int $runId, string $status, array $stats, ?string $corpusMax, ?string $reason): void
    {
        if ($runId === null) {
            return;
        }

        try {
            $started = DB::table(self::TABLE)->where('id', $runId)->value('started_at');
            $ms = $started ? (int) round((microtime(true) - strtotime((string) $started)) * 1000) : null;

            DB::table(self::TABLE)->where('id', $runId)->update([
                'status'          => $status,
                'finished_at'     => now(),
                'duration_ms'     => $ms !== null && $ms >= 0 ? $ms : null,
                'rows_read'       => $stats['rows_read'] ?? $stats['raw_rows'] ?? $stats['source_rows'] ?? null,
                'rows_written'    => $stats['rows_written'] ?? $stats['events'] ?? $stats['visits'] ?? null,
                'corpus_max_date' => $corpusMax,
                'failure_reason'  => $reason,
                'stats'           => json_encode($stats),
                'updated_at'      => now(),
            ]);
        } catch (Throwable) {
            // See start(): the ledger never breaks the thing it observes.
        }
    }

    // ── Reporting ───────────────────────────────────────────────────────────────────────────────

    /**
     * Health of one derived table, for Data Health and for the staleness banner.
     *
     * `is_stale` is the field the UI acts on. It is deliberately TRUE when there is no successful run
     * at all: "never rebuilt" and "rebuilt too long ago" are the same problem to a reader, and
     * treating an absent record as healthy is how a dead scheduler stays invisible.
     *
     * @return array<string, mixed>
     */
    public function health(string $targetTable): array
    {
        $staleAfterHours = (int) config('metrics.recurrence.freshness.stale_after_hours', 36);

        try {
            $last = DB::table(self::TABLE)
                ->where('target_table', $targetTable)
                ->where('status', self::STATUS_SUCCESS)
                ->orderByDesc('started_at')
                ->first();

            $lastFailure = DB::table(self::TABLE)
                ->where('target_table', $targetTable)
                ->whereIn('status', [self::STATUS_FAILED, self::STATUS_VALIDATION_FAILED])
                ->orderByDesc('started_at')
                ->first();

            $failuresSinceSuccess = DB::table(self::TABLE)
                ->where('target_table', $targetTable)
                ->whereIn('status', [self::STATUS_FAILED, self::STATUS_VALIDATION_FAILED])
                ->when($last, fn ($q) => $q->where('started_at', '>', $last->started_at))
                ->count();

            $lastRebuiltAt = $last?->finished_at ? CarbonImmutable::parse($last->finished_at) : null;
            $ageHours = $lastRebuiltAt?->diffInHours(now());

            return [
                'target_table'           => $targetTable,
                'last_rebuilt_at'        => $lastRebuiltAt?->toIso8601String(),
                'age_hours'              => $ageHours,
                'stale_after_hours'      => $staleAfterHours,
                // No successful run is NOT healthy.
                'is_stale'               => $lastRebuiltAt === null || $ageHours > $staleAfterHours,
                'never_rebuilt'          => $lastRebuiltAt === null,
                'duration_ms'            => $last?->duration_ms,
                'rows_read'              => $last?->rows_read,
                'rows_written'           => $last?->rows_written,
                'corpus_max_date'        => $last?->corpus_max_date,
                'metric_version'         => $last?->metric_version,
                'failures_since_success' => $failuresSinceSuccess,
                'last_failure_at'        => $lastFailure?->started_at,
                'last_failure_reason'    => $lastFailure?->failure_reason,
            ];
        } catch (Throwable $e) {
            return [
                'target_table'  => $targetTable,
                'is_stale'      => true,
                'never_rebuilt' => true,
                'error'         => $e->getMessage(),
            ];
        }
    }

    /** @return array<int, array<string, mixed>> health for every table the platform derives */
    public function healthAll(): array
    {
        return [
            $this->health('fault_recurrence_pairs'),
            $this->health('repair_visits'),
        ];
    }

    /** True when any derived table is stale — the one boolean a banner needs. */
    public function anyStale(): bool
    {
        foreach ($this->healthAll() as $h) {
            if ($h['is_stale'] ?? true) {
                return true;
            }
        }

        return false;
    }
}
