<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FaultCatalog;
use App\Models\FindingKeyword;
use App\Models\KeywordProfile;
use App\Models\KeywordTerm;
use App\Models\MaintenanceTask;
use App\Services\KeywordAiEnrichmentService;
use App\Services\SelectableFindings;
use App\Support\OntologyConcepts;
use App\Support\TextNormalizer;

/**
 * ONE READ for the fault vocabulary — the page behind Control Desk → Fault Vocabulary.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────────────────────────
 * A fault type is TWO rows ([[FaultTypeRegistrar]]): `fault_catalog` decides whether an inspector can
 * TAP the word, `finding_keywords` decides what the word MEANS and how serious it is. Since the
 * registrar started writing both halves on every door, one act produces both rows — but the office
 * still read them on two separate screens ("Fault Types" and "Keyword Risk"), each showing half a
 * word and each printing its own total. The only reading available from two totals that differ by
 * design was "one of these is broken".
 *
 * So the READ is merged even though the WRITES stay where they are. This controller does not write
 * anything and deliberately owns no rules: it joins the two tables on the NORMALISED name — the same
 * key the matcher, the picker and the registrar all compare on — and reports, per word, which halves
 * actually exist. Editing still goes through the two existing controllers, so there is no third
 * writer to drift from the registrar.
 *
 * ── THE THREE STATES A WORD CAN BE IN, AND WHY ALL THREE ARE SHOWN ───────────────────────────────
 *   both          the normal case: tappable, graded, matchable.
 *   picker_only   a word the CONFIG authors with no library row behind it ("Detached" today). It is
 *                 tappable and ungraded — real, and not a defect this page can fix from a form.
 *   library_only  either withheld ON PURPOSE (`understanding_only` — the garage records it during the
 *                 repair) or a half-added word nobody can tap. Those two look identical until they are
 *                 told apart, which is why `withheld` is reported beside `in_picker` rather than one
 *                 amber flag covering both. Flagging the deliberate four trains the eye to ignore the
 *                 real one.
 *
 * `has_vocabulary` is a separate axis again and is NOT a defect either: it says the matcher has no
 * words for the concept beyond its own name, because synonyms live in `database/seeders/ontology/*.php`
 * and are authored in commits. The page states it; `findings:vocabulary-check` still fails on it.
 *
 * Read-gated to maintenance.view, like both screens it replaces.
 */
class FaultVocabularyController extends Controller
{
    /** Both halves grade on the same three words, which is why one column can show either. */
    private const GRADES = ['routine', 'moderate', 'critical'];

    public function index(SelectableFindings $selectable)
    {
        // One grouped count rather than one query per row — this table grows with every recorded fault.
        $usage = MaintenanceTask::query()
            ->whereNotNull('fault_catalog_id')
            ->selectRaw('fault_catalog_id, COUNT(*) as c')
            ->groupBy('fault_catalog_id')
            ->pluck('c', 'fault_catalog_id');

        // Slugs the authored file still owns. An authored row can be renamed here but not retired: the
        // next deploy would assert it straight back and the curator would never know why.
        $authored = collect((array) config('fault_catalog', []))->pluck('slug')->filter()->flip();

        $offered  = $selectable->keywords();
        $withheld = $selectable->withheld();

        // Term COUNT and the profile only — the term list itself is the big relation and loads per
        // word in the knowledge drawer, so this stays two queries however large the library grows.
        $keywords = FindingKeyword::query()
            ->withCount(['terms' => fn ($q) => $q->where('is_active', true)])
            ->with('profile')
            ->orderBy('category_label')
            ->orderBy('sort_order')
            ->orderBy('keyword')
            ->get()
            ->groupBy(fn (FindingKeyword $k) => TextNormalizer::key($k->keyword));

        $categories = $this->categories($selectable);
        $rows       = collect();
        $claimed    = [];

        foreach (FaultCatalog::query()->orderBy('category_key')->orderBy('sort_order')->orderBy('name')->get() as $fault) {
            $key = TextNormalizer::key($fault->name);
            // First row wins the pairing. A second library row under a different category is a real,
            // separate row and is emitted below rather than silently folded into this one.
            $twin = ($keywords[$key] ?? collect())->first();

            if ($twin) {
                $claimed[$twin->id] = true;
            }

            $rows->push($this->row($fault, $twin, $categories, $authored, $usage, $offered, $withheld));
        }

        foreach ($keywords->flatten() as $keyword) {
            if (! isset($claimed[$keyword->id])) {
                $rows->push($this->row(null, $keyword, $categories, $authored, $usage, $offered, $withheld));
            }
        }

        $rows = $rows->sortBy([['category_label', 'asc'], ['name', 'asc']])->values();
        $live = $rows->where('is_active', true);

        return ResponseHelper::SuccessResponse(
            [
                'words'      => $rows,
                'categories' => $categories->values(),
                'grades'     => self::GRADES,
                'grade_meta' => FindingKeyword::RISK_META,

                // THE RECONCILIATION, stated rather than left to a subtraction between two screens.
                // These are four different facts about one library, not four attempts at one number.
                'summary' => [
                    'total'      => $rows->count(),
                    'active'     => $live->count(),
                    // What the PICKER itself renders, read from the same service the picker reads, so
                    // the one number a curator compares between screens cannot drift.
                    'selectable' => count($offered),
                    // Unselectable AND correct: the config withholds these, the garage records them
                    // during the repair.
                    'garage_only' => $live->where('withheld', true)->where('in_picker', false)->count(),
                    // Unselectable and WRONG — the half-added word. This is the number that should be
                    // zero, and the only one the page flags in amber.
                    'not_in_picker' => $live->where('withheld', false)->where('in_picker', false)->count(),
                    // Tappable, but the matcher cannot recognise it in a written note. A real gap, and
                    // one no form on this page can close.
                    'no_vocabulary' => $live->where('has_vocabulary', false)->count(),
                    // Graded twice, differently, before the registrar held the two halves equal. Fixed
                    // by re-saving the row, which is why it is worth counting rather than tolerating.
                    'grade_disagrees' => $rows->where('grade_disagrees', true)->count(),
                    // Grade mix across the whole library, so the tiles stay stable while filtering.
                    'critical' => $rows->where('grade', 'critical')->count(),
                    'moderate' => $rows->where('grade', 'moderate')->count(),
                    'routine'  => $rows->where('grade', 'routine')->count(),
                ],

                // How much of the library the AI has actually described, and whether enrichment can run
                // at all (no API key ⇒ the UI states the fact instead of offering an action that fails).
                'knowledge' => [
                    'ai_available' => KeywordAiEnrichmentService::isConfigured(),
                    'enriched'     => KeywordProfile::count(),
                    'term_total'   => KeywordTerm::where('is_active', true)->count(),
                    'kind_meta'    => KeywordTerm::KIND_META,
                ],
            ],
            'Fault vocabulary retrieved successfully',
            200
        );
    }

