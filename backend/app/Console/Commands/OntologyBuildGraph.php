<?php

namespace App\Console\Commands;

use App\Models\ActionCatalog;
use App\Models\FindingKeyword;
use App\Models\KeywordProfile;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Services\KeywordOntologyService;
use App\Services\OntologyGraphService;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns the fault vocabulary into a connected knowledge graph.
 *
 * WHY THIS EXISTS. The category seeders already record each concept's components, causes,
 * inspection steps and repair actions — but as JSON on `keyword_profiles`, where nothing can
 * traverse them. A JSON list of components answers "what parts does this fault involve?" and
 * nothing else. The same facts as typed edges answer "what ELSE fails on the caliper?", "which
 * faults share a cause?", and "what should I inspect first?" — questions a keyword table cannot be
 * asked at all.
 *
 * The distinction the owner drew is exactly right: a large synonym dictionary is not the product.
 * The structure behind it is, and this command is what turns one into the other.
 *
 * TYPED, NOT PROMISCUOUS. Every edge carries a specific relation, because "connected to" is not a
 * useful answer. A component that a fault AFFECTS and a procedure that INSPECTS it are different
 * claims with different uses, and collapsing them into a generic link destroys the reasoning value.
 *
 *   presents_as       fault → symptom      how it shows itself
 *   affects_component fault → component    what is involved
 *   caused_by         fault → cause        why it happens
 *   inspected_by      fault → procedure    how to confirm it
 *   fixed_by          fault → repair       what resolves it (FKs to the Action Catalog)
 *   related_to        fault ↔ fault        DERIVED from shared components
 *   confused_with     fault ↔ fault        DERIVED from real matcher ambiguity
 *
 * DERIVED EDGES ARE NOT ASSERTED ONES. `related_to` and `confused_with` are computed from evidence
 * — shared components, and concepts the live matcher actually returns together with near-equal
 * scores — rather than hand-listed. A hand-written "related faults" list is one person's guess;
 * these are measurements, and they update as the vocabulary changes.
 *
 * Re-runnable. Provenance rules in [[OntologyGraphService]] mean human-curated edges survive.
 */
class OntologyBuildGraph extends Command
{
    protected $signature = 'ontology:build-graph
                            {--skip-confused : Skip the derived confused-with pass (it runs the matcher per concept)}';

    protected $description = 'Project the fault vocabulary into a typed knowledge graph';

    /** Two concepts scoring within this many points of each other are genuinely confusable. */
    private const CONFUSION_BAND = 12;

    /** Below this shared-component count, "related" is coincidence rather than signal. */
    private const MIN_SHARED_COMPONENTS = 2;

    public function handle(OntologyGraphService $graph, KeywordOntologyService $matcher): int
    {
        $stats = ['faults' => 0, 'symptoms' => 0, 'components' => 0, 'causes' => 0,
                  'procedures' => 0, 'repairs' => 0, 'related' => 0, 'confused' => 0];

        $concepts = FindingKeyword::query()->where('is_active', true)->get();
        $profiles = KeywordProfile::query()->get()->keyBy('finding_keyword_id');

        $bar = $this->output->createProgressBar($concepts->count());
        $bar->start();

        // Components per concept, kept for the related_to pass below.
        $componentsByConcept = [];

        foreach ($concepts as $concept) {
            $bar->advance();

            $faultNode = $graph->nodeForKeyword($concept);
            $stats['faults']++;

            $profile = $profiles->get($concept->id);

            if (! $profile) {
                continue;
            }

            $stats['symptoms']   += $this->link($graph, $faultNode, OntologyNode::TYPE_SYMPTOM,
                OntologyEdge::REL_PRESENTS_AS, (array) $profile->symptoms, 55);

            $components = array_values(array_filter((array) $profile->components));
            $componentsByConcept[$concept->id] = array_map(fn ($c) => TextNormalizer::key($c), $components);

            $stats['components'] += $this->link($graph, $faultNode, OntologyNode::TYPE_COMPONENT,
                OntologyEdge::REL_AFFECTS, $components, 65);

            $stats['causes']     += $this->link($graph, $faultNode, OntologyNode::TYPE_CAUSE,
                OntologyEdge::REL_CAUSED_BY, (array) $profile->likely_causes, 60);

            $stats['procedures'] += $this->link($graph, $faultNode, OntologyNode::TYPE_PROCEDURE,
                OntologyEdge::REL_INSPECTED_BY, (array) $profile->inspection_order, 60);

            $stats['repairs']    += $this->linkActions($graph, $faultNode, $concept);
        }

        $bar->finish();
        $this->newLine(2);

        $stats['related'] = $this->deriveRelated($graph, $concepts, $componentsByConcept);

        if (! $this->option('skip-confused')) {
            $stats['confused'] = $this->deriveConfusable($graph, $concepts, $matcher);
        }

        $this->table(
            ['Faults', 'Symptoms', 'Components', 'Causes', 'Procedures', 'Repairs', 'Related', 'Confused'],
            [[$stats['faults'], $stats['symptoms'], $stats['components'], $stats['causes'],
              $stats['procedures'], $stats['repairs'], $stats['related'], $stats['confused']]],
        );

        $this->line('  Total graph: '.number_format(OntologyNode::count()).' nodes · '
            .number_format(OntologyEdge::count()).' edges');

        return self::SUCCESS;
    }

