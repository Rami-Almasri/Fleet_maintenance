<?php

namespace App\Services;

use App\Models\FindingKeyword;
use App\Models\KeywordProfile;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Support\FaultVocabulary;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Support\Facades\DB;

/**
 * FLEET LEARNING — turning our own maintenance history into graph evidence.
 *
 * Public documentation says what usually causes a fault. This says what actually happened to OUR
 * cars, and that is a stronger claim: "of 214 tickets with this symptom, 92% were closed by
 * replacing the pads" beats any manual, because it is measured on this fleet, in this climate,
 * with these drivers and these garages.
 *
 * TWO SOURCES, BECAUSE THE DATA LIVES IN TWO PLACES
 *
 *  1. `maintenance_tasks` — the structured workflow: a `symptom`, a `root_cause`, a
 *     `resolution_note`, timestamps. High quality, directly gives fault → cause and fault → repair
 *     edges. TODAY THIS IS THIN (the workflow engine is new — tens of rows, not thousands), so it
 *     produces few edges now and more every month. That is expected, not a bug.
 *
 *  2. `maintenances.service_main` / `service_sup` — the historical N-Maintenance sheet, thousands
 *     of visits going back years ([[maintenance-data-architecture]]). It records what was DONE, not
 *     what was reported, so it cannot give fault → cause. What it CAN give, and what nothing else
 *     can, is CO-OCCURRENCE at scale: which issues get worked on in the same visit, and how often.
 *     Two faults appearing together across hundreds of visits is real, measured evidence of a
 *     `related_to` relationship — the "when you see this, also check that" knowledge a good
 *     supervisor has and a new one doesn't.
 *
 * Splitting and normalising the sheet's labels goes through [[FaultVocabulary]], the same way
 * MaintenanceForesightService and VehicleFaultRecurrenceService do it — one vocabulary, so the
 * graph can never disagree with the foresight engine about what an issue label means.
 *
 * EVERYTHING IT WRITES IS LABELLED `fleet`. Those edges outrank AI claims in
 * [[OntologyEdge::rank()]] and are never overwritten by enrichment, but they are also honestly
 * counted: `observed_count` and `observed_rate` travel with the edge so a 3-case pattern is never
 * presented as a 300-case one.
 *
 * RETIRED TICKETS ARE INCLUDED, DELIBERATELY. `maintenances` is soft-deleted; the raw queries below do
 * not inherit the model's scope and are not meant to. This class measures WHAT HAPPENED, and a retired
 * ticket is still a repair that occurred — excluding it would let history change whenever somebody
 * tidied the board, and would move a denominator without its numerator. Live operational surfaces take
 * the opposite rule and filter `deleted_at` explicitly. See docs/Maintenance-Deletion-Model.md.
 */
class FleetEvidenceService
{
    /** Below this many observations a pattern is an anecdote — recorded, but weighted right down. */
    private const MIN_OBSERVATIONS = 3;

    /** Co-occurrence needs a higher bar than a direct fault→repair link; visits bundle work. */
    private const MIN_COOCCURRENCE = 5;

    public function __construct(
        private readonly OntologyGraphService $graph,
        private readonly KeywordOntologyService $ontology,
    ) {
    }

    /**
     * Run the whole miner.
     *
     * @param  bool  $perMake  also mine make-scoped edges (Toyota-specific patterns), not just fleet-wide
     * @return array<string,int>  counters for the CLI
     */
    public function learn(bool $perMake = true): array
    {
        $stats = [
            'resolutions'   => 0,
            'causes'        => 0,
            'cooccurrence'  => 0,
            'labor_updated' => 0,   // retained for CLI shape; owned by the maintenance-side module
            'skipped_unmatched' => 0,
        ];

        $this->learnFromResolvedTasks($stats, $perMake);
        $this->learnCoOccurrence($stats, $perMake);

        // NOTE: measured labour time and cost are deliberately NOT learned here. Predicting how
        // long a repair takes and what it costs is owned by the maintenance-side Repair
        // Intelligence module (RepairRecommendationService over `maintenance_signatures`), which
        // already does it from ~49k real repair signatures — far better evidence than anything
        // this miner could derive, and duplicating it here would create two competing answers to
        // the same question. See [[repair-intelligence-boundary]]: the dependency runs one way,
        // maintenance reads the ontology, never the reverse.

        KeywordOntologyService::flushCache();

        return $stats;
    }

