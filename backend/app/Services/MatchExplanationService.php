<?php

namespace App\Services;

use App\Models\EvidenceLink;
use App\Models\FindingKeyword;
use App\Models\KeywordTerm;
use App\Models\OntologyEdge;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Support\Collection;

/**
 * EXPLAINABILITY — why the engine answered the way it did, in plain sentences.
 *
 * Every match comes back with a reason list:
 *
 *      Matched: Brake vibration
 *      • matched the workshop phrase "wheel shakes when braking"
 *      • Bosch Automotive Handbook — Brake Systems, §5.2 (confidence 96%)
 *      • 214 fleet cases with this symptom; 92% were resolved by machining the rotors
 *      • usually caused by: warped brake rotors, uneven pad deposit
 *
 * WHY THIS IS A SERVICE AND NOT A STRING IN THE UI. Three reasons, all learned the hard way in
 * systems like this:
 *  - The explanation must be assembled from the SAME rows the decision used. If the UI reconstructs
 *    a plausible-sounding reason from the score, it will eventually explain a decision that didn't
 *    happen, which is worse than no explanation at all.
 *  - Every reason carries a machine-readable `kind` alongside its prose, so the frontend can style
 *    a fleet observation differently from a model prior without parsing English.
 *  - It refuses to overstate. A term generated from the model's own training with nothing retrieved
 *    produces the reason "no documentation retrieved — model knowledge only". That line is the
 *    whole point of the class: an engine that only explains itself when the answer is good is a
 *    marketing feature, not an audit trail.
 */
class MatchExplanationService
{
    public function __construct(private readonly OntologyGraphService $graph)
    {
    }

    /**
     * Build the explanation for one matched concept.
     *
     * @param  array  $match  a row from KeywordOntologyService::resolve()
     * @param  array<int,string>  $scopeChain  the vehicle the question was asked about
     * @return array{summary:string,reasons:array<int,array>,grounding:int}
     */
    public function explain(array $match, array $scopeChain = [VehicleScope::UNIVERSAL]): array
    {
        /** @var FindingKeyword $keyword */
        $keyword = $match['keyword'];
        $reasons = collect();

        $reasons = $reasons
            ->merge($this->lexicalReasons($match))
            ->merge($this->evidenceReasons($keyword))
            ->merge($this->fleetReasons($keyword, $scopeChain))
            ->merge($this->graphReasons($keyword, $scopeChain))
            ->merge($this->curationReasons($keyword));

        // Grounding is the honest headline: how much of this answer rests on something we retrieved
        // or measured, versus something the model asserted. Shown next to the match, always.
        $grounding = $this->groundingScore($keyword);

        if ($grounding < 25) {
            $reasons->push([
                'kind' => 'ungrounded',
                'text' => 'No documentation has been retrieved for this fault yet — these terms come '
                    .'from the model’s own knowledge. Treat the match as a suggestion.',
            ]);
        }

        return [
            'summary'   => $this->summary($match),
            'reasons'   => $reasons->values()->all(),
            'grounding' => $grounding,
        ];
    }

    /** One-line verdict: what matched and how confident we are. */
    private function summary(array $match): string
    {
        $name  = $match['keyword']->keyword;
        $score = $match['score'];

        return $match['confidence'] === 'strong'
            ? "Matched {$name} ({$score}/100) — the wording lines up closely with this fault."
            : "Possible match: {$name} ({$score}/100) — some of the wording overlaps this fault.";
    }

    /** Which term fired, and by which rule. The literal mechanics of the decision. */
    private function lexicalReasons(array $match): Collection
    {
        $phrasing = [
            'exact'  => 'the exact term',
            'phrase' => 'the phrase',
            'tokens' => 'the wording of',
            'fuzzy'  => 'a likely typo of',
        ];

        return collect($match['matches'] ?? [])
            ->take(3)
            ->map(function (array $hit) use ($phrasing) {
                $kindLabel = mb_strtolower(KeywordTerm::kindMeta($hit['kind'])['label']);
                $how = $phrasing[$hit['how']] ?? 'the term';

                return [
                    'kind' => 'lexical',
                    'text' => "matched {$how} \"{$hit['term']}\" ({$kindLabel})",
                    'meta' => ['how' => $hit['how'], 'term' => $hit['term'], 'score' => $hit['score']],
                ];
            });
    }

    /** Retrieved documentation backing this fault's vocabulary. */
    private function evidenceReasons(FindingKeyword $keyword): Collection
    {
        $termIds = $keyword->terms()->pluck('id');

        $links = EvidenceLink::query()
            ->retrieved()
            ->where(function ($q) use ($keyword, $termIds) {
                $q->where(fn ($w) => $w->where('evidenceable_type', FindingKeyword::class)->where('evidenceable_id', $keyword->id))
                  ->orWhere(fn ($w) => $w->where('evidenceable_type', KeywordTerm::class)->whereIn('evidenceable_id', $termIds));
            })
            ->orderByDesc('confidence')
            ->limit(3)
            ->get();

        return $links->map(fn (EvidenceLink $link) => [
            'kind' => 'evidence',
            'text' => collect([
                $link->document_title,
                $link->section,
            ])->filter()->implode(', ').' (confidence '.$link->confidence.'%)',
            'meta' => [
                'url'    => $link->url,
                'method' => $link->retrieval_method,
                'snippet'=> $link->snippet ? mb_substr($link->snippet, 0, 300) : null,
            ],
        ]);
    }

