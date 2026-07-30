<?php

namespace App\Console\Commands;

use App\Services\Knowledge\RepairSignatureClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the `maintenance_signatures` projection from the maintenance corpus.
 *
 * Idempotent and re-runnable: the projection is derived data with no authority of its own, so the
 * safe operation is always "throw it away and rebuild". Run it after any change to the classifier's
 * pattern set, and always run `intelligence:validate-classifier` first — that command is the gate,
 * this one is the apply.
 *
 * A human label on the ticket always wins over a derived one; where a ticket has both, the human
 * signatures are written as `human` and any EXTRA signatures the notes surfaced are still written
 * as `derived`. That matters: 14.5% of validated events are cases where the human saw a fault the
 * notes did not mention, and the reverse happens too. Keeping both is how the projection stays
 * richer than either source alone.
 *
 * TWO SOURCES, BOTH REQUIRED — this is load-bearing:
 *
 *   LEGACY   `maintenances.service_main` + `service_sup` + `maintenance_notes` — the imported
 *            N-Maintenance sheet, 26,690 tickets of free text.
 *   WORKFLOW `maintenance_tasks.symptom` + `notes` — structured faults, written ONLY by the live
 *            ticket workflow, which records nothing in the legacy free-text fields.
 *
 * Reading only the legacy fields was a real defect: at the time it was found, just 7 of 149
 * workflow-native tickets appeared in the projection at all. Nothing was visibly broken, because
 * the sheet still carries the volume — but every ticket the new workflow creates would have been
 * invisible to the intelligence layer, and the corpus would have quietly frozen at the moment the
 * sheet was retired. A history that stops growing stops being history.
 *
 * The live delivery path (OperationalIntelligence) already classifies `maintenance_tasks`, so an
 * open ticket produced signatures for TODAY's card while contributing nothing to TOMORROW's history.
 * This closes that asymmetry: one classifier, one vocabulary, both halves of the corpus.
 */
class RebuildMaintenanceSignatures extends Command
{
    // NOTE: the version option is `--classifier-version`, not `--version`. Symfony Console reserves
    // `--version`/`-V` globally, so a command that declares it silently prints the framework version
    // and exits instead of running.
    protected $signature = 'intelligence:rebuild-signatures
                            {--classifier-version=v1 : Classifier version stamped on every row}
                            {--fresh : Delete the existing projection before rebuilding}
                            {--dry-run : Report what would be written without writing it}';

    protected $description = 'Rebuild the canonical repair-signature projection from maintenance history';

    public function handle(RepairSignatureClassifier $classifier): int
    {
        $version = (string) $this->option('classifier-version');
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('fresh') && ! $dryRun) {
            $this->warn('Clearing the existing projection…');
            DB::table('maintenance_signatures')->delete();
        }

        $this->info('Rebuilding signature projection'.($dryRun ? ' (DRY RUN — nothing will be written)' : '').'…');

        $stats = ['tickets' => 0, 'rows' => 0, 'human' => 0, 'derived' => 0, 'noise' => 0, 'unlabelled' => 0, 'from_tasks' => 0];
        $perSignature = [];
        $now = now();

        // Workflow faults, keyed by ticket. Loaded once: this is the small half of the corpus today
        // and the whole of it eventually.
        $taskText = DB::table('maintenance_tasks')
            ->selectRaw("maintenance_id, GROUP_CONCAT(CONCAT_WS(' ', symptom, notes) SEPARATOR ' ') AS text")
            ->groupBy('maintenance_id')
            ->pluck('text', 'maintenance_id');

        $this->line('  workflow tickets carrying structured faults: '.number_format($taskText->count()));

        DB::table('maintenances')
            ->select('id', 'vehicle_id', 'out_date', 'created_at', 'service_main', 'service_sup', 'maintenance_notes')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($classifier, $version, $dryRun, $now, $taskText, &$stats, &$perSignature) {
                $batch = [];

                foreach ($rows as $row) {
                    $stats['tickets']++;

                    $human = $classifier->fromHumanLabel($row->service_main);

                    // Legacy free text AND workflow fault text — a ticket may legitimately have both
                    // (an imported ticket later worked in the new workflow), so they are concatenated
                    // rather than chosen between.
                    $tasks = $taskText[$row->id] ?? null;
                    $text = trim(($row->maintenance_notes ?? '').' '.($row->service_sup ?? '').' '.($tasks ?? ''));

                    $result = $classifier->classify($text);
                    $derived = $result['signatures'];

                    if ($human === [] && $derived === []) {
                        $result['noise'] ? $stats['noise']++ : $stats['unlabelled']++;
                        continue;
                    }

                    if ($tasks !== null) {
                        $stats['from_tasks']++;
                    }

                    // A workflow ticket has no `out_date` until it closes, but a comeback lookup is
                    // worthless without a date — so an open ticket is dated by when it was raised.
                    // Without this every live ticket would project as NULL and be filtered out of
                    // exactly the queries it exists to feed.
                    $occurredAt = $row->out_date ?: $row->created_at;

                    // Human wins on overlap; note-only signatures are still kept as derived.
                    foreach (array_unique(array_merge($human, $derived)) as $signature) {
                        $isHuman = in_array($signature, $human, true);

                        $batch[] = [
                            'maintenance_id'     => $row->id,
                            'vehicle_id'         => $row->vehicle_id,
                            'occurred_at'        => $occurredAt,
                            'signature'          => $signature,
                            'source'             => $isHuman ? 'human' : 'derived',
                            'is_exposure'        => $classifier->isExposure($signature),
                            'matched_terms'      => isset($result['matches'][$signature])
                                ? json_encode($result['matches'][$signature])
                                : null,
                            'classifier_version' => $version,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ];

                        $stats['rows']++;
                        $isHuman ? $stats['human']++ : $stats['derived']++;
                        $perSignature[$signature] = ($perSignature[$signature] ?? 0) + 1;
                    }
                }

                if ($batch !== [] && ! $dryRun) {
                    // upsert keeps the rebuild idempotent without a prior --fresh.
                    foreach (array_chunk($batch, 500) as $slice) {
                        DB::table('maintenance_signatures')->upsert(
                            $slice,
                            ['maintenance_id', 'signature'],
                            ['vehicle_id', 'occurred_at', 'source', 'is_exposure', 'matched_terms', 'classifier_version', 'updated_at']
                        );
                    }
                }
            });

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['tickets scanned',            number_format($stats['tickets'])],
            ['signature rows written',     number_format($stats['rows'])],
            ['  from human labels',        number_format($stats['human'])],
            ['  derived from notes',       number_format($stats['derived'])],
            ['tickets w/ workflow faults',  number_format($stats['from_tasks'])],
            ['workflow noise (skipped)',   number_format($stats['noise'])],
            ['unlabelled (skipped)',       number_format($stats['unlabelled'])],
            ['tickets with ≥1 signature',  number_format($stats['tickets'] - $stats['noise'] - $stats['unlabelled'])],
        ]);

        arsort($perSignature);
        $this->line('<comment>Signature distribution</comment>');
        $this->table(
            ['Signature', 'Rows'],
            array_map(fn ($k, $v) => [$k, number_format($v)], array_keys($perSignature), $perSignature)
        );

        if ($dryRun) {
            $this->warn('Dry run — nothing written.');
        } else {
            $this->info('Projection rebuilt at version '.$version.'.');
        }

        return self::SUCCESS;
    }
}
