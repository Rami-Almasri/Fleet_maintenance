<?php
$r = app(App\Services\FleetUtilizationService::class)->maintenanceOverlaps(12);
echo "events=".$r['summary']['events']
    ." live=".$r['summary']['live']
    ." overlap_days=".$r['summary']['overlap_days']
    ." value=".$r['summary']['value']."\n";
// Fingerprint the full event list so we can prove byte-for-byte equality after the change.
echo "md5=".md5(json_encode($r['events']))."\n";
