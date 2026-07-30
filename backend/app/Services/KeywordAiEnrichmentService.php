<?php

namespace App\Services;

use App\Models\EvidenceLink;
use App\Models\FindingKeyword;
use App\Models\KeywordEnrichmentRun;
use App\Models\KeywordProfile;
use App\Models\KeywordTerm;
use App\Models\KnowledgeSource;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Ontology\Contracts\ExtractionProvider;
use App\Ontology\Contracts\ResearchProvider;
use App\Ontology\DTO\RetrievedPassage;
use App\Ontology\Retrieval\RetrievalManager;
use App\Ontology\Versioning\RunProvenance;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The enrichment engine — turns one fault keyword into a grounded, cited, connected knowledge entry.
 *
 * A run produces, for a single fault:
 *  - SURFACE FORMS   synonyms, workshop wording, abbreviations, spelling variants, deliberate
 *                    misspellings, Arabic — each with a confidence and, where documentation backed
 *                    it, a citation.
 *  - GRAPH EDGES     fault → symptoms / components / causes / repairs / procedures / related faults,
 *                    and repair → parts / tools / skills. See [[OntologyGraphService]].
 *  - REPAIR INTEL    complexity, book-time range, tools, skills, ordered inspection path, cost band.
 *  - EVIDENCE        an [[EvidenceLink]] per claim, naming the document and passage behind it — or
 *                    honestly marked `model_prior` when nothing was retrieved.
 *
 * ── WHY TWO API CALLS ───────────────────────────────────────────────────────────────────────────
 *
 * Retrieval and extraction are deliberately separate requests:
 *
 *   1. RESEARCH — web search + fetch, restricted to the allow-listed domains of the registered
 *      public-web sources. Free-form output; the model reads and summarises real documentation.
 *   2. EXTRACT  — no tools, structured outputs against a locked JSON schema, given the research
 *      brief plus any local corpus passages.
 *
 * They cannot be one call: structured outputs (`output_config.format`) are incompatible with the
 * citation blocks the search tools emit, so forcing them together returns a 400. Splitting them is
 * also just better RAG — the expensive, variable-latency retrieval step is separable, cacheable and
 * skippable, and the extraction step is cheap and deterministic. Step 1 is skipped entirely when
 * web grounding is off or unavailable, and the run continues on corpus + prior alone.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────────────────────────
 *
 * It never retrieves from a `licensed` source without credentials. ALLDATA, Mitchell 1, Haynes,
 * Chilton and OEM factory manuals are paid and copyrighted; the allowlist handed to the search tool
 * is built only from sources registered as public-web. See [[KnowledgeRetrievalService]].
 *
 * ── HUMANS ALWAYS WIN ───────────────────────────────────────────────────────────────────────────
 *
 * A term or edge whose source is `human` is never rewritten, and the corrections a workshop has
 * already made are fed back into the prompt by [[OntologyFeedbackService]] so the same mistake is
 * not regenerated. The admin's `finding_keywords.risk` is never touched — the model's opinion lands
 * in `keyword_profiles.severity_estimate` beside it.
 */
class KeywordAiEnrichmentService
{
    public function __construct(
        private readonly RetrievalManager $retrieval,
        private readonly OntologyGraphService $graph,
        private readonly OntologyFeedbackService $feedback,
        private readonly ResearchProvider $research,
        private readonly ExtractionProvider $extraction,
        private readonly RunProvenance $provenance,
    ) {
    }

    /**
     * Enrichment needs an extraction provider; everything else degrades. Research, corpus and
     * embeddings are all optional — a run with none of them still produces a valid (if less
     * grounded) entry, and says so through its confidence.
     */
    public static function isConfigured(): bool
    {
        return app(ExtractionProvider::class)->isAvailable();
    }

