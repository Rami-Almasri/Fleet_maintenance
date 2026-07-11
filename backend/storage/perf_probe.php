<?php
$svc = app(App\Services\DashboardService::class);
// Warm once (fill buffer pool / opcache), then measure.
try { $svc->summary(7); } catch (\Throwable $e) {}
$methods = ['summary','negativeYield','fleetStatus','carsInMaintenance','overdueRentalsList'];
foreach ($methods as $m) {
    DB::flushQueryLog(); DB::enableQueryLog();
    $t = microtime(true);
    try { $m === 'summary' ? $svc->summary(7) : $svc->$m(); }
    catch (\Throwable $e) { echo "ERR $m: ".$e->getMessage()."\n"; continue; }
    $ms = round((microtime(true)-$t)*1000);
    $q = count(DB::getQueryLog());
    echo str_pad($m,24).str_pad($ms,7,' ',STR_PAD_LEFT)." ms ".str_pad($q,5,' ',STR_PAD_LEFT)." q\n";
}
