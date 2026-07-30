<?php

namespace App\Console\Commands;

use App\Models\FindingKeyword;
use App\Models\KeywordTerm;
use App\Services\KeywordOntologyService;
use Illuminate\Console\Command;

/**
 * Quality guard: finds concepts that overlap, duplicate each other, or cannot be told apart.
 *
 * An ontology that only ever grows eventually gets worse. Two concepts describing the same fault
 * split the vocabulary between them, so BOTH match weakly and neither wins — the matcher gets less
 * accurate as the dictionary gets bigger, which is the opposite of the intended effect. Consolidation
 * has to be a routine operation, not a rescue mission.
 *
 * Four checks, in descending order of severity:
 *
 *   SELF-MATCH FAILURE   a concept whose own name matches a DIFFERENT concept better. This is
 *                        unambiguously broken: the concept is unreachable by its own name.
 *   SHARED TERMS         two concepts owning the same surface form. Whichever wins is arbitrary.
 *   HIGH OVERLAP         a large share of one concept's vocabulary also belongs to another.
 *   NEAR-IDENTICAL NAMES normalised names differing only trivially.
 *
 * NOTHING IS MERGED AUTOMATICALLY. Every finding is a recommendation for a person. "Brake noise" and
 * "Worn pads / discs" overlap heavily and are still genuinely different concepts — one is a symptom
 * a driver reports, the other a condition an inspector measures — and an automatic merge would
 * destroy a distinction the workshop depends on. The tool finds candidates; a human decides.
 */
class OntologyDuplicates extends Command
{
    protected $signature = 'ontology:duplicates
                            {--overlap=40 : Report pairs sharing at least this % of the smaller concept\'s vocabulary}
                            {--limit=20 : Maximum pairs to list per section}';

    protected $description = 'Detect duplicate, overlapping or indistinguishable fault concepts';

    public function handle(KeywordOntologyService $matcher): int
    {
        $concepts = FindingKeyword::query()->where('is_active', true)->get()->keyBy('id');

        $terms = KeywordTerm::query()
            ->where('is_active', true)
            ->get(['finding_keyword_id', 'normalized', 'term']);

        $byConcept = $terms->groupBy('finding_keyword_id')
            ->map(fn ($rows) => $rows->pluck('normalized')->unique()->values()->all());

        $issues = 0;
        $issues += $this->reportSelfMatchFailures($concepts, $matcher);
        $issues += $this->reportSharedTerms($concepts, $terms);
        $issues += $this->reportOverlap($concepts, $byConcept);
        $issues += $this->reportSimilarNames($concepts);

        $this->newLine();

        if ($issues === 0) {
            $this->info('No duplicate or overlapping concepts detected.');
        } else {
            $this->warn("{$issues} consolidation candidate(s). Each needs a human decision — nothing was merged.");
        }

        return self::SUCCESS;
    }

    /**
     * The worst failure mode: a concept that does not win on its own name.
     *
     * It means the concept cannot be found the way it is written down, which usually indicates a
     * near-duplicate has absorbed its vocabulary.
     */
    private function reportSelfMatchFailures($concepts, KeywordOntologyService $matcher): int
    {
        $rows = [];

        foreach ($concepts as $concept) {
            $best = $matcher->resolve($concept->keyword, ['limit' => 1])->first();

            if (! $best) {
                $rows[] = [$concept->keyword, '(no match at all)', '—'];

                continue;
            }

            if ($best['keyword']->id !== $concept->id) {
                $rows[] = [$concept->keyword, $best['keyword']->keyword, $best['score']];
            }
        }

        if ($rows === []) {
            return 0;
        }

        $this->newLine();
        $this->error('SELF-MATCH FAILURE — these concepts are unreachable by their own name');
        $this->table(['Concept', 'Matched instead', 'Score'], array_slice($rows, 0, (int) $this->option('limit')));

        return count($rows);
    }

    /** The same surface form owned by two concepts — whichever wins is arbitrary. */
    private function reportSharedTerms($concepts, $terms): int
    {
        $owners = [];

        foreach ($terms as $t) {
            $owners[$t->normalized][$t->finding_keyword_id] = $t->term;
        }

        $rows = [];

        foreach ($owners as $normalized => $map) {
            if (count($map) < 2) {
                continue;
            }

            $names = collect(array_keys($map))
                ->map(fn ($id) => $concepts->get($id)?->keyword)
                ->filter()
                ->implode('  ⇄  ');

            $rows[] = [reset($map), $names];
        }

        if ($rows === []) {
            return 0;
        }

        $this->newLine();
        $this->warn('SHARED TERMS — one wording claimed by several concepts');
        $this->table(['Term', 'Claimed by'], array_slice($rows, 0, (int) $this->option('limit')));

        return count($rows);
    }

    /**
     * Vocabulary overlap, measured against the SMALLER concept.
     *
     * Using the smaller side is deliberate: a small concept fully contained inside a large one is
     * the real duplicate risk, and dividing by the larger side would hide exactly that case.
     */
    private function reportOverlap($concepts, $byConcept): int
    {
        $threshold = (int) $this->option('overlap');
        $ids = $byConcept->keys()->all();
        $rows = [];

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $termsA = $byConcept[$a];
                $termsB = $byConcept[$b];

                if ($termsA === [] || $termsB === []) {
                    continue;
                }

                $shared = count(array_intersect($termsA, $termsB));

                if ($shared === 0) {
                    continue;
                }

                $pct = (int) round($shared / min(count($termsA), count($termsB)) * 100);

                if ($pct < $threshold) {
                    continue;
                }

                $rows[] = [
                    $concepts->get($a)?->keyword ?? $a,
                    $concepts->get($b)?->keyword ?? $b,
                    $shared,
                    $pct.'%',
                ];
            }
        }

        if ($rows === []) {
            return 0;
        }

        usort($rows, fn ($x, $y) => (int) $y[3] <=> (int) $x[3]);

        $this->newLine();
        $this->warn("VOCABULARY OVERLAP — at least {$threshold}% of the smaller concept's wording is shared");
        $this->table(['Concept A', 'Concept B', 'Shared terms', 'Overlap'],
            array_slice($rows, 0, (int) $this->option('limit')));

        return count($rows);
    }

    /** Names that differ only trivially once normalised. */
    private function reportSimilarNames($concepts): int
    {
        $rows = [];
        $list = $concepts->values()->all();

        foreach ($list as $i => $a) {
            foreach (array_slice($list, $i + 1) as $b) {
                $ka = \App\Support\TextNormalizer::key($a->keyword);
                $kb = \App\Support\TextNormalizer::key($b->keyword);

                if ($ka === '' || $kb === '') {
                    continue;
                }

                similar_text($ka, $kb, $percent);

                if ($percent >= 85) {
                    $rows[] = [$a->keyword, $b->keyword, round($percent).'%'];
                }
            }
        }

        if ($rows === []) {
            return 0;
        }

        $this->newLine();
        $this->warn('NEAR-IDENTICAL NAMES');
        $this->table(['Concept A', 'Concept B', 'Similarity'],
            array_slice($rows, 0, (int) $this->option('limit')));

        return count($rows);
    }
}
