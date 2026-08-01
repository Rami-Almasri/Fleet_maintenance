<?php

namespace App\Console\Commands;

use App\Models\GarageRecommendationDecision;
use App\Services\Garage\DecisionLearning;
use Illuminate\Console\Command;

/**
 * Does the operation agree with the recommendation engine — and when it does not, why?
 *
 * Reads the recorded dispatch decisions and reports acceptance, override reasons and whether any of it
 * is a pattern worth acting on.
 *
 * ⚠️ Read the ADJUSTED rate, not the headline one. Overrides for a customer request or a standing
 * garage relationship are decisions the engine has no business modelling; counting them as misses makes
 * the engine look worse the better the operation serves its customers. The adjusted figure excludes
 * them; the raw figure is printed alongside only so the difference is visible.
 *
 * Suggestions are PROPOSALS. Nothing here retunes anything — see the note in {@see DecisionLearning}.
 */
class DecisionLearningCommand extends Command
{
    protected $signature = 'intelligence:decision-learning
                            {--days=180 : Only consider decisions recorded in this window}
                            {--json : Machine-readable output}';

    protected $description = 'Recommendation acceptance, override reasons, and where the weights disagree with the operation';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $rows = GarageRecommendationDecision::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->get([
                'recommended_vendor_id', 'chosen_vendor_id', 'followed',
                'override_reason', 'score_gap', 'chosen_advantages', 'chosen_rank',
            ])
            ->map(fn ($d) => [
                'recommended_vendor_id' => $d->recommended_vendor_id,
                'followed'              => $d->followed,
                'override_reason'       => $d->override_reason,
                'score_gap'             => $d->score_gap,
                'chosen_advantages'     => $d->chosen_advantages,
                'chosen_rank'           => $d->chosen_rank,
            ])
            ->all();

        $report = (new DecisionLearning(
            (array) config('garage_recommendation.override_reasons', []),
            (array) config('garage_recommendation.learning', []),
        ))->report($rows);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info("Recommendation acceptance — last {$days} days");
        $this->line("  decisions with a recommendation : {$report['total']}");
        $this->line("  followed                        : {$report['followed']}");
        $this->line("  overridden                      : {$report['overridden']}");
        $this->newLine();

        if (! $report['sufficient']) {
            $this->warn('  ' . $report['note']);
            $this->line('  Everything below is shown for shape only — do not act on it yet.');
            $this->newLine();
        }

        // The adjusted rate leads, because it is the one that actually judges the engine.
        $this->line('  <options=bold>Acceptance (engine-judgeable)</> : ' . ($report['adjusted_pct'] ?? '—') . '%'
            . "  [{$report['followed']} of {$report['in_scope_total']}]");
        $this->line('  Raw acceptance, all overrides   : ' . ($report['acceptance_pct'] ?? '—') . '%');
        $this->line("  Excluded as out of scope        : {$report['out_of_scope']}  (customer request, relationship — not engine misses)");
        if ($report['unexplained'] > 0) {
            $this->line("  <fg=yellow>No reason captured              : {$report['unexplained']}  (these teach nothing)</>");
        }

        if (! empty($report['by_reason'])) {
            $this->newLine();
            $this->info('Why supervisors chose differently');
            $this->table(
                ['Reason', 'Axis', 'n', 'Median gap', 'Near-ties', 'Backed by data', 'In scope'],
                array_map(fn ($r) => [
                    $r['label'], $r['axis'], $r['count'],
                    $r['median_gap'] ?? '—', $r['near_ties'],
                    in_array($r['axis'], ['cost', 'speed', 'availability'], true) ? "{$r['corroborated']}/{$r['count']}" : 'n/a',
                    $r['in_scope'] ? 'yes' : 'no',
                ], $report['by_reason']),
            );
        }

        if (! empty($report['suggestions'])) {
            $this->newLine();
            $this->info('Worth reviewing (proposals — nothing is applied automatically)');
            foreach ($report['suggestions'] as $s) {
                $this->line("  <options=bold>[{$s['kind']}]</> {$s['detail']}");
                $this->line("      → {$s['action']}");
            }
        } elseif ($report['sufficient']) {
            $this->newLine();
            $this->line('  No override pattern crosses the evidence threshold — nothing to change.');
        }

        return self::SUCCESS;
    }
}
