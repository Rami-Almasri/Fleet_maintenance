<?php

namespace App\Console\Commands;

use App\Services\Knowledge\RepairSignatureClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures the RepairSignatureClassifier against human judgement, on the live corpus.
 *
 * This is the classifier's acceptance test and its ongoing health check. It answers three
 * questions the first learning loop depends on:
 *
 *   1. AGREEMENT  — where a human labelled `service_main`, does the classifier reach the same
 *                   signature from `maintenance_notes` alone? Baseline to beat: 80.5%.
 *   2. COVERAGE   — how many events gain a usable label. Human labels alone cover 30.5%.
 *   3. WEAK SPOTS — per-signature recall, so the next improvement is chosen from evidence rather
 *                   than from whichever signature someone happened to look at.
 *
 * Read-only: it writes nothing. Run it before and after any change to the pattern set — a change
 * that raises one signature's recall by breaking another's is the failure mode this catches.
 */
class ValidateRepairSignatureClassifier extends Command
{
    protected $signature = 'intelligence:validate-classifier {--limit=0 : Sample size (0 = the whole corpus)}';

    protected $description = 'Measure the repair-signature classifier against human service_main labels';

    public function handle(RepairSignatureClassifier $classifier): int
    {
        $limit = (int) $this->option('limit');

        $this->info('Loading corpus…');

        $query = DB::table('maintenances')
            ->select('id', 'service_main', 'service_sup', 'maintenance_notes')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = 0;
        $humanLabelled = 0;
        $derivedLabelled = 0;
        $both = 0;
        $agreed = 0;
        $partial = 0;
        $newFromNotes = 0;
        $noise = 0;
        $unlabelled = 0;

        /** @var array<string, array{human:int, hit:int, derived:int}> */
        $perSignature = [];

        $query->chunk(2000, function ($rows) use (
            $classifier, &$total, &$humanLabelled, &$derivedLabelled, &$both, &$agreed,
            &$partial, &$newFromNotes, &$noise, &$unlabelled, &$perSignature
        ) {
            foreach ($rows as $row) {
                $total++;

                $human = $classifier->fromHumanLabel($row->service_main);
                // service_sup is a free-text refinement of the same event — part of the note corpus.
                $result = $classifier->classify(trim(($row->maintenance_notes ?? '').' '.($row->service_sup ?? '')));
                $derived = $result['signatures'];

                if ($human !== []) {
                    $humanLabelled++;
                }
                if ($derived !== []) {
                    $derivedLabelled++;
                }

                foreach ($human as $sig) {
                    $perSignature[$sig] ??= ['human' => 0, 'hit' => 0, 'derived' => 0];
                    $perSignature[$sig]['human']++;
                    if (in_array($sig, $derived, true)) {
                        $perSignature[$sig]['hit']++;
                    }
                }
                foreach ($derived as $sig) {
                    $perSignature[$sig] ??= ['human' => 0, 'hit' => 0, 'derived' => 0];
                    $perSignature[$sig]['derived']++;
                }

                if ($human !== [] && $derived !== []) {
                    $both++;
                    if (array_intersect($human, $derived) !== []) {
                        $agreed++;
                        // The human saw a fault the notes did not surface — a miss worth tracking
                        // separately from an outright disagreement.
                        if (array_diff($human, $derived) !== []) {
                            $partial++;
                        }
                    }
                } elseif ($human === [] && $derived !== []) {
                    $newFromNotes++;
                } elseif ($human === [] && $derived === []) {
                    $result['noise'] ? $noise++ : $unlabelled++;
                }
            }
        });

        $labelledTotal = $total - $noise - $unlabelled;
        $pct = fn ($n, $d) => $d > 0 ? number_format(100 * $n / $d, 1).'%' : '—';

        $this->newLine();
        $this->line('<comment>AGREEMENT (the acceptance test)</comment>');
        $this->table(['Metric', 'Count', 'Share'], [
            ['events in corpus',                 number_format($total),         ''],
            ['human-labelled (service_main)',    number_format($humanLabelled), $pct($humanLabelled, $total)],
            ['derived from notes',               number_format($derivedLabelled), $pct($derivedLabelled, $total)],
            ['both present (validation set)',    number_format($both),          ''],
            ['  → AGREED (≥1 shared signature)', number_format($agreed),        '<info>'.$pct($agreed, $both).'</info>'],
            ['  → of which partial (human saw more)', number_format($partial),  $pct($partial, $both)],
        ]);

        $this->line('<comment>COVERAGE (what the loop can actually read)</comment>');
        $this->table(['Metric', 'Count', 'Share'], [
            ['human labels only (before)',   number_format($humanLabelled), $pct($humanLabelled, $total)],
            ['NEW labels from notes',        number_format($newFromNotes),  $pct($newFromNotes, $total)],
            ['TOTAL LABELLED (after)',       number_format($labelledTotal), '<info>'.$pct($labelledTotal, $total).'</info>'],
            ['workflow noise (correctly unlabelled)', number_format($noise), $pct($noise, $total)],
            ['unlabelled (text too vague)',  number_format($unlabelled),    $pct($unlabelled, $total)],
        ]);

        $this->line('<comment>PER-SIGNATURE RECALL — where the next improvement should go</comment>');
        $rows = [];
        foreach ($perSignature as $sig => $s) {
            $rows[] = [
                $sig,
                number_format($s['derived']),
                $s['human'] > 0 ? number_format($s['human']) : '—',
                $s['human'] > 0 ? $pct($s['hit'], $s['human']) : '—',
                $s['human'] >= 50 && $s['hit'] / max($s['human'], 1) < 0.6 ? '← weak' : '',
            ];
        }
        usort($rows, fn ($a, $b) => (int) str_replace(',', '', $b[1]) <=> (int) str_replace(',', '', $a[1]));
        $this->table(['Signature', 'Derived', 'Human', 'Recall', ''], $rows);

        $agreementPct = $both > 0 ? 100 * $agreed / $both : 0;
        if ($agreementPct < 75) {
            $this->error(sprintf('Agreement %.1f%% is below the 75%% floor — do not ship this pattern set.', $agreementPct));

            return self::FAILURE;
        }

        $this->info(sprintf('Agreement %.1f%% · coverage %.1f%% — classifier is within acceptance.', $agreementPct, 100 * $labelledTotal / max($total, 1)));

        return self::SUCCESS;
    }
}
