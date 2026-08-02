<?php

namespace App\Console\Commands;

use App\Models\ConceptBridgeLabel;
use App\Models\ConceptBridgeSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load a Concept Bridge benchmark sample (and optionally the AI baseline) from the CSVs in
 * docs/gold-set into the review tables.
 *
 *   php artisan conceptbridge:import docs/gold-set/concept-bridge-TECHNICIAN-SET.csv
 *   php artisan conceptbridge:import docs/gold-set/concept-bridge-AI-BASELINE.csv --labels=ai
 *
 * Samples are keyed on (sample_set, row_no) and upserted, so a re-run refreshes rather than
 * duplicates. HUMAN labels are never written here — they can only come from a person using the
 * review page, which is the entire point of the benchmark.
 */
class ConceptBridgeImportSamples extends Command
{
    protected $signature = 'conceptbridge:import
        {path : CSV exported from the gold-set builder}
        {--set=v2 : Which frozen sample set these rows belong to}
        {--labels= : Also import the LABEL_ columns as this source (only "ai" is accepted)}
        {--human-review : Mark these rows as the subset a technician must answer}';

    protected $description = 'Import Concept Bridge benchmark samples (and optionally the AI baseline) from CSV';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");
            return self::FAILURE;
        }

        $labelSource = $this->option('labels');
        if ($labelSource !== null && $labelSource !== ConceptBridgeLabel::SOURCE_AI) {
            // Guard rail, not a limitation: human ground truth must be typed by a human in the UI.
            $this->error('Only --labels=ai is allowed. Human labels come from the review page.');
            return self::FAILURE;
        }

        $set        = (string) $this->option('set');
        $markHuman  = (bool) $this->option('human-review');
        $fh  = fopen($path, 'r');
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $head = fgetcsv($fh);
        $col  = array_flip($head);

        foreach (['row_id', 'segment_text'] as $required) {
            if (! isset($col[$required])) {
                $this->error("Missing column '{$required}'.");
                return self::FAILURE;
            }
        }

        $samples = $labels = 0;

        DB::transaction(function () use ($fh, $col, $set, $labelSource, $markHuman, &$samples, &$labels) {
            while ($r = fgetcsv($fh)) {
                $g = fn (string $c) => isset($col[$c]) ? (trim((string) ($r[$col[$c]] ?? '')) ?: null) : null;

                $sample = ConceptBridgeSample::updateOrCreate(
                    ['sample_set' => $set, 'row_no' => (int) $g('row_id')],
                    [
                        'stratum'             => $g('stratum') ?? 'unknown',
                        ...($markHuman ? ['human_review' => true] : []),
                        'maintenance_id'      => $g('maintenance_id'),
                        'v1_signature'        => $g('v1_signature'),
                        'source_field'        => $g('source_field'),
                        'segment_text'        => (string) $g('segment_text'),
                        'pred1_concept'       => $g('pred1_concept'),
                        'pred1_score'         => $g('pred1_score'),
                        'pred1_primary_stage' => $g('pred1_primary_stage'),
                        'pred1_all_stages'    => $g('pred1_all_stages'),
                        'pred1_matched_term'  => $g('pred1_matched_term'),
                        'pred2_concept'       => $g('pred2_concept'),
                        'pred2_score'         => $g('pred2_score'),
                        'pred3_concept'       => $g('pred3_concept'),
                        'pred3_score'         => $g('pred3_score'),
                    ],
                );
                $samples++;

                if ($labelSource === null || ! $g('LABEL_pred1_verdict')) {
                    continue;
                }

                ConceptBridgeLabel::updateOrCreate(
                    [
                        'concept_bridge_sample_id' => $sample->id,
                        'source'                   => $labelSource,
                        'user_id'                  => null,
                    ],
                    [
                        'labeller'         => 'claude-opus-5',
                        'segment_type'     => $g('LABEL_segment_type'),
                        'verdict'          => $g('LABEL_pred1_verdict'),
                        'evidence_quality' => $g('LABEL_evidence_quality'),
                        'valid_concepts'   => $g('LABEL_valid_concepts'),
                        'invalid_concepts' => $g('LABEL_invalid_concepts'),
                        'missing_concepts' => $g('LABEL_missing_concepts'),
                        'notes'            => $g('LABEL_notes'),
                    ],
                );
                $labels++;
            }
        });

        fclose($fh);

        $this->info("Imported {$samples} samples into set '{$set}'."
            . ($labels ? " Wrote {$labels} {$labelSource} labels." : ''));

        return self::SUCCESS;
    }
}
