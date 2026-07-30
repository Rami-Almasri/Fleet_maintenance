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

        $stats = ['tickets' => 0, 'rows' => 0, 'human' => 0, 'derived' => 0, 'noise' => 0, 'unlabelled' => 0];
        $perSignature = [];
        $now = now();

        DB::table('maintenances')
            ->select('id', 'vehicle_id', 'out_date', 'service_main', 'service_sup', 'maintenance_notes')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($classifier, $version, $dryRun, $now, &$stats, &$perSignature) {
                $batch = [];

                foreach ($rows as $row) {
                    $stats['tickets']++;

                    $human = $classifier->fromHumanLabel($row->service_main);
                    $result = $classifier->classify(trim(($row->maintenance_notes ?? '').' '.($row->service_sup ?? '')));
                    $derived = $result['signatures'];

                    if ($human === [] && $derived === []) {
                        $result['noise'] ? $stats['noise']++ : $stats['unlabelled']++;
                        continue;
                    }

                    // Human wins on overlap; note-only signatures are still kept as derived.
                    foreach (array_unique(array_merge($human, $derived)) as $signature) {
                        $isHuman = in_array($signature, $human, true);

                        $batch[] = [
                            'maintenance_id'     => $row->id,
                            'vehicle_id'         => $row->vehicle_id,
                            'occurred_at'        => $row->out_date,
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
