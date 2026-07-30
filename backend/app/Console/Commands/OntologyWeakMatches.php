<?php

namespace App\Console\Commands;

use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns the weak-match bucket into a targeted work list.
 *
 * Weak matches are now the largest bucket (31.6%) and the cheapest to fix: the RIGHT concept is
 * already being found, just not confidently, so the answer is vocabulary depth on a concept that
 * exists — not another concept. Adding concepts to fix weak matches makes things worse, because two
 * concepts then split the same vocabulary and both match weakly.
 *
 * The coverage command says HOW MUCH is weak. This says WHICH CONCEPTS are weak and WHAT PEOPLE
 * WROTE, which is the difference between knowing there is a problem and being able to fix it.
 *
 * Output is grouped by concept and ranked by how many real records hit it weakly, so the first row
 * is always the single highest-value vocabulary edit available.
 */
class OntologyWeakMatches extends Command
{
    protected $signature = 'ontology:weak-matches
                            {--limit=4000 : Records to sample}
                            {--strong=70 : Score at or above which a match is confident}
                            {--concepts=15 : How many concepts to report}
                            {--phrases=6 : Example phrases to show per concept}';

    protected $description = 'Show which concepts match weakly, and the real wording that fell short';

    /** Workflow chatter — same exclusions as ontology:coverage, for the same reason. */
    private const STATUS_PREFIXES = [
        'car is ready', 'car ready', 'car is reeady', 'car is reday', 'car is in parking', 'car in parking',
        'car is under test', 'under test by', 'under test with', 'car is with', 'car is at',
        'car delivered', 'car is delivered', 'still under', 'waiting', 'car is waiting',
        'in parking for', 'in parking fo', 'parts are at', 'will be under test',
    ];

    public function handle(KeywordOntologyService $matcher): int
    {
        $strong = (int) $this->option('strong');

        $rows = DB::table('maintenances')
            ->whereNotNull('maintenance_notes')
            ->where('maintenance_notes', '<>', '')
            ->inRandomOrder()
            ->limit((int) $this->option('limit'))
            ->pluck('maintenance_notes');

        /** @var array<int,array{concept:string,count:int,phrases:array<string,int>,scores:array<int,int>}> $weak */
        $weak = [];

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $text) {
            $bar->advance();

            $clean = trim(preg_replace('/\s+/u', ' ', (string) $text));

            if ($clean === '' || $this->isStatus($clean) || mb_strlen($clean) > 80) {
                continue;
            }

            $best = $matcher->resolve($clean, ['limit' => 1])->first();

            if (! $best || $best['score'] >= $strong) {
                continue;
            }

            $id = $best['keyword']->id;
            $weak[$id]['concept'] = $best['keyword']->keyword;
            $weak[$id]['count']   = ($weak[$id]['count'] ?? 0) + 1;
            $weak[$id]['scores'][] = $best['score'];

            // Grouped by normalised text so one wording written twenty times is one suggestion.
            $key = TextNormalizer::key($clean);
            $weak[$id]['phrases'][$key] = ($weak[$id]['phrases'][$key] ?? 0) + 1;
            $weak[$id]['originals'][$key] = $clean;
        }

        $bar->finish();
        $this->newLine(2);

        if ($weak === []) {
            $this->info('No weak matches in this sample.');

            return self::SUCCESS;
        }

        uasort($weak, fn ($a, $b) => $b['count'] <=> $a['count']);

        $this->info('WEAK MATCHES BY CONCEPT — the right concept was found, the wording was not');
        $this->line('  <fg=gray>Fix by adding these phrasings to the concept that already exists. Do NOT add a new concept.</>');

        $shown = 0;

        foreach ($weak as $entry) {
            if (++$shown > (int) $this->option('concepts')) {
                break;
            }

            $avg = (int) round(array_sum($entry['scores']) / count($entry['scores']));

            $this->newLine();
            $this->line(sprintf('  <options=bold>%s</>  <fg=gray>— %d record(s), average score %d</>',
                $entry['concept'], $entry['count'], $avg));

            arsort($entry['phrases']);

            $i = 0;
            foreach ($entry['phrases'] as $key => $count) {
                if (++$i > (int) $this->option('phrases')) {
                    break;
                }

                $this->line(sprintf('     %s×  %s', str_pad((string) $count, 2, ' ', STR_PAD_LEFT),
                    $entry['originals'][$key]));
            }
        }

        $this->newLine();
        $this->line(sprintf('  %d concept(s) matching weakly across %d record(s).',
            count($weak), array_sum(array_column($weak, 'count'))));

        return self::SUCCESS;
    }

    private function isStatus(string $text): bool
    {
        $key = TextNormalizer::key($text);

        foreach (self::STATUS_PREFIXES as $prefix) {
            $p = TextNormalizer::key($prefix);

            if ($p !== '' && str_starts_with($key, $p)) {
                $remainder = trim(mb_substr($key, mb_strlen($p)));

                if ($remainder === '' || mb_strlen($remainder) < 6) {
                    return true;
                }
            }
        }

        return false;
    }
}
