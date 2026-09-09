<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\EvidenceLinkResource;
use App\Http\Resources\FindingKeywordResource;
use App\Http\Resources\KeywordTermResource;
use App\Models\FindingKeyword;
use App\Models\KeywordTerm;
use App\Models\MaintenanceTask;
use App\Models\OntologyFeedback;
use App\Services\KeywordAiEnrichmentService;
use App\Services\KeywordOntologyService;
use App\Services\KnowledgeRetrievalService;
use App\Services\MatchExplanationService;
use App\Services\OntologyFeedbackService;
use App\Services\OntologyGraphService;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administration of the findings keyword library — the quick-pick fault keywords the Inspector /
 * workshop tap, each carrying a RISK grade (critical / moderate / routine). This is the CRUD surface
 * behind the "Keyword Risk Library" admin page: list + filter, create, edit (incl. re-grading the
 * risk), and retire. Reads are gated to maintenance.view; writes to maintenance.manage (route-level).
 *
 * The keyword strings themselves are still what gets persisted onto maintenances.findings, so this
 * screen only curates the menu + its metadata — no ticket data moves when a keyword is edited.
 *
 * AI KNOWLEDGE BASE. The same controller now serves the ontology layer that sits behind each
 * keyword: `show` returns the full concept (every surface form + the engineering profile + the
 * enrichment audit trail), `enrich` triggers a Claude run for one keyword, `resolve` answers the
 * question the whole thing exists for — "which fault is this free text describing?" — and the term
 * endpoints let an admin correct or add a wording by hand. A hand-edited term is marked
 * `source=human` and is never overwritten by a later AI run.
 */
