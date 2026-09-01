<?php

namespace App\Console\Commands;

use App\Models\SpareKeyRequirement;
use App\Models\Vehicle;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import the "NEED SPARE KEY" sheet as spare-key REQUIREMENTS — and nothing more.
 *
 * WHAT THE SHEET IS EVIDENCE OF, and what it is not. Each row says: somebody wrote this car down as
 * needing a spare key on a date, and later wrote DONE beside it. That is a requirement and its
 * closure. It is NOT evidence that the car has two keys today, that a key was bought, from whom, for
 * how much, or by whose approval — the sheet records none of that, and neither does this import.
 *
 * So a DONE row becomes a COMPLETED requirement carrying its own sheet dates, with:
 *   · no part request and no purchase — nobody approved or paid anything we can point at;
 *   · no vehicle_components row — a key in the asset ledger is a claim that a specific physical
 *     object exists, and inventing fourteen of them would corrupt the one count this whole feature
 *     was built to make trustworthy ("how many keys does this car actually have?").
 *
 * That leaves an honest asymmetry on an imported car: HISTORY says one requirement was raised and
 * met, CURRENT says zero keys on record. That is exactly right — we know the process happened, and
 * we have never seen the key. The vehicle panel prints both figures side by side for this reason.
 *
 * A row with a blank status is imported as still REQUIRED, so it lands on the operations board as
 * outstanding work. It raises no notification: nobody chose to raise it today.
 *
 * Idempotent by `external_ref`: re-running updates rows rather than duplicating them.
 */
class SpareKeysImportHistory extends Command
{
    protected $signature = 'spare-keys:import-history
                            {--file= : path to the JSON (defaults to database/data/spare_key_history.json)}
                            {--dry-run : resolve and report, write nothing}';

    protected $description = 'Import the "NEED SPARE KEY" sheet as historical spare-key requirements (no keys, no purchases fabricated)';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/spare_key_history.json');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = data_get(json_decode((string) file_get_contents($path), true), 'rows', []);
        if (! $rows) {
            $this->error('The file carries no `rows`.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $created = $updated = $unmatched = 0;

        foreach ($rows as $row) {
            $vehicle = $this->resolveVehicle($row);

            if (! $vehicle) {
                $unmatched++;
                $this->warn(sprintf('  ✗ no vehicle for %s %s — skipped',
                    $row['label'] ?? '?', $row['plate'] ?? $row['vin'] ?? ''));
                continue;
            }

            $done   = strtoupper(trim((string) ($row['status'] ?? ''))) === 'DONE';
            $status = $done ? SpareKeyRequirement::STATUS_COMPLETED : SpareKeyRequirement::STATUS_REQUIRED;

            $this->line(sprintf('  %s %-30s %-10s → %s',
                $dry ? '·' : '✓', $row['label'] ?? '?', $vehicle->plate_no, $status));

            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($row, $vehicle, $done, $status, &$created, &$updated) {
                $existing = SpareKeyRequirement::where('external_ref', $row['ref'])->first();

                $requirement = $existing ?: new SpareKeyRequirement();
                $requirement->fill([
                    'vehicle_id'   => $vehicle->id,
                    // The sheet never states a number. One is what "needs a spare key" means, and
                    // it is recorded as the assumption it is rather than dressed up as data.
                    'quantity'     => 1,
                    'reason_code'  => SpareKeyRequirement::REASON_MISSING,
                    'notes'        => trim(sprintf('Imported from the "NEED SPARE KEY" sheet: %s %s %s. '
                        . 'The sheet records the requirement and its dates only — no supplier, price, approval or key serial was recorded, '
                        . 'and none has been invented here.',
                        $row['label'] ?? '', $row['color'] ?? '', $row['year'] ?? '')),
                    'source'       => SpareKeyRequirement::SOURCE_SHEET_IMPORT,
                    'external_ref' => $row['ref'],
                    'started_on'   => $row['started_on'] ?? null,
                    'finished_on'  => $row['finished_on'] ?? null,
                    // Deliberately NOT the sheet's start date: requested_at is when THIS system was
                    // told, and started_on is when the sheet says the process began. Collapsing the
                    // two would make imported history indistinguishable from history we produced.
                    'requested_by_name' => 'Sheet import (NEED SPARE KEY)',
                ]);

                $requirement->status            = $status;
                $requirement->received_quantity = 0;
                // A completed row holds no open lock; an unfinished one does — and the unique index
                // means a car whose sheet row is still blank cannot also have a live app requirement.
                $requirement->open_vehicle_id   = $done ? null : $vehicle->id;
                $requirement->completed_at      = $done && isset($row['finished_on'])
                    ? Carbon::parse($row['finished_on'])->endOfDay()
                    : null;

                $requirement->save();

                $existing ? $updated++ : $created++;
            });
        }

        $this->newLine();
        $this->info(sprintf('Spare key history: %d created, %d updated, %d unmatched%s.',
            $created, $updated, $unmatched, $dry ? ' (dry run — nothing written)' : ''));

        return self::SUCCESS;
    }

    /** Plate first (the sheet's usual key), VIN when a row carries one instead. */
    private function resolveVehicle(array $row): ?Vehicle
    {
        if (! empty($row['vin'])) {
            $byVin = Vehicle::where('vin', $row['vin'])->first();
            if ($byVin) {
                return $byVin;
            }
        }

        return ! empty($row['plate'])
            ? PlateResolver::resolve($row['plate'])
            : null;
    }
}