    /**
     * Enrich one fault.
     *
     * @param  string  $scopeKey  '*' for universal knowledge, or a VehicleScope key to generate a
     *                            manufacturer-specific overlay ("what does this mean on a BMW?")
     */
    public function enrich(
        FindingKeyword $keyword,
        ?int $userId = null,
        bool $dryRun = false,
        string $scopeKey = VehicleScope::UNIVERSAL,
    ): KeywordEnrichmentRun {
        $run = KeywordEnrichmentRun::create([
            'finding_keyword_id' => $keyword->id,
            'status'             => KeywordEnrichmentRun::STATUS_PENDING,
            'triggered_by'       => $userId,
        ]);

        $startedAt = microtime(true);
        $usage = ['input' => 0, 'output' => 0];

        try {
            if (! self::isConfigured()) {
                throw new RuntimeException(
                    'No extraction provider is available. Configure one in config/knowledge_platform.php '
                    .'(current: '.$this->extraction->name().').'
                );
            }

            $scopeChain = [VehicleScope::UNIVERSAL];
            if ($scopeKey !== VehicleScope::UNIVERSAL) {
                $scopeChain[] = $scopeKey;
            }

            // ---- Step 0: what does the knowledge layer already hold? --------------------------
            // One call across EVERY registered source — corpus, fleet history, and anything added
            // later. This service has no idea which sources exist, which is the whole point.
            $passages = $this->retrieval->retrieve(
                trim($keyword->keyword.' '.$keyword->description),
                $scopeChain,
            );

            // ---- Step 1: research the open literature (optional, provider-agnostic) -----------
            $research = $this->runResearch($keyword, $scopeKey, $usage);

            // Research passages join the corpus and fleet ones — same shape, same citation path.
            $passages = array_merge($passages, $research['passages']);
            $grounding = $this->retrieval->renderGrounding($passages);

            // ---- Step 2: extract the structured entry -----------------------------------------
            $payload = $this->runExtraction($keyword, $scopeKey, $grounding, $research['findings'], $usage);

            if ($dryRun) {
                $run->delete();

                return $this->previewRun($keyword, $payload, $usage, $startedAt);
            }

            $counts = DB::transaction(function () use ($keyword, $payload, $grounding, $scopeKey) {
                $termCounts = $this->writeTerms($keyword, $payload, $grounding['index']);
                $this->writeProfile($keyword, $payload, $scopeKey, $grounding['index']);
                $edges = $this->writeGraph($keyword, $payload, $scopeKey, $grounding['index']);

                return $termCounts + ['edges' => $edges];
            });

            // Corrections folded into this prompt shouldn't be repeated at the model forever.
            $this->feedback->markApplied($keyword);
            KeywordOntologyService::flushCache();

            // Everything that could make a regenerated run differ — providers, versions, and a
            // snapshot of the knowledge base itself. See [[RunProvenance]].
            $retrievalSummary = collect($passages)
                ->groupBy(fn (RetrievedPassage $p) => $p->source)
                ->map(fn ($g) => $g->count())
                ->all();

            $run->update(array_merge(
                $this->provenance->stamp($scopeKey, $retrievalSummary),
                [
                    'status'          => KeywordEnrichmentRun::STATUS_SUCCESS,
                    'terms_added'     => $counts['added'],
                    'terms_updated'   => $counts['updated'],
                    'profile_written' => true,
                    'input_tokens'    => $usage['input'],
                    'output_tokens'   => $usage['output'],
                    'duration_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
                ]
            ));
        } catch (Throwable $e) {
            Log::warning('Keyword enrichment failed', [
                'keyword_id' => $keyword->id,
                'keyword'    => $keyword->keyword,
                'error'      => $e->getMessage(),
            ]);

            $run->update([
                'status'        => KeywordEnrichmentRun::STATUS_FAILED,
                'error'         => mb_substr($e->getMessage(), 0, 500),
                'input_tokens'  => $usage['input'],
                'output_tokens' => $usage['output'],
                'duration_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $run->fresh();
    }

    // ===========================================================================================
    // Step 1 — research (provider-agnostic)
    // ===========================================================================================

    /**
     * Read the open technical literature via the configured [[ResearchProvider]].
     *
     * The engine does not know or care which vendor does this. When no research provider is
     * configured — an air-gapped deployment, or one that trusts only its own licensed corpus — the
     * null provider returns an empty result and the run continues on corpus and fleet knowledge
     * alone, reporting lower documentation confidence rather than failing.
     *
     * @return array{findings:string,passages:array<int,RetrievedPassage>}
     */
    private function runResearch(FindingKeyword $keyword, string $scopeKey, array &$usage): array
    {
        if (! $this->research->isAvailable()) {
            return ['findings' => '', 'passages' => []];
        }

        $context = collect([
            'Category: '.$keyword->category_label,
            $scopeKey === VehicleScope::UNIVERSAL
                ? 'Cover vehicles in general.'
                : 'Focus specifically on '.VehicleScope::label($scopeKey).'.',
            filled($keyword->description) ? 'Admin note: '.$keyword->description : null,
        ])->filter()->implode("\n");

        $result = $this->research->research(
            $keyword->keyword,
            $context,
            \App\Models\KnowledgeSource::allowedDomains(),
        );

        $usage['input']  += $result->inputTokens;
        $usage['output'] += $result->outputTokens;

        return [
            'findings' => $result->ok() ? $result->findings : '',
            'passages' => $result->ok() ? $result->passages : [],
        ];
    }

    // ===========================================================================================
    // Step 2 — extract (provider-agnostic)
    // ===========================================================================================

    /**
     * Turn the retrieved knowledge into the structured entry via the configured
     * [[ExtractionProvider]].
     *
     * Separate request from research on purpose — see the class doc. The provider guarantees the
     * payload matches the schema, however it achieves that, so nothing below parses prose.
     */
    private function runExtraction(
        FindingKeyword $keyword,
        string $scopeKey,
        array $grounding,
        string $researchFindings,
        array &$usage,
    ): array {
        $result = $this->extraction->extract(
            $this->systemPrompt(),
            $this->userPrompt($keyword, $scopeKey, $grounding, $researchFindings),
            $this->schema(),
        );

        $usage['input']  += $result->inputTokens;
        $usage['output'] += $result->outputTokens;

        if (! $result->ok()) {
            throw new RuntimeException('Extraction failed: '.$result->error);
        }

        return $result->data;
    }
    // ===========================================================================================
    // Writing
    // ===========================================================================================

    /**
     * Upsert terms and attach their evidence.
     *
     * @return array{added:int,updated:int}
     */
    private function writeTerms(FindingKeyword $keyword, array $payload, array $index): array
    {
        $added = $updated = 0;
        $seen = [];

        foreach ($payload['terms'] ?? [] as $spec) {
            $term = trim((string) ($spec['term'] ?? ''));
            $key  = TextNormalizer::key($term);

            if ($term === '' || $key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $existing = $keyword->terms()->where('normalized', $key)->first();

            // The contract that makes re-enrichment safe: a human-owned row is untouchable.
            if ($existing && $existing->source === KeywordTerm::SOURCE_HUMAN) {
                continue;
            }

            $attributes = [
                'term'               => $term,
                'lang'               => in_array($spec['lang'] ?? null, ['en', 'ar'], true)
                                            ? $spec['lang']
                                            : (TextNormalizer::isArabic($term) ? 'ar' : 'en'),
                'kind'               => in_array($spec['kind'] ?? null, KeywordTerm::KINDS, true)
                                            ? $spec['kind'] : KeywordTerm::KIND_SYNONYM,
                'confidence'         => max(0, min(100, (int) ($spec['confidence'] ?? 80))),
                'source_quality'     => in_array($spec['source_quality'] ?? null, KeywordTerm::SOURCE_QUALITIES, true)
                                            ? $spec['source_quality'] : null,
                'workshop_frequency' => in_array($spec['workshop_frequency'] ?? null, KeywordTerm::FREQUENCIES, true)
                                            ? $spec['workshop_frequency'] : null,
                'source'             => KeywordTerm::SOURCE_AI,
                'is_active'          => true,
            ];

            if ($existing) {
                if ($existing->kind === KeywordTerm::KIND_CANONICAL) {
                    unset($attributes['kind'], $attributes['source']);
                }
                $existing->update($attributes);
                $row = $existing;
                $updated++;
            } else {
                $row = $keyword->terms()->create($attributes + ['normalized' => $key]);
                $added++;
            }

            $this->attachEvidence($row, $spec["evidence_refs"] ?? [], $index, (int) ($spec["confidence"] ?? 70));
        }

        $keyword->syncCanonicalTerms();

        return ['added' => $added, 'updated' => $updated];
    }

    /** Write the profile, including repair intelligence, for the requested vehicle scope. */
    private function writeProfile(FindingKeyword $keyword, array $payload, string $scopeKey, array $index): void
    {
        $repair = $payload['repair'] ?? [];
        $parts  = explode('|', $scopeKey);

        $profile = KeywordProfile::updateOrCreate(
            ['finding_keyword_id' => $keyword->id, 'scope_key' => $scopeKey],
            [
                'make'              => $scopeKey === VehicleScope::UNIVERSAL ? null : ($parts[0] ?? null),
                'model_name'        => $parts[1] ?? null,
                'generation'        => $parts[2] ?? null,

                'vehicle_system'    => $this->str($payload['vehicle_system'] ?? null, 80),
                'subsystem'         => $this->str($payload['subsystem'] ?? null, 80),
                'repair_discipline' => $this->str($payload['repair_discipline'] ?? null, 40),
                'severity_estimate' => in_array($payload['severity_estimate'] ?? null, FindingKeyword::RISKS, true)
                                          ? $payload['severity_estimate'] : null,
                'summary_en'        => $this->str($payload['summary_en'] ?? null, 1000),
                'summary_ar'        => $this->str($payload['summary_ar'] ?? null, 1000),

                'symptoms'          => $this->list($payload['symptoms'] ?? []),
                'components'        => $this->list($payload['components'] ?? []),
                'likely_causes'     => $this->list($payload['likely_causes'] ?? []),
                'repair_actions'    => $this->list($payload['repair_actions'] ?? []),
                'related_faults'    => $this->list($payload['related_faults'] ?? []),

                // --- repair intelligence ---
                'complexity'        => in_array($repair['complexity'] ?? null, ['trivial', 'routine', 'moderate', 'complex', 'specialist'], true)
                                          ? $repair['complexity'] : null,
                'labor_hours_min'   => $this->num($repair['labor_hours_min'] ?? null),
                'labor_hours_max'   => $this->num($repair['labor_hours_max'] ?? null),
                'required_tools'    => $this->list($repair['required_tools'] ?? []),
                'required_skills'   => $this->list($repair['required_skills'] ?? []),
                'inspection_order'  => $this->list($repair['inspection_order'] ?? []),
                'cost_min'          => $this->num($repair['cost_min'] ?? null),
                'cost_max'          => $this->num($repair['cost_max'] ?? null),
                'cost_currency'     => 'AED',

                'evidence_sources'  => $this->list($payload['evidence_sources'] ?? []),
                'confidence'        => max(0, min(100, (int) ($payload['confidence'] ?? 0))),
                'model'             => config('keyword_ai.model'),
                'enriched_at'       => now(),
            ]
        );

        $this->attachEvidence($profile, $payload["evidence_refs"] ?? [], $index, (int) ($payload["confidence"] ?? 60));

        // Grounding: what share of this entry actually rests on something we retrieved.
        $profile->update(['grounding_score' => app(MatchExplanationService::class)->groundingScore($keyword)]);

        if (blank($keyword->keyword_ar) && filled($payload['canonical_ar'] ?? null)) {
            $keyword->update(['keyword_ar' => $this->str($payload['canonical_ar'], 191)]);
        }
    }

    /**
     * Turn the generated relationships into graph nodes and edges.
     *
     * The relation determines the node type, so the model never has to name a type — it says
     * "caused by worn friction material" and the graph knows that target is a `cause`. Fewer
     * degrees of freedom in the schema means fewer ways for a run to produce an unusable graph.
     */
    private function writeGraph(FindingKeyword $keyword, array $payload, string $scopeKey, array $index): int
    {
        $faultNode = $this->graph->nodeForKeyword($keyword);
        $written = 0;

        $typeForRelation = [
            OntologyEdge::REL_PRESENTS_AS   => OntologyNode::TYPE_SYMPTOM,
            OntologyEdge::REL_AFFECTS       => OntologyNode::TYPE_COMPONENT,
            OntologyEdge::REL_CAUSED_BY     => OntologyNode::TYPE_CAUSE,
            OntologyEdge::REL_FIXED_BY      => OntologyNode::TYPE_REPAIR,
            OntologyEdge::REL_INSPECTED_BY  => OntologyNode::TYPE_PROCEDURE,
            OntologyEdge::REL_RELATED_TO    => OntologyNode::TYPE_FAULT,
        ];

        // Repairs are indexed as we create them, so the requires_* edges below can hang off the
        // right repair node instead of re-resolving it by label.
        $repairNodes = [];

        foreach ($payload['relationships'] ?? [] as $rel) {
            $relation = $rel['relation'] ?? null;
            $label    = trim((string) ($rel['target'] ?? ''));

            if (! isset($typeForRelation[$relation]) || $label === '') {
                continue;
            }

            $node = $this->graph->upsertNode($typeForRelation[$relation], $label, [
                'source'     => 'ai',
                'confidence' => max(0, min(100, (int) ($rel['confidence'] ?? 70))),
            ], $scopeKey);

            if (! $node) {
                continue;
            }

            $edge = $this->graph->upsertEdge($faultNode, $node, $relation, [
                'weight'     => max(0, min(100, (int) ($rel['weight'] ?? 50))),
                'confidence' => max(0, min(100, (int) ($rel['confidence'] ?? 70))),
                'source'     => OntologyEdge::SOURCE_AI,
                'note'       => $this->str($rel['note'] ?? null, 500),
            ], $scopeKey);

            if ($edge) {
                $this->attachEvidence($edge, $rel["evidence_refs"] ?? [], $index, (int) ($rel["confidence"] ?? 70));
                $written++;
            }

            if ($relation === OntologyEdge::REL_FIXED_BY) {
                $repairNodes[TextNormalizer::key($label)] = $node;
            }
        }

        // Execution requirements hang off the REPAIR, not the fault — "requires a torque wrench" is
        // a property of the job, and modelling it that way is what lets two faults share a repair
        // and inherit its parts list.
        foreach ($payload['repair_requirements'] ?? [] as $req) {
            $repairKey = TextNormalizer::key($req['repair'] ?? '');
            $target    = trim((string) ($req['target'] ?? ''));
            $relation  = $req['relation'] ?? null;

            $typeFor = [
                OntologyEdge::REL_REQUIRES_PART  => OntologyNode::TYPE_PART,
                OntologyEdge::REL_REQUIRES_TOOL  => OntologyNode::TYPE_TOOL,
                OntologyEdge::REL_REQUIRES_SKILL => OntologyNode::TYPE_SKILL,
            ];

            if (! isset($repairNodes[$repairKey], $typeFor[$relation]) || $target === '') {
                continue;
            }

            $node = $this->graph->upsertNode($typeFor[$relation], $target, ['source' => 'ai'], $scopeKey);

            if ($node && $this->graph->upsertEdge($repairNodes[$repairKey], $node, $relation, [
                'weight'     => 70,
                'confidence' => 75,
                'source'     => OntologyEdge::SOURCE_AI,
            ], $scopeKey)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Attach citations to whatever the model just backed up.
     *
     * `$refs` are passage numbers from the grounding block, and `$index` maps them to the
     * [[RetrievedPassage]] objects that produced them — whatever source those came from. Because
     * every retriever emits the same shape, one code path cites a corpus chunk, a web page and a
     * fleet statistic identically, and each carries its own honest `retrieval_method`.
     *
     * Anything the model did not cite falls back to a `model_prior` link — recorded rather than
     * omitted, because "we know this is ungrounded" is itself a fact the confidence model needs.
     *
     * @param  array<int,int>  $refs
     * @param  array<int,RetrievedPassage>  $index
     */
    private function attachEvidence(object $subject, array $refs, array $index, int $confidence): void
    {
        $subject->evidence()->delete();     // one authoritative citation set per run
        $wrote = false;

        foreach (array_slice($refs, 0, 5) as $ref) {
            $passage = $index[(int) $ref] ?? null;
            if (! $passage instanceof RetrievedPassage) {
                continue;
            }

            EvidenceLink::create(array_merge(
                [
                    'evidenceable_type' => $subject::class,
                    'evidenceable_id'   => $subject->getKey(),
                ],
                $passage->toEvidenceAttributes($confidence),
            ));
            $wrote = true;
        }

        if (! $wrote) {
            EvidenceLink::create([
                'evidenceable_type' => $subject::class,
                'evidenceable_id'   => $subject->getKey(),
                'retrieval_method'  => EvidenceLink::METHOD_MODEL_PRIOR,
                'document_title'    => 'Model knowledge (no document retrieved)',
                'confidence'        => min(70, $confidence),
                'retrieved_at'      => now(),
            ]);
        }
    }

    // ===========================================================================================
    // Prompts & schema
    // ===========================================================================================

    private function systemPrompt(): string
    {
        $sources = collect(config('keyword_ai.sources'))->map(fn ($s) => "- {$s}")->implode("\n");
        $t = config('keyword_ai.targets');

        return <<<PROMPT
        You are a master automotive diagnostic technician and terminologist building the knowledge
        base for a vehicle fleet maintenance system in the United Arab Emirates. Its users are
        mechanics, service advisors, workshop staff, inspectors and fleet supervisors, working in
        English and Gulf/Modern Standard Arabic.

        For ONE fault you produce three things: every realistic way a human would write it, the
        relationships that connect it to the rest of the vehicle, and what repairing it takes.

        GROUNDING
        Prefer retrieved documentation over recollection. Where a RETRIEVED DOCUMENTATION block is
        supplied, cite the passage numbers that support each item in its `evidence_refs`. Where
        nothing was retrieved, still answer — but leave `evidence_refs` empty rather than inventing
        a citation. An honest gap is far more useful to us than a fabricated source.
        Trusted bodies of knowledge:
        {$sources}

        SURFACE FORMS
        Return {$t['min_terms']}-{$t['max_terms']} terms covering ALL of:
        - obvious AND non-obvious wording (for "Engine overheating": overheating, high coolant
          temperature, coolant boiling, temperature warning, temp gauge high, radiator overheating,
          engine running hot, coolant loss, overheat warning light);
        - true synonyms (for "Brake noise": brake squeal, squeaking brakes, grinding brakes,
          scraping brakes, brake rubbing, metallic brake noise);
        - real workshop and service-advisor phrasing, including how a customer describes it verbally;
        - abbreviations and their punctuated variants where they genuinely exist (A/C, AC, aircon,
          HVAC; ABS; TPMS; EGR). Never invent an abbreviation nobody uses;
        - British/American spelling variants (tyre/tire, windscreen/windshield);
        - at least {$t['min_misspell']} REAL misspellings workshop staff actually type ("over
          heating", "break noise", "radiater", "coolent leak"). Spell them wrong on purpose — they
          exist to maximise search matching;
        - at least {$t['min_arabic']} Arabic terms: the formal wording plus how a Gulf technician or
          driver really says it (ارتفاع حرارة المحرك، حرارة المحرك، الموتر يسخن، السيارة تسخن).

        Every term must denote THIS fault specifically. A term that would equally describe a
        different fault makes the search return the wrong answer — precision beats volume.

        RELATIONSHIPS
        Return the graph edges that connect this fault to the vehicle: the symptoms it presents as,
        the components it affects, its causes, the repairs that fix it, the procedures that diagnose
        it, and the faults it is related to or confused with. `weight` is how strong or how common
        the relationship is (0-100) — causes and repairs should be weighted by how often they are
        the answer, because that ranking is what a technician actually reads.

        Then, in `repair_requirements`, attach the parts, tools and skills each REPAIR needs (not
        the fault — the repair), matching the `repair` field to a repair you listed above.

        REPAIR INTELLIGENCE
        `complexity` is about who can do the job: trivial / routine / moderate / complex /
        specialist, where `specialist` means it cannot be done by a general workshop. Labour hours
        are documented book time, not elapsed workshop time. `inspection_order` is the ordered
        diagnostic path — cheapest and most likely check first — because that ordering is the single
        most useful thing you can give a technician who is standing in front of the car.

        `severity_estimate` is your independent professional read using exactly this scale: critical
        (unsafe to drive / grounds the car), moderate (needs attention soon, still usable), routine
        (scheduled or cosmetic). Judge the fault itself; ignore whatever grade the fleet assigned.

        Be accurate and specific. If unsure about a term, lower its confidence rather than dropping
        it — but never fabricate a component, cause, procedure or labour figure.
        PROMPT;
    }

    private function userPrompt(FindingKeyword $keyword, string $scopeKey, array $grounding, string $researchFindings): string
    {
        $lines = [
            'Fault / maintenance topic: '.$keyword->keyword,
            'Category: '.$keyword->category_label.' ('.$keyword->category_key.')',
            'Fleet risk grade (context only — do not copy into severity_estimate): '.$keyword->risk,
        ];

        if (filled($keyword->keyword_ar)) {
            $lines[] = 'Existing Arabic name: '.$keyword->keyword_ar;
        }
        if (filled($keyword->description)) {
            $lines[] = 'Admin note: '.$keyword->description;
        }

        if ($scopeKey !== VehicleScope::UNIVERSAL) {
            $lines[] = '';
            $lines[] = 'VEHICLE SCOPE: '.VehicleScope::label($scopeKey).'. Return only knowledge that '
                .'is specific to these vehicles — wording, causes, procedures or parts that differ '
                .'from the general case. Omit anything that is simply true of all cars.';
        }

        $prompt = implode("\n", $lines);

        if (filled($grounding['text'])) {
            $prompt .= $grounding['text'];
        }

        if (filled($researchFindings)) {
            $prompt .= "\n\nRESEARCH FINDINGS\nA prior research pass read the open technical "
                ."literature for this fault and reported:\n\n".mb_substr($researchFindings, 0, 12000);
        }

        // Everything this workshop has already corrected — the continuous-learning loop.
        $prompt .= $this->feedback->guidanceFor($keyword);

        $prompt .= "\n\nBuild the complete knowledge-base entry for this fault.";

        return $prompt;
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $refs = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Passage numbers from RETRIEVED DOCUMENTATION that support this. Empty if none.'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'canonical_ar', 'vehicle_system', 'subsystem', 'repair_discipline',
                'severity_estimate', 'summary_en', 'summary_ar', 'symptoms', 'components',
                'likely_causes', 'repair_actions', 'related_faults', 'evidence_sources',
                'evidence_refs', 'confidence', 'terms', 'relationships', 'repair_requirements', 'repair',
            ],
            'properties' => [
                'canonical_ar'      => ['type' => 'string'],
                'vehicle_system'    => ['type' => 'string', 'description' => 'e.g. "Brake System".'],
                'subsystem'         => ['type' => 'string', 'description' => 'e.g. "Front disc brakes".'],
                'repair_discipline' => ['type' => 'string', 'enum' => ['mechanical', 'electrical', 'bodywork', 'air_conditioning', 'tyres', 'diagnostics', 'routine_service']],
                'severity_estimate' => ['type' => 'string', 'enum' => FindingKeyword::RISKS],
                'summary_en'        => ['type' => 'string'],
                'summary_ar'        => ['type' => 'string'],
                'symptoms'          => $strings,
                'components'        => $strings,
                'likely_causes'     => $strings,
                'repair_actions'    => $strings,
                'related_faults'    => $strings,
                'evidence_sources'  => $strings + ['description' => 'Verbatim entries from the trusted source list.'],
                'evidence_refs'     => $refs,
                'confidence'        => ['type' => 'integer'],

                'terms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['term', 'lang', 'kind', 'confidence', 'source_quality', 'workshop_frequency', 'evidence_refs'],
                        'properties' => [
                            'term'               => ['type' => 'string'],
                            'lang'               => ['type' => 'string', 'enum' => ['en', 'ar']],
                            'kind'               => ['type' => 'string', 'enum' => KeywordTerm::KINDS],
                            'confidence'         => ['type' => 'integer'],
                            'source_quality'     => ['type' => 'string', 'enum' => KeywordTerm::SOURCE_QUALITIES],
                            'workshop_frequency' => ['type' => 'string', 'enum' => KeywordTerm::FREQUENCIES],
                            'evidence_refs'      => $refs,
                        ],
                    ],
                ],

                'relationships' => [
                    'type' => 'array',
                    'description' => 'Graph edges from this fault.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['relation', 'target', 'weight', 'confidence', 'note', 'evidence_refs'],
                        'properties' => [
                            'relation' => ['type' => 'string', 'enum' => [
                                OntologyEdge::REL_PRESENTS_AS, OntologyEdge::REL_AFFECTS,
                                OntologyEdge::REL_CAUSED_BY, OntologyEdge::REL_FIXED_BY,
                                OntologyEdge::REL_INSPECTED_BY, OntologyEdge::REL_RELATED_TO,
                            ]],
                            'target'        => ['type' => 'string', 'description' => 'Short noun phrase — a reusable name, not a sentence.'],
                            'weight'        => ['type' => 'integer', 'description' => 'How strong/common this relationship is, 0-100.'],
                            'confidence'    => ['type' => 'integer'],
                            'note'          => ['type' => 'string'],
                            'evidence_refs' => $refs,
                        ],
                    ],
                ],

                'repair_requirements' => [
                    'type' => 'array',
                    'description' => 'Parts, tools and skills each repair needs.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['repair', 'relation', 'target'],
                        'properties' => [
                            'repair'   => ['type' => 'string', 'description' => 'Must match a fixed_by target above.'],
                            'relation' => ['type' => 'string', 'enum' => [
                                OntologyEdge::REL_REQUIRES_PART, OntologyEdge::REL_REQUIRES_TOOL, OntologyEdge::REL_REQUIRES_SKILL,
                            ]],
                            'target'   => ['type' => 'string'],
                        ],
                    ],
                ],

                'repair' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['complexity', 'labor_hours_min', 'labor_hours_max', 'required_tools', 'required_skills', 'inspection_order', 'cost_min', 'cost_max'],
                    'properties' => [
                        'complexity'       => ['type' => 'string', 'enum' => ['trivial', 'routine', 'moderate', 'complex', 'specialist']],
                        'labor_hours_min'  => ['type' => 'number'],
                        'labor_hours_max'  => ['type' => 'number'],
                        'required_tools'   => $strings,
                        'required_skills'  => $strings,
                        'inspection_order' => $strings + ['description' => 'Ordered diagnostic path, cheapest/most likely first.'],
                        'cost_min'         => ['type' => 'number', 'description' => 'Estimated total in AED.'],
                        'cost_max'         => ['type' => 'number'],
                    ],
                ],
            ],
        ];
    }

    // ===========================================================================================
    // Helpers
    // ===========================================================================================

    private function previewRun(FindingKeyword $keyword, array $payload, array $usage, float $startedAt): KeywordEnrichmentRun
    {
        $preview = new KeywordEnrichmentRun([
            'finding_keyword_id' => $keyword->id,
            'status'             => KeywordEnrichmentRun::STATUS_SUCCESS,
            'model'              => config('keyword_ai.model'),
            'terms_added'        => count($payload['terms'] ?? []),
            'input_tokens'       => $usage['input'],
            'output_tokens'      => $usage['output'],
            'duration_ms'        => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        $preview->setRelation('findingKeyword', $keyword);
        $preview->preview = $payload;

        return $preview;
    }

    private function str(mixed $value, int $max): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) && $value > 0 ? round((float) $value, 2) : null;
    }

    /** @return array<int,string> */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->map(fn ($v) => mb_substr($v, 0, 191))
            ->unique()->values()->all();
    }
}
