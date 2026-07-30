<?php

namespace App\Console\Commands;

use App\Models\FindingKeyword;
use App\Models\KeywordTerm;
use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes the source files the source of truth, not just an additive feed.
 *
 * THE PROBLEM THIS FIXES. The seeder upserts and never removes, so editing a category file leaves
 * the old wording behind in the database. That is not a tidiness issue — it actively breaks
 * matching. When "clutch not engaging" was qualified to "ac compressor clutch not engaging", the
 * original stayed, still claimed by two concepts, and whichever won a query was arbitrary. The
 * ontology had quietly stopped being what the files said it was.
 *
 * REPORT FIRST, DELETE ONLY WHEN ASKED. Runs read-only by default. Deletion needs `--prune`, because
 * a term that vanished from a file might be a deliberate removal or might be someone's editing
 * mistake, and those look identical from here.
 *
 * HUMAN-OWNED RECORDS ARE NEVER ORPHANS. A term an admin wrote is not in any source file and never
 * will be — treating that as drift would delete exactly the curation the platform is meant to
 * protect. Only `source = seed` rows are ever considered.
 *
 * CONCEPTS ARE REPORTED BUT NEVER AUTO-PRUNED, whatever the flags. A concept is referenced by
 * tickets, findings, graph edges and history; removing one is a data-migration decision, not a
 * cleanup. The command says which look abandoned and stops there.
 */
class OntologyReconcile extends Command
{
    protected $signature = 'ontology:reconcile
                            {--prune : Delete orphaned TERMS and ACTION LINKS (never concepts)}
                            {--limit=30 : How many orphans to list per section}';

    protected $description = 'Compare the ontology in the database against the source files and report drift';

    private const CATEGORIES = [
        'engine', 'cooling', 'transmission', 'brakes', 'steering', 'suspension',
        'tyres', 'electrical', 'hvac', 'body', 'interior', 'lights', 'safety', 'fluids',
    ];

    public function handle(): int
    {
        $expected = $this->loadSource();

        if ($expected['concepts'] === []) {
            $this->error('No source files found — refusing to report everything as drift.');

            return self::FAILURE;
        }

        $this->line(sprintf('  Source declares %d concept(s), %d term(s), %d action link(s).',
            count($expected['concepts']), count($expected['terms']), count($expected['actions'])));

        $orphanTerms   = $this->findOrphanTerms($expected);
        $orphanActions = $this->findOrphanActions($expected);
        $orphanConcepts = $this->findOrphanConcepts($expected);

        $this->reportTerms($orphanTerms);
        $this->reportActions($orphanActions);
        $this->reportConcepts($orphanConcepts);

        $total = $orphanTerms->count() + $orphanActions->count();

        if ($total === 0) {
            $this->newLine();
            $this->info('Database matches the source files. No drift.');

            return self::SUCCESS;
        }

        if (! $this->option('prune')) {
            $this->newLine();
            $this->warn("{$total} orphaned record(s). Nothing was deleted — re-run with --prune to remove them.");

            return self::SUCCESS;
        }

        $this->prune($orphanTerms, $orphanActions);

        return self::SUCCESS;
    }

    /**
     * Everything the category files currently declare.
     *
     * @return array{concepts:array<string,string>,terms:array<string,true>,actions:array<string,true>}
     */
    private function loadSource(): array
    {
        $concepts = [];
        $terms = [];
        $actions = [];

        foreach (self::CATEGORIES as $file) {
            $path = database_path("seeders/ontology/{$file}.php");

            if (! is_file($path)) {
                continue;
            }

            foreach (require $path as $c) {
                $conceptKey = TextNormalizer::key($c['name']);
                $concepts[$conceptKey] = $c['name'];

                // The canonical name is a term in its own right.
                $terms[$conceptKey.'|'.$conceptKey] = true;

                foreach (['syn', 'workshop', 'customer', 'miss', 'abbr'] as $group) {
                    foreach ($c['en'][$group] ?? [] as $t) {
                        $terms[$conceptKey.'|'.TextNormalizer::key($t)] = true;
                    }
                }

                foreach (['formal', 'workshop'] as $group) {
                    foreach ($c['ar'][$group] ?? [] as $t) {
                        $terms[$conceptKey.'|'.TextNormalizer::key($t)] = true;
                    }
                }

                // The Arabic concept name is seeded as a translation by the seeder.
                if (filled($c['name_ar'] ?? null)) {
                    $terms[$conceptKey.'|'.TextNormalizer::key($c['name_ar'])] = true;
                }

                foreach ($c['actions'] ?? [] as $slug) {
                    $actions[$conceptKey.'|'.$slug] = true;
                }
            }
        }

        return ['concepts' => $concepts, 'terms' => $terms, 'actions' => $actions];
    }

