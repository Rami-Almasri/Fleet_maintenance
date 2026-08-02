<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ConceptBridgeLabel;
use App\Models\ConceptBridgeSample;
use App\Services\Knowledge\ConceptBridgeBenchmark;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Concept Bridge Review — the human-in-the-loop benchmark that decides whether we may enrich 26,839
 * legacy tickets with ontology concepts.
 *
 * The reviewer is shown one real ticket segment at a time and asked what it says, BEFORE the
 * matcher's prediction is revealed. That ordering is enforced by the UI on purpose: a reviewer who
 * sees our answer first tends to agree with it, and the benchmark would then measure agreement
 * instead of correctness.
 *
 * Human labels are the benchmark. The AI baseline lives in the same table under `source = ai` and is
 * never mixed into the official figure — see [[ConceptBridgeBenchmark]].
 *
 * Gated by maintenance.manage, matching the sibling human-review surface (Classification Review).
 */
class ConceptBridgeReviewController extends Controller
{
    public function __construct(private ConceptBridgeBenchmark $benchmark)
    {
    }

    /**
     * The review queue: every sample in the active set, this reviewer's own answers, and the
     * progress counters. Predictions ride along — the UI is responsible for hiding them until the
     * blind questions are answered.
     */
    public function index(Request $request)
    {
        try {
            $set  = (string) $request->query('set', 'v2');
            $mine = (int) $request->user()->id;

            // Only the flagged subset is put in front of a person. The rest of the frozen set keeps
            // its AI baseline and is reported as Track C — asking a technician to answer 210 rows
            // when 90 decide the outcome is how a benchmark never gets finished.
            $samples = ConceptBridgeSample::query()
                ->where('sample_set', $set)
                ->where('human_review', true)
                ->with(['labels' => fn ($q) => $q->where('source', ConceptBridgeLabel::SOURCE_HUMAN)])
                ->orderBy('row_no')
                ->get();

            $rows = $samples->map(function (ConceptBridgeSample $s) use ($mine) {
                $own = $s->labels->first(fn ($l) => $l->source === ConceptBridgeLabel::SOURCE_HUMAN && (int) $l->user_id === $mine);

                return [
                    'id'          => $s->id,
                    'row_no'      => $s->row_no,
                    'segment'     => $s->segment_text,
                    'ticket_id'   => $s->maintenance_id,
                    'field'       => $s->source_field,
                    // stratum and v1_signature are DELIBERATELY withheld: knowing a row was picked
                    // as "action_like" gives away the answer to the first question, and the old
                    // signature is exactly the thing we established must not drive judgement.
                    'predictions' => $s->predictions(),
                    'stage'       => $s->pred1_primary_stage,
                    'matched_term' => $s->pred1_matched_term,
                    'answer'      => $own ? [
                        'segment_type'     => $own->segment_type,
                        'verdict'          => $own->verdict,
                        'evidence_quality' => $own->evidence_quality,
                        'valid_concepts'   => $own->valid_concepts,
                        'invalid_concepts' => $own->invalid_concepts,
                        'missing_concepts' => $own->missing_concepts,
                        'notes'            => $own->notes,
                    ] : null,
                ];
            });

            return ResponseHelper::SuccessResponse([
                'sample_set' => $set,
                'rows'       => $rows,
                'progress'   => [
                    'total'    => $samples->count(),
                    'answered' => $rows->filter(fn ($r) => $r['answer'] !== null)->count(),
                ],
                'options' => [
                    'segment_type' => ConceptBridgeLabel::TYPES,
                    'verdict'      => ConceptBridgeLabel::VERDICTS,
                    'quality'      => ConceptBridgeLabel::QUALITIES,
                ],
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Save (or revise) this reviewer's answer for one sample. Idempotent per (sample, reviewer). */
    public function store(Request $request, ConceptBridgeSample $sample)
    {
        $data = $request->validate([
            'segment_type'     => ['nullable', Rule::in(ConceptBridgeLabel::TYPES)],
            'verdict'          => ['nullable', Rule::in(ConceptBridgeLabel::VERDICTS)],
            'evidence_quality' => ['nullable', Rule::in(ConceptBridgeLabel::QUALITIES)],
            'valid_concepts'   => ['nullable', 'string', 'max:1000'],
            'invalid_concepts' => ['nullable', 'string', 'max:1000'],
            'missing_concepts' => ['nullable', 'string', 'max:1000'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $label = ConceptBridgeLabel::updateOrCreate(
                [
                    'concept_bridge_sample_id' => $sample->id,
                    'source'                   => ConceptBridgeLabel::SOURCE_HUMAN,
                    'user_id'                  => $request->user()->id,
                ],
                $data + ['labeller' => $request->user()->name],
            );

            return ResponseHelper::SuccessResponse(['id' => $label->id], 'Saved.');
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The scored benchmark — Track A (human, official), Track B (AI-vs-human agreement) and
     * Track C (AI-only, indicative), plus the pre-registered decision rules.
     */
    public function results(Request $request)
    {
        try {
            $samples = ConceptBridgeSample::query()
                ->where('sample_set', (string) $request->query('set', 'v2'))
                ->with('labels')
                ->orderBy('row_no')
                ->get();

            return ResponseHelper::SuccessResponse($this->benchmark->score($samples));
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
