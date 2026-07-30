<?php

namespace App\Console\Commands;

use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The ontology's real success metric: how much of what people ACTUALLY WRITE does it already
 * understand?
 *
 * Concept and term counts measure effort, not coverage. The number that matters is the share of real
 * fleet text the matcher resolves without anyone having to invent a phrase — so this runs the live
 * matcher over the historical corpus and reports exactly that, plus the vocabulary that would close
 * the biggest gaps.
 *
 * NOISE IS SEPARATED, NOT COUNTED AS FAILURE. Roughly a third of `maintenance_notes` are workflow
 * status ("car is ready", "waiting parts", "invoice sent"), not fault descriptions. Counting those as
 * misses would understate coverage badly and, worse, would send enrichment off to learn vocabulary
 * for "car is ready". They are classified out and reported separately so the coverage figure means
 * what it claims to.
 *
 * THE MISS LIST IS THE ROADMAP. The unmatched phrases, ranked by how often they occur, are the
 * highest-value vocabulary to add next — derived from what this fleet actually writes rather than
 * from a guess about what mechanics might say.
 */
class OntologyCoverage extends Command
{
    protected $signature = 'ontology:coverage
                            {--limit=4000 : How many records to sample}
                            {--source=notes : notes | complaints | services | all}
                            {--misses=25 : How many unmatched phrases to list}
                            {--strong=70 : Score at or above which a match counts as confident}';

    protected $description = 'Measure what share of real fleet text the ontology already understands';

    /**
     * Workflow chatter, not fault descriptions.
     *
     * Deliberately conservative — only phrases that are unambiguously status. Anything that could be
     * describing a fault stays in the measured population, because flattering the coverage number is
     * the one failure mode that makes this whole exercise pointless.
     */
    private const STATUS_MARKERS = [
        'car is ready', 'ready', 'car ready', 'vehicle ready', 'done', 'completed', 'finished',
        'waiting parts', 'waiting for parts', 'waiting approval', 'under repair', 'in progress',
        'invoice', 'quotation', 'quote sent', 'no cost', 'delivered', 'picked up', 'collected',
        'car delivered', 'sent to garage', 'received', 'checked ok', 'ok',
    ];

    /**
     * Notes that BEGIN with one of these are workflow status, whatever follows.
     *
     * Exact whole-note matching was not enough: the first measurement counted "car is ready 0",
     * "car is reeady", "car is ready in parking" and "car is under test by abo marof" as vocabulary
     * failures, which pushed the coverage figure down for reasons that have nothing to do with the
     * ontology — and would have sent enrichment off to learn synonyms for "car is ready".
     *
     * Prefix rather than substring, deliberately. "in parking still has windshield issue" opens as
     * status but reports a real fault, so a substring rule would have discarded a genuine miss.
     * Anything ambiguous stays in the measured population.
     */
    private const STATUS_PREFIXES = [
        'car is ready', 'car ready', 'car is reeady', 'car is in parking', 'car in parking',
        'car is under test', 'under test by', 'car is with', 'car is at', 'car delivered',
        'car is delivered', 'still under', 'waiting', 'car is waiting', 'in parking for',
        'in parking fo', 'car is ready in parking',
    ];

