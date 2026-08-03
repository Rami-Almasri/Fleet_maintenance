<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\VehicleFaultRecurrenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Can this fleet's history support a confidence score, or only a story?
 *
 * The Suggested Checks panel currently says WHAT HAPPENED ("returned 3×, usually every ~47 days") and
 * promotes a fault when the car is past the interval it established. The obvious next step is to say
 * "91% likely" — but a probability nobody has scored is decoration, and reads as more certain than the
 * system is. The evidence contract says so out loud: E15 §② allows bands, not decimals, *until a Brier
 * reading exists*. This command produces that reading.
 *
 * It does not need new data. The fleet has ~3 years of repair history, so the outcomes a predictor
 * would be judged on ALREADY HAPPENED — we can hold out the recent past, predict into it, and score
 * against what actually occurred.
 *
 * ── How a sample is made ──────────────────────────────────────────────────────────────────────────
 * For every repeat-fault chain, walk a WEEKLY grid of as-of dates. At each date D we know only what
 * was knowable then — episodes that had already ended, the gaps between them, how long it had been
 * quiet. That state is the feature vector. The label is the answer to one precise question:
 *
 *      did this fault start another episode in the window (D, D + horizon] ?
 *
 * "High probability of brake issues" is not scoreable; that question is. Everything below rests on it.
 *
 * THREE RULES KEEP THIS HONEST:
 *   1. No leakage — features at D are computed from episodes ending on or before D. Nothing that
 *      happened after D can influence the prediction made at D.
 *   2. Right-censoring — a sample is DROPPED when D + horizon runs past the end of the corpus. We do
 *      not know whether a fault recurred after our data ends, and scoring an unknown as "no" would
 *      manufacture correct negatives and flatter every model.
 *   3. Temporal holdout — the calibration table is built on the OLD period and scored on the RECENT
 *      one. Fitting and scoring on the same rows measures memorisation, not prediction.
 *
 * ── What it reports ───────────────────────────────────────────────────────────────────────────────
 *   · base rate — how often a fault recurs in the window at all. The number any model must beat.
 *   · the LIVE rule (VehicleSuggestedChecksService promotion) as a binary classifier: precision,
 *     recall, and lift over the base rate. This is "is the Due now badge actually right?"
 *   · an empirical-probability model: bucket by how far through its own interval a fault is, take the
 *     observed frequency per bucket from the training period, apply to the holdout. Scored with
 *     Brier, against a base-rate-only baseline. Skill = the reduction. Positive skill is what earns a
 *     percentage on the panel; near-zero skill means the honest answer is a band or nothing.
 *   · a reliability table — predicted vs observed per bucket. A model can have good Brier and still be
 *     badly calibrated in the range operators act on, which is the range that matters.
 *
 *   php artisan intelligence:backtest-recurrence
 *   php artisan intelligence:backtest-recurrence --horizon=14 --holdout=6
 */
class IntelligenceBacktestRecurrence extends Command
{
    protected $signature = 'intelligence:backtest-recurrence
        {--horizon=30 : the prediction window, in days — "will it come back within N days?"}
        {--holdout=12 : months of the most recent history reserved for scoring, never for fitting}
        {--step=7 : spacing of the as-of grid, in days}';

    protected $description = 'Score the repeat-fault predictor against what actually happened: base rate, the live Due-now rule, and whether a calibrated probability is earnable (Brier + reliability)';

    /**
     * How far through its own interval a fault is (days quiet ÷ its average gap), bucketed. These are
     * the bands the panel would show if a decimal turns out not to be earnable — so they are also
     * exactly what the reliability table needs to report on. The live promotion rule fires at 0.8.
     */
    private const RATIO_BUCKETS = [
        ['key' => '<0.5',    'lo' => 0.0, 'hi' => 0.5],
        ['key' => '0.5–0.8', 'lo' => 0.5, 'hi' => 0.8],
        ['key' => '0.8–1.0', 'lo' => 0.8, 'hi' => 1.0],
        ['key' => '1.0–1.5', 'lo' => 1.0, 'hi' => 1.5],
        ['key' => '1.5–2.0', 'lo' => 1.5, 'hi' => 2.0],
        ['key' => '2.0–3.0', 'lo' => 2.0, 'hi' => 3.0],
        ['key' => '3.0+',    'lo' => 3.0, 'hi' => INF],
    ];

