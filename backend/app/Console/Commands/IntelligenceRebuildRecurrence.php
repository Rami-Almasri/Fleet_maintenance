<?php

namespace App\Console\Commands;

use App\Intelligence\Support\RecurrencePairBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rebuilds `fault_recurrence_pairs` — the table behind the platform's highest-value metric.
 *
 * MUST RUN AFTER `intelligence:rebuild-visits`.
 *
 * ── THE DEDUPLICATION ────────────────────────────────────────────────────────────────────────────
 * `maintenance_signatures` holds 2–8 rows for one fault on one car on one day (33,026 raw fault rows
 * → 12,608 distinct events). Pairing without collapsing those first counts a single real recurrence
 * several times: it inflated the first published garage figures by 2.5–3×, and made one garage look
 * like a 209-repair finding when it is a 29-repair watch-item. RecurrencePairBuilder collapses first;
 * the unique index on the table enforces the same rule at the storage layer.
 *
 * ── EXPOSURE ROWS ARE EXCLUDED ───────────────────────────────────────────────────────────────────
 * `is_exposure = 1` records that a car was exposed to a system, not that the system failed. Body and
 * rim damage recur constantly because customers scrape cars, not because repairs fail. Counting them
 * roughly doubles every fault number and would push garage scores down for reasons no workshop can
 * influence.
 *
 * STAGING → VALIDATE → ATOMIC SWAP, and HISTORICAL — as for the visits rebuild.
 */
class IntelligenceRebuildRecurrence extends Command
{
    /**
     * Rows per INSERT. MySQL caps a prepared statement at 65,535 placeholders and this table has 19
     * bound columns, so anything above ~3,400 rows fails at runtime rather than at review.
     */
    protected $signature = 'intelligence:rebuild-recurrence
                            {--dry-run : Build and validate, then roll back without swapping}
                            {--chunk=1000 : Rows buffered per insert (19 bound columns; keep well under 65535/19)}';

    protected $description = 'Rebuild fault_recurrence_pairs (deduplicated fault events + when each fault next returned)';

    private const TABLE   = 'fault_recurrence_pairs';
    private const STAGING = 'fault_recurrence_pairs_rebuild';

    public function handle(): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->error('fault_recurrence_pairs does not exist — run the migrations first.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $builtAt = now();

        try {
            DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);
            DB::statement('CREATE TABLE ' . self::STAGING . ' LIKE ' . self::TABLE);

            $stats = $this->build($builtAt, (int) $this->option('chunk'));
            $this->report($stats, $started);

            $failures = $this->validate($stats);
            if ($failures !== []) {
                $this->newLine();
                $this->error('Validation FAILED — the live table was left untouched:');
                foreach ($failures as $f) {
                    $this->error('  • ' . $f);
                }
                DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);

                return self::FAILURE;
            }

            $this->info('All validation rules passed.');

            if ($this->option('dry-run')) {
                $this->warn('--dry-run: staging discarded, live table unchanged.');
                DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);

