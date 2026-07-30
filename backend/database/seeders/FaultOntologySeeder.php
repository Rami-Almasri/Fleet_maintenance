<?php

namespace Database\Seeders;

use App\Models\ActionCatalog;
use App\Models\FaultCause;
use App\Models\FindingKeyword;
use App\Models\KeywordProfile;
use App\Models\KeywordTerm;
use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The automotive fault ontology — the platform's language for what goes wrong with a car.
 *
 * Loads one file per category from database/seeders/ontology/, each returning fault concepts with
 * their full vocabulary, causes and normalized repair actions. Split by category because a single
 * file holding a thousand concepts is one nobody will ever review, and this content only stays
 * trustworthy if a person can read the brakes section and say "yes, that's how we talk".
 *
 * WHAT EACH CONCEPT CARRIES
 *   category / system / subsystem   Category → System → Concept, the hierarchy
 *   risk + safety_impact            critical | moderate | routine, and whether it can hurt someone
 *   en{syn,workshop,customer,miss}  synonyms, trade wording, how customers phrase it, real typos
 *   ar{formal,workshop}             Modern Standard for records, Gulf/Levantine for what people type
 *   causes                          seeded into fault_causes, feeding CausalReasoner
 *   actions                         FKs into action_catalog — never free text
 *
 * VOCABULARY RULES, APPLIED WHILE WRITING RATHER THAN AFTERWARDS. Every term here is one a mechanic,
 * inspector, service advisor or customer would actually say. No marketing language, no textbook
 * terminology nobody uses, no generic words that carry no automotive meaning ("problem", "issue"),
 * because a term that matches everything ranks noise above the right answer. Misspellings are
 * included ON PURPOSE — "breaks", "radiater", "shocks absorber" are what people type, and a search
 * that only matches correct spelling fails exactly when someone is in a hurry.
 *
 * ARABIC IS NOT TRANSLATED ENGLISH. "الموتر يحما" is not a rendering of "the engine is overheating";
 * it is what a driver in this fleet actually says. Formal Arabic goes in for written records, but
 * the workshop and Gulf/Levantine forms are what the matcher will really see.
 *
 * RE-RUNNABLE. Concepts, terms, causes and action links all upsert, and anything a person has taken
 * ownership of (source = human) is never overwritten.
 */
class FaultOntologySeeder extends Seeder
{
    /** Loaded in this order; the order also sets sort_order within a category. */
    private const CATEGORIES = [
        'engine', 'cooling', 'transmission', 'brakes', 'steering', 'suspension',
        'tyres', 'electrical', 'hvac', 'body', 'interior', 'lights', 'safety', 'fluids',
    ];

    public function run(): void
    {
        $actions = ActionCatalog::pluck('id', 'slug');

        $stats = ['concepts' => 0, 'terms' => 0, 'causes' => 0, 'links' => 0, 'missing_actions' => []];

        foreach (self::CATEGORIES as $file) {
            $path = database_path("seeders/ontology/{$file}.php");

            if (! is_file($path)) {
                continue;
            }

            foreach (require $path as $order => $concept) {
                $this->seedConcept($concept, $order, $actions, $stats);
            }
        }

        $this->command?->info(sprintf(
            'Fault ontology: %d concepts · %d terms · %d causes · %d action links.',
            $stats['concepts'], $stats['terms'], $stats['causes'], $stats['links'],
        ));

        // Surfaced rather than swallowed: an action slug that does not exist means the concept
        // references a repair the catalogue cannot express, which is a real gap in one or the other.
        if ($stats['missing_actions'] !== []) {
            $this->command?->warn('Unknown action slugs referenced: '.implode(', ', array_unique($stats['missing_actions'])));
        }

        KeywordOntologyService::flushCache();
    }

    /** @param array<string,mixed> $c */
    private function seedConcept(array $c, int $order, $actions, array &$stats): void
    {
        // MATCHED ON THE NAME ALONE, NOT (category, name).
        //
        // Keying on the pair created silent duplicates: "Brake-fluid leak" already existed under
        // `fluids`, the brakes file declared it under `brakes`, and the result was two concepts with
        // identical vocabulary — each matching at 100% and neither reachable, because whichever won
        // was arbitrary. Caught by `ontology:duplicates` as a self-match failure.
        //
        // A fault has ONE identity regardless of which category file happens to describe it. The
        // category is an attribute of the concept, not part of its key.
        $normalizedName = TextNormalizer::key($c['name']);

        $keyword = FindingKeyword::query()
            ->get(['id', 'keyword'])
            ->first(fn (FindingKeyword $k) => TextNormalizer::key($k->keyword) === $normalizedName);

        $keyword = $keyword
            ? FindingKeyword::find($keyword->id)
            : new FindingKeyword(['keyword' => $c['name']]);

        $keyword->category_key = $c['category'];

        $keyword->fill([
            'category_label'    => $c['category_label'] ?? ucfirst($c['category']),
            'category_label_ar' => $c['category_label_ar'] ?? null,
            'keyword_ar'        => $c['name_ar'] ?? $keyword->keyword_ar,
            'risk'              => $c['risk'],
            'description'       => $c['description'] ?? $keyword->description,
            'is_active'         => true,
            'sort_order'        => ($order + 1) * 10,
        ])->save();

        $keyword->syncCanonicalTerms();
        $stats['concepts']++;

        $stats['terms']  += $this->seedTerms($keyword, $c);
        $stats['causes'] += $this->seedCauses($keyword, $c);
        $stats['links']  += $this->seedActions($keyword, $c, $actions, $stats);

        $this->seedProfile($keyword, $c);
    }

