<?php

namespace App\Ontology\Reasoning;

use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;

/**
 * Customer sentence in, engineering interpretation out.
 *
 * "The steering wheel shakes only while braking at highway speed" is not a fault name, and no amount
 * of keyword matching alone turns it into one. It carries two separable things:
 *
 *   the SYMPTOM     the steering wheel shakes    → matched against the concept vocabulary
 *   the CONDITIONS  only while braking, at highway speed → extracted here
 *
 * The conditions are the diagnostic content. "Shakes" alone spans wheel balance, alignment, worn
 * suspension and warped rotors; "shakes WHILE BRAKING" collapses that to the brake rotors almost by
 * itself. A technician does this narrowing instinctively, which is exactly why a system that drops
 * the qualifier and keeps only the noun feels stupid to the people using it.
 *
 * WHAT THIS CLASS IS NOT. It is not a second matcher. Concept matching is delegated entirely to the
 * existing pipeline via [[KeywordOntologyService]] — customer phrasing was made matchable by adding
 * it to the vocabulary (see CustomerVocabularySeeder), not by writing new matching logic. This class
 * adds only what the pipeline structurally cannot: condition extraction, and the reasoning that
 * follows a matched concept into its probable causes.
 *
 * AMBIGUITY IS AN OUTPUT, NOT A FAILURE. When a complaint genuinely supports several readings, all
 * of them are returned with an `ambiguous` flag. Customers describe sensations, and sensations map
 * to several faults; picking one and hiding the rest would be inventing a diagnosis the words do not
 * support.
 */
class ComplaintInterpreter
{
    /**
     * Diagnostic conditions worth extracting, as normalised phrase => (label, discriminates-toward).
     *
     * The `hints` are concept names this condition makes MORE likely — used to break ties, never to
     * create a match on their own. A condition is evidence about which fault, not evidence that
     * there is one.
     */
    private const CONDITIONS = [
        'when braking'        => ['label' => 'while braking',        'hints' => ['Vibration when braking', 'Brake noise (squeal / grind)', 'Pulling to one side']],
        'while braking'       => ['label' => 'while braking',        'hints' => ['Vibration when braking', 'Brake noise (squeal / grind)', 'Pulling to one side']],
        'when i brake'        => ['label' => 'while braking',        'hints' => ['Vibration when braking', 'Brake noise (squeal / grind)']],
        'when stopping'       => ['label' => 'while braking',        'hints' => ['Vibration when braking', 'Brake noise (squeal / grind)']],
        'when slowing'        => ['label' => 'while braking',        'hints' => ['Brake noise (squeal / grind)']],
        'when cold'           => ['label' => 'only when cold',       'hints' => ['Rough idle / misfire', 'Hard starting']],
        'in the morning'      => ['label' => 'only when cold',       'hints' => ['Hard starting', 'Battery / won\'t start']],
        'when hot'            => ['label' => 'only when hot',        'hints' => ['Overheating', 'Stalling']],
        'at highway speed'    => ['label' => 'at high speed',        'hints' => ['Steering vibration', 'Wheel balancing']],
        'at high speed'       => ['label' => 'at high speed',        'hints' => ['Steering vibration', 'Wheel balancing']],
        'when turning'        => ['label' => 'while turning',        'hints' => ['Wheel-bearing noise', 'Power-steering leak', 'Hard / heavy steering']],
        'over bumps'          => ['label' => 'over bumps',           'hints' => ['Knocking over bumps', 'Worn shock / strut']],
        'when accelerating'   => ['label' => 'while accelerating',   'hints' => ['Loss of power', 'Rough idle / misfire', 'Gear slipping']],
        'uphill'              => ['label' => 'under load',           'hints' => ['Loss of power', 'Overheating']],
        'when idle'           => ['label' => 'at idle',              'hints' => ['Rough idle / misfire', 'Stalling']],
        'at traffic lights'   => ['label' => 'at idle',              'hints' => ['Rough idle / misfire', 'Stalling']],
        'when reversing'      => ['label' => 'while reversing',      'hints' => ['Brake noise (squeal / grind)', 'Delayed engagement']],
        'when changing gear'  => ['label' => 'while shifting',       'hints' => ['Hard / jerky shifting', 'Gear slipping']],
        'after rain'          => ['label' => 'after rain',           'hints' => ['Brake noise (squeal / grind)', 'Wiring / fuse issue']],
        'when ac is on'       => ['label' => 'with the A/C running', 'hints' => ['Overheating', 'Rough idle / misfire']],
    ];

    /** How much a matching condition may lift a candidate. Deliberately modest — see the class doc. */
    private const CONDITION_BONUS = 8;

    /** Two candidates within this many points of each other are treated as genuinely ambiguous. */
    private const AMBIGUITY_BAND = 10;

    public function __construct(
        private readonly KeywordOntologyService $matcher,
        private readonly CausalReasoner $reasoner,
    ) {
    }