    /** Mirrors VehicleSuggestedChecksService — the rule under test must be the rule that ships. */
    private const MIN_GAPS_FOR_INTERVAL = 2;
    private const DUE_SOON_GAP_RATIO    = 0.8;
    private const PATTERN_LAPSED_MULTIPLE = 3.0;

    public function handle(VehicleFaultRecurrenceService $recurrence): int
    {
        $horizon = max(1, (int) $this->option('horizon'));
        $holdout = max(1, (int) $this->option('holdout'));
        $step    = max(1, (int) $this->option('step'));

        $corpusEnd = Carbon::today();
        $splitAt   = $corpusEnd->copy()->subMonths($holdout);

        $this->newLine();
        $this->line("Backtesting the repeat-fault predictor — will a fault recur within <info>{$horizon}</info> days?");
        $this->line("Training on everything before <info>{$splitAt->toDateString()}</info>; scoring on the {$holdout} months after it.");

        $samples = $this->buildSamples($recurrence, $horizon, $step, $corpusEnd);

        if (count($samples) < 50) {
            $this->newLine();
            $this->error('Not enough scoreable samples (' . count($samples) . '). No verdict is possible — and that IS the finding: this fleet cannot yet support a confidence score.');

            return self::FAILURE;
        }

        $this->reportCorpusWindow($samples);

        $train = array_values(array_filter($samples, fn ($s) => $s['at'] < $splitAt));
        $test  = array_values(array_filter($samples, fn ($s) => $s['at'] >= $splitAt));

        // A fixed calendar split assumes the corpus is older than the holdout window. This fleet's
        // fault labelling only began in 2025, so the requested split can swallow EVERY sample and
        // leave nothing to fit on — which silently degrades every band to the base rate and reports a
        // meaningless 0% skill. Fall back to a median split, and say so loudly, because a backtest on
        // three months of history is a weaker claim and the reader has to know that.
        if (count($train) < 0.2 * count($samples)) {
            $dates = array_map(fn ($s) => $s['at']->toDateString(), $samples);
            sort($dates);
            $median  = $dates[intdiv(count($dates), 2)];
            $splitAt = Carbon::parse($median);

            $train = array_values(array_filter($samples, fn ($s) => $s['at'] < $splitAt));
            $test  = array_values(array_filter($samples, fn ($s) => $s['at'] >= $splitAt));

            $this->newLine();
            $this->warn("The requested {$holdout}-month holdout leaves too little history to fit on — this fleet's");
            $this->warn("fault labelling is far younger than that. Falling back to a median split at {$splitAt->toDateString()}.");
            $this->warn('Treat everything below as indicative, not settled: both halves are only months long.');
        }

        $this->newLine();
        $this->line('<comment>── Corpus ──</comment>');
        $this->table(
            ['', 'Samples', 'Recurred within window', 'Base rate'],
            [
                ['Train', count($train), $this->positives($train), $this->pct($this->rate($train))],
                ['Holdout', count($test), $this->positives($test), $this->pct($this->rate($test))],
            ]
        );

        if (count($test) < 30) {
            $this->warn('Holdout is too small to score (' . count($test) . ' samples). Widen --holdout or shorten --horizon.');

            return self::FAILURE;
        }

        $this->reportLiveRule($test);
        $this->reportCalibration($train, $test);

        return self::SUCCESS;
    }