    // -------------------------------------------------------------------------------------------
    // 1. Structured workflow: symptom → what actually fixed it, and what actually caused it
    // -------------------------------------------------------------------------------------------

    /**
     * Mine closed maintenance tasks. Each gives us a symptom the inspector wrote, and — where the
     * workshop filled them in — the root cause they found and the resolution they applied.
     *
     * The symptom is resolved to a fault CONCEPT through the same ontology matcher a technician's
     * search goes through. That is deliberate: if the matcher can't recognise a symptom our own
     * workshop wrote, that is a coverage gap we want visible in `skipped_unmatched`, not silently
     * papered over with a fuzzier match rule here.
     */
    private function learnFromResolvedTasks(array &$stats, bool $perMake): void
    {
        DB::table('maintenance_tasks')
            ->leftJoin('vehicles', 'vehicles.id', '=', 'maintenance_tasks.vehicle_id')
            ->whereNotNull('maintenance_tasks.symptom')
            ->where('maintenance_tasks.symptom', '!=', '')
            ->where(function ($q) {
                $q->whereNotNull('maintenance_tasks.resolution_note')
                    ->orWhereNotNull('maintenance_tasks.root_cause');
            })
            ->select([
                'maintenance_tasks.symptom',
                'maintenance_tasks.root_cause',
                'maintenance_tasks.resolution_note',
                'maintenance_tasks.resolved_at',
                'vehicles.make',
            ])
            ->orderBy('maintenance_tasks.id')
            ->chunk(500, function ($rows) use (&$stats, $perMake) {
                // Aggregate in memory first: we want counts per (fault, outcome), not one edge
                // written per ticket. Writing per row would make the last ticket look like the
                // whole truth instead of one observation among many.
                $buckets = [];

                foreach ($rows as $row) {
                    $keyword = $this->resolveConcept($row->symptom);
                    if (! $keyword) {
                        $stats['skipped_unmatched']++;
                        continue;
                    }

                    foreach ($this->scopesFor($row->make, $perMake) as $scopeKey) {
                        foreach ([
                            [OntologyNode::TYPE_CAUSE,  OntologyEdge::REL_CAUSED_BY, $row->root_cause],
                            [OntologyNode::TYPE_REPAIR, OntologyEdge::REL_FIXED_BY,  $row->resolution_note],
                        ] as [$nodeType, $relation, $text]) {
                            $label = $this->cleanOutcomeLabel($text);
                            if ($label === null) {
                                continue;
                            }

                            $bucketKey = implode('§', [$keyword->id, $scopeKey, $relation, TextNormalizer::key($label)]);
                            $buckets[$bucketKey] ??= [
                                'keyword'  => $keyword,
                                'scope'    => $scopeKey,
                                'relation' => $relation,
                                'type'     => $nodeType,
                                'label'    => $label,
                                'count'    => 0,
                                'last'     => null,
                            ];
                            $buckets[$bucketKey]['count']++;
                            $buckets[$bucketKey]['last'] = max($buckets[$bucketKey]['last'], $row->resolved_at);
                        }
                    }
                }

                $this->writeOutcomeEdges($buckets, $stats);
            });
    }

    /**
     * Turn the aggregated buckets into fleet edges, with `observed_rate` computed against the total
     * number of observations for that (fault, relation, scope) — which is what makes the number
     * mean "92% of cases" rather than "92 cases".
     */
    private function writeOutcomeEdges(array $buckets, array &$stats): void
    {
        // Totals per (fault, relation, scope) so each outcome's share is honest.
        $totals = [];
        foreach ($buckets as $b) {
            $totals[$b['keyword']->id.'§'.$b['relation'].'§'.$b['scope']] = ($totals[$b['keyword']->id.'§'.$b['relation'].'§'.$b['scope']] ?? 0) + $b['count'];
        }

        foreach ($buckets as $b) {
            if ($b['count'] < self::MIN_OBSERVATIONS) {
                continue;
            }

            $total = $totals[$b['keyword']->id.'§'.$b['relation'].'§'.$b['scope']] ?: 1;
            $rate  = (int) round($b['count'] / $total * 100);

            $faultNode = $this->graph->nodeForKeyword($b['keyword']);
            $outcome   = $this->graph->upsertNode($b['type'], $b['label'], [
                'source'     => OntologyEdge::SOURCE_FLEET,
                'confidence' => 90,
            ], $b['scope']);

            if (! $outcome) {
                continue;
            }

            $this->graph->upsertEdge($faultNode, $outcome, $b['relation'], [
                // Weight IS the observed rate for a fleet edge — that is the whole claim.
                'weight'           => $rate,
                'confidence'       => $this->sampleConfidence($b['count']),
                'source'           => OntologyEdge::SOURCE_FLEET,
                'observed_count'   => $b['count'],
                'observed_rate'    => $rate,
                'last_observed_at' => $b['last'],
                'note'             => "{$rate}% of {$total} fleet case(s)",
            ], $b['scope']);

            $b['relation'] === OntologyEdge::REL_FIXED_BY ? $stats['resolutions']++ : $stats['causes']++;
        }
    }

