<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\DiagnosticGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Demo helper for the Proactive Diagnostic Monitor. Arms a real "oil overdue" condition on one car so
 * that `inspections:generate-tasks` raises a "Needs Test Drive" request for it, and can put the car's
 * service anchors back exactly.
 *
 *   php artisan gate:demo            # arm the first Ready car
 *   php artisan gate:demo 42         # arm vehicle #42
 *   php artisan gate:demo 42 --reset # restore vehicle #42's service anchors
 */
class DiagnosticGateDemo extends Command
{
    protected $signature = 'gate:demo {vehicle? : vehicle id (defaults to the first Ready car)} {--reset : restore the car service anchors}';

    protected $description = 'Arm (or reset) an overdue-oil condition on a car to test the Proactive Diagnostic Monitor';

    public function handle(DiagnosticGateService $gate): int
    {
        $vehicle = $this->argument('vehicle')
            ? Vehicle::find((int) $this->argument('vehicle'))
            : Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)->orderBy('id')->first();

        if (! $vehicle) {
            $this->error('No matching vehicle found.');
            return self::FAILURE;
        }

        $cacheKey = 'gate_demo:' . $vehicle->id;

        if ($this->option('reset')) {
            $original = Cache::pull($cacheKey);
            if ($original) {
                $vehicle->forceFill($original)->save();
                $this->info("Restored vehicle #{$vehicle->id} ({$vehicle->plate_no}) service anchors.");
            } else {
                $this->warn("No stashed values for vehicle #{$vehicle->id} — leaving service anchors as-is.");
            }
            $stillDue = count($gate->conditionsDue($vehicle->fresh()));
            $this->line('Conditions due now: ' . $stillDue);
            return self::SUCCESS;
        }

        // Stash the real values ONCE so --reset can put them back exactly. Guard against a second arm
        // (without a reset in between) clobbering the stash with already-armed values.
        if (! Cache::has($cacheKey)) {
            Cache::forever($cacheKey, [
                'odometer'              => $vehicle->odometer,
                'last_service_odometer' => $vehicle->last_service_odometer,
                'service_interval_km'   => $vehicle->service_interval_km,
            ]);
        }

        // Force the km rule over the edge: distance (odometer - last_service) >= interval.
        $vehicle->odometer              = 100000;
        $vehicle->last_service_odometer = 90000;
        $vehicle->service_interval_km   = 5000;   // → 5,000 km over
        $vehicle->save();

        $conditions = $gate->conditionsDue($vehicle->fresh());

        $this->newLine();
        $this->info("Armed a due routine on vehicle #{$vehicle->id} ({$vehicle->plate_no}).");
        $this->line('  Conditions the monitor will detect:');
        foreach ($conditions as $c) {
            $this->line('    • ' . $c['label'] . '  (' . $c['detail'] . ')');
        }
        if (empty($conditions)) {
            $this->warn('    (none — the car reports nothing due)');
        }
        $this->newLine();
        $this->line('  Fire the proactive monitor to raise the "Needs Test Drive" request + notify the Inspector:');
        $this->line('    php artisan inspections:generate-tasks --dry-run   # preview');
        $this->line('    php artisan inspections:generate-tasks             # raise it for real');
        $this->newLine();
        $this->line('  Then see it: Notifications bell + the "Needs Test Drive" lane on /maintenance-workflow');
        $this->line('  Undo with : php artisan gate:demo ' . $vehicle->id . ' --reset');
        $this->newLine();

        return self::SUCCESS;
    }
}
