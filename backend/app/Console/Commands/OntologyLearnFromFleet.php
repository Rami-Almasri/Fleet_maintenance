<?php

namespace App\Console\Commands;

use App\Services\FleetEvidenceService;
use Illuminate\Console\Command;

/**
 * Mine our own maintenance history into the knowledge graph.
 *
 *   php artisan ontology:learn-from-fleet
 *   php artisan ontology:learn-from-fleet --fleet-wide     # skip per-make edges
 *
 * Safe to schedule (nightly/weekly) — every write is an idempotent upsert keyed on
 * (fault, outcome, scope), so a re-run refreshes counts rather than duplicating edges.
 *
 * See [[FleetEvidenceService]] for what it actually learns and why the two data sources differ.
 * Expect the structured-workflow numbers to be small until the workflow engine has been in use for
 * a while; the co-occurrence numbers, mined from years of historical maintenance rows, are where
 * the volume is today.
 */
class OntologyLearnFromFleet extends Command
{
    protected $signature = 'ontology:learn-from-fleet
        {--fleet-wide : Only learn fleet-wide patterns, skipping per-manufacturer edges}';

    protected $description = 'Turn our own maintenance history into weighted knowledge-graph edges';

    public function handle(FleetEvidenceService $fleet): int
    {
        $this->info('Mining maintenance history into the knowledge graph…');
        $this->newLine();

        $stats = $fleet->learn(perMake: ! $this->option('fleet-wide'));

        $this->table(
            ['What was learned', 'Edges'],
            [
                ['Fault → repair that actually fixed it', $stats['resolutions']],
                ['Fault → root cause actually found', $stats['causes']],
                ['Faults worked on together (co-occurrence)', $stats['cooccurrence']],
                ['Faults with measured turnaround time', $stats['labor_updated']],
            ]
        );

        if ($stats['skipped_unmatched'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d history row(s) mentioned a symptom the ontology could not confidently recognise.',
                $stats['skipped_unmatched']
            ));
            // This is a coverage report, not an error: an unmatched symptom is a keyword whose
            // vocabulary is too thin, and enriching it turns that row into evidence next run.
            $this->line('  These are keywords needing richer terms — run <fg=cyan>keywords:enrich</> and mine again.');
        }

        if (array_sum($stats) === 0) {
            $this->newLine();
            $this->comment('Nothing learned yet. Fleet learning needs closed tickets with a symptom '
                .'plus a root cause or resolution note, or historical maintenance rows with service details.');
        }

        return self::SUCCESS;
    }
}
