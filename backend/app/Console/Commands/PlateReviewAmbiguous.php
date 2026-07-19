<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY review report for plates shared by more than one CURRENTLY-ACTIVE vehicle —
 * the cases the Phase 1 backfill will NOT auto-resolve. For each such plate it prints, per
 * vehicle: identity (serial/VIN/status), purchase + exit-proxy dates, live OM status, the
 * volume of history it owns (maintenance / inspections / repairs), last activity, why the
 * plate is ambiguous, and which car the resolver would pick today. Writes nothing — it exists
 * so a human can confirm the current holder of each plate before the backfill runs.
 */
class PlateReviewAmbiguous extends Command
{
    protected $signature = 'plate:review-ambiguous
        {--plate= : Limit to one plate (digits, e.g. 76722)}
        {--all : Show every reused plate, not just the ambiguous (>1 active) ones}';

    protected $description = 'Read-only report of ambiguous reused plates (>1 active car) for manual review before backfill';

    /** OM AssetStatusNo that mean the car has left the fleet. */
    private const OM_GONE_NO = [7, 8, 9];

    public function handle(): int
    {
        $vehicles = Vehicle::withTrashed()->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'make', 'model', 'vin', 'status', 'status_no', 'car_serial', 'purchase_date', 'synced_at', 'for_sale']);

        // group by canonical plate key
        $groups = [];
        foreach ($vehicles as $v) {
            $k = PlateResolver::plateDigits($v->plate_no);
            if ($k !== '') { $groups[$k][] = $v; }
        }
        ksort($groups);

        $onlyPlate = $this->option('plate') ? PlateResolver::plateDigits($this->option('plate')) : null;
        $showAll = (bool) $this->option('all');

        $shown = 0;
        foreach ($groups as $key => $g) {
            if ($onlyPlate && $key !== $onlyPlate) { continue; }
            if (count($g) < 2) { continue; } // not reused

            $actives = array_values(array_filter($g, fn ($v) => ! in_array($v->status, PlateResolver::GONE_STATUSES, true)));
            $needsReview = count($actives) > 1;
            if (! $showAll && ! $onlyPlate && ! $needsReview) { continue; }

            // deterministic order (acquisition): car_serial asc
            usort($g, fn ($a, $b) => (int) $a->car_serial <=> (int) $b->car_serial);
            $suggestedId = $actives ? (int) end($actives)->id : null;   // highest-serial active
            $resolverPick = PlateResolver::resolve($g[0]->plate_no, true);

            $reason = $needsReview
                ? count($actives) . ' currently-active cars share this plate — current holder cannot be inferred'
                : (count($actives) === 0 ? 'all cars gone — historical plate, no current holder' : 'single active car (auto-resolvable)');

            $this->newLine();
            $this->line("<fg=black;bg=yellow> PLATE {$key} </> " . ($needsReview ? '<fg=red>NEEDS REVIEW</>' : '<fg=green>auto</>')
                . "  — {$reason}");

            $rows = [];
            foreach ($g as $v) {
                $gone = in_array($v->status, PlateResolver::GONE_STATUSES, true);
                $last = $this->lastActivity($v->id);
                $rows[] = [
                    $v->plate_no,
                    $v->id,
                    $v->car_serial,
                    $v->vin ?: '—',
                    $v->status,
                    $this->d($v->purchase_date),
                    $gone ? ($last ?: '—') : '—',                       // exit-proxy (last activity) for gone cars
                    ($v->id === $suggestedId) ? 'YES' : 'no',
                    $needsReview ? 'low' : ($gone && count($actives) === 0 ? 'derived' : 'high'),
                ];
            }
            $this->table(
                ['Plate', 'VehID', 'Serial', 'VIN', 'Status', 'Purchased', 'Exit≈', 'Suggested?', 'Conf'],
                $rows
            );

            // history volumes + OM status per vehicle
            foreach ($g as $v) {
                $mCount   = DB::table('maintenances')->where('vehicle_id', $v->id)->count();
                $insp     = DB::table('inspection_records')->where('vehicle_id', $v->id)->count();
                $tasks    = DB::table('maintenance_tasks')->where('vehicle_id', $v->id)->count();
                $repairQc = DB::table('repair_inspections')->where('vehicle_id', $v->id)->count();
                $omNo     = $v->status_no;
                $omActive = $omNo !== null ? (! in_array((int) $omNo, self::OM_GONE_NO, true) ? 'ACTIVE' : 'gone') : 'unknown';
                $synced   = $v->synced_at ? $this->d($v->synced_at) : 'never';
                $this->line(sprintf(
                    "   veh %-5s (serial %-6s): maintenance=%-4s inspections=%-3s repairs(tasks)=%-3s QC=%-2s · last activity=%s · OM[%s no=%s synced=%s]%s",
                    $v->id, $v->car_serial, $mCount, $insp, $tasks, $repairQc,
                    $this->lastActivity($v->id) ?: '—', $omActive, $omNo ?? '-', $synced,
                    $v->for_sale ? ' · FOR-SALE' : ''
                ));
            }

            // maintenance history still UNLINKED on this plate (would attach on the approved re-import)
            $poolNull = DB::table('maintenances')->whereNull('vehicle_id')
                ->where('plate', 'like', '%' . $key . '%')->count();
            if ($poolNull > 0) {
                $this->line("   <comment>{$poolNull} maintenance row(s) on this plate are currently UNLINKED (vehicle_id NULL) — pending the approved re-import.</comment>");
            }

            $this->line('   Resolver would currently choose: '
                . ($resolverPick ? "veh {$resolverPick->id} (serial {$resolverPick->car_serial}, status {$resolverPick->status})" : 'none')
                . '  — rule: prefer in-fleet over sold/disposed, then highest car_serial.');
            $this->line('   <fg=red>Backfill action: leave is_current UNSET — awaiting your confirmation of the current holder.</>');
            $shown++;
        }

        $this->newLine();
        $this->info($shown === 0 ? 'No ambiguous plates found.' : "{$shown} plate(s) shown. Read-only — nothing was written.");
        return self::SUCCESS;
    }

    /** Latest real-world activity for a vehicle across maintenance, contracts, and the log. */
    private function lastActivity(int $vehicleId): ?string
    {
        $dates = array_filter([
            DB::table('maintenances')->where('vehicle_id', $vehicleId)->max('out_date'),
            DB::table('contracts')->where('vehicle_id', $vehicleId)->max(DB::raw('COALESCE(in_date, out_date)')),
            DB::table('vehicle_log_events')->where('vehicle_id', $vehicleId)->max('occurred_at'),
        ]);
        if (! $dates) { return null; }
        return $this->d(max($dates));
    }

    private function d($v): string
    {
        if (empty($v)) { return '—'; }
        return substr((string) $v, 0, 10);
    }
}