    /**
     * What our own history says. This is the reason a supervisor will actually act on, so it leads
     * with the count — "214 cases" earns trust in a way "high confidence" never does.
     */
    private function fleetReasons(FindingKeyword $keyword, array $scopeChain): Collection
    {
        $node = $keyword->ontologyNode;
        if (! $node) {
            return collect();
        }

        $edges = OntologyEdge::query()
            ->active()
            ->where('from_node_id', $node->id)
            ->where('source', OntologyEdge::SOURCE_FLEET)
            ->inScope($scopeChain)
            ->relation([OntologyEdge::REL_FIXED_BY, OntologyEdge::REL_CAUSED_BY, OntologyEdge::REL_RELATED_TO])
            ->with('to')
            ->orderByDesc('observed_count')
            ->limit(3)
            ->get();

        return $edges->filter(fn ($e) => $e->to !== null)->map(function (OntologyEdge $edge) {
            $verb = match ($edge->relation) {
                OntologyEdge::REL_FIXED_BY   => 'were resolved by',
                OntologyEdge::REL_CAUSED_BY  => 'were traced to',
                default                      => 'also involved',
            };

            $scope = VehicleScope::label($edge->scope_key);
            $where = $scope ? " on {$scope}" : '';

            return [
                'kind' => 'fleet',
                'text' => "in our own history{$where}, {$edge->observed_rate}% of {$edge->observed_count} case(s) "
                    ."{$verb} {$edge->to->label}",
                'meta' => [
                    'observed_count' => $edge->observed_count,
                    'observed_rate'  => $edge->observed_rate,
                    'relation'       => $edge->relation,
                ],
            ];
        });
    }

    /** The documented relationships — what this fault is usually caused by and fixed by. */
    private function graphReasons(FindingKeyword $keyword, array $scopeChain): Collection
    {
        $node = $keyword->ontologyNode;
        if (! $node) {
            return collect();
        }

        $profile = $this->graph->diagnosticProfile($node, $scopeChain);
        $out = collect();

        // Only report ASSERTED relationships here — the fleet ones already had their own, better,
        // reason line above, and repeating them would double-count the same evidence.
        foreach ([['causes', 'usually caused by'], ['repairs', 'usually fixed by']] as [$key, $phrase]) {
            $items = collect($profile[$key] ?? [])
                ->where('source', '!=', OntologyEdge::SOURCE_FLEET)
                ->take(3)
                ->pluck('label');

            if ($items->isNotEmpty()) {
                $out->push([
                    'kind' => 'graph',
                    'text' => "{$phrase}: ".$items->implode(', '),
                    'meta' => ['relation' => $key],
                ]);
            }
        }

        return $out;
    }

    /** Whether a human has curated this fault — the strongest trust signal available. */
    private function curationReasons(FindingKeyword $keyword): Collection
    {
        $humanTerms = $keyword->terms()->where('source', KeywordTerm::SOURCE_HUMAN)->count();

        if ($humanTerms === 0) {
            return collect();
        }

        return collect([[
            'kind' => 'curated',
            'text' => "{$humanTerms} term(s) on this fault were written or corrected by your staff",
            'meta' => ['human_terms' => $humanTerms],
        ]]);
    }

    /**
     * 0–100: how much of this fault's knowledge rests on retrieved documentation or measured fleet
     * history rather than the model's own prior. Averaged over the evidence links, so a fault with
     * one good citation and nine priors scores honestly low.
     */
    public function groundingScore(FindingKeyword $keyword): int
    {
        $termIds = $keyword->terms()->pluck('id');

        $links = EvidenceLink::query()
            ->where(function ($q) use ($keyword, $termIds) {
                $q->where(fn ($w) => $w->where('evidenceable_type', FindingKeyword::class)->where('evidenceable_id', $keyword->id))
                  ->orWhere(fn ($w) => $w->where('evidenceable_type', KeywordTerm::class)->whereIn('evidenceable_id', $termIds));
            })
            ->get();

        if ($links->isEmpty()) {
            return 0;
        }

        return (int) round($links->avg(fn (EvidenceLink $l) => $l->groundingScore()));
    }

    /**
     * Explain a match the user is about to judge, given free text — used by the tester and by any
     * caller that wants the reason before the user commits. Kept here rather than in the matcher so
     * that scoring stays a pure function and explanation stays a presentation concern.
     */
    public function explainQuery(string $text, array $match, array $scopeChain = [VehicleScope::UNIVERSAL]): array
    {
        $explanation = $this->explain($match, $scopeChain);

        $explanation['query'] = [
            'text'       => $text,
            'normalized' => TextNormalizer::key($text),
        ];

        return $explanation;
    }
}