    /**
     * Interpret a complaint.
     *
     * @param  array<int,string>  $scopeChain
     * @return array<string,mixed>
     */
    public function interpret(string $complaint, array $scopeChain = [VehicleScope::UNIVERSAL], int $limit = 4): array
    {
        $complaint = trim($complaint);

        if ($complaint === '') {
            return $this->empty($complaint);
        }

        $conditions = $this->extractConditions($complaint);
        $matches = $this->matcher->resolve($complaint, ['limit' => $limit + 2, 'scope' => $scopeChain]);

        if ($matches->isEmpty()) {
            return $this->empty($complaint, $conditions);
        }

        $hinted = collect($conditions)->flatMap(fn (array $c) => $c['hints'])->unique()->all();

        $ranked = $matches
            ->map(function (array $match) use ($hinted) {
                $isHinted = in_array($match['keyword']->keyword, $hinted, true);

                return [
                    'concept'    => $match['keyword'],
                    'score'      => $match['score'] + ($isHinted ? self::CONDITION_BONUS : 0),
                    'base_score' => $match['score'],
                    'hinted'     => $isHinted,
                    'confidence' => $match['confidence_detail'],
                    'matches'    => $match['matches'],
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        $top = $ranked->first();
        $runnerUp = $ranked->get(1);
        $ambiguous = $runnerUp !== null && ($top['score'] - $runnerUp['score']) <= self::AMBIGUITY_BAND;

        return [
            'complaint'    => $complaint,
            'language'     => TextNormalizer::isArabic($complaint) ? 'ar' : 'en',
            'conditions'   => array_values(array_map(fn (array $c) => $c['label'], $conditions)),
            'interpreted'  => true,
            'ambiguous'    => $ambiguous,
            'candidates'   => $ranked->map(fn (array $r) => [
                'id'                => $r['concept']->id,
                'concept'           => $r['concept']->keyword,
                'concept_ar'        => $r['concept']->keyword_ar,
                'category'          => $r['concept']->category_key,
                'score'             => $r['score'],
                'condition_boosted' => $r['hinted'],
                'confidence'        => $r['confidence'],
                'matched_on'        => $r['matches'],
            ])->all(),

            // Causes for the leading candidate only. Expanding every candidate would produce a wall
            // of hypotheses that reads as noise; the runner-up is one click away.
            'probable_causes' => array_map(
                fn (ProbableCause $c) => $c->toArray(),
                $this->reasoner->causesOf($top['concept'], $scopeChain, 5),
            ),

            'explanation' => $this->explain($complaint, $top, $conditions, $ambiguous, $runnerUp),
        ];
    }

    /**
     * Find the diagnostic conditions in the sentence.
     *
     * Normalised substring matching rather than regex: the normaliser already folds punctuation,
     * casing and Arabic orthography, so "when I brake", "when i brake!" and "When  I  Brake" all
     * reduce to the same string here. Keeping this on the shared normaliser is what stops the
     * complaint path from drifting into its own dialect of "the same text".
     *
     * @return array<string,array{label:string,hints:array<int,string>}>
     */
    private function extractConditions(string $complaint): array
    {
        $normalized = TextNormalizer::key($complaint);
        $found = [];

        foreach (self::CONDITIONS as $phrase => $meta) {
            if (str_contains($normalized, TextNormalizer::key($phrase))) {
                // Keyed by label so "when braking" and "while braking" collapse to one condition.
                $found[$meta['label']] = [
                    'label' => $meta['label'],
                    'hints' => array_merge($found[$meta['label']]['hints'] ?? [], $meta['hints']),
                ];
            }
        }

        return $found;
    }

    /** The reasoning, in the order a person would say it. */
    private function explain(string $complaint, array $top, array $conditions, bool $ambiguous, ?array $runnerUp): array
    {
        $lines = [];

        $lines[] = sprintf('Read "%s" as %s.', $complaint, $top['concept']->keyword);

        if ($top['matches'] !== []) {
            $first = $top['matches'][0];
            $lines[] = sprintf('Matched on the %s wording "%s" (%s).',
                str_replace('_', ' ', (string) ($first['kind'] ?? 'known')),
                $first['term'],
                $first['how'],
            );
        }

        if ($conditions !== []) {
            $labels = implode(' and ', array_map(fn (array $c) => $c['label'], $conditions));
            $lines[] = $top['hinted']
                ? sprintf('The customer said it happens %s, which points to this fault specifically.', $labels)
                : sprintf('The customer said it happens %s.', $labels);
        } else {
            $lines[] = 'No condition was given (when it happens), which would narrow this considerably.';
        }

        if ($ambiguous && $runnerUp) {
            $lines[] = sprintf('Close alternative: %s. The wording supports both — confirm on inspection.',
                $runnerUp['concept']->keyword);
        }

        return $lines;
    }

    /** @param array<string,array{label:string,hints:array<int,string>}> $conditions */
    private function empty(string $complaint, array $conditions = []): array
    {
        return [
            'complaint'       => $complaint,
            'language'        => TextNormalizer::isArabic($complaint) ? 'ar' : 'en',
            'conditions'      => array_values(array_map(fn (array $c) => $c['label'], $conditions)),
            'interpreted'     => false,
            'ambiguous'       => false,
            'candidates'      => [],
            'probable_causes' => [],
            'explanation'     => ['Nothing in this complaint matched a known fault concept. It is worth adding the customer\'s wording to the vocabulary.'],
        ];
    }
}
