<?php

namespace App\Ontology\Confidence;

use App\Models\EvidenceLink;
use App\Models\KeywordTerm;
use App\Models\OntologyEdge;
use App\Models\OntologyFeedback;
use App\Ontology\Matching\MatchCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Folds the knowledge layers' confidence into a match.
 *
 * The matching stages can only speak to one dimension: did the words line up. But a fault backed by
 * three OEM citations, 214 fleet observations and a human-curated vocabulary deserves more
 * confidence than an identical word match on a fault nobody has ever verified — and that difference
 * is invisible to any lexical scorer.
 *
 * This is what makes "every layer exposes confidence" true rather than aspirational: after the
 * pipeline has produced candidates, each one is credited with
 *
 *   documentation  — how well its vocabulary is cited (from evidence_links)
 *   fleet          — how much measured history stands behind it (from fleet-sourced edges)
 *   human          — whether staff have curated it (human-authored terms, confirmed matches)
 *
 * BATCHED ON PURPOSE. Three queries for the whole candidate set, not three per candidate. A match
 * runs on every keystroke in some surfaces, so an N+1 here would be felt immediately — and a
 * confidence model that is too slow to run is one that gets switched off.
 */
class ConfidenceEnricher
{
    /**
     * @param  array<int,MatchCandidate>  $candidates  keyed by finding_keyword_id
     */
    public function enrich(array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $ids = array_keys($candidates);

        $this->addDocumentation($candidates, $ids);
        $this->addFleet($candidates, $ids);
        $this->addHuman($candidates, $ids);
    }

    /**
     * Documentation confidence — the average grounding of a fault's citations.
     *
     * Uses the stored [[EvidenceLink]] rows rather than re-retrieving, because this must be cheap.
     * A `model_prior` link scores low by design, so a fault "cited" only by the model's own
     * training correctly produces weak documentation confidence rather than none — the distinction
     * between "unverified" and "unknown" matters to whoever reads the result.
     *
     * @param  array<int,MatchCandidate>  $candidates
     * @param  array<int,int>  $ids
     */
    private function addDocumentation(array $candidates, array $ids): void
    {
        // Evidence hangs off both the concept and its terms, so gather term ids per concept first.
        $termsByKeyword = KeywordTerm::query()
            ->whereIn('finding_keyword_id', $ids)
            ->get(['id', 'finding_keyword_id'])
            ->groupBy('finding_keyword_id')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $allTermIds = $termsByKeyword->flatten()->all();

        $links = EvidenceLink::query()
            ->where(function ($q) use ($ids, $allTermIds) {
                $q->where(fn ($w) => $w->where('evidenceable_type', \App\Models\FindingKeyword::class)->whereIn('evidenceable_id', $ids));
                if ($allTermIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('evidenceable_type', KeywordTerm::class)->whereIn('evidenceable_id', $allTermIds));
                }
            })
            ->get(['evidenceable_type', 'evidenceable_id', 'retrieval_method', 'confidence', 'document_title']);

        if ($links->isEmpty()) {
            return;
        }

        // Map every link back to its concept.
        $termToKeyword = [];
        foreach ($termsByKeyword as $keywordId => $termIds) {
            foreach ($termIds as $termId) {
                $termToKeyword[$termId] = $keywordId;
            }
        }

        $byKeyword = [];
        foreach ($links as $link) {
            $keywordId = $link->evidenceable_type === KeywordTerm::class
                ? ($termToKeyword[$link->evidenceable_id] ?? null)
                : $link->evidenceable_id;

            if ($keywordId !== null) {
                $byKeyword[$keywordId][] = $link;
            }
        }

