<?php

namespace App\Console\Commands;

use App\Intelligence\Health\RebuildLedger;
use App\Intelligence\Support\VisitCollapser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rebuilds `repair_visits` from `maintenances`, collapsing EVENTS into VISITS.
 *
 * FULL REBUILD, ALWAYS. 27k rows takes seconds; incremental logic would be a correctness risk for no
 * measurable gain, and "rebuilt from scratch every night" is what makes the table trivially
 * reproducible and the golden tests meaningful.
 *
 * STAGING → VALIDATE → ATOMIC SWAP. The new rows are built in a staging table and validated before
 * anything user-facing changes. If validation fails, the command aborts and the last known-good
 * table is still in place — a stale number is recoverable, a silently wrong one is not.
 *
 * HISTORICAL: soft-deleted tickets are INCLUDED. A retired ticket is a repair that really happened;
 * excluding it would let history rewrite itself whenever someone tidied the board, and would move a
 * denominator without its numerator. This matches OperationalKpiService's documented policy.
 */
class IntelligenceRebuildVisits extends Command
{
    /**
     * Rows per INSERT. MySQL caps a prepared statement at 65,535 placeholders and this table has 16
     * bound columns, so anything above ~4,000 rows fails at runtime rather than at review.
     */
    protected $signature = 'intelligence:rebuild-visits
                            {--dry-run : Build and validate, then roll back without swapping}
                            {--chunk=1000 : Rows buffered per insert (16 bound columns; keep well under 65535/16)}';

    protected $description = 'Rebuild the repair_visits table (collapses maintenance events into real garage visits)';

    private const TABLE   = 'repair_visits';
    private const STAGING = 'repair_visits_rebuild';

    public function handle(RebuildLedger $ledger): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->error('repair_visits does not exist — run the migrations first.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $builtAt = now();

        // Recorded like the recurrence rebuild: a derived table that silently stops updating has no
        // symptom at all, it just keeps serving yesterday's answer with today's confidence.
        $runId = $ledger->start('intelligence:rebuild-visits', self::TABLE);

        try {
            $this->createStaging();

            $stats = $this->build($builtAt, (int) $this->option('chunk'));
            $this->report($stats, $started);

            $failures = $this->validate($stats);
            if ($failures !== []) {
                $this->newLine();
                $this->error('Validation FAILED — the live table was left untouched:');
                foreach ($failures as $f) {
                    $this->error('  • ' . $f);
                }
                $this->dropStaging();
                $ledger->validationFailed($runId, $stats, implode(' | ', $failures));

                return self::FAILURE;
            }

            $this->info('All validation rules passed.');

            if ($this->option('dry-run')) {
                $this->warn('--dry-run: staging discarded, live table unchanged.');
                $this->dropStaging();
                $ledger->succeed($runId, $stats + ['dry_run' => true]);

                return self::SUCCESS;
            }

            $this->swap();
            $this->info('Swapped into place.');
            $ledger->succeed($runId, $stats);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Rebuild failed: ' . $e->getMessage());
            $this->dropStaging();
            $ledger->fail($runId, $e->getMessage());

            return self::FAILURE;
        }
    }