    /** Seed-owned terms in the database that no source file declares any more. */
    private function findOrphanTerms(array $expected)
    {
        $names = FindingKeyword::pluck('keyword', 'id')
            ->map(fn ($k) => TextNormalizer::key($k));

        return KeywordTerm::query()
            ->where('source', KeywordTerm::SOURCE_SEED)
            ->get(['id', 'finding_keyword_id', 'term', 'normalized', 'kind'])
            ->filter(function (KeywordTerm $t) use ($expected, $names) {
                $conceptKey = $names[$t->finding_keyword_id] ?? null;

                // A term whose concept no longer exists in source is handled by the concept report,
                // not here — otherwise removing one concept would flood this list with its terms.
                if ($conceptKey === null || ! isset($expected['concepts'][$conceptKey])) {
                    return false;
                }

                return ! isset($expected['terms'][$conceptKey.'|'.$t->normalized]);
            })
            ->values();
    }

    private function findOrphanActions(array $expected)
    {
        $names = FindingKeyword::pluck('keyword', 'id')
            ->map(fn ($k) => TextNormalizer::key($k));

        return DB::table('fault_concept_actions as f')
            ->join('action_catalog as a', 'a.id', '=', 'f.action_catalog_id')
            ->where('f.source', 'seed')
            ->get(['f.id', 'f.finding_keyword_id', 'a.slug', 'a.label'])
            ->filter(function ($row) use ($expected, $names) {
                $conceptKey = $names[$row->finding_keyword_id] ?? null;

                if ($conceptKey === null || ! isset($expected['concepts'][$conceptKey])) {
                    return false;
                }

                return ! isset($expected['actions'][$conceptKey.'|'.$row->slug]);
            })
            ->values();
    }

    /**
     * Concepts in the database that no source file declares.
     *
     * Reported for judgement only. Many are legitimately not in the files — they predate the
     * taxonomy work — so this is a list to read, never a list to act on automatically.
     */
    private function findOrphanConcepts(array $expected)
    {
        return FindingKeyword::query()
            ->get(['id', 'keyword', 'category_key'])
            ->filter(fn (FindingKeyword $k) => ! isset($expected['concepts'][TextNormalizer::key($k->keyword)]))
            ->values();
    }

    private function reportTerms($orphans): void
    {
        if ($orphans->isEmpty()) {
            return;
        }

        $names = FindingKeyword::pluck('keyword', 'id');

        $this->newLine();
        $this->warn("ORPHANED TERMS ({$orphans->count()}) — in the database, no longer in any source file");
        $this->table(
            ['Concept', 'Term', 'Kind'],
            $orphans->take((int) $this->option('limit'))
                ->map(fn ($t) => [$names[$t->finding_keyword_id] ?? '?', $t->term, $t->kind])
                ->all(),
        );
    }

    private function reportActions($orphans): void
    {
        if ($orphans->isEmpty()) {
            return;
        }

        $names = FindingKeyword::pluck('keyword', 'id');

        $this->newLine();
        $this->warn("ORPHANED ACTION LINKS ({$orphans->count()})");
        $this->table(
            ['Concept', 'Action'],
            $orphans->take((int) $this->option('limit'))
                ->map(fn ($r) => [$names[$r->finding_keyword_id] ?? '?', $r->label])
                ->all(),
        );
    }

    private function reportConcepts($orphans): void
    {
        if ($orphans->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=gray>CONCEPTS NOT IN SOURCE ({$orphans->count()}) — informational only, never pruned:</>");

        foreach ($orphans->take((int) $this->option('limit')) as $c) {
            $this->line("    · {$c->keyword}  <fg=gray>[{$c->category_key}]</>");
        }
    }

    private function prune($orphanTerms, $orphanActions): void
    {
        $termIds = $orphanTerms->pluck('id')->all();
        $actionIds = $orphanActions->pluck('id')->all();

        DB::transaction(function () use ($termIds, $actionIds) {
            if ($termIds !== []) {
                KeywordTerm::whereIn('id', $termIds)->delete();
            }

            if ($actionIds !== []) {
                DB::table('fault_concept_actions')->whereIn('id', $actionIds)->delete();
            }
        });

        KeywordOntologyService::flushCache();

        $this->newLine();
        $this->info(sprintf('Pruned %d term(s) and %d action link(s). Concepts untouched.',
            count($termIds), count($actionIds)));
    }
}
