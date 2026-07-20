<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\OfficeManagerClient;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate vehicles.plate_code from OM's `PlateColorNo` — the plate's numeric code, which pairs
 * with `CarNo` (the digits) on the same OM row. A car can carry several OM rows (plate changes),
 * so we match the row whose CarNo digits equal the vehicle's stored plate_no and take THAT row's
 * PlateColorNo — the code for the plate we actually hold.
 *
 * Additive: writes only the new plate_code column, only when currently empty-or-different, and
 * reports every vehicle whose code could not be resolved. --dry-run computes and reports only.
 */
class PlateSyncCodes extends Command
{
    protected $signature = 'plate:sync-codes {--dry-run : Compute and report only, write nothing}';

    protected $description = 'Fill vehicles.plate_code from OM PlateColorNo (matched by VIN + plate digits)';

    public function handle(OfficeManagerClient $api): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Syncing plate codes from OM…');

        // Build (VIN | digits) => PlateColorNo from the live OM feed.
        $codeByVinPlate = [];
        $rows = 0;
        foreach ($api->vehicles() as $row) {
            $rows++;
            $vin = strtoupper(trim((string) ($row['ChasisNo'] ?? '')));
            if ($vin === '') {
                continue;
            }
            $digits = PlateResolver::plateDigits($row['CarNo'] ?? '');
            $code = $row['PlateColorNo'] ?? null;
            if ($code === null || $code === '') {
                continue;
            }
            $codeByVinPlate[$vin . '|' . $digits] = (string) $code;
        }
        $this->line("Scanned {$rows} OM rows; " . count($codeByVinPlate) . ' distinct VIN+plate codes.');

        $matched = 0; $changed = 0; $unresolved = [];
        $vehicles = Vehicle::withTrashed()->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'vin', 'plate_no', 'plate_code', 'make', 'model', 'status']);

        $updates = [];
        foreach ($vehicles as $v) {
            $vin = strtoupper(trim((string) $v->vin));
            $digits = PlateResolver::plateDigits($v->plate_no);
            $code = $codeByVinPlate[$vin . '|' . $digits] ?? null;
            if ($code === null) {
                $unresolved[] = $v;

                continue;
            }
            $matched++;
            if ((string) $v->plate_code !== $code) {
                $changed++;
                $updates[$v->id] = $code;
            }
        }

        if (! $dry && $updates) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as $id => $code) {
                    DB::table('vehicles')->where('id', $id)->update(['plate_code' => $code]);
                }
            });
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Vehicles with a plate', $vehicles->count()],
            ['  code resolved from OM', $matched],
            [$dry ? '  would set/update plate_code' : '  plate_code set/updated', $changed],
            ['  UNRESOLVED (no matching OM row)', count($unresolved)],
        ]);

        if ($unresolved) {
            $this->newLine();
            $this->line('<comment>Unresolved (VIN + stored plate digits found no OM row — likely a changed/stale plate):</comment>');
            foreach (array_slice($unresolved, 0, 40) as $v) {
                $this->line(sprintf('  veh %-5s plate %-9s %-22s %-12s vin=%s',
                    $v->id, $v->plate_no, trim($v->make . ' ' . $v->model), $v->status, $v->vin ?: '—'));
            }
            if (count($unresolved) > 40) {
                $this->line('  … ' . (count($unresolved) - 40) . ' more');
            }
        }

        $this->info($dry ? 'Dry run complete — nothing written.' : 'Plate codes synced.');

        return self::SUCCESS;
    }
}