    private function createStaging(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);
        DB::statement('CREATE TABLE ' . self::STAGING . ' LIKE ' . self::TABLE);
    }

    private function dropStaging(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);
    }

    /**
     * One ordered pass over `maintenances`, streamed through the collapser.
     *
     * The ORDER BY is load-bearing: VisitCollapser is a streaming grouper and requires rows sorted
     * by (vehicle_id, vendor_id, out_date, id). Removing or reordering it silently produces wrong
     * groups rather than an error.
     */
    private function build($builtAt, int $chunkSize): array
    {
        $multiVendorDays = $this->multiVendorDays();

        $collapser = new VisitCollapser(VisitCollapser::DEFAULT_WINDOW_DAYS);
        $signatures = $this->signaturesByMaintenance();

        $stats = [
            'source_rows'       => 0,
            'skipped_no_date'   => 0,
            'visits'            => 0,
            'collapsed_events'  => 0,
            'single_row_visits' => 0,
            'null_vendor'       => 0,
            'null_vehicle'      => 0,
            'open'              => 0,
            'negative_duration' => 0,
            'multi_vendor_days' => count($multiVendorDays),
        ];

        // HISTORICAL — no deleted_at filter, deliberately. See the class docblock.
        $source = DB::table('maintenances')
            ->select('id', 'vehicle_id', 'vendor_id', 'out_date', 'actual_in_date', 'origin', 'workflow_status')
            ->orderByRaw('vehicle_id IS NULL, vehicle_id')
            ->orderByRaw('vendor_id IS NULL, vendor_id')
            ->orderBy('out_date')
            ->orderBy('id')
            ->cursor();

        $rows = (function () use ($source, &$stats) {
            foreach ($source as $row) {
                $stats['source_rows']++;
                if ($row->out_date === null || $row->out_date === '') {
                    $stats['skipped_no_date']++;
                }
                yield $row;
            }
        })();

        $buffer = [];

        foreach ($collapser->collapse($rows, $multiVendorDays) as $visit) {
            $stats['visits']++;
            $stats['collapsed_events'] += $visit['event_row_count'];

            if ($visit['event_row_count'] === 1)          { $stats['single_row_visits']++; }
            if ($visit['vendor_id'] === null)             { $stats['null_vendor']++; }
            if ($visit['vehicle_id'] === null)            { $stats['null_vehicle']++; }
            if ($visit['is_open'])                        { $stats['open']++; }
            if ($visit['has_close_date'] && $visit['duration_days'] === null) {
                $stats['negative_duration']++;
            }

            $sigs = [];
            foreach ($visit['maintenance_ids'] as $mid) {
                foreach ($signatures[$mid] ?? [] as $sig) {
                    $sigs[$sig] = true;
                }
            }
            $sigs = array_keys($sigs);
            sort($sigs);

            $buffer[] = [
                'vehicle_id'             => $visit['vehicle_id'],
                'vendor_id'              => $visit['vendor_id'],
                'started_at'             => $visit['started_at'],
                'ended_at'               => $visit['ended_at'],
                'duration_days'          => $visit['duration_days'],
                'is_open'                => $visit['is_open'],
                'is_cancelled'           => $visit['is_cancelled'],
                'has_close_date'         => $visit['has_close_date'],
                'event_row_count'        => $visit['event_row_count'],
                'maintenance_ids'        => json_encode($visit['maintenance_ids']),
                'primary_maintenance_id' => $visit['primary_maintenance_id'],
                'origin_mix'             => $visit['origin_mix'],
                'multi_vendor_day'       => $visit['multi_vendor_day'],
                'grouping_window_days'   => $visit['grouping_window_days'],
                'signature_set'          => $sigs === [] ? null : json_encode($sigs),
                'built_at'               => $builtAt,
            ];

            if (count($buffer) >= $chunkSize) {
                DB::table(self::STAGING)->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table(self::STAGING)->insert($buffer);
        }

        return $stats;
    }

    /** Vehicle-days where the car appears at more than one garage — flagged, never merged. */
    private function multiVendorDays(): array
    {
        $rows = DB::table('maintenances')
            ->select('vehicle_id', 'out_date')
            ->whereNotNull('vehicle_id')
            ->whereNotNull('vendor_id')
            ->whereNotNull('out_date')
            ->groupBy('vehicle_id', 'out_date')
            ->havingRaw('COUNT(DISTINCT vendor_id) > 1')
            ->get();

        $keys = [];
        foreach ($rows as $r) {
            $keys[$r->vehicle_id . '|' . substr((string) $r->out_date, 0, 10)] = true;
        }

        return $keys;
    }

    /** @return array<int, string[]> maintenance_id => signatures (faults only) */
    private function signaturesByMaintenance(): array
    {
        $out = [];

        DB::table('maintenance_signatures')
            ->select('maintenance_id', 'signature')
            ->where('is_exposure', 0)
            ->orderBy('maintenance_id')
            ->chunk(20000, function ($rows) use (&$out) {
                foreach ($rows as $r) {
                    $out[(int) $r->maintenance_id][$r->signature] = true;
                }
            });

        return array_map('array_keys', $out);
    }

    /** @return string[] human-readable failures; empty means every rule held */
    private function validate(array $stats): array
    {
        $fail = [];

        $datedRows = $stats['source_rows'] - $stats['skipped_no_date'];

        // V1 — every dated source row landed in exactly one visit.
        if ($stats['collapsed_events'] !== $datedRows) {
            $fail[] = "V1 event conservation: {$stats['collapsed_events']} collapsed vs {$datedRows} dated source rows.";
        }

        // V2 — collapsing actually happened.
        if ($stats['visits'] >= $datedRows && $datedRows > 0) {
            $fail[] = "V2 no collapse occurred: {$stats['visits']} visits from {$datedRows} rows.";
        }

        // V4 — every visit is placed in time.
        $nullStart = DB::table(self::STAGING)->whereNull('started_at')->count();
        if ($nullStart > 0) {
            $fail[] = "V4 {$nullStart} visit(s) have no started_at.";
        }

        // V5 — a stored duration is never negative.
        $negative = DB::table(self::STAGING)->where('duration_days', '<', 0)->count();
        if ($negative > 0) {
            $fail[] = "V5 {$negative} visit(s) have a negative duration_days.";
        }

        // V3 — single-row groups ~23% (±3pp). A large move means the corpus or the rule changed.
        if ($stats['visits'] > 0) {
            $pct = $stats['single_row_visits'] / $stats['visits'] * 100;
            if ($pct < 15 || $pct > 45) {
                $fail[] = sprintf('V3 single-row visits %.1f%% — outside the expected 15–45%% band.', $pct);
            }
        }

        return $fail;
    }

    private function swap(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE . '_old');
        DB::statement('RENAME TABLE ' . self::TABLE . ' TO ' . self::TABLE . '_old, '
            . self::STAGING . ' TO ' . self::TABLE);
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE . '_old');
    }

    private function report(array $stats, float $started): void
    {
        $ms = (int) round((microtime(true) - $started) * 1000);

        $this->info('repair_visits rebuild');
        $this->table(['metric', 'value'], [
            ['source rows (HISTORICAL)', number_format($stats['source_rows'])],
            ['skipped — no out_date', number_format($stats['skipped_no_date'])],
            ['visits produced', number_format($stats['visits'])],
            ['events collapsed', number_format($stats['collapsed_events'])],
            ['single-row visits', sprintf('%s (%.1f%%)', number_format($stats['single_row_visits']),
                $stats['visits'] ? $stats['single_row_visits'] / $stats['visits'] * 100 : 0)],
            ['open (no return date)', sprintf('%s (%.1f%%)', number_format($stats['open']),
                $stats['visits'] ? $stats['open'] / $stats['visits'] * 100 : 0)],
            ['no vendor', number_format($stats['null_vendor'])],
            ['no vehicle', number_format($stats['null_vehicle'])],
            ['negative duration (nulled)', number_format($stats['negative_duration'])],
            ['multi-vendor days flagged', number_format($stats['multi_vendor_days'])],
            ['runtime', $ms . ' ms'],
        ]);
    }
}