    /**
     * Link a fault to a list of labels of one node type.
     *
     * Weights differ by relation because they are not equally informative: a component a fault
     * definitely involves is stronger evidence than a symptom it may present with.
     *
     * @param  array<int,string>  $labels
     */
    private function link(OntologyGraphService $graph, OntologyNode $fault, string $type, string $relation, array $labels, int $weight): int
    {
        $written = 0;

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label === '' || mb_strlen($label) > 120) {
                continue;
            }

            $node = $graph->upsertNode($type, $label, ['source' => 'seed', 'confidence' => 80]);

            if (! $node) {
                continue;
            }

            if ($graph->upsertEdge($fault, $node, $relation, [
                'source' => OntologyEdge::SOURCE_SEED, 'weight' => $weight, 'confidence' => 80,
            ])) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Repairs come from the Action Catalog rather than free text, so the graph and what a technician
     * actually records speak the same vocabulary and can be compared directly.
     */
    private function linkActions(OntologyGraphService $graph, OntologyNode $fault, FindingKeyword $concept): int
    {
        $rows = DB::table('fault_concept_actions as f')
            ->join('action_catalog as a', 'a.id', '=', 'f.action_catalog_id')
            ->where('f.finding_keyword_id', $concept->id)
            ->orderBy('f.sort_order')
            ->get(['a.id', 'a.label', 'a.slug', 'f.relevance']);

        $written = 0;

        foreach ($rows as $row) {
            $node = $graph->upsertNode(OntologyNode::TYPE_REPAIR, $row->label, [
                'source' => 'seed', 'confidence' => 85, 'key' => $row->slug,
            ]);

            if (! $node) {
                continue;
            }

            if ($graph->upsertEdge($fault, $node, OntologyEdge::REL_FIXED_BY, [
                'source'     => OntologyEdge::SOURCE_SEED,
                // A typical repair outranks a possible one, so the picker and any recommendation
                // read them in the order a technician would try them.
                'weight'     => $row->relevance === 'typical' ? 75 : 50,
                'confidence' => 82,
                'note'       => 'action_catalog:'.$row->slug,
            ])) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * RELATED_TO, derived from shared components rather than hand-listed.
     *
     * Two faults that involve the same parts genuinely belong near each other — "brake vibration"
     * and "brake noise" share pads, discs and calipers, and a technician looking at one should see
     * the other. Requiring several shared components keeps coincidence out: nearly every fault
     * touches "wiring" somewhere.
     *
     * @param  array<int,array<int,string>>  $componentsByConcept
     */
    private function deriveRelated(OntologyGraphService $graph, $concepts, array $componentsByConcept): int
    {
        $nodes = OntologyNode::query()
            ->whereIn('finding_keyword_id', $concepts->pluck('id'))
            ->ofType(OntologyNode::TYPE_FAULT)
            ->get()
            ->keyBy('finding_keyword_id');

        $written = 0;
        $ids = array_keys($componentsByConcept);

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $shared = array_intersect($componentsByConcept[$a], $componentsByConcept[$b]);

                if (count($shared) < self::MIN_SHARED_COMPONENTS) {
                    continue;
                }

                $from = $nodes->get($a);
                $to   = $nodes->get($b);

                if (! $from || ! $to) {
                    continue;
                }

                // Both directions: "related" is symmetric, and a one-way edge would make the answer
                // depend on which concept you happened to start from.
                foreach ([[$from, $to], [$to, $from]] as [$x, $y]) {
                    if ($graph->upsertEdge($x, $y, OntologyEdge::REL_RELATED_TO, [
                        'source'     => OntologyEdge::SOURCE_SEED,
                        'weight'     => min(80, 40 + count($shared) * 10),
                        'confidence' => 75,
                        'note'       => count($shared).' shared component(s)',
                    ])) {
                        $written++;
                    }
                }
            }
        }

        return $written;
    }