    public function handle(KeywordOntologyService $matcher): int
    {
        $limit  = max(1, (int) $this->option('limit'));
        $strong = (int) $this->option('strong');

        $rows = $this->corpus($this->option('source'), $limit);

        if ($rows->isEmpty()) {
            $this->error('No text found for that source.');

            return self::FAILURE;
        }

        $buckets = ['strong' => 0, 'weak' => 0, 'none' => 0, 'status' => 0];
        $misses  = [];

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $text) {
            $bar->advance();

            $clean = trim(preg_replace('/\s+/u', ' ', (string) $text));

            if ($clean === '') {
                continue;
            }

            if ($this->isStatusNote($clean)) {
                $buckets['status']++;

                continue;
            }

            $best = $matcher->resolve($clean, ['limit' => 1])->first();

            if (! $best) {
                $buckets['none']++;
                $this->collectMiss($clean, $misses);

                continue;
            }

            if ($best['score'] >= $strong) {
                $buckets['strong']++;
            } else {
                $buckets['weak']++;
                // A weak match is a near-miss: the right concept may be there but the wording is
                // not, which is exactly the vocabulary worth adding.
                $this->collectMiss($clean, $misses);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->report($buckets, $misses, (int) $this->option('misses'));

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,string> */
    private function corpus(string $source, int $limit)
    {
        $q = match ($source) {
            'complaints' => DB::table('maintenances')->whereNotNull('customer_complaint')
                ->where('customer_complaint', '<>', '')->select('customer_complaint as t'),
            'services'   => DB::table('maintenances')->whereNotNull('service_main')
                ->where('service_main', '<>', '')->select('service_main as t'),
            'all'        => DB::table('maintenances')
                ->selectRaw("TRIM(CONCAT_WS(' ', COALESCE(customer_complaint,''), COALESCE(service_main,''), COALESCE(maintenance_notes,''))) as t")
                ->havingRaw("t <> ''"),
            default      => DB::table('maintenances')->whereNotNull('maintenance_notes')
                ->where('maintenance_notes', '<>', '')->select('maintenance_notes as t'),
        };

        return $q->inRandomOrder()->limit($limit)->pluck('t');
    }

    private function isStatusNote(string $text): bool
    {
        $key = TextNormalizer::key($text);

        if ($key === '') {
            return true;
        }

        foreach (self::STATUS_MARKERS as $marker) {
            // Whole-note match. A note that merely CONTAINS "ready" ("ac not ready to cool") is
            // still a fault description, and excluding it would inflate the coverage number.
            if ($key === TextNormalizer::key($marker)) {
                return true;
            }
        }

        foreach (self::STATUS_PREFIXES as $prefix) {
            $p = TextNormalizer::key($prefix);

            if ($p !== '' && str_starts_with($key, $p)) {
                // ...unless the note goes on to describe an actual fault. "in parking still has
                // windshield issue" opens as status and ends as a complaint, and the complaint is
                // the part that matters.
                $remainder = trim(mb_substr($key, mb_strlen($p)));

                if ($remainder === '' || mb_strlen($remainder) < 6) {
                    return true;
                }

                // "car is under test BY ABO MAROF" is still pure status — the remainder only names
                // who has the car. The length cutoff alone let these through and they dominated the
                // unmatched list, which would have sent the next round of vocabulary work at
                // people's names. A remainder that begins with a preposition introduces a person or
                // a place, never a fault.
                if (preg_match('/^(by|with|at|in|to|for|from)\s/u', $remainder)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Group misses by their normalised text so the same unmatched wording, written fifty times,
     * appears once with a count rather than fifty times in the list.
     *
     * @param  array<string,array{text:string,count:int}>  $misses
     */
    private function collectMiss(string $text, array &$misses): void
    {
        // Long notes are several statements at once and are not useful as a vocabulary suggestion;
        // the actionable misses are the short, repeated phrases.
        if (mb_strlen($text) > 60) {
            return;
        }

        $key = TextNormalizer::key($text);

        if ($key === '') {
            return;
        }

        $misses[$key]['text'] = $misses[$key]['text'] ?? $text;
        $misses[$key]['count'] = ($misses[$key]['count'] ?? 0) + 1;
    }

    /** @param array<string,array{text:string,count:int}> $misses */
    private function report(array $buckets, array $misses, int $show): void
    {
        $measured = $buckets['strong'] + $buckets['weak'] + $buckets['none'];

        if ($measured === 0) {
            $this->warn('Every sampled record was workflow status — nothing to measure.');

            return;
        }

        $pct = fn (int $n) => number_format($n / $measured * 100, 1).'%';

        $this->info('COVERAGE — share of real fleet text the ontology understands');
        $this->table(
            ['Outcome', 'Records', 'Share'],
            [
                ['Confident match', $buckets['strong'], $pct($buckets['strong'])],
                ['Weak match (concept found, wording missing)', $buckets['weak'], $pct($buckets['weak'])],
                ['No match', $buckets['none'], $pct($buckets['none'])],
            ],
        );

        $this->line(sprintf(
            '  %s of fault text resolves confidently · %s excluded as workflow status (not fault descriptions)',
            $pct($buckets['strong']),
            number_format($buckets['status']),
        ));

        // The success metric the owner actually set: how rarely someone must invent a phrase.
        $needsHuman = $buckets['none'] + $buckets['weak'];
        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>A phrase would need inventing for %s of records.</> Target: near zero.',
            $pct($needsHuman),
        ));

        if ($misses === []) {
            return;
        }

        uasort($misses, fn ($a, $b) => $b['count'] <=> $a['count']);

        $this->newLine();
        $this->info('HIGHEST-VALUE VOCABULARY TO ADD NEXT — real unmatched wording, by frequency');
        $this->table(
            ['Occurrences', 'Phrase the ontology does not understand'],
            collect($misses)->take($show)->map(fn ($m) => [$m['count'], $m['text']])->values()->all(),
        );
    }
}
