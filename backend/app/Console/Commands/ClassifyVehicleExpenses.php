<?php

namespace App\Console\Commands;

use App\Services\Expenses\ExpenseCategoryClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-derive `vehicle_expenses.category` from each line's remark.
 *
 * The category is DERIVED data, not source data — `remarks` is never touched, and this command can be
 * re-run at any time. Run it whenever {@see ExpenseCategoryClassifier}'s rules change, otherwise the
 * stored buckets (and therefore what the cost total excludes) still reflect the OLD rules.
 *
 *   php artisan expenses:classify              # re-classify every line, then report the distribution
 *   php artisan expenses:classify --dry-run    # show what would change, write nothing
 */
class ClassifyVehicleExpenses extends Command
{
    protected $signature = 'expenses:classify
        {--source= : Only this source tag (default: every source)}
        {--dry-run : Report the distribution and the changes, write nothing}';

    protected $description = 'Re-derive the operational category of every expense line from its remark';

    public function handle(): int
    {
        $classifier = new ExpenseCategoryClassifier();
        $dryRun     = (bool) $this->option('dry-run');
        $source     = $this->option('source');

        $base = fn () => DB::table('vehicle_expenses')->when($source, fn ($q) => $q->where('source', $source));

        $total = $base()->count();
        if ($total === 0) {
            $this->warn('No expense lines to classify.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Classifying (dry run) ' : 'Classifying ') . number_format($total) . ' expense lines…');
        $bar = $this->output->createProgressBar($total);

        $tally = []; $changed = 0; $moves = [];

        $base()->select('id', 'remarks', 'category', 'amount')->orderBy('id')
            ->chunk(2000, function ($rows) use ($classifier, $dryRun, &$tally, &$changed, &$moves, $bar) {
                foreach ($rows as $r) {
                    $c   = $classifier->classify($r->remarks);
                    $key = $c['key'];

                    $tally[$key]['count'] = ($tally[$key]['count'] ?? 0) + 1;
                    $tally[$key]['total'] = ($tally[$key]['total'] ?? 0.0) + (float) $r->amount;

                    if ($r->category !== $key) {
                        $changed++;
                        $move = ($r->category ?: '—') . ' → ' . $key;
                        $moves[$move] = ($moves[$move] ?? 0) + 1;
                    }
                    if (! $dryRun) {
                        DB::table('vehicle_expenses')->where('id', $r->id)
                            ->update(['category' => $key, 'category_matched' => $c['matched']]);
                    }
                }
                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine(2);

        // ---- Distribution, in the classifier's own order so it reads like the filter UI ------------
        $excluded = array_map('strval', array_keys((array) config('expenses.excluded_categories', [])));
        $rows = [];
        foreach (ExpenseCategoryClassifier::categories() as $cat) {
            if (! isset($tally[$cat['key']])) {
                continue;
            }
            $rows[] = [
                $cat['label'],
                number_format($tally[$cat['key']]['count']),
                number_format($tally[$cat['key']]['total'], 2),
                in_array($cat['key'], $excluded, true) ? 'excluded from cost' : '',
            ];
        }
        $this->table(['Category', 'Lines', 'Amount (AED)', ''], $rows);

        $other = $tally[ExpenseCategoryClassifier::UNCATEGORISED]['count'] ?? 0;
        $this->line('<info>Classified:</info> ' . number_format($total - $other) . ' of ' . number_format($total)
            . ' (' . round(100 * ($total - $other) / $total, 1) . '%) · <comment>uncategorised:</comment> ' . number_format($other));

        if ($changed) {
            arsort($moves);
            $this->line('<info>Changed:</info> ' . number_format($changed) . ' line(s)' . ($dryRun ? ' would move' : ''));
            foreach (array_slice($moves, 0, 10, true) as $move => $n) {
                $this->line('  ' . $move . '  ×' . number_format($n));
            }
        } else {
            $this->line('<info>Changed:</info> nothing — stored categories already match the current rules.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