    // -------------------------------------------------------------------------------------------
    // 2. Historical sheet: which issues get worked on together
    // -------------------------------------------------------------------------------------------

    /**
     * Mine `service_main` / `service_sup` for co-occurrence. Thousands of visits, each listing the
     * issues addressed; two issues that keep appearing on the same visit are related in practice
     * whatever the manual says.
     *
     * This is the one place the engine learns something no documentation contains: the failure
     * patterns of THIS fleet — the model that always needs the aircon done when the radiator is
     * touched, the pairing that means a tow rather than a drive-in.
     */
    private function learnCoOccurrence(array &$stats, bool $perMake): void
    {
        $pairs  = [];   // "conceptA§conceptB§scope" => count
        $visits = [];   // per-concept visit totals, for the rate denominator

        DB::table('maintenances')
            ->leftJoin('vehicles', 'vehicles.id', '=', 'maintenances.vehicle_id')
            ->where(function ($q) {
                $q->whereNotNull('maintenances.service_main')->where('maintenances.service_main', '!=', '');
            })
            ->select(['maintenances.service_main', 'maintenances.service_sup', 'vehicles.make'])
            ->orderBy('maintenances.id')
            ->chunk(1000, function ($rows) use (&$pairs, &$visits, $perMake) {
                foreach ($rows as $row) {
                    // Same splitter the foresight engines use — one vocabulary across the app.
                    $issues = FaultVocabulary::splitIssues($row->service_main, $row->service_sup);

                    // Resolve each label to a concept and de-duplicate: a visit that lists
                    // "Brake pads" and "Brake pad replacement" is ONE brake observation, and
                    // counting it twice would invent a self-reinforcing pattern.
                    $concepts = [];
                    foreach ($issues as $issue) {
                        if (! FaultVocabulary::isMechanical($issue)) {
                            continue;   // cosmetic rental-return work is not a failure pattern
                        }
                        $keyword = $this->resolveConcept($issue);

                        // SCHEDULED SERVICING IS EXCLUDED, and this is the single most important
                        // filter in the miner. An oil change is performed on nearly every visit
                        // whatever brought the car in, so without this it co-occurs with everything
                        // and the graph fills with "engine noise is related to oil change, 464
                        // visits" — a true count of a meaningless relationship. Co-occurrence is
                        // only evidence when both items are unplanned faults.
                        if ($keyword && $keyword->category_key === 'routine') {
                            continue;
                        }

                        if ($keyword) {
                            $concepts[$keyword->id] = $keyword;
                        }
                    }

                    if (count($concepts) < 2) {
                        // Still counts toward the denominator — a fault that usually appears alone
                        // must not get a high co-occurrence rate from its rare paired visits.
                        foreach ($concepts as $id => $_) {
                            foreach ($this->scopesFor($row->make, $perMake) as $scope) {
                                $visits[$id.'§'.$scope] = ($visits[$id.'§'.$scope] ?? 0) + 1;
                            }
                        }
                        continue;
                    }

                    $ids = array_keys($concepts);
                    sort($ids);

                    foreach ($this->scopesFor($row->make, $perMake) as $scope) {
                        foreach ($ids as $id) {
                            $visits[$id.'§'.$scope] = ($visits[$id.'§'.$scope] ?? 0) + 1;
                        }
                        // Unordered pairs — related_to is symmetric, so store one direction and
                        // write both edges at the end.
                        for ($i = 0; $i < count($ids); $i++) {
                            for ($j = $i + 1; $j < count($ids); $j++) {
                                $k = $ids[$i].'§'.$ids[$j].'§'.$scope;
                                $pairs[$k] = ($pairs[$k] ?? 0) + 1;
                            }
                        }
                    }
                }
            });

        $this->writeCoOccurrenceEdges($pairs, $visits, $stats);
    }

