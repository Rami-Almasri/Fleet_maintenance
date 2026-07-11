<?php

use Illuminate\Support\Facades\DB;

$from = \Carbon\Carbon::today()->subMonths(12)->toDateString();
$to   = \Carbon\Carbon::today()->toDateString();

$cases = [
    'maintenance-overlaps' => fn () => app(App\Services\FleetUtilizationService::class)->maintenanceOverlaps(12),
    'utilization'          => fn () => app(App\Services\FleetUtilizationService::class)->report($from, $to, null),
    'foresight'            => fn () => app(App\Services\MaintenanceForesightService::class)->report(),
    'mileage-chain'        => fn () => app(App\Services\MileageChainService::class)->chains(),
    'incidents'            => fn () => app(App\Services\MaintenanceIncidentService::class)->log(),
    'vehicle-index'        => fn () => app(App\Services\VehicleService::class)->index(),
];

// Warm the buffer pool once across everything.
foreach ($cases as $fn) { try { $fn(); } catch (\Throwable $e) {} }

foreach ($cases as $name => $fn) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t = microtime(true);
    try { $fn(); }
    catch (\Throwable $e) { echo str_pad($name, 22)." ERR: ".$e->getMessage()."\n"; continue; }
    $ms = round((microtime(true) - $t) * 1000);
    $log = DB::getQueryLog();
    $q = count($log);
    // Find the single slowest query in the log.
    $slow = 0; $slowSql = '';
    foreach ($log as $row) { if (($row['time'] ?? 0) > $slow) { $slow = $row['time']; $slowSql = $row['query']; } }
    echo str_pad($name, 22).str_pad($ms, 7, ' ', STR_PAD_LEFT)." ms ".str_pad($q, 6, ' ', STR_PAD_LEFT)." q   slowest ".round($slow)."ms\n";
    if ($q > 50 || $slow > 200) {
        echo "      -> ".substr(preg_replace('/\s+/', ' ', $slowSql), 0, 150)."\n";
    }
}
