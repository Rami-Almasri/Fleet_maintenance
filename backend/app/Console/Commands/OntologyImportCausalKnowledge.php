<?php

namespace App\Console\Commands;

use App\Ontology\Learning\CausalKnowledgeImporter;
use App\Services\KeywordOntologyService;
use Illuminate\Console\Command;

/**
 * Connects the curated symptom → root-cause catalogue to the knowledge graph.
 *
 * Re-runnable: the graph upserts, and human-authored edges are never overwritten by a later run.
 */
class OntologyImportCausalKnowledge extends Command
{
    protected $signature = 'ontology:import-causal-knowledge {--dry-run : Report what would be imported without writing}';

    protected $description = 'Import the approved fault_causes catalogue into the ontology graph as causal edges';

    public function handle(CausalKnowledgeImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        $stats = $importer->import($dryRun);

        $this->table(
            ['Symptoms matched', 'Cause nodes', 'Causal edges', 'Causes that are tracked faults'],
            [[$stats['symptoms'], $stats['causes'], $stats['edges'], $stats['linked']]],
        );

        if ($stats['unmatched'] !== []) {
            $this->newLine();
            $this->warn('Catalogue symptoms with no matching fault concept ('.count($stats['unmatched']).'):');
            foreach ($stats['unmatched'] as $label) {
                $this->line('  · '.$label);
            }
        }

        if (! $dryRun) {
            KeywordOntologyService::flushCache();
        }

        return self::SUCCESS;
    }
}
