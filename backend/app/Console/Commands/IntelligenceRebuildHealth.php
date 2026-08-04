<?php

namespace App\Console\Commands;

use App\Intelligence\Health\RebuildLedger;
use Illuminate\Console\Command;

/**
 * Is the intelligence layer's data actually fresh?
 *
 * ── THE FAILURE THIS EXISTS TO MAKE VISIBLE ──────────────────────────────────────────────────────
 * Convergence concentrated risk on purpose. Six recurrence implementations meant six things could be
 * wrong independently; ONE means every recurrence figure in the platform is wrong together the
 * moment the nightly rebuild stops. That is the correct trade — a consistently wrong number is
 * detectable, six quietly inconsistent ones are not — but it changes what "healthy" means.
 *
 * And the failure has NO SYMPTOM. The tables keep serving. The pages keep rendering. Every figure
 * keeps carrying its sample size and its coverage badge and looking exactly as authoritative as it
 * did yesterday, while the whole platform answers out of a corpus that stopped growing on whatever
 * night the scheduler died.
 *
 * The scheduler is already known dead on developer machines and is unverified on the server, so this
 * is not a hypothetical.
 *
 *   php artisan intelligence:rebuild-health           inspect
 *   php artisan intelligence:rebuild-health --alert   exit 1 when stale, for monitoring/cron
 */
class IntelligenceRebuildHealth extends Command
{
    protected $signature = 'intelligence:rebuild-health
                            {--alert : Exit non-zero when any derived table is stale}
                            {--json= : Write the health report to this path}';

    protected $description = 'Report freshness of the derived intelligence tables (recurrence, visits)';

    public function handle(RebuildLedger $ledger): int
    {
        $all   = $ledger->healthAll();
        $stale = false;

        $rows = [];
        foreach ($all as $h) {
            $isStale = (bool) ($h['is_stale'] ?? true);
            $stale   = $stale || $isStale;

            $rows[] = [
                $h['target_table'],
                match (true) {
                    ! empty($h['never_rebuilt']) => '<fg=red>NEVER BUILT</>',
                    $isStale                     => '<fg=red>STALE</>',
                    default                      => '<fg=green>fresh</>',
                },
                $h['last_rebuilt_at'] ?? '—',
                $h['age_hours'] !== null ? round((float) $h['age_hours'], 1) . 'h' : '—',
                ($h['stale_after_hours'] ?? '—') . 'h',
                number_format((int) ($h['rows_written'] ?? 0)),
                $h['duration_ms'] !== null ? $h['duration_ms'] . 'ms' : '—',
                $h['corpus_max_date'] ?? '—',
                $h['metric_version'] ?? '—',
                (int) ($h['failures_since_success'] ?? 0),
            ];
        }

        $this->info('INTELLIGENCE REBUILD HEALTH');
        $this->table(
            ['table', 'status', 'last rebuilt', 'age', 'limit', 'rows', 'took', 'corpus to', 'v', 'fails'],
            $rows,
        );

        foreach ($all as $h) {
            if (! empty($h['last_failure_reason'])) {
                $this->warn("  {$h['target_table']} last failure ({$h['last_failure_at']}): {$h['last_failure_reason']}");
            }
        }

        if ($stale) {
            $this->newLine();
            $this->error('DERIVED DATA IS STALE.');
            $this->line('  <fg=gray>Every recurrence figure on the platform is ageing — the fleet baseline, every</>');
            $this->line('  <fg=gray>garage score, and Repair Intelligence all read these tables. Nothing will look</>');
            $this->line('  <fg=gray>broken; the numbers are simply out of date.</>');
            $this->newLine();
            $this->line('  Fix:  php artisan intelligence:rebuild-visits && php artisan intelligence:rebuild-recurrence');
            $this->line('  Then: check the scheduler is firing — schedule:list shows 04:20 and 04:35 daily.');
        } else {
            $this->newLine();
            $this->info('All derived intelligence tables are fresh.');
        }

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'generated_at' => now()->toIso8601String(),
                'any_stale'    => $stale,
                'tables'       => $all,
            ], JSON_PRETTY_PRINT));
            $this->line("  <fg=gray>report written to {$path}</>");
        }

        return ($this->option('alert') && $stale) ? self::FAILURE : self::SUCCESS;
    }
}
