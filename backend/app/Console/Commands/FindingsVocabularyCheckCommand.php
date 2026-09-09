<?php

namespace App\Console\Commands;

use App\Models\FindingKeyword;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;

/**
 * findings:vocabulary-check — prove the inspector's menu and the engine's vocabulary are one language.
 *
 * THE RULE THIS ENFORCES. The findings catalog (config/maintenance_findings.php) defines what an
 * inspector can SELECT; the fault ontology (database/seeders/ontology/*.php) defines what the engine
 * can UNDERSTAND. The ontology is deliberately the wider of the two — it has to recognise causes and
 * garage diagnoses, not just the symptoms a driver reports. What it must never be is *disjoint*.
 *
 * WHY THIS COMMAND EXISTS. FaultOntologySeeder binds a concept to its FindingKeyword row by
 * TextNormalizer::key($name) and CREATES the row when none matches. That is the right behaviour and
 * it fails silently in two directions:
 *
 *   1. A concept the catalog never lists still gets a row, so `/finding-keywords/resolve` can propose
 *      a fault the picker cannot offer. The inspector reads a confident answer and has nowhere to tap.
 *      34 concepts were in this state before this check existed.
 *
 *   2. A one-letter spelling difference forks one fault into two rows. 'Tire Rotation' (catalog) and
 *      'Tyre rotation' (ontology) keyed differently — the selectable row carried no vocabulary and the
 *      enriched row was unreachable. Nothing errored; the fault simply stopped being findable.
 *
 * Neither failure raises an exception, appears in a log, or breaks a test. They surface as "the AI
 * gave a weird answer", months later, from someone who will not report it. So the invariant is
 * asserted here instead, and asserted as a FAILURE rather than a warning.
 *
 * THE INVARIANT, in both directions:
 *   · every selectable catalog keyword resolves to exactly one ontology concept — it has words
 *   · every finding_keywords row is either selectable, or declared `understanding_only` — no dead ends
 *
 * READ-ONLY by default, like [[components:verify]]. `--prune` is the one exception and it only
 * DEACTIVATES: an orphan row may already carry human feedback and hand-written terms, and deleting it
 * would destroy the corrections that explain why it exists.
 *
 *   php artisan findings:vocabulary-check           # assert both directions
 *   php artisan findings:vocabulary-check --prune   # deactivate orphaned rows (reversible)
 */
class FindingsVocabularyCheckCommand extends Command
{
    protected $signature = 'findings:vocabulary-check
        {--prune : Deactivate finding_keywords rows that are neither selectable nor declared understanding-only}';

    protected $description = 'Verify the findings catalog and the fault ontology share one vocabulary';