    /**
     * One word, both halves, and an honest account of which of them exist.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $categories
     * @param  \Illuminate\Support\Collection<string, int>                $authored
     * @param  \Illuminate\Support\Collection<int, int>                   $usage
     * @param  array<string, string>                                      $offered
     * @param  array<string, string>                                      $withheld
     * @return array<string, mixed>
     */
    private function row(
        ?FaultCatalog $fault,
        ?FindingKeyword $keyword,
        $categories,
        $authored,
        $usage,
        array $offered,
        array $withheld,
    ): array {
        $name         = $fault->name ?? $keyword->keyword;
        $key          = TextNormalizer::key($name);
        $categoryKey  = $fault->category_key ?? $keyword->category_key;
        $category     = $categories->get($categoryKey);

        // The catalog's severity leads where both halves exist: it is the value the TASK is prefilled
        // from, so it is the one a curator is actually deciding. The registrar keeps them equal.
        $grade = $fault->default_severity ?? $keyword->risk;

        return [
            'key'        => $key,
            'fault_id'   => $fault?->id,
            'keyword_id' => $keyword?->id,
            'slug'       => $fault?->slug,

            'name'        => $name,
            'name_ar'     => $fault?->name_ar ?: $keyword?->keyword_ar,
            'description' => $keyword?->description,

            'category_key'      => $categoryKey,
            'category_label'    => $category['label'] ?? $categoryKey,
            'category_label_ar' => $category['label_ar'] ?? null,

            'grade' => $grade,
            // KeywordRiskAnalytics and the grade tiles read this name; kept so the chart strip that
            // already existed works unchanged against a merged row.
            'risk'  => $grade,
            // WHAT THE OTHER HALF SAYS, when it says something different.
            //
            // The registrar carries a re-grade across both tables, so nothing written since it existed
            // can disagree. 27 rows on this fleet do, because they were graded on two screens before
            // that — one page said a cracked windscreen was routine and the other said moderate, and
            // neither page could see the other. Reported with the actual other value rather than a bare
            // "these differ" flag: the curator is choosing between two grades and needs to see both.
            // Saving the row through either door reconciles them.
            'keyword_grade'   => $keyword?->risk,
            'grade_disagrees' => $fault && $keyword && $fault->default_severity !== $keyword->risk,

            'on_site'   => (bool) ($fault?->on_site ?? false),
            // Retired is a property of the word, not of one table. Where both halves exist they are
            // held equal by the registrar; the catalog is the half the picker reads, so it answers.
            'is_active' => (bool) ($fault?->is_active ?? $keyword?->is_active ?? false),
            'authored'  => $fault !== null && $authored->has($fault->slug),

            // WHERE THE WORD LIVES. Three facts, because collapsing them is what made two screens
            // necessary in the first place.
            'half'       => $fault && $keyword ? 'both' : ($fault ? 'picker_only' : 'library_only'),
            'in_picker'  => isset($offered[$key]),
            'withheld'   => isset($withheld[$key]),

            // OFFERED BY THE CONFIG FILE ITSELF, with no catalog row behind it — 14 words here today.
            // They are tappable, and NOTHING on this page can retire them: the file re-asserts the chip
            // on the next deploy. `authored` cannot answer this, because it matches a catalog row's slug
            // and these have no catalog row at all. Without its own flag the page would offer a Retire
            // button that removes the grade and leaves the chip, which is the drift it exists to end.
            'config_offered' => $fault === null && isset($offered[$key]),

            // WHAT THE MATCHER KNOWS. `has_vocabulary` reads the authored ontology files, not the
            // database, so it answers "has anyone written words for this" rather than "was it seeded".
            'has_vocabulary' => OntologyConcepts::has($name),
            'term_count'     => $keyword?->terms_count,
            'is_enriched'    => $keyword ? $keyword->profile !== null : null,

            // What "can I delete this?" costs. Read from the task table itself, never derived.
            'usage_count' => $fault ? (int) ($usage[$fault->id] ?? 0) : null,
        ];
    }

    /**
     * The categories a word may be filed under, straight from the list the picker draws its headings
     * from — a word filed under a key the picker has no heading for would never render.
     *
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function categories(SelectableFindings $selectable)
    {
        return collect($selectable->categories())
            ->filter(fn ($c) => ($c['key'] ?? null) !== null)
            ->mapWithKeys(fn ($c) => [$c['key'] => [
                'key'      => $c['key'],
                'label'    => $c['label'] ?? $c['key'],
                'label_ar' => $c['label_ar'] ?? null,
                'on_site'  => (bool) ($c['on_site'] ?? false),
            ]]);
    }
}