    /** @param array<string,int> $pairs @param array<string,int> $visits */
    private function writeCoOccurrenceEdges(array $pairs, array $visits, array &$stats): void
    {
        arsort($pairs);
        $keywords = FindingKeyword::query()->get()->keyBy('id');

        foreach ($pairs as $key => $count) {
            if ($count < self::MIN_COOCCURRENCE) {
                continue;
            }

            [$idA, $idB, $scope] = explode('§', $key);
            $a = $keywords->get((int) $idA);
            $b = $keywords->get((int) $idB);

            if (! $a || ! $b) {
                continue;
            }

            $nodeA = $this->graph->nodeForKeyword($a);
            $nodeB = $this->graph->nodeForKeyword($b);

            // Rate is directional: "when A happens, B is also worked on X% of the time" is not the
            // same statement as the reverse, and a rare fault paired with a common one would look
            // falsely strong if we used one symmetric number.
            foreach ([[$nodeA, $nodeB, (int) $idA], [$nodeB, $nodeA, (int) $idB]] as [$from, $to, $fromId]) {
                $denominator = max(1, $visits[$fromId.'§'.$scope] ?? $count);
                $rate = (int) round(min(100, $count / $denominator * 100));

                $this->graph->upsertEdge($from, $to, OntologyEdge::REL_RELATED_TO, [
                    'weight'         => $rate,
                    'confidence'     => $this->sampleConfidence($count),
                    'source'         => OntologyEdge::SOURCE_FLEET,
                    'observed_count' => $count,
                    'observed_rate'  => $rate,
                    'note'           => "worked on together in {$count} of {$denominator} visit(s)",
                ], $scope);
            }

            $stats['cooccurrence']++;
        }
    }

    // -------------------------------------------------------------------------------------------
    // 3. What the job actually takes us
    // -------------------------------------------------------------------------------------------

    // -------------------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------------------

    /** Resolve a workshop-written label to a fault concept, memoised for the length of the run. */
    private array $conceptCache = [];

    private function resolveConcept(?string $text): ?FindingKeyword
    {
        $key = TextNormalizer::key($text);
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $this->conceptCache)) {
            return $this->conceptCache[$key];
        }

        // A high floor on purpose: fleet evidence is the strongest thing in the graph, so a shaky
        // match must not be allowed to mint it. Unmatched rows are counted and reported instead.
        // FAULT LANE — fleet evidence links a repair history to the FAULT it was about; a service
        // concept minted as evidence would teach the graph that planned work is a failure (audit H6).
        $match = $this->ontology->resolve($text, [
            'limit' => 1, 'min_score' => 60, 'kinds' => [\App\Models\MaintenanceTask::KIND_FAULT],
        ])->first();

        return $this->conceptCache[$key] = $match['keyword'] ?? null;
    }

    /** Fleet-wide always; plus the make when we know it and per-make learning is on. */
    private function scopesFor(?string $make, bool $perMake): array
    {
        $scopes = [VehicleScope::UNIVERSAL];

        if ($perMake && filled($make)) {
            $scopes[] = VehicleScope::key($make);
        }

        return array_unique($scopes);
    }

    /**
     * Confidence from sample size. 3 observations is a hint (55); 50+ is solid (95). This is what
     * stops a handful of tickets from looking as authoritative as a year of history.
     */
    private function sampleConfidence(int $count): int
    {
        return (int) round(min(95, 50 + min(45, log10(max(1, $count)) * 30)));
    }

    /**
     * Tidy a free-text outcome into a node label, or reject it.
     *
     * Workshop notes are messy — "done", "ok", "fixed", ticket numbers, whole paragraphs. A node
     * label has to be a reusable NAME, so anything too short to be meaningful, too long to be a
     * name, or purely a filler word is dropped rather than polluting the graph with junk nodes.
     */
    private function cleanOutcomeLabel(?string $text): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($s === '' || mb_strlen($s) < 4 || mb_strlen($s) > 90) {
            return null;
        }

        $filler = ['done', 'ok', 'okay', 'fixed', 'completed', 'complete', 'good', 'no issue',
                   'n/a', 'na', 'none', 'nil', 'checked', 'yes', 'no'];

        if (in_array(TextNormalizer::key($s), $filler, true)) {
            return null;
        }

        // Sentences are notes, not names. Keep the first clause when someone wrote a paragraph.
        if (str_contains($s, '. ')) {
            $s = trim(explode('. ', $s)[0]);
        }

        return mb_strlen($s) >= 4 ? mb_substr($s, 0, 90) : null;
    }
}
