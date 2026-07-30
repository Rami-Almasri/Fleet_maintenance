<?php

namespace App\Services;

use App\Models\FindingKeyword;
use App\Models\OntologyFeedback;
use Illuminate\Database\Eloquent\Model;

/**
 * CONTINUOUS LEARNING — capturing human corrections and feeding them back into the engine.
 *
 * Every time someone edits a term, deletes a bad synonym, or tells the matcher it got a search
 * wrong, that is a piece of knowledge the engine did not have. This service records it, and — the
 * part that makes it *learning* rather than logging — turns the accumulated corrections into
 * explicit guidance injected into the next enrichment prompt for that fault.
 *
 * WHY PROMPT GUIDANCE AND NOT FINE-TUNING. Fine-tuning needs thousands of examples, produces a
 * checkpoint nobody can inspect, and makes a bad correction permanent and invisible. Feeding the
 * corrections back as instructions works from the very first rejection, is completely auditable
 * (you can read exactly what the model was told), and is undone by deleting a row. For a workshop
 * vocabulary that drifts slowly and matters a lot, that trade is the right one — and if the corpus
 * of match outcomes ever grows large enough to justify a trained retriever, these same rows are
 * already the labelled dataset for it.
 *
 * The signal that matters most is the one nobody usually captures: `reject_match` with the actual
 * query text. It says "a human read this sentence, saw what we proposed, and told us it was wrong"
 * — the only ground truth this system will ever get about its own retrieval.
 */
class OntologyFeedbackService
{
    /** How many past corrections to fold into one enrichment prompt before it stops being useful. */
    private const GUIDANCE_LIMIT = 25;

    /**
     * Record a correction.
     *
     * @param  Model|null  $subject  the row acted on (a KeywordTerm, OntologyNode, OntologyEdge…)
     * @param  array<string,mixed>  $context  before / after / reason / query_text / match_score / context
     */
    public function record(
        string $action,
        ?Model $subject,
        ?FindingKeyword $keyword = null,
        array $context = [],
        ?int $userId = null,
    ): ?OntologyFeedback {
        if (! in_array($action, OntologyFeedback::ACTIONS, true)) {
            return null;
        }

        return OntologyFeedback::create([
            'action'             => $action,
            'subject_type'       => $subject ? $subject::class : ($context['subject_type'] ?? null),
            'subject_id'         => $subject?->getKey(),
            'finding_keyword_id' => $keyword?->id,
            'before'             => $context['before'] ?? null,
            'after'              => $context['after'] ?? null,
            'query_text'         => $context['query_text'] ?? null,
            'match_score'        => $context['match_score'] ?? null,
            'reason'             => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null,
            'context'            => $context['context'] ?? null,
            'user_id'            => $userId,
        ]);
    }

    /**
     * The learned guidance for one fault, as prompt text.
     *
     * Reads the corrections a human has made to this concept and renders them as instructions the
     * model must follow. Returns an empty string when there is nothing learned yet, so the prompt
     * stays clean on a first run.
     *
     * Note it deliberately teaches from BOTH directions: what was rejected (don't do this again)
     * and what was hand-written (this is how our workshop actually says it). The second is the more
     * valuable of the two and the one a naive "log the deletions" implementation would miss.
     */
    public function guidanceFor(FindingKeyword $keyword): string
    {
        $feedback = OntologyFeedback::query()
            ->where('finding_keyword_id', $keyword->id)
            ->latest()
            ->limit(self::GUIDANCE_LIMIT)
            ->get();

        if ($feedback->isEmpty()) {
            return '';
        }

        $rejected = $feedback
            ->whereIn('action', [OntologyFeedback::ACTION_REJECT, OntologyFeedback::ACTION_DELETE])
            ->map(function (OntologyFeedback $f) {
                $term = $f->before['term'] ?? $f->before['label'] ?? null;

                return $term ? '"'.$term.'"'.($f->reason ? " (staff said: {$f->reason})" : '') : null;
            })
            ->filter()->unique()->values();

        $authored = $feedback
            ->whereIn('action', [OntologyFeedback::ACTION_CREATE, OntologyFeedback::ACTION_EDIT])
            ->map(fn (OntologyFeedback $f) => $f->after['term'] ?? $f->after['label'] ?? null)
            ->filter()->unique()->values();

        $badMatches = $feedback
            ->where('action', OntologyFeedback::ACTION_REJECT_MATCH)
            ->pluck('query_text')
            ->filter()->unique()->values();

        $lines = [];

        if ($rejected->isNotEmpty()) {
            $lines[] = 'The workshop has REJECTED these terms for this fault. Do not generate them '
                .'again, and do not generate close variants of them: '.$rejected->take(15)->implode(', ').'.';
        }

        if ($authored->isNotEmpty()) {
            $lines[] = 'The workshop has hand-written these terms for this fault — this is how the '
                .'staff here actually word it. Treat them as the house vocabulary and generate terms '
                .'consistent with that register: '.$authored->take(15)->implode(', ').'.';
        }

        if ($badMatches->isNotEmpty()) {
            $lines[] = 'These searches were WRONGLY matched to this fault, meaning its terms are too '
                .'broad or overlap another fault. Tighten the vocabulary so these no longer match: '
                .$badMatches->take(10)->map(fn ($q) => '"'.mb_substr($q, 0, 120).'"')->implode('; ').'.';
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nWHAT THIS WORKSHOP HAS ALREADY CORRECTED\n"
            .'This fault has been curated by staff before. Their corrections override general '
            ."automotive practice — they know their own fleet and their own wording.\n- "
            .implode("\n- ", $lines);
    }

    /** Stamp feedback as folded into a prompt, so the model isn't nagged with it forever. */
    public function markApplied(FindingKeyword $keyword): void
    {
        OntologyFeedback::query()
            ->where('finding_keyword_id', $keyword->id)
            ->unapplied()
            ->update(['applied_at' => now()]);
    }

    /**
     * Health of the learning loop, for the admin screen: how much signal exists, how much of it is
     * negative, and how much labelled retrieval data has accumulated.
     */
    public function stats(): array
    {
        $all = OntologyFeedback::query()
            ->selectRaw('action, COUNT(*) as c')
            ->groupBy('action')
            ->pluck('c', 'action');

        return [
            'total'          => (int) $all->sum(),
            'corrections'    => (int) ($all['edit'] ?? 0) + (int) ($all['create'] ?? 0),
            'rejections'     => (int) ($all['reject'] ?? 0) + (int) ($all['delete'] ?? 0),
            'match_labels'   => (int) ($all['confirm_match'] ?? 0) + (int) ($all['reject_match'] ?? 0),
            'pending_apply'  => OntologyFeedback::query()->unapplied()->count(),
        ];
    }
}