    public function handle(): int
    {
        $catalog       = $this->normalisedCatalog();
        $understanding = $this->normalisedUnderstandingOnly();
        $ontology      = $this->normalisedOntology();

        $this->line(sprintf(
            'Vocabulary: %d selectable · %d understanding-only · %d ontology concepts',
            count($catalog), count($understanding), count($ontology),
        ));

        $failures = 0;

        // ── Contradiction: a keyword cannot be both offered and withheld ──────────────────────────
        // Checked first because it makes the other two results meaningless — a keyword in both lists
        // would satisfy either check while the config says two opposite things about it.
        $contradictions = array_intersect_key($catalog, $understanding);

        if ($contradictions !== []) {
            $failures++;
            $this->newLine();
            $this->error('Declared BOTH selectable and understanding-only — the config contradicts itself:');
            foreach ($contradictions as $label) {
                $this->line("  · {$label}");
            }
            $this->line('  Fix: remove it from `understanding_only`, or from its category `keywords`.');
        }

        // ── Direction 1: every selectable keyword has vocabulary ──────────────────────────────────
        // A keyword here with no ontology concept is a chip the inspector can tap that the matcher
        // cannot recognise in a sentence: it matches its own name and nothing else.
        $voiceless = array_diff_key($catalog, $ontology);

        if ($voiceless !== []) {
            $failures++;
            $this->newLine();
            $this->error('Selectable, but no ontology concept describes it (matches its own name only):');
            foreach ($voiceless as $key => $label) {
                $this->line("  · {$label}   [{$key}]");
                if ($near = $this->nearest($key, array_keys($ontology))) {
                    // Almost always a spelling fork rather than a genuinely missing concept, so name
                    // the near-miss — that is the Tire/Tyre bug, and it reads as "missing" without this.
                    $this->line("      did you mean the ontology's \"{$ontology[$near]}\"? (spelling fork)");
                }
            }
            $this->line('  Fix: add a concept to database/seeders/ontology/, spelled as the catalog spells it.');
        }

        // ── Direction 1b: the two sides must agree on WHICH category ──────────────────────────────
        // Not cosmetic, and not merely a display concern. FindingKeywordSeeder keys its rows on
        // (category_key, keyword) while FaultOntologySeeder binds by name alone and then OVERWRITES
        // category_key. When the two disagree the first seeder creates a second row under its own
        // category and the second seeder tries to drag it back — which lands on the
        // finding_keywords_category_keyword_unique index as a raw PDOException mid-seed, naming a
        // constraint rather than the disagreement that caused it.
        //
        // The category also carries the `on_site` repair-location flag, so a concept filed on the wrong
        // side of it changes whether the job is offered as a mobile repair. Worth failing over.
        $misfiled = [];

        foreach ($this->ontologyCategories() as $key => $ontologyCategory) {
            if (! isset($catalog[$key])) {
                continue;   // understanding-only or absent — Direction 2 owns that case
            }

            $catalogCategory = $this->catalogCategoryOf($key);

            if ($catalogCategory !== null && $catalogCategory !== $ontologyCategory) {
                $misfiled[] = [$catalog[$key], $catalogCategory, $ontologyCategory];
            }
        }

        if ($misfiled !== []) {
            $failures++;
            $this->newLine();
            $this->error('Filed under different categories by the catalog and the ontology (seeding will collide):');
            foreach ($misfiled as [$label, $catalogCategory, $ontologyCategory]) {
                $this->line("  · {$label}   catalog=<comment>{$catalogCategory}</comment>  ontology=<comment>{$ontologyCategory}</comment>");
            }
            $this->line('  Fix: pick one. Check `on_site` on both categories before deciding — it decides');
            $this->line('       whether the fault can be offered as a mobile repair.');
        }

        // ── Direction 2: every row is reachable ───────────────────────────────────────────────────
        // Read from the DATABASE, not the seeder files: the rows are what `resolve` actually searches,
        // and a concept deleted from a seeder leaves its row behind. That orphan is still matchable.
        $orphans = FindingKeyword::query()
            ->where('is_active', true)
            ->get(['id', 'keyword', 'category_key'])
            ->reject(fn (FindingKeyword $k) => isset($catalog[TextNormalizer::key($k->keyword)])
                || isset($understanding[TextNormalizer::key($k->keyword)]));

        if ($orphans->isNotEmpty()) {
            $failures++;
            $this->newLine();
            $this->error('Matchable, but the inspector cannot select it (the AI can propose a dead end):');
            foreach ($orphans as $row) {
                $this->line("  · {$row->keyword}   [#{$row->id} · {$row->category_key}]");
            }
            $this->line('  Fix: add it to a category in config/maintenance_findings.php so it can be picked,');
            $this->line('       or list it under `understanding_only` if it is a garage diagnosis, not an observation.');

            if ($this->option('prune')) {
                $this->newLine();
                foreach ($orphans as $row) {
                    FindingKeyword::whereKey($row->id)->update(['is_active' => false]);
                }
                $this->warn("Deactivated {$orphans->count()} orphaned keyword(s). Terms and feedback are kept — set is_active=1 to restore.");
                $failures--;   // handled, not ignored
            }
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error('Vocabulary check FAILED — the picker and the matcher disagree about what a fault is.');

            return self::FAILURE;
        }

        $this->info('Vocabulary check passed: every selectable fault has words, every known fault has a home.');

        return self::SUCCESS;
    }

    /** @return array<string,string> normalised key → catalog label */
    private function normalisedCatalog(): array
    {
        return app(\App\Services\SelectableFindings::class)->keywords();
    }

    /**
     * Which catalog category a selectable keyword sits in.
     *
     * @return string|null the category key, or null when the keyword is not selectable
     */
    private function catalogCategoryOf(string $normalisedKeyword): ?string
    {
        return app(\App\Services\SelectableFindings::class)->categoryOf($normalisedKeyword);
    }

    /** @return array<string,string> normalised concept name → the category the ontology declares */
    private function ontologyCategories(): array
    {
        return \App\Support\OntologyConcepts::categories();
    }

    /** @return array<string,string> normalised key → declared label */
    private function normalisedUnderstandingOnly(): array
    {
        $out = [];

        foreach ((array) config('maintenance_findings.understanding_only', []) as $keyword) {
            $out[TextNormalizer::key($keyword)] = $keyword;
        }

        return $out;
    }

    /**
     * Read from the SEEDER FILES, not the database.
     *
     * The question this direction asks is "did anyone write words for this fault", and the seeders are
     * where those words are authored and reviewed. Reading the DB instead would pass on a machine where
     * the seeder had been run once and the file has since lost the concept.
     *
     * @return array<string,string> normalised key → concept name
     */
    private function normalisedOntology(): array
    {
        return \App\Support\OntologyConcepts::names();
    }

    /**
     * Closest known concept to a missing one, or null when nothing is close.
     *
     * Threshold is deliberately tight: this exists to catch tyre/tire and colour/color forks, and a
     * loose match would confidently point at an unrelated fault, which is worse than staying quiet.
     *
     * @param  array<int,string>  $candidates
     */
    private function nearest(string $key, array $candidates): ?string
    {
        $best     = null;
        $bestDist = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($key, $candidate);

            if ($distance < $bestDist) {
                $bestDist = $distance;
                $best     = $candidate;
            }
        }

        return $bestDist <= max(2, (int) floor(mb_strlen($key) * 0.15)) ? $best : null;
    }
}