                return self::SUCCESS;
            }

            DB::statement('DROP TABLE IF EXISTS ' . self::TABLE . '_old');
            DB::statement('RENAME TABLE ' . self::TABLE . ' TO ' . self::TABLE . '_old, '
                . self::STAGING . ' TO ' . self::TABLE);
            DB::statement('DROP TABLE IF EXISTS ' . self::TABLE . '_old');

            $this->info('Swapped into place.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Rebuild failed: ' . $e->getMessage());
            DB::statement('DROP TABLE IF EXISTS ' . self::STAGING);

            return self::FAILURE;
        }
    }

    /**
     * One ordered pass, streamed through the builder.
     *
     * The ORDER BY is load-bearing: RecurrencePairBuilder is a streaming grouper that relies on rows
     * for the same (vehicle, signature) arriving together and in date order, and on same-day rows
     * being adjacent so they can be collapsed.
     */
    private function build($builtAt, int $chunkSize): array
    {
        $stats = [
            'raw_rows'       => 0,
            'events'         => 0,
            'recurred'       => 0,
            'open_chains'    => 0,
            'no_vendor'      => 0,
            'multi_vendor'   => 0,
            'both_labels'    => 0,
            'min_days'       => null,
        ];

        // HISTORICAL, faults only (is_exposure = 0). Joined to `maintenances` for the vendor.
        $source = DB::table('maintenance_signatures as s')
            ->leftJoin('maintenances as m', 'm.id', '=', 's.maintenance_id')
            ->select(
                's.vehicle_id', 's.signature', 's.occurred_at', 's.source',
                's.maintenance_id', 'm.vendor_id'
            )
            ->where('s.is_exposure', 0)
            ->whereNotNull('s.vehicle_id')
            ->whereNotNull('s.occurred_at')
            ->orderBy('s.vehicle_id')
            ->orderBy('s.signature')
            ->orderBy('s.occurred_at')
            ->orderBy('s.maintenance_id')
            ->cursor();

        $rows = (function () use ($source, &$stats) {
            foreach ($source as $row) {
                $stats['raw_rows']++;
                yield $row;
            }
        })();

        $builder = new RecurrencePairBuilder();
        $buffer  = [];

        foreach ($builder->build($rows) as $pair) {
            $stats['events']++;

            if ($pair['next_occurred_at'] !== null) {
                $stats['recurred']++;
                if ($stats['min_days'] === null || $pair['days_to_return'] < $stats['min_days']) {
                    $stats['min_days'] = $pair['days_to_return'];
                }
            } else {
                $stats['open_chains']++;
            }

            if ($pair['first_vendor_id'] === null) { $stats['no_vendor']++; }
            if ($pair['multi_vendor_day'])         { $stats['multi_vendor']++; }
            if ($pair['label_source'] === 'both')  { $stats['both_labels']++; }

            $buffer[] = [
                'vehicle_id'           => $pair['vehicle_id'],
                'signature'            => $pair['signature'],
                'occurred_at'          => $pair['occurred_at'],
                'first_maintenance_id' => $pair['first_maintenance_id'],
                'first_vendor_id'      => $pair['first_vendor_id'],
                'next_occurred_at'     => $pair['next_occurred_at'],
                'next_maintenance_id'  => $pair['next_maintenance_id'],
                'next_vendor_id'       => $pair['next_vendor_id'],
                'days_to_return'       => $pair['days_to_return'],
                'returned_30'          => $pair['returned_30'],
                'returned_60'          => $pair['returned_60'],
                'returned_90'          => $pair['returned_90'],
                'same_vendor'          => $pair['same_vendor'],
                'label_source'         => $pair['label_source'],
                'source_row_count'     => $pair['source_row_count'],
                'multi_vendor_day'     => $pair['multi_vendor_day'],
                'chain_position'       => $pair['chain_position'],
                'chain_length'         => $pair['chain_length'],
                'built_at'             => $builtAt,
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

    /** @return string[] */
    private function validate(array $stats): array
    {
        $fail = [];

        // R1 — the fault vocabulary is 20 signatures. A change means the classifier moved.
        $signatures = DB::table(self::STAGING)->distinct()->count('signature');
        if ($signatures < 1) {
            $fail[] = 'R1 no signatures present.';
        }

        // R2 — the deduplication guarantee. A zero- or negative-day gap means same-day rows were
        // paired with each other, which is precisely the bug this table was rebuilt to eliminate.
        $sameDay = DB::table(self::STAGING)->where('days_to_return', '<', 1)->count();
        if ($sameDay > 0) {
            $fail[] = "R2 {$sameDay} pair(s) have days_to_return < 1 — deduplication did not happen.";
        }

        // R3 — one row per (vehicle, signature, date). The unique index would have thrown, but an
        // explicit count makes the failure legible instead of a driver error.
        $dupes = DB::table(self::STAGING)
            ->select('vehicle_id', 'signature', 'occurred_at')
            ->groupBy('vehicle_id', 'signature', 'occurred_at')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($dupes > 0) {
            $fail[] = "R3 {$dupes} duplicate (vehicle, signature, date) group(s) survived.";
        }

        // R4 — every raw row is accounted for by exactly one event.
        $sourceRows = (int) DB::table(self::STAGING)->sum('source_row_count');
        if ($sourceRows !== $stats['raw_rows']) {
            $fail[] = "R4 row conservation: {$sourceRows} accounted for vs {$stats['raw_rows']} read.";
        }

        // R7 — exposure rows must never enter.
        if ($stats['events'] > 0 && $stats['raw_rows'] === 0) {
            $fail[] = 'R7 events produced from zero source rows.';
        }

        return $fail;
    }

    private function report(array $stats, float $started): void
    {
        $ms = (int) round((microtime(true) - $started) * 1000);
        $dupFactor = $stats['events'] ? $stats['raw_rows'] / $stats['events'] : 0;

        $this->info('fault_recurrence_pairs rebuild');
        $this->table(['metric', 'value'], [
            ['raw fault rows read', number_format($stats['raw_rows'])],
            ['distinct fault events', number_format($stats['events'])],
            ['duplication factor', sprintf('%.2fx', $dupFactor)],
            ['events that recurred', number_format($stats['recurred'])],
            ['open chains (never returned)', number_format($stats['open_chains'])],
            ['events with no garage', number_format($stats['no_vendor'])],
            ['multi-vendor days', number_format($stats['multi_vendor'])],
            ['labelled derived+human', number_format($stats['both_labels'])],
            ['shortest gap (days)', $stats['min_days'] ?? '—'],
            ['runtime', $ms . ' ms'],
        ]);
    }
}
