<?php
$svc = app(App\Services\FleetUtilizationService::class);
$svc->maintenanceOverlaps(12); // warm
$t = microtime(true);
$r = $svc->maintenanceOverlaps(12);
$ms = round((microtime(true) - $t) * 1000);
echo "events=".$r['summary']['events']
    ." live=".$r['summary']['live']
    ." overlap_days=".$r['summary']['overlap_days']
    ." value=".$r['summary']['value']."\n";
echo "md5=".md5(json_encode($r['events']))."\n";
echo "time={$ms}ms\n";
echo (md5(json_encode($r['events'])) === 'bbed5faa455b50a37e75b9a2dcda0035' ? "IDENTICAL ✓" : "!!! CHANGED")."\n";
