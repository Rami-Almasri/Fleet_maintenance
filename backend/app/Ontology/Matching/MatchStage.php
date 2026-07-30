<?php

namespace App\Ontology\Matching;

/**
 * One stage of the deterministic matching pipeline.
 *
 * Stages run in the order declared in config and each contributes evidence to the shared candidate
 * set. A stage may ADD a candidate or STRENGTHEN an existing one; it must never remove or suppress
 * another stage's finding. That rule is what keeps the pipeline explainable — every candidate's
 * presence is always traceable to a stage that put it there.
 *
 * ORDER IS SEMANTIC, NOT JUST PERFORMANCE. Exact runs before fuzzy so that a perfect match is never
 * reported as a typo correction; semantic runs last so it can only ever add recall the
 * deterministic stages missed, never outrank them.
 */
interface MatchStage
{
    /**
     * @param  array<int,MatchCandidate>  $candidates  keyed by finding_keyword_id, mutated in place
     */
    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void;

    /** Stage key, matching the config list: 'exact', 'alias', 'phrase', 'token', 'fuzzy', 'semantic'. */
    public function key(): string;
}
