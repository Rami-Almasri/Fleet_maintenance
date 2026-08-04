<?php

namespace App\Console\Commands;

use App\Intelligence\Health\RebuildLedger;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                            {--alert : Notify the managers and exit non-zero when any derived table is stale}
                            {--json= : Write the health report to this path}';

    protected $description = 'Report freshness of the derived intelligence tables (recurrence, visits)';

    public function handle(RebuildLedger $ledger, NotificationScanner $notifier): int
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
            $this->newLine();
            $this->notifyIfAsked($notifier, $all);
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

    /**
     * Reach a human, not just an exit code.
     *
     * An exit code only helps if something is watching for it. This platform already has a path to
     * the people who can act — the same one the evidence-health gate uses — so a stale corpus lands
     * in the bell as well as in a monitoring log. Belt and braces, deliberately: the failure mode
     * here is silence, and silence is exactly what a missing exit-code watcher produces.
     *
     * Managers, not inspectors: the fix is a scheduling decision, and paging the people already
     * doing the work would be noise to them.
     *
     * @param  array<int, array<string, mixed>>  $all
     */
    private function notifyIfAsked(NotificationScanner $notifier, array $all): void
    {
        if (! $this->option('alert')) {
            $this->line('  <fg=gray>(run with --alert to notify the maintenance managers)</>');

            return;
        }

        $staleTables = array_values(array_filter($all, fn ($h) => $h['is_stale'] ?? true));
        $names       = implode(', ', array_column($staleTables, 'target_table'));
        $oldest      = max(array_map(fn ($h) => (float) ($h['age_hours'] ?? 9999), $staleTables));
        $neverBuilt  = (bool) array_filter($staleTables, fn ($h) => $h['never_rebuilt'] ?? false);

        $key = 'intel:rebuild-stale:' . now()->toDateString();

        // DEDUPE, BECAUSE notifyByPermission DOES NOT.
        //
        // That method is documented as event-driven — it fires on a real state change and delivers
        // immediately with no dedup loop, which is right for "the ticket moved to dispatch". This is
        // the opposite shape: a recurring check of a CONDITION that persists. Without a guard, an
        // outage lasting a day would page six managers on every run — 144 notifications from an
        // hourly monitor — and an alert that noisy gets muted, which costs more than never having
        // built it.
        //
        // Keyed by day: silent for the rest of today, speaks again tomorrow if still broken.
        if (DB::table('notifications')->where('data', 'like', '%"' . $key . '"%')->exists()) {
            $this->line('  <fg=gray>already raised today — staying quiet so the alert keeps its meaning</>');

            return;
        }

        $sent = $notifier->notifyByPermission('maintenance.manage', [
            'type'     => 'intel_rebuild_stale',
            'category' => 'maintenance',
            'severity' => 'critical',
            'title'    => $neverBuilt
                ? 'Intelligence data has never been built'
                : 'Intelligence data is out of date',
            // Says what it MEANS, not what broke. "fault_recurrence_pairs is stale" is a sentence
            // for us; "every garage score is showing old numbers" is one a manager can act on.
            'body'     => $neverBuilt
                ? 'The nightly rebuild has never completed, so garage scores and repair history are not being measured at all.'
                : sprintf(
                    'The nightly rebuild last completed %d hours ago. Every garage score, the fleet comeback rate and Repair Intelligence are showing figures from before then. Nothing will look broken — the numbers are simply out of date.',
                    (int) round($oldest),
                ),
            'url'      => '/data-health',
            // Keyed by DAY so a persistent outage re-raises each morning rather than either spamming
            // hourly or being deduplicated into silence for the whole outage.
            'key'      => $key,
            'icon'     => 'alert',
            'meta'     => [
                'stale_tables' => $names,
                'age_hours'    => round($oldest, 1),
                'never_built'  => $neverBuilt,
            ],
        ]);

        $this->line(sprintf('  <fg=gray>notified %d manager%s</>', $sent, $sent === 1 ? '' : 's'));
    }
}