    /**
     * CONFUSED_WITH, measured rather than guessed.
     *
     * Each concept's own name is put through the live matcher. Whatever ELSE comes back within a few
     * points is, by definition, something the system itself cannot cleanly separate — and if the
     * matcher cannot, a person reading the same words probably cannot either.
     *
     * This is the edge that makes the graph self-aware about its own ambiguity, and it is worth more
     * than a hand-written list because it updates automatically as vocabulary is added: fix the
     * wording and the confusion edge disappears on the next run.
     */
    private function deriveConfusable(OntologyGraphService $graph, $concepts, KeywordOntologyService $matcher): int
    {
        $nodes = OntologyNode::query()
            ->whereIn('finding_keyword_id', $concepts->pluck('id'))
            ->ofType(OntologyNode::TYPE_FAULT)
            ->get()
            ->keyBy('finding_keyword_id');

        $written = 0;

        $bar = $this->output->createProgressBar($concepts->count());
        $bar->start();

        // Confusion is measured on the phrases PEOPLE ACTUALLY TYPE, not on canonical names.
        //
        // The first version tested each concept's own name and produced almost nothing — of course
        // it did: the names were written to be distinct. Real confusion lives in the symptom. "the
        // car shakes" spans brake vibration, wheel imbalance and worn suspension; "noise from the
        // front" spans brakes, bearings and suspension. Those are the sentences that arrive in a
        // complaint, and those are what must be tested.
        $phrases = \App\Models\KeywordTerm::query()
            ->where('is_active', true)
            ->whereIn('kind', [
                \App\Models\KeywordTerm::KIND_CUSTOMER_PHRASE,
                \App\Models\KeywordTerm::KIND_SYNONYM,
                \App\Models\KeywordTerm::KIND_WORKSHOP_PHRASE,
            ])
            ->where('lang', 'en')
            ->get(['finding_keyword_id', 'term']);

        $bar->setMaxSteps($phrases->count());

        /** @var array<string,array{from:int,to:int,gap:int,count:int,phrase:string}> $pairs */
        $pairs = [];

        foreach ($phrases as $phrase) {
            $bar->advance();

            $results = $matcher->resolve($phrase->term, ['limit' => 3]);
            $self = $results->first();

            // The phrase must actually lead to its OWN concept first. If it does not, that is a
            // vocabulary defect for ontology:duplicates to report, not a confusion pair.
            if (! $self || $self['keyword']->id !== $phrase->finding_keyword_id) {
                continue;
            }

            foreach ($results->slice(1) as $other) {
                $gap = $self['score'] - $other['score'];

                if ($gap > self::CONFUSION_BAND) {
                    continue;
                }

                // Normalised pair key so A↔B is counted once however it was reached — confusion is
                // symmetric, and counting both directions separately would double every weight.
                $a = min($phrase->finding_keyword_id, $other['keyword']->id);
                $b = max($phrase->finding_keyword_id, $other['keyword']->id);

                if ($a === $b) {
                    continue;
                }

                $key = $a.':'.$b;
                $pairs[$key]['from']   = $a;
                $pairs[$key]['to']     = $b;
                $pairs[$key]['count']  = ($pairs[$key]['count'] ?? 0) + 1;
                $pairs[$key]['gap']    = min($pairs[$key]['gap'] ?? 99, $gap);
                $pairs[$key]['phrase'] = $pairs[$key]['phrase'] ?? $phrase->term;
            }
        }

        foreach ($pairs as $pair) {
            $from = $nodes->get($pair['from']);
            $to   = $nodes->get($pair['to']);

            if (! $from || ! $to) {
                continue;
            }

            // Weight rises with how MANY phrases confuse the pair and how CLOSE the scores were —
            // one near-tie is a curiosity, twelve is a disambiguation the UI should force.
            $weight = min(90, 30 + $pair['count'] * 8 + max(0, self::CONFUSION_BAND - $pair['gap']) * 2);
            $note = sprintf('%d phrase(s) match both, closest gap %d — e.g. "%s"',
                $pair['count'], $pair['gap'], $pair['phrase']);

            foreach ([[$from, $to], [$to, $from]] as [$x, $y]) {
                if ($graph->upsertEdge($x, $y, OntologyEdge::REL_CONFUSED_WITH, [
                    'source'     => OntologyEdge::SOURCE_SEED,
                    'weight'     => $weight,
                    'confidence' => 70,
                    'note'       => $note,
                ])) {
                    $written++;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        return $written;
    }
}