class FindingKeywordController extends Controller
{
    /**
     * The whole library, ordered by category then intra-category sort order. Optional filters:
     * `?category=engine`, `?risk=critical`, `?active=1|0`, `?q=` (keyword / description search).
     * Also returns the risk vocabulary (value → emoji/label/tone) and headline counts so the admin
     * screen can render the risk legend + badge the totals without a second request.
     */
    public function index(Request $request)
    {
        $request->validate([
            'category' => ['nullable', 'string', 'max:60'],
            'risk'     => ['nullable', Rule::in(FindingKeyword::RISKS)],
            'active'   => ['nullable', 'boolean'],
            'q'        => ['nullable', 'string', 'max:191'],
        ]);

        $rows = FindingKeyword::query()
            // Ontology summary: a term COUNT plus the profile (small, one row per keyword). The term
            // list itself — the big relation — only loads per-keyword in `show`, so the library
            // table stays two queries however large the knowledge base grows.
            ->withCount(['terms' => fn ($q) => $q->where('is_active', true)])
            ->with('profile')
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->string('category')))
            ->when($request->filled('risk'), fn ($q) => $q->withRisk($request->string('risk')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('keyword', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->orderBy('category_label')
            ->orderBy('sort_order')
            ->orderBy('keyword')
            ->get();

        // Risk totals across the WHOLE library (unfiltered) so the KPI tiles stay stable while filtering.
        $counts = FindingKeyword::query()
            ->selectRaw('risk, COUNT(*) as c')
            ->groupBy('risk')
            ->pluck('c', 'risk');

        return ResponseHelper::SuccessResponse(
            [
                'keywords'   => FindingKeywordResource::collection($rows),
                'risk_meta'  => FindingKeyword::RISK_META,
                'categories' => array_values(array_map(
                    fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'label_ar' => $c['label_ar'] ?? null],
                    config('maintenance_findings.categories', []),
                )),
                'counts' => [
                    // Words that are graded and matchable and that nobody can tap. This is the number
                    // that should be zero: every one of them is a fault somebody added and believed was
                    // in the picker. Counted over the WHOLE library, unfiltered, like the risk tiles —
                    // and over ACTIVE rows only, because a retired word is offered nowhere by design.
                    //
                    // The `understanding_only` words are excluded: they are withheld deliberately, and
                    // counting them would put a permanent 4 on a tile whose whole job is to be zero —
                    // which is how a real one hides. Exactly the set findings:vocabulary-check exempts.
                    'not_selectable' => $this->deadEndCount(),
                    'total'    => (int) $counts->sum(),
                    'critical' => (int) ($counts[FindingKeyword::RISK_CRITICAL] ?? 0),
                    'moderate' => (int) ($counts[FindingKeyword::RISK_MODERATE] ?? 0),
                    'routine'  => (int) ($counts[FindingKeyword::RISK_ROUTINE] ?? 0),
                ],
                // Knowledge-base coverage: how much of the library the AI has actually described,
                // and whether enrichment can run at all (no API key ⇒ the UI hides the buttons
                // rather than offering an action that would fail).
                'knowledge' => [
                    'ai_available'  => KeywordAiEnrichmentService::isConfigured(),
                    'enriched'      => \App\Models\KeywordProfile::count(),
                    'term_total'    => KeywordTerm::where('is_active', true)->count(),
                    'kind_meta'     => KeywordTerm::KIND_META,
                ],
            ],
            'Findings keyword library retrieved successfully',
            200
        );
    }

    /**
     * How many active words are matchable, ungraded as garage-only, and offered nowhere — the dead ends
     * `findings:vocabulary-check` fails on, counted with the same rule so the page and the check cannot
     * disagree about how many there are.
     */
    private function deadEndCount(): int
    {
        $selectable = app(\App\Services\SelectableFindings::class);
        $offered    = $selectable->keywords();
        $withheld   = $selectable->withheld();

        return FindingKeyword::query()
            ->where('is_active', true)
            ->get(['keyword'])
            ->reject(function (FindingKeyword $k) use ($offered, $withheld) {
                $key = TextNormalizer::key($k->keyword);

                return isset($offered[$key]) || isset($withheld[$key]);
            })
            ->count();
    }

    /**
     * One fault CONCEPT in full: every surface form, the engineering profile, and the enrichment
     * audit trail. This is what the knowledge drawer on the admin page reads.
     */
    public function show(Request $request, FindingKeyword $findingKeyword, OntologyGraphService $graph)
    {
        $findingKeyword->load([
            'terms' => fn ($q) => $q->orderByDesc('search_rank')->orderBy('term'),
            'profile',
            'ontologyNode',
            'evidence',
            'enrichmentRuns' => fn ($q) => $q->with('user:id,name')->limit(10),
        ]);

        // Optional vehicle lens: ?make=BMW&model=3 Series narrows the graph to knowledge that
        // applies to that car, falling back to universal where nothing specific exists.
        $scopeChain = VehicleScope::chain($request->query('make'), $request->query('model'));

        return ResponseHelper::SuccessResponse(
            [
                'keyword'   => new FindingKeywordResource($findingKeyword),
                'kind_meta' => KeywordTerm::KIND_META,

                // The knowledge graph around this fault — ranked causes, repairs (with the parts
                // and tools each one needs), inspection steps and related faults.
                'graph' => $findingKeyword->ontologyNode
                    ? $graph->diagnosticProfile($findingKeyword->ontologyNode, $scopeChain)
                    : null,

                // Citations behind the entry, and how much of it is actually grounded.
                'evidence'  => EvidenceLinkResource::collection($findingKeyword->evidence),
                'grounding' => app(MatchExplanationService::class)->groundingScore($findingKeyword),

                'scope'        => ['chain' => $scopeChain, 'label' => VehicleScope::label(end($scopeChain))],
                'ai_available' => KeywordAiEnrichmentService::isConfigured(),
                'retrieval'    => [
                    'corpus' => app(KnowledgeRetrievalService::class)->hasCorpus(),
                    'web'    => app(KnowledgeRetrievalService::class)->hasWebGrounding(),
                ],
            ],
            'Keyword knowledge retrieved successfully',
            200
        );
    }

    /**
     * Run AI enrichment for one keyword — generate its synonyms, workshop wording, abbreviations,
     * spelling variants, misspellings, Arabic terms and engineering metadata.
     *
     * Synchronous on purpose: it is a single deliberate admin click on one keyword (a few seconds),
     * and returning the freshly-written concept is what makes the drawer feel like it worked. The
     * WHOLE library is enriched from the CLI instead (`php artisan keywords:enrich --all`), which is
     * where a fleet-sized batch belongs.
     */
    public function enrich(Request $request, FindingKeyword $findingKeyword, KeywordAiEnrichmentService $service)
    {
        if (! KeywordAiEnrichmentService::isConfigured()) {
            return ResponseHelper::FailureResponse(null, 'AI enrichment is not configured (ANTHROPIC_API_KEY is missing).', 422);
        }

        $run = $service->enrich($findingKeyword, $request->user()?->id);

        if ($run->status !== \App\Models\KeywordEnrichmentRun::STATUS_SUCCESS) {
            return ResponseHelper::FailureResponse(null, 'Enrichment failed: '.$run->error, 502);
        }

        $findingKeyword->load([
            'terms' => fn ($q) => $q->orderByDesc('search_rank')->orderBy('term'),
            'profile',
            'enrichmentRuns' => fn ($q) => $q->with('user:id,name')->limit(10),
        ]);

        return ResponseHelper::SuccessResponse(
            [
                'keyword' => new FindingKeywordResource($findingKeyword),
                'run'     => [
                    'terms_added'   => $run->terms_added,
                    'terms_updated' => $run->terms_updated,
                    'duration_ms'   => $run->duration_ms,
                    'input_tokens'  => $run->input_tokens,
                    'output_tokens' => $run->output_tokens,
                ],
            ],
            "Knowledge base updated: {$run->terms_added} new term(s), {$run->terms_updated} refreshed",
            200
        );
    }

    /**
     * Free text → ranked fault concepts. The point of the whole knowledge base.
     *
     * "The car makes a strange metallic sound when braking" comes back as Brake noise, with the
     * exact terms that matched and HOW they matched, so the answer is explainable rather than a
     * bare similarity number ([[traceability-visibility-requirement]]). Also powers the "test the
     * matcher" box on the admin page, which is how you tell a thin keyword from a well-described one.
     */
    /**
     * Name what was actually matched. The old wording called every hit a "fault", which is how a matcher
     * that happily returns "Oil Change" tells the reader it found a defect (audit H6).
     *
     * @param  \Illuminate\Support\Collection<int,array>  $matches
     */
    private function resolveMessage($matches): string
    {
        if ($matches->isEmpty()) {
            return 'No match found';
        }

        $counts = $matches->countBy('kind');
        $parts  = [];
        foreach ([MaintenanceTask::KIND_FAULT => 'fault', MaintenanceTask::KIND_SERVICE => 'service'] as $kind => $noun) {
            if ($n = (int) ($counts[$kind] ?? 0)) {
                $parts[] = $n . ' ' . $noun . ($n === 1 ? '' : 's');
            }
        }

        return 'Matched ' . implode(' and ', $parts);
    }

    public function resolve(Request $request, KeywordOntologyService $ontology, MatchExplanationService $explainer)
    {
        $data = $request->validate([
            'text'     => ['required', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:60'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:20'],
            'make'     => ['nullable', 'string', 'max:60'],
            'model'    => ['nullable', 'string', 'max:60'],
        ]);

        // The same symptom means different things on different cars, so a match can be asked for
        // in the context of one — the explanation then cites that vehicle's knowledge where it
        // exists and falls back to universal where it doesn't.
        $scopeChain = VehicleScope::chain($data['make'] ?? null, $data['model'] ?? null);

        $matches = $ontology->resolve($data['text'], [
            'category' => $data['category'] ?? null,
            'limit'    => $data['limit'] ?? null,
        ]);

        // Loaded once for the whole result set rather than per match — five matches would otherwise be
        // ten extra queries on a keystroke-triggered endpoint.
        $matches->each(fn (array $m) => $m['keyword']->loadMissing([
            'profile:id,finding_keyword_id,likely_causes',
            'repairActions:id,label,label_ar',
        ]));

        $selectable = $this->selectableKeywords();

        return ResponseHelper::SuccessResponse(
            [
                'query'      => $data['text'],
                'normalized' => TextNormalizer::key($data['text']),
                'scope'      => ['chain' => $scopeChain, 'label' => VehicleScope::label(end($scopeChain))],
                'matches'    => $matches->map(fn (array $m) => [
                    'keyword'    => new FindingKeywordResource($m['keyword']),
                    'score'      => $m['score'],
                    'confidence' => $m['confidence'],
                    'matches'    => $m['matches'],

                    // WHICH LANE IS THIS? fault | service. The picker renders scheduled work differently
                    // from a defect and must not present "Oil Change" as a diagnosis of the text typed.
                    // The endpoint previously reported every match as a fault regardless (audit H6).
                    'kind'       => $m['kind'],

                    // CAN THE PERSON READING THIS ACT ON IT?
                    //
                    // The ontology understands more faults than the findings catalog offers (garage
                    // diagnoses like "Water pump failure" arrive through repair capture, not the
                    // picker — see `understanding_only` in config/maintenance_findings.php). Without
                    // this flag the inspector's picker would render a confident suggestion with no
                    // chip behind it. The engine still answers; the UI decides what to do about it.
                    'selectable' => isset($selectable[TextNormalizer::key($m['keyword']->keyword)]),

                    // The two lines a technician actually wants next to a fault name. Trimmed to
                    // three: this renders on a phone in a yard, and the full profile is a tap away.
                    'causes'     => array_slice((array) ($m['keyword']->profile?->likely_causes ?? []), 0, 3),
                    'fixes'      => $m['keyword']->repairActions
                        ->sortBy(fn ($a) => $a->pivot->relevance === 'typical' ? 0 : 1)
                        ->take(3)
                        ->map(fn ($a) => ['label' => $a->label, 'label_ar' => $a->label_ar])
                        ->values()
                        ->all(),

                    // Why this answer — lexical hit, documentation, fleet history, graph context.
                    // Assembled from the same rows the decision used, never reconstructed.
                    'explanation' => $explainer->explain($m, $scopeChain),
                ])->all(),
            ],
            $this->resolveMessage($matches),
            200
        );
    }

    /**
     * The keywords an inspector can actually tap, normalised for comparison.
     *
     * Read from the CATALOG rather than from `finding_keywords.is_active`, because the catalog — not
     * that table — is what the picker renders. A row can be active and matchable while its concept is
     * declared understanding-only, and that is exactly the case this has to catch.
     *
     * Answered by SelectableFindings so this gate, the picker and the vocabulary check cannot drift:
     * the catalog is now config PLUS the fault rows the admin page owns, and a gate still reading the
     * config alone would reject exactly the words the office had just added.
     *
     * @return array<string,true>
     */
    private function selectableKeywords(): array
    {
        return array_map(fn () => true, app(\App\Services\SelectableFindings::class)->keywords());
    }

    /**
     * Record a human verdict on a match — the continuous-learning signal.
     *
     * These are the rarest and most valuable rows the system collects: a person read a real
     * sentence, saw what the engine proposed, and said whether it was right. They are labelled
     * retrieval pairs, they feed straight back into the next enrichment prompt for that fault, and
     * they cannot be reconstructed after the fact — which is why this endpoint exists at all rather
     * than inferring satisfaction from clicks. See [[OntologyFeedbackService]].
     */
    public function matchFeedback(Request $request, OntologyFeedbackService $feedback)
    {
        $data = $request->validate([
            'text'       => ['required', 'string', 'max:1000'],
            'keyword_id' => ['required', 'integer', 'exists:finding_keywords,id'],
            'correct'    => ['required', 'boolean'],
            'score'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'reason'     => ['nullable', 'string', 'max:500'],
            // Constrained to the known surfaces rather than free text: this field decides whether a
            // row counts as field-observed ground truth or an admin experiment, and a typo would
            // silently drop a verdict out of whichever set someone later measures.
            'context'    => ['nullable', 'string', Rule::in([
                OntologyFeedback::CONTEXT_MATCH_TESTER,
                OntologyFeedback::CONTEXT_TEST_FINDINGS,
                OntologyFeedback::CONTEXT_GARAGE_FINDINGS,
            ])],
            'maintenance_id' => ['nullable', 'integer', 'exists:maintenances,id'],
            'vehicle_id'     => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        $keyword = FindingKeyword::findOrFail($data['keyword_id']);

        $feedback->record(
            $data['correct'] ? OntologyFeedback::ACTION_CONFIRM_MATCH : OntologyFeedback::ACTION_REJECT_MATCH,
            $keyword,
            $keyword,
            [
                'query_text'     => $data['text'],
                'match_score'    => $data['score'] ?? null,
                'reason'         => $data['reason'] ?? null,
                'context'        => $data['context'] ?? OntologyFeedback::CONTEXT_MATCH_TESTER,
                'maintenance_id' => $data['maintenance_id'] ?? null,
                'vehicle_id'     => $data['vehicle_id'] ?? null,
            ],
            $request->user()?->id,
        );

        return ResponseHelper::SuccessResponse(
            null,
            $data['correct'] ? 'Thanks — recorded as a good match' : 'Recorded. This fault will be tightened on its next enrichment.',
            200
        );
    }

    /**
     * Add a term by hand. Always stored as `source=human`, which permanently protects it from being
     * rewritten by a future AI run — the model can extend the library, never overrule the workshop.
     */
    public function storeTerm(Request $request, FindingKeyword $findingKeyword, OntologyFeedbackService $feedback)
    {
        $data = $this->validateTerm($request, $findingKeyword);

        $term = $findingKeyword->terms()->create($data + ['source' => KeywordTerm::SOURCE_HUMAN]);

        // A hand-written term is the house vocabulary — recorded so future enrichment matches the
        // register the workshop actually uses, not just avoids what it rejected.
        $feedback->record(OntologyFeedback::ACTION_CREATE, $term, $findingKeyword, [
            'after'   => ['term' => $term->term, 'kind' => $term->kind, 'lang' => $term->lang],
            'context' => 'keyword_drawer',
        ], $request->user()?->id);

        KeywordOntologyService::flushCache();

        return ResponseHelper::SuccessResponse(new KeywordTermResource($term), 'Term added', 201);
    }

    /** Edit a term — re-word it, re-classify its kind, or retire it from matching. */
    public function updateTerm(Request $request, FindingKeyword $findingKeyword, KeywordTerm $term, OntologyFeedbackService $feedback)
    {
        abort_unless($term->finding_keyword_id === $findingKeyword->id, 404);

        $before = ['term' => $term->term, 'kind' => $term->kind, 'lang' => $term->lang, 'is_active' => $term->is_active];
        $data = $this->validateTerm($request, $findingKeyword, $term);

        // Touching a term makes it human-owned from here on — the edit must survive re-enrichment.
        $term->update($data + ['source' => KeywordTerm::SOURCE_HUMAN]);

        $feedback->record(OntologyFeedback::ACTION_EDIT, $term, $findingKeyword, [
            'before'  => $before,
            'after'   => ['term' => $term->term, 'kind' => $term->kind, 'lang' => $term->lang, 'is_active' => $term->is_active],
            'reason'  => $request->input('reason'),
            'context' => 'keyword_drawer',
        ], $request->user()?->id);

        KeywordOntologyService::flushCache();

        return ResponseHelper::SuccessResponse(new KeywordTermResource($term->fresh()), 'Term updated', 200);
    }

    /** Delete a term outright. Use `is_active=false` instead when you may want it back. */
    public function destroyTerm(Request $request, FindingKeyword $findingKeyword, KeywordTerm $term, OntologyFeedbackService $feedback)
    {
        abort_unless($term->finding_keyword_id === $findingKeyword->id, 404);

        // Recorded BEFORE the delete — this is the strongest correction signal there is ("the
        // engine generated a word that is wrong for this fault") and it must outlive the row.
        $feedback->record(OntologyFeedback::ACTION_DELETE, $term, $findingKeyword, [
            'before'  => ['term' => $term->term, 'kind' => $term->kind, 'source' => $term->source],
            'reason'  => $request->input('reason'),
            'context' => 'keyword_drawer',
        ], $request->user()?->id);

        $term->delete();

        KeywordOntologyService::flushCache();

        return ResponseHelper::SuccessResponse(null, 'Term removed', 200);
    }

    /**
     * Shared term validation. Uniqueness is checked on the NORMALISED key rather than the raw text,
     * because "A/C" and "a c" are the same term to the matcher and two rows would be dead weight.
     */
    private function validateTerm(Request $request, FindingKeyword $keyword, ?KeywordTerm $existing = null): array
    {
        $data = $request->validate([
            'term'               => ['required', 'string', 'max:191'],
            'lang'               => ['nullable', Rule::in(['en', 'ar'])],
            'kind'               => ['required', Rule::in(KeywordTerm::KINDS)],
            'confidence'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'source_quality'     => ['nullable', Rule::in(KeywordTerm::SOURCE_QUALITIES)],
            'workshop_frequency' => ['nullable', Rule::in(KeywordTerm::FREQUENCIES)],
            'is_active'          => ['nullable', 'boolean'],
        ]);

        $normalized = TextNormalizer::key($data['term']);

        if ($normalized === '') {
            abort(422, 'That term contains no searchable characters.');
        }

        $clash = $keyword->terms()
            ->where('normalized', $normalized)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($clash) {
            abort(422, 'This keyword already has an equivalent term.');
        }

        $data['normalized'] = $normalized;
        $data['confidence'] = $data['confidence'] ?? 100;   // a human said it — treat it as certain
        $data['is_active']  = $request->boolean('is_active', $existing->is_active ?? true);

        return $data;
    }

    /**
     * Add a new keyword to the library — and, in the same act, the fault type that makes it TAPPABLE.
     *
     * Adding a word here used to write one row of the two a fault is made of: the matcher learned it and
     * the picker never offered it, so the library counted one more fault than the picker did and the new
     * word came back from the picker's search as "0 matching issues" beside a suggestion card claiming
     * the garage records this one during the repair. It was not withheld; it was half-added.
     * [[FaultTypeRegistrar]] writes both halves, so the two doors into the vocabulary agree.
     */
    public function store(Request $request, \App\Services\FaultTypeRegistrar $registrar)
    {
        $data = $this->validatePayload($request);

        // A SERVICE has no fault row and never will — typing "Coolant service" as a fault would put
        // planned work into Top Faults, recurrence and the reliability score. Refusing here beats
        // creating a word that can only ever be half-added, which is the bug this method just fixed.
        if ($registrar->isServiceWording($data['keyword'])) {
            return ResponseHelper::FailureResponse(
                null,
                'That wording reads as scheduled service, not a fault, so it cannot be offered in the '
                . 'findings picker — a task typed from it would count as a failure in every fault report. '
                . 'Service items are authored in config/service_catalog.php.',
                422
            );
        }

        $keyword = FindingKeyword::create($data);

        // A brand-new keyword is searchable immediately — its own EN/AR strings become terms before
        // any AI run happens. Enrichment then widens the vocabulary; it isn't a prerequisite.
        $keyword->syncCanonicalTerms();
        KeywordOntologyService::flushCache();

        $registrar->ensureFaultType($keyword);

        return ResponseHelper::SuccessResponse(
            new FindingKeywordResource($keyword),
            'Keyword added to the library — inspectors can select it in the findings picker now.',
            201
        );
    }

    /** Edit a keyword — rename, re-categorise, re-grade its risk, or toggle it on/off. */
    public function update(Request $request, FindingKeyword $findingKeyword, \App\Services\FaultTypeRegistrar $registrar)
    {
        $data = $this->validatePayload($request, $findingKeyword);

        // Captured BEFORE the write: the twin is found by the word it currently carries, and after the
        // update that word is gone — a rename would otherwise create a second row instead of moving one.
        $previousName = $findingKeyword->keyword;

        $findingKeyword->update($data);

        // Re-wording the keyword changes what the ontology must match on.
        $findingKeyword->syncCanonicalTerms();
        KeywordOntologyService::flushCache();

        // The chip has to say what the library says. A rename that reached only this table would leave
        // the picker offering the old wording and the two tables describing one fault differently.
        $registrar->syncFaultTypeFrom($findingKeyword, $previousName);

        return ResponseHelper::SuccessResponse(new FindingKeywordResource($findingKeyword->fresh()), 'Keyword updated', 200);
    }

    /** Remove a keyword from the library (does not touch any historical findings that used it). */
    public function destroy(FindingKeyword $findingKeyword, \App\Services\FaultTypeRegistrar $registrar)
    {
        // The chip goes with the word. Left behind it would be a tappable fault with no grade, no
        // Arabic and nothing the matcher knows — the same dead end, pointing the other way. A type
        // tasks were typed from is retired rather than deleted; the registrar draws that line.
        $registrar->withdrawFaultTypeFor($findingKeyword);

        // Terms, profile and run history cascade with the concept (FK onDelete cascade).
        $findingKeyword->delete();
        KeywordOntologyService::flushCache();

        return ResponseHelper::SuccessResponse(null, 'Keyword removed from the library', 200);
    }

    /**
     * Shared validation. On update, `$existing` lets the (category, keyword) uniqueness rule ignore
     * the row itself. `category_label` is derived from the chosen category so the two never drift.
     */
    private function validatePayload(Request $request, ?FindingKeyword $existing = null): array
    {
        $categories = collect(config('maintenance_findings.categories', []));

        $data = $request->validate([
            'category_key' => ['required', 'string', 'max:60', Rule::in($categories->pluck('key')->all())],
            'keyword'      => [
                'required', 'string', 'max:191',
                Rule::unique('finding_keywords', 'keyword')
                    ->where(fn ($q) => $q->where('category_key', $request->input('category_key')))
                    ->ignore($existing?->id),
            ],
            'keyword_ar'  => ['nullable', 'string', 'max:191'],
            'risk'        => ['required', Rule::in(FindingKeyword::RISKS)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        // Keep the denormalised labels in lock-step with the category slug (single source: the config).
        $category = $categories->firstWhere('key', $data['category_key']);
        $data['category_label']    = $category['label'] ?? $data['category_key'];
        $data['category_label_ar'] = $category['label_ar'] ?? null;
        $data['is_active'] = $request->boolean('is_active', $existing->is_active ?? true);

        return $data;
    }
}
