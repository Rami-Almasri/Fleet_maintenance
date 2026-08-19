<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\GoogleSheetsService;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Make the fleet exactly what the "Faster" register lists: RETIRE every car the sheet does not.
 *
 * Retire = soft-delete. Never a real delete. Those cars carry ten thousand contracts, thousands of
 * expenses and their whole timeline; a hard delete would either destroy that history or be refused
 * by the foreign keys. A retired car simply stops being part of the fleet — it disappears from the
 * lists, boards and counts, its history stays exactly where it is, and putting the car back on the
 * register brings it back (VehicleImporter::import() restores it).
 *
 * Two things are never retired without --force, because retiring them would strand live work:
 *   - a car with an OPEN contract (it is out with a customer), and
 *   - a car with an OPEN workflow ticket (it is in a garage right now).
 * A car in either state that is missing from the register is far more likely to be a gap in the
 * sheet than a car we no longer own, so it is reported and left alone.
 */
class FleetRetireUnlisted extends Command
{
    protected $signature = 'fleet:retire-unlisted
        {--dry : Show what would be retired and change nothing}
        {--force : Retire even cars with an open contract or an open workflow ticket}
        {--twins : Also retire a held-back car that is a DUPLICATE of a car we keep (its ticket belongs to the real car)}
        {--strict : Keep ONLY cars whose VIN is on the register — no plate fallback, so VIN-less leftovers go too}
        {--restore : Reverse: bring every retired car back}';

    protected $description = 'Retire (soft-delete) every car the "Faster" register does not list, so the fleet matches the sheet';

    public function handle(GoogleSheetsService $sheets): int
    {
        if ($this->option('restore')) {
            return $this->restoreAll();
        }

        $dry = (bool) $this->option('dry');

        $register = $this->readRegister($sheets);
        if ($register === null) {
            return self::FAILURE;
        }
        [$vins, $plates] = $register;

        $this->line('Register ("Faster" tab): ' . count($vins) . ' car(s).');

        // A car is ours if the register lists its VIN. The plate is a fallback for a car that has no
        // VIN at all, so a real car we hold without a chassis number is not retired by accident.
        //
        // That fallback cuts the other way too: a long-sold VIN-less row whose plate digits were
        // later reassigned to a car that IS on the register gets kept, and the fleet reads a couple
        // of cars above the register forever. --strict drops the fallback — VIN on the register or
        // out — which is what "my fleet is exactly this sheet" actually means. Check first that
        // every register row HAS matched a VIN (fleet:register-audit reports it), because in strict
        // mode a genuinely VIN-less fleet car has nothing left to match on.
        $strict = (bool) $this->option('strict');

        $drop = Vehicle::all()->filter(function (Vehicle $v) use ($vins, $plates, $strict) {
            if ($v->vin) {
                return ! isset($vins[strtoupper(trim($v->vin))]);
            }
            if ($strict) {
                return true;
            }
            $digits = PlateResolver::plateDigits($v->plate_no);

            return ! ($digits !== '' && isset($plates[$digits]));
        });

        if ($drop->isEmpty()) {
            $this->info('Every car we hold is on the register — nothing to retire.');

            return self::SUCCESS;
        }

        // --- Hold back anything with live work on it. ---
        $ids = $drop->pluck('id')->all();
        $busy = $this->carsWithLiveWork($ids);

        $held = $this->option('force') ? collect() : $drop->filter(fn ($v) => isset($busy[$v->id]));

        // A held-back car that is really a DUPLICATE of a car we keep — OfficeManager's
        // chassis-typed-into-the-plate-field row. Its ticket is work on the REAL car, so holding the
        // duplicate back over it just leaves the same car on screen twice. An open CONTRACT is still
        // an absolute stop: money moved against that row, so it is not a phantom.
        $twins = [];
        if ($this->option('twins')) {
            foreach ($held as $v) {
                if (($twin = $this->twinOf($v)) && ! str_contains($busy[$v->id], 'contract')) {
                    $twins[$v->id] = $twin;
                }
            }
            $held = $held->reject(fn ($v) => isset($twins[$v->id]));
        }

        $retire = $drop->reject(fn ($v) => $held->contains('id', $v->id));

        if ($twins) {
            $this->newLine();
            $this->line('DUPLICATES being retired (--twins) — the same car listed twice:');
            $this->table(
                ['duplicate', 'car', 'its "plate"', 'is really', 'the real car'],
                collect($twins)->map(fn ($t, $id) => [
                    $id,
                    trim(($d = $drop->firstWhere('id', $id))->make . ' ' . $d->model),
                    $d->plate_no ?: '—',
                    'id ' . $t->id . ' · plate ' . ($t->plate_no ?: '—'),
                    $t->vin,
                ])->values()->all(),
            );
        }

        if ($held->isNotEmpty()) {
            $this->newLine();
            $this->warn('HELD BACK — these are not on the register but have live work on them:');
            $this->table(
                ['id', 'car', 'plate', 'status', 'why'],
                $held->map(fn ($v) => [
                    $v->id, trim($v->make . ' ' . $v->model), $v->plate_no ?: '—', $v->status, $busy[$v->id],
                ])->all(),
            );
            $this->line('Put them on the sheet, or finish the work, or re-run with --force'
                . ($this->option('twins') ? '.' : ' (or --twins if they are duplicates).'));
        }

        $this->newLine();
        $this->line(($dry ? 'WOULD retire ' : 'Retiring ') . $retire->count() . ' car(s) the register does not list.');

        $byStatus = $retire->groupBy('status')->map->count()->sortDesc();
        foreach ($byStatus as $status => $n) {
            $this->line(sprintf('   %-16s %d', $status, $n));
        }

        if ($dry) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $failed = [];
        DB::transaction(function () use ($retire, &$failed) {
            foreach ($retire as $v) {
                try {
                    $v->delete();   // soft-delete: the row and everything hanging off it stays
                } catch (Throwable $e) {
                    $failed[$v->id] = $e->getMessage();
                }
            }
        });

        $done = $retire->count() - count($failed);
        $this->newLine();
        $this->info("Retired {$done} car(s). Their contracts, expenses and timeline are untouched.");
        if ($failed) {
            $this->error(count($failed) . ' could not be retired:');
            foreach (array_slice($failed, 0, 10, true) as $id => $msg) {
                $this->line("   id {$id}: {$msg}");
            }
        }
        $this->line('Reverse the whole thing with: php artisan fleet:retire-unlisted --restore');

        return self::SUCCESS;
    }