        foreach ($byKeyword as $keywordId => $keywordLinks) {
            if (! isset($candidates[$keywordId])) {
                continue;
            }

            $grounded = collect($keywordLinks)->filter(fn ($l) => $l->retrieval_method !== EvidenceLink::METHOD_MODEL_PRIOR);
            $score = collect($keywordLinks)->avg(fn (EvidenceLink $l) => $l->groundingScore());

            $candidates[$keywordId]->addConfidence(
                'documentation',
                (float) $score,
                $grounded->isNotEmpty()
                    ? $grounded->count().' cited source(s): '.$grounded->pluck('document_title')->filter()->unique()->take(2)->implode('; ')
                    : 'no documentation retrieved — model knowledge only',
            );
        }
    }

    /**
     * Fleet confidence — how much of our own measured history stands behind this fault.
     *
     * Driven by total observations rather than any single edge: a fault we have seen 300 times
     * across many relationships is well understood here, whatever the manuals say. Logarithmic,
     * because the difference between 5 and 50 cases matters far more than between 300 and 350.
     *
     * @param  array<int,MatchCandidate>  $candidates
     * @param  array<int,int>  $ids
     */
    private function addFleet(array $candidates, array $ids): void
    {
        $stats = DB::table('ontology_edges')
            ->join('ontology_nodes', 'ontology_nodes.id', '=', 'ontology_edges.from_node_id')
            ->whereIn('ontology_nodes.finding_keyword_id', $ids)
            ->where('ontology_edges.source', OntologyEdge::SOURCE_FLEET)
            ->where('ontology_edges.is_active', true)
            ->groupBy('ontology_nodes.finding_keyword_id')
            ->selectRaw('ontology_nodes.finding_keyword_id as keyword_id,
                         SUM(ontology_edges.observed_count) as observations,
                         COUNT(*) as relationships')
            ->get();

        foreach ($stats as $row) {
            if (! isset($candidates[$row->keyword_id]) || $row->observations <= 0) {
                continue;
            }

            // 5 observations ≈ 55, 50 ≈ 80, 500 ≈ 95. Capped below 100: our history is the best
            // evidence about OUR fleet, never proof about vehicles in general.
            $score = min(95, 35 + log10(max(1, (int) $row->observations)) * 30);

            $candidates[$row->keyword_id]->addConfidence(
                'fleet',
                $score,
                sprintf('%d observation(s) across %d learned relationship(s) in our own history',
                    (int) $row->observations, (int) $row->relationships),
            );
        }
    }

    /**
     * Human confidence — has anyone here actually verified this?
     *
     * The rarest and strongest signal in the system. Counts both hand-written terms and confirmed
     * match outcomes, because both are a person saying "yes, this is right". Rejected matches
     * deliberately do NOT reduce it: a rejection is a correction to the vocabulary (handled by
     * enrichment), not evidence that the concept itself is untrustworthy.
     *
     * @param  array<int,MatchCandidate>  $candidates
     * @param  array<int,int>  $ids
     */
    private function addHuman(array $candidates, array $ids): void
    {
        $humanTerms = KeywordTerm::query()
            ->whereIn('finding_keyword_id', $ids)
            ->where('source', KeywordTerm::SOURCE_HUMAN)
            ->groupBy('finding_keyword_id')
            ->selectRaw('finding_keyword_id, COUNT(*) as c')
            ->pluck('c', 'finding_keyword_id');

        $confirmations = OntologyFeedback::query()
            ->whereIn('finding_keyword_id', $ids)
            ->where('action', OntologyFeedback::ACTION_CONFIRM_MATCH)
            ->groupBy('finding_keyword_id')
            ->selectRaw('finding_keyword_id, COUNT(*) as c')
            ->pluck('c', 'finding_keyword_id');

        foreach ($ids as $id) {
            $terms = (int) ($humanTerms[$id] ?? 0);
            $confirmed = (int) ($confirmations[$id] ?? 0);

            if ($terms === 0 && $confirmed === 0) {
                continue;
            }

            $score = min(95, 60 + ($terms * 6) + ($confirmed * 8));
            $reasons = [];
            if ($terms > 0) {
                $reasons[] = "{$terms} term(s) written by your staff";
            }
            if ($confirmed > 0) {
                $reasons[] = "{$confirmed} match(es) confirmed by staff";
            }

            $candidates[$id]->addConfidence('human', $score, implode(', ', $reasons));
        }
    }
}
