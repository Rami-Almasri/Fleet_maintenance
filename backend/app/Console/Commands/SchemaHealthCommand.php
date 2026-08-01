<?php

namespace App\Console\Commands;

use App\Services\Schema\SchemaHealthService;
use Illuminate\Console\Command;

/**
 * System health for the things the intelligence layer silently assumes.
 *
 * Schema defects do not announce themselves: a missing unique index does not throw, it just lets a
 * duplicate through and quietly biases every number computed from it. This surfaces those as named,
 * actionable checks so infrastructure problems cannot degrade decision quality unnoticed.
 *
 * Exits non-zero on any `fail`, so it can gate a deploy.
 */
class SchemaHealthCommand extends Command
{
    protected $signature = 'schema:health
                           {--json : Machine-readable output for CI}
                           {--drift : Also compare the live schema against a clean migration run (slow)}
                           {--detail : Show every individual check, not just the category summary}';

    protected $description = 'Check the schema guarantees the recommendation engine depends on';

    private const ICON = ['ok' => '✓', 'warn' => '⚠', 'fail' => '✗'];

    public function handle(SchemaHealthService $health): int
    {
        $checks = $health->checks((bool) $this->option('drift'));
        $summary = $health->summary($checks);
        $verdict = $summary['verdict'];

        if ($this->option('json')) {
            $this->line(json_encode($summary + ['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $verdict === 'fail' ? self::FAILURE : self::SUCCESS;
        }

        // The operator's view first: one score and four category verdicts. The score never stands alone —
        // "98%" means nothing if the missing 2% is a broken integrity constraint, so the worst status is
        // always shown beside it.
        $this->newLine();
        $this->info('System Health');
        $this->newLine();
        $this->line('  Overall' . str_repeat(' ', 29) . $this->tint($verdict, $summary['score'] . '%  ' . strtoupper($verdict)));
        $this->newLine();

        foreach ($summary['categories'] as $cat) {
            $icon = self::ICON[$cat['status']] ?? '?';
            $pad = str_repeat(' ', max(1, 32 - mb_strlen($cat['label'])));
            $this->line('  ' . $this->tint($cat['status'], "{$icon} {$cat['label']}{$pad}" . $this->word($cat['status'])));
        }
        $this->newLine();

        // Engineers get the detail; operators do not need it unless something is wrong.
        if (! $this->option('detail') && $verdict === 'ok') {
            $this->comment('  All checks passed. Re-run with --detail for the full breakdown.');
            return self::SUCCESS;
        }

        foreach ($checks as $c) {
            if (! $this->option('detail') && $c['status'] === 'ok') {
                continue;   // when something is wrong, lead with what is wrong
            }
            $icon = self::ICON[$c['status']] ?? '?';
            // Pad by CHARACTERS, not bytes — labels contain em-dashes, and str_pad would under-pad them
            // and break the column alignment.
            $pad = str_repeat(' ', max(1, 34 - mb_strlen($c['label'])));
            $this->line('  ' . $this->tint($c['status'], "{$icon} {$c['label']}{$pad}" . $this->word($c['status'])));
            $this->line('      ' . $c['detail']);
            if ($c['fix']) {
                $this->line("      <fg=gray>Fix: {$c['fix']}</>");
            }
            $this->newLine();
        }

        match ($verdict) {
            'ok'   => $this->info('All schema guarantees verified.'),
            'warn' => $this->warn('Degraded — functioning, but act on the warnings above.'),
            default => $this->error('FAILED — the code depends on guarantees this database does not provide.'),
        };

        return $verdict === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    private function tint(string $status, string $text): string
    {
        $colour = ['ok' => 'green', 'warn' => 'yellow'][$status] ?? 'red';
        return "<fg={$colour}>{$text}</>";
    }

    /** Operator-facing wording: a degraded calibration loop is "Learning", not "Warn". */
    private function word(string $status): string
    {
        return ['ok' => 'Healthy', 'warn' => 'Degraded', 'fail' => 'Failed'][$status] ?? $status;
    }
}
