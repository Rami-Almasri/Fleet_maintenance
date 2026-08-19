<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\GoogleSheetsService;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * READ-ONLY: compare our `vehicles` table against the fleet register — the "Faster" tab.
 *
 * Since 2026-08-19 the sheet decides which cars exist, but the database was built while the
 * OfficeManager API decided that, and OM lists every car we have ever owned under our owner
 * number. So the table still holds cars the register does not list. This command names them
 * instead of guessing: nothing is written, nothing is deleted — deleting a car would take its
 * contracts, tickets and mileage history with it, and that history is worth keeping.
 *
 * Read the two lists as: "cars the sheet lists that we could not match" = an import problem;
 * "cars we hold that the sheet does not list" = history (mostly sold) plus, occasionally, a
 * live car someone forgot to put on the sheet — that second group is the one to act on.
 */
class FleetRegisterAudit extends Command
{
    protected $signature = 'fleet:register-audit
        {--all : List every unlisted car, not just the ones that are still in the active pool}';

    protected $description = 'READ-ONLY: which cars we hold are missing from the "Faster" fleet register (and vice versa)';

    /** Statuses that keep a car out of the working fleet, so an unlisted one is just history. */
    private const RETIRED = ['sold', 'disposed', 'returned', 'suspended'];

    public function handle(GoogleSheetsService $sheets): int
    {
        $cars      = config('google.sheets.cars');
        $headerRow = max(1, (int) ($cars['header_row'] ?? 1));

        try {
            $rows = $sheets->readByGid($cars['id'], (int) $cars['gid']);
        } catch (Throwable $e) {
            $this->error('Could not read the fleet register: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (count($rows) < $headerRow) {
            $this->error('No header row found in the fleet register.');

            return self::FAILURE;
        }

        $map = [];
        foreach ($rows[$headerRow - 1] as $i => $h) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', (string) $h))));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        if (! isset($map['chassis'])) {
            $this->error("The register has no 'Chassis' column — cannot compare.");

            return self::FAILURE;
        }

        // --- The register, keyed by VIN and by plate digits. ---
        $byVin = [];
        $byPlate = [];
        foreach (array_slice($rows, $headerRow) as $row) {
            $vin = strtoupper(trim((string) ($row[$map['chassis']] ?? '')));
            if ($vin === '') {
                continue;
            }
            $plate = trim(trim((string) ($row[$map['code'] ?? -1] ?? '')) . ' ' . trim((string) ($row[$map['plate'] ?? -1] ?? '')));
            $byVin[$vin] = [
                'name'   => trim((string) ($row[$map['name'] ?? -1] ?? '')),
                'plate'  => $plate,
                'status' => trim((string) ($row[$map['status'] ?? -1] ?? '')),
            ];
            if (($digits = PlateResolver::plateDigits($plate)) !== '') {
                $byPlate[$digits] = true;
            }
        }

        $this->line('Fleet register ("Faster" tab): ' . count($byVin) . ' car(s).');

        // --- Cars we hold that the register does not list. ---
        // Matched by VIN, then by plate digits, so a car the API gave us with no VIN is not
        // reported as missing just because we have nothing to match it on.
        $unlisted = Vehicle::query()
            ->orderBy('status')
            ->orderBy('make')
            ->get(['id', 'vin', 'plate_no', 'make', 'model', 'status', 'origin', 'car_serial'])
            ->filter(function (Vehicle $v) use ($byVin, $byPlate) {
                if ($v->vin && isset($byVin[strtoupper(trim($v->vin))])) {
                    return false;
                }
                $digits = PlateResolver::plateDigits($v->plate_no);

                return ! ($digits !== '' && isset($byPlate[$digits]));
            });

        $live = $unlisted->reject(fn (Vehicle $v) => in_array($v->status, self::RETIRED, true));

        $this->newLine();
        $this->line('Cars we hold that the register does NOT list: ' . $unlisted->count()
            . ' (' . $live->count() . ' still in the active pool)');

        $show = $this->option('all') ? $unlisted : $live;
        if ($show->isNotEmpty()) {
            $this->table(
                ['id', 'VIN', 'plate', 'car', 'status', 'origin', 'OM serial'],
                $show->map(fn (Vehicle $v) => [
                    $v->id,
                    $v->vin ?: '—',
                    $v->plate_no ?: '—',
                    trim($v->make . ' ' . $v->model),
                    $v->status,
                    $v->origin,
                    $v->car_serial ?: '—',
                ])->all(),
            );
        }
        if (! $this->option('all') && $unlisted->count() > $live->count()) {
            $this->line('  (' . ($unlisted->count() - $live->count()) . ' more are sold/disposed/returned/suspended — pass --all to see them.)');
        }

        // --- Register rows we could not match to a car. ---
        $ourVins = Vehicle::whereNotNull('vin')->pluck('vin')
            ->mapWithKeys(fn ($v) => [strtoupper(trim($v)) => true])->all();
        $missing = collect($byVin)->reject(fn ($r, $vin) => isset($ourVins[$vin]));

        $this->newLine();
        $this->line('Register rows with no car in our database: ' . $missing->count()
            . ($missing->isNotEmpty() ? ' — `php artisan sync:vehicles` creates these.' : ''));
        if ($missing->isNotEmpty()) {
            $this->table(
                ['VIN', 'car', 'plate', 'sheet status'],
                $missing->map(fn ($r, $vin) => [$vin, $r['name'], $r['plate'], $r['status']])->values()->all(),
            );
        }

        $this->newLine();
        $this->info('Read-only — nothing was changed.');

        return self::SUCCESS;
    }
}
