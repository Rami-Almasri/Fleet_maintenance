<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\MaintenanceReasonMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Repair-Intelligence substrate widener (docs/Repair-Intelligence-Architecture.md §8).
 *
 * Backfills maintenance_reason_id on rows that DON'T yet have one, by re-running the
 * deterministic MaintenanceReasonMatcher over their MAIN (+ exact SUP) columns — roughly
 * doubling the categorized training set that the ETA cascade's Level-1 (reason × garage)
 * cohort depends on.
 *
 * Auditability (LOCKED): this NEVER silently overwrites historical truth.
 *   - Fills BLANKS only. Rows that already carry a category are left untouched.
 *   - Stamps provenance: reason_source='backfill' + reason_matched_at, so a system-derived
 *     category is always distinguishable from an original ('sheet') or human ('manual') one.
 *   - Idempotent: re-running skips rows already filled (blank filter), so it self-limits.
 *   - Reversible: --rollback clears ONLY reason_source='backfill' rows; original data is safe.
 *   - Honest: a MAIN the matcher can't map stays NULL — never forced into a bucket.
 */
class BackfillMaintenanceReasons extends Command
{
    protected $signature = 'repair-intel:backfill-reasons
        {--dry-run : report what would change without writing}
        {--rollback : undo a prior backfill (clears ONLY system-backfilled rows)}';

    protected $description = 'Auditable backfill of maintenance_reason_id on uncategorized rows (Repair Intelligence substrate)';

    public function handle(MaintenanceReasonMatcher $matcher): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $dry = (bool) $this->option('dry-run');

        // BLANKS only — never re-categorize an existing value.
        $query = Maintenance::query()->whereNull('maintenance_reason_id');
        $total = (clone $query)->count();
        $this->info(($dry ? '[dry-run] ' : '') . "Scanning {$total} uncategorized maintenance row(s)…");

        $matched = 0; $unmatched = 0;
        $byLevel = ['critical' => 0, 'minor' => 0, 'routine' => 0, 'special' => 0];

        $query->orderBy('id')->chunkById(500, function ($chunk) use ($matcher, $dry, &$matched, &$unmatched, &$byLevel) {
            foreach ($chunk as $m) {
                $reason = $matcher->resolve($m->service_main, $m->service_sup);

                if (! $reason) {
                    $unmatched++;   // MAIN blank/unmapped and no exact SUP — stays NULL, not guessed
                    continue;
                }

                $matched++;
                $byLevel[$reason->level] = ($byLevel[$reason->level] ?? 0) + 1;

                if (! $dry) {
                    $m->forceFill([
                        'maintenance_reason_id' => $reason->id,
                        'reason_source'         => 'backfill',
                        'reason_matched_at'     => Carbon::now(),
                    ])->save();
                }
            }
        });

        $this->info(sprintf(
            '%s matched %d · unmatched (left NULL) %d  |  critical %d · minor %d · routine %d · special %d',
            $dry ? '[dry-run] would backfill' : 'Backfilled',
            $matched, $unmatched,
            $byLevel['critical'], $byLevel['minor'], $byLevel['routine'], $byLevel['special']
        ));
        if ($dry) {
            $this->line('Run without --dry-run to apply. Rows will be stamped reason_source=backfill.');
        }

        return self::SUCCESS;
    }

    /**
     * Undo a prior backfill — clears the category ONLY on rows this command set
     * (reason_source='backfill'). Import- and human-set rows are never touched.
     */
    private function rollback(): int
    {
        $count = Maintenance::where('reason_source', 'backfill')->count();
        if ($count === 0) {
            $this->info('Nothing to roll back — no rows are reason_source=backfill.');
            return self::SUCCESS;
        }

        Maintenance::where('reason_source', 'backfill')->update([
            'maintenance_reason_id' => null,
            'reason_source'         => null,
            'reason_matched_at'     => null,
        ]);

        $this->info("Rolled back {$count} system-backfilled row(s). Original 'sheet'/'manual' categories untouched.");
        return self::SUCCESS;
    }
}