    /**
     * The car this row is a duplicate OF: a kept car whose VIN carries this row's "plate". Only a
     * VIN-less row can be one — a row with its own chassis number is its own car. Same evidence bar
     * as the importers: six digits or more ending the VIN, or eight or more anywhere inside it.
     */
    private function twinOf(Vehicle $v): ?Vehicle
    {
        if ($v->vin) {
            return null;
        }
        $digits = PlateResolver::plateDigits($v->plate_no);
        if (strlen($digits) < 6) {
            return null;
        }

        return Vehicle::whereNotNull('vin')->get(['id', 'vin', 'plate_no', 'make', 'model'])
            ->first(function (Vehicle $c) use ($digits) {
                $vin = strtoupper(trim($c->vin));

                return str_ends_with($vin, $digits) || (strlen($digits) >= 8 && str_contains($vin, $digits));
            });
    }

    /** Bring every retired car back — the undo for this command. */
    private function restoreAll(): int
    {
        $trashed = Vehicle::onlyTrashed()->get();
        if ($trashed->isEmpty()) {
            $this->info('No retired cars — nothing to restore.');

            return self::SUCCESS;
        }

        foreach ($trashed as $v) {
            $v->restore();
        }
        $this->info('Restored ' . $trashed->count() . ' car(s) to the fleet.');

        return self::SUCCESS;
    }

    /**
     * Which of these cars have work in flight, and what it is.
     *
     * @return array<int,string> vehicle id => reason
     */
    private function carsWithLiveWork(array $ids): array
    {
        $busy = [];

        foreach (DB::table('contracts')->whereIn('vehicle_id', $ids)->whereNull('in_date')
            ->select('vehicle_id', DB::raw('count(*) n'))->groupBy('vehicle_id')->get() as $r) {
            $busy[$r->vehicle_id] = "{$r->n} open contract(s)";
        }

        $open = DB::table('maintenances')->whereIn('vehicle_id', $ids)
            ->whereNotNull('workflow_status')
            ->whereNotIn('workflow_status', ['closed', 'cancelled'])
            ->select('vehicle_id', DB::raw('count(*) n'))->groupBy('vehicle_id')->get();
        foreach ($open as $r) {
            $busy[$r->vehicle_id] = trim(($busy[$r->vehicle_id] ?? '') . " {$r->n} open ticket(s)");
        }

        return $busy;
    }

    /**
     * The register, as [VIN => true, plateDigits => true].
     *
     * @return array{0:array<string,true>, 1:array<string,true>}|null
     */
    private function readRegister(GoogleSheetsService $sheets): ?array
    {
        $cars      = config('google.sheets.cars');
        $headerRow = max(1, (int) ($cars['header_row'] ?? 1));

        try {
            $rows = $sheets->readByGid($cars['id'], (int) $cars['gid']);
        } catch (Throwable $e) {
            $this->error('Could not read the register: ' . $e->getMessage());

            return null;
        }

        if (count($rows) < $headerRow) {
            $this->error('No header row found in the register.');

            return null;
        }

        $map = [];
        foreach ($rows[$headerRow - 1] as $i => $h) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', (string) $h))));
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        if (! isset($map['chassis'])) {
            $this->error("The register has no 'Chassis' column — refusing to retire anything.");

            return null;
        }

        $vins = [];
        $plates = [];
        foreach (array_slice($rows, $headerRow) as $row) {
            $vin = strtoupper(trim((string) ($row[$map['chassis']] ?? '')));
            if ($vin === '') {
                continue;
            }
            $vins[$vin] = true;
            $plate = trim(trim((string) ($row[$map['code'] ?? -1] ?? '')) . ' ' . trim((string) ($row[$map['plate'] ?? -1] ?? '')));
            if (($digits = PlateResolver::plateDigits($plate)) !== '') {
                $plates[$digits] = true;
            }
        }

        // A register we failed to parse would look like an empty fleet and retire everything.
        if (count($vins) < 50) {
            $this->error('The register read back only ' . count($vins) . ' car(s) — that is not a fleet. Refusing to retire anything.');

            return null;
        }

        return [$vins, $plates];
    }
}