    /** @param array<string,mixed> $c */
    private function seedTerms(FindingKeyword $keyword, array $c): int
    {
        $written = 0;

        $groups = [
            KeywordTerm::KIND_SYNONYM         => ['en', $c['en']['syn'] ?? []],
            KeywordTerm::KIND_WORKSHOP_PHRASE => ['en', $c['en']['workshop'] ?? []],
            KeywordTerm::KIND_CUSTOMER_PHRASE => ['en', $c['en']['customer'] ?? []],
            KeywordTerm::KIND_MISSPELLING     => ['en', $c['en']['miss'] ?? []],
            KeywordTerm::KIND_ABBREVIATION    => ['en', $c['en']['abbr'] ?? []],
            KeywordTerm::KIND_TRANSLATION     => ['ar', $c['ar']['formal'] ?? []],
        ];

        foreach ($groups as $kind => [$lang, $terms]) {
            foreach ($terms as $term) {
                $written += $this->writeTerm($keyword, $term, $kind, $lang) ? 1 : 0;
            }
        }

        // Spoken Arabic is filed as CUSTOMER wording rather than translation: "الموتر يحما" is not a
        // translation of the concept name, it is how someone reports the fault. Filing it as a
        // translation would rank it as canonical Arabic, which it is not.
        foreach ($c['ar']['workshop'] ?? [] as $term) {
            $written += $this->writeTerm($keyword, $term, KeywordTerm::KIND_CUSTOMER_PHRASE, 'ar') ? 1 : 0;
        }

        return $written;
    }

    private function writeTerm(FindingKeyword $keyword, string $term, string $kind, string $lang): bool
    {
        $normalized = TextNormalizer::key($term);

        if ($normalized === '') {
            return false;
        }

        $row = KeywordTerm::firstOrNew([
            'finding_keyword_id' => $keyword->id,
            'normalized'         => $normalized,
        ]);

        // A term an admin owns is left exactly as they left it.
        if ($row->exists && $row->source === KeywordTerm::SOURCE_HUMAN) {
            return false;
        }

        $row->fill([
            'term'               => $term,
            'lang'               => $lang,
            'kind'               => $kind,
            'source'             => KeywordTerm::SOURCE_SEED,
            'source_quality'     => 'workshop',
            // Misspellings exist to widen recall, not to rank highly — a typo should find the right
            // concept without ever outranking the correct spelling of a different one.
            'workshop_frequency' => $kind === KeywordTerm::KIND_MISSPELLING ? 'low' : 'high',
            'confidence'         => $kind === KeywordTerm::KIND_MISSPELLING ? 70 : 88,
            'is_active'          => true,
        ])->save();

        return true;
    }

    /** @param array<string,mixed> $c */
    private function seedCauses(FindingKeyword $keyword, array $c): int
    {
        $written = 0;

        foreach ($c['causes'] ?? [] as $cause) {
            $row = FaultCause::firstOrNew([
                'symptom_key' => FaultCause::normalizeKey($c['name']),
                'root_cause'  => $cause,
            ]);

            if ($row->exists && $row->status === FaultCause::STATUS_REJECTED) {
                continue;   // someone reviewed this and said no
            }

            $row->fill([
                'symptom_label' => $c['name'],
                'category_key'  => $c['category'],
                'status'        => FaultCause::STATUS_APPROVED,
                'source'        => 'seed',
            ])->save();

            $written++;
        }

        return $written;
    }

    /** @param array<string,mixed> $c */
    private function seedActions(FindingKeyword $keyword, array $c, $actions, array &$stats): int
    {
        $written = 0;
        $order = 0;

        foreach ($c['actions'] ?? [] as $slug) {
            $actionId = $actions[$slug] ?? null;

            if (! $actionId) {
                $stats['missing_actions'][] = $slug;

                continue;
            }

            DB::table('fault_concept_actions')->updateOrInsert(
                ['finding_keyword_id' => $keyword->id, 'action_catalog_id' => $actionId],
                [
                    // The first two listed are what usually fixes it; the rest are what sometimes
                    // does. Encoded by position so the data stays readable in the category files.
                    'relevance'  => $order < 2 ? 'typical' : 'possible',
                    'sort_order' => ($order += 1) * 10,
                    'source'     => 'seed',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $written++;
        }

        return $written;
    }

    /** The engineering profile — system, components, inspection order. */
    private function seedProfile(FindingKeyword $keyword, array $c): void
    {
        $profile = KeywordProfile::firstOrNew(['finding_keyword_id' => $keyword->id]);

        // Never flatten an enrichment run's work with seed defaults.
        if ($profile->exists && filled($profile->enriched_at)) {
            return;
        }

        $profile->fill([
            'vehicle_system'    => $c['system'] ?? null,
            'subsystem'         => $c['subsystem'] ?? null,
            'repair_discipline' => $c['discipline'] ?? null,
            'severity_estimate' => $c['risk'],
            'summary_en'        => $c['description'] ?? null,
            'components'        => $c['components'] ?? null,
            'likely_causes'     => $c['causes'] ?? null,
            'inspection_order'  => $c['inspection'] ?? null,
            'symptoms'          => $c['en']['customer'] ?? null,
            'confidence'        => 70,
        ])->save();
    }
}