    /**
     * How much history the predictor actually has — which is NOT how many rows the workshop log holds.
     *
     * A repair only becomes evidence once someone wrote down WHAT was wrong. The log carries ~25,500
     * rows back to 2023, but the fault-label columns were only filled in from 2025 onward, so the
     * usable corpus is a fraction of the apparent one. That gap is the single biggest constraint on
     * every predictive claim this platform can make, and it belongs at the top of this report rather
     * than buried — an honest sample window is what stops a thin result being read as a strong one.
     *
     * @param  array<int,array<string,mixed>>  $samples
     */
    private function reportCorpusWindow(array $samples): void
    {
        $labelled = \Illuminate\Support\Facades\DB::table('maintenances')
            ->selectRaw("date_format(out_date,'%Y') y, count(*) total,
                sum(case when coalesce(service_main,'')<>'' or coalesce(service_sup,'')<>'' then 1 else 0 end) labelled")
            ->whereNotNull('out_date')
            ->groupBy('y')
            ->orderBy('y')
            ->get();

        $rows = [];
        foreach ($labelled as $r) {
            $rows[] = [
                $r->y,
                number_format($r->total),
                number_format($r->labelled),
                sprintf('%d%%', round(100 * $r->labelled / max(1, $r->total))),
            ];
        }

        $dates = array_map(fn ($s) => $s['at']->toDateString(), $samples);
        sort($dates);

        $this->newLine();
        $this->line('<comment>── How much history is actually usable ──</comment>');
        $this->table(['Year', 'Workshop rows', 'With a fault label', 'Coverage'], $rows);
        $this->line(sprintf(
            '  Scoreable window: <info>%s → %s</info> · %s samples from %s fault chains.',
            reset($dates) ?: '—',
            end($dates) ?: '—',
            number_format(count($samples)),
            number_format(count(array_unique(array_map(fn ($s) => $s['chain'] ?? '', $samples))) ?: 0)
        ));
    }

    /**
     * Walk every fault chain on a weekly grid, emitting one labelled sample per as-of date.
     *
     * @return array<int,array{at:Carbon,ratio:?float,gaps:int,episodes:int,quiet:int,label:int}>
     */
    private function buildSamples(VehicleFaultRecurrenceService $recurrence, int $horizon, int $step, Carbon $corpusEnd): array
    {
        $vehicles = Vehicle::all();
        $bar      = $this->output->createProgressBar($vehicles->count());
        $bar->start();

        $samples = [];

        foreach ($vehicles as $vehicle) {
            $bar->advance();

            foreach ($recurrence->forVehicle($vehicle)['faults'] as $fault) {
                $episodes = $this->episodeDates($fault['chain'] ?? []);
                if (count($episodes) < 2) {
                    continue;   // a chain needs at least one completed gap to say anything at all
                }

                // Start the grid once the first gap exists — before that there is nothing to predict from.
                $cursor = $episodes[1]['end']->copy();
                $last   = end($episodes)['start'];

                while ($cursor->lte($corpusEnd)) {
                    // Rule 2 — beyond here we cannot observe the outcome, so we must not score it.
                    if ($cursor->copy()->addDays($horizon)->gt($corpusEnd)) {
                        break;
                    }

                    $state = $this->stateAt($episodes, $cursor);
                    if ($state) {
                        $samples[] = $state + [
                            'at'    => $cursor->copy(),
                            'chain' => $vehicle->id . ':' . $fault['key'],
                            'label' => $this->recurredWithin($episodes, $cursor, $horizon),
                        ];
                    }

                    $cursor->addDays($step);
                }

                unset($last);
            }
        }

        $bar->finish();

        return $samples;
    }

    /**
     * The chain's episodes as {start, end} dates, oldest first.
     *
     * @param  array<int,array<string,mixed>>  $chain
     * @return array<int,array{start:Carbon,end:Carbon}>
     */
    private function episodeDates(array $chain): array
    {
        $out = [];
        foreach ($chain as $episode) {
            if (empty($episode['first'])) {
                continue;
            }
            $out[] = [
                'start' => Carbon::parse($episode['first'])->startOfDay(),
                'end'   => Carbon::parse($episode['last'] ?? $episode['first'])->startOfDay(),
            ];
        }

        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /**
     * What was knowable at date D, and nothing more (Rule 1 — no leakage). Returns null when fewer than
     * two episodes had finished by then, because there is no interval to reason about yet.
     *
     * @param  array<int,array{start:Carbon,end:Carbon}>  $episodes
     * @return array{ratio:?float,gaps:int,episodes:int,quiet:int}|null
     */
    private function stateAt(array $episodes, Carbon $at): ?array
    {
        $past = array_values(array_filter($episodes, fn ($e) => $e['end']->lte($at)));
        if (count($past) < 2) {
            return null;
        }

        // Gaps BETWEEN the episodes we can already see — the same quantity avg_gap_days averages.
        $gaps = [];
        for ($i = 1; $i < count($past); $i++) {
            $gaps[] = (int) $past[$i - 1]['end']->diffInDays($past[$i]['start']);
        }

        $avgGap = $gaps ? array_sum($gaps) / count($gaps) : null;
        $quiet  = (int) end($past)['end']->diffInDays($at);

        return [
            'ratio'    => ($avgGap && $avgGap > 0) ? $quiet / $avgGap : null,
            'gaps'     => count($gaps),
            'episodes' => count($past),
            'quiet'    => $quiet,
        ];
    }

    /** Did a new episode START inside (D, D + horizon]? This is the label — the whole ground truth. */
    private function recurredWithin(array $episodes, Carbon $at, int $horizon): int
    {
        $until = $at->copy()->addDays($horizon);

        foreach ($episodes as $episode) {
            if ($episode['start']->gt($at) && $episode['start']->lte($until)) {
                return 1;
            }
        }

        return 0;
    }

    // ── Reporting ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The shipping "Due now" rule, scored as the binary classifier it is. Precision answers the only
     * question an operator cares about: when the badge appears, how often is it right?
     *
     * @param  array<int,array<string,mixed>>  $test
     */
    private function reportLiveRule(array $test): void
    {
        $tp = $fp = $fn = 0;
        foreach ($test as $s) {
            $fires = $s['gaps'] >= self::MIN_GAPS_FOR_INTERVAL
                && $s['ratio'] !== null
                && $s['ratio'] >= self::DUE_SOON_GAP_RATIO
                && $s['ratio'] <= self::PATTERN_LAPSED_MULTIPLE;

            if ($fires && $s['label']) {
                $tp++;
            } elseif ($fires) {
                $fp++;
            } elseif ($s['label']) {
                $fn++;
            }
        }

        $precision = ($tp + $fp) ? $tp / ($tp + $fp) : null;
        $recall    = ($tp + $fn) ? $tp / ($tp + $fn) : null;
        $base      = $this->rate($test);

        $this->newLine();
        $this->line('<comment>── The live "Due now" rule, on the holdout ──</comment>');
        $this->table(
            ['Fires', 'Correct (TP)', 'Wrong (FP)', 'Missed (FN)', 'Precision', 'Recall', 'Lift vs base rate'],
            [[
                $tp + $fp,
                $tp,
                $fp,
                $fn,
                $precision === null ? '—' : $this->pct($precision),
                $recall === null ? '—' : $this->pct($recall),
                ($precision === null || ! $base) ? '—' : sprintf('%.2f×', $precision / $base),
            ]]
        );

        if ($precision !== null && $base) {
            $lift = $precision / $base;
            $this->line($lift >= 1.2
                ? "  <info>The badge carries signal</info> — a flagged fault is {$this->pct($precision)} likely to recur, against a {$this->pct($base)} base rate."
                : "  <error>The badge is close to noise</error> — flagged faults recur at {$this->pct($precision)} versus a {$this->pct($base)} base rate. It is not telling operators much they could not assume.");
        }
    }

    /**
     * Fit an empirical probability per band on TRAIN, score it on HOLDOUT.
     *
     * No model is trained here in any machine-learning sense — each band's "probability" is simply how
     * often faults in that band actually recurred. That is the most explainable predictor available
     * and the honest first version of a confidence score: every number traces to a count.
     *
     * @param  array<int,array<string,mixed>>  $train
     * @param  array<int,array<string,mixed>>  $test
     */
    private function reportCalibration(array $train, array $test): void
    {
        $base = $this->rate($train);

        $rows        = [];
        $brierModel  = 0.0;
        $brierBase   = 0.0;
        $scored      = 0;

        foreach (self::RATIO_BUCKETS as $bucket) {
            $inTrain = $this->inBucket($train, $bucket);
            $inTest  = $this->inBucket($test, $bucket);

            // An unfitted band falls back to the base rate rather than inventing a number for it.
            $predicted = $inTrain ? $this->rate($inTrain) : $base;
            $observed  = $inTest ? $this->rate($inTest) : null;

            foreach ($inTest as $s) {
                $brierModel += ($predicted - $s['label']) ** 2;
                $brierBase  += ($base - $s['label']) ** 2;
                $scored++;
            }

            $rows[] = [
                $bucket['key'],
                count($inTrain),
                $inTrain ? $this->pct($predicted) : '(base)',
                count($inTest),
                $observed === null ? '—' : $this->pct($observed),
                $observed === null ? '—' : sprintf('%+.1f pt', ($observed - $predicted) * 100),
            ];
        }

        $this->newLine();
        $this->line('<comment>── Is a percentage earnable? Predicted (from train) vs observed (on holdout) ──</comment>');
        $this->table(
            ['Through its own interval', 'Train n', 'Predicted', 'Holdout n', 'Observed', 'Error'],
            $rows
        );

        if (! $scored) {
            $this->warn('Nothing scoreable in the holdout.');

            return;
        }

        $brierModel /= $scored;
        $brierBase  /= $scored;
        $skill = $brierBase > 0 ? 1 - ($brierModel / $brierBase) : 0.0;

        $this->line(sprintf(
            '  Brier: <info>%.4f</info> (band model) vs <info>%.4f</info> (base rate only) — skill score <info>%+.1f%%</info>',
            $brierModel,
            $brierBase,
            $skill * 100
        ));
        $this->newLine();

        // The verdict, stated in the terms E15 §② is written in.
        if ($skill >= 0.05) {
            $this->info('VERDICT: the bands beat the base rate. A calibrated number is earnable — publish this Brier reading alongside it, and show the band where a row\'s error is large.');
        } elseif ($skill > -0.01) {
            $this->warn('VERDICT: the bands barely beat guessing the base rate. Ship BANDS, not a decimal — a percentage here would look precise and carry no more information than "faults recur about ' . $this->pct($this->rate($test)) . ' of the time".');
        } else {
            $this->error('VERDICT: the bands are WORSE than the base rate on unseen data. Do not publish a probability. The historical story ("returned 3×, usually every ~47 days") is what this data supports.');
        }

        $this->newLine();
        $this->line('<comment>Reading these numbers:</comment>');
        $this->line('  · Samples are weekly snapshots of the same chains, so they are heavily autocorrelated —');
        $this->line('    the EFFECTIVE sample size is nearer the chain count than the row count, and every');
        $this->line('    interval above is therefore wider than the raw n suggests. This cuts against reading');
        $this->line('    a weak positive as a real one; it does not rescue a negative.');
        $this->line('  · A base rate that moves between the two halves means the target is non-stationary:');
        $this->line('    a calibration fitted once would drift out of date on its own. Re-run this before');
        $this->line('    trusting any published probability, and treat it as the gate, not a one-off.');
    }

    // ── Small helpers ──────────────────────────────────────────────────────────────────────────────

    /** @param array<int,array<string,mixed>> $rows */
    private function inBucket(array $rows, array $bucket): array
    {
        return array_values(array_filter(
            $rows,
            fn ($s) => $s['ratio'] !== null
                && $s['gaps'] >= self::MIN_GAPS_FOR_INTERVAL
                && $s['ratio'] >= $bucket['lo']
                && $s['ratio'] < $bucket['hi']
        ));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rate(array $rows): float
    {
        return $rows ? $this->positives($rows) / count($rows) : 0.0;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function positives(array $rows): int
    {
        return (int) array_sum(array_column($rows, 'label'));
    }

    private function pct(float $v): string
    {
        return sprintf('%.1f%%', $v * 100);
    }
}
