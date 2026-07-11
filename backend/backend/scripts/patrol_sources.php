<?php

use App\Models\Maintenance;
use Illuminate\Support\Facades\DB;

$id = 1886; // NISSAN PATROL plate 45051

echo "=== TYPE-'U' MAINTENANCE CONTRACTS (OM API) for vehicle {$id} ===\n";
$u = DB::table('contracts')
    ->where('vehicle_id', $id)
    ->where('contract_type', 'U')
    ->orderBy('out_date')
    ->get(['contract_no', 'out_date', 'in_date', 'status_no']);
echo "count = " . $u->count() . "\n";
foreach ($u as $c) {
    echo "  {$c->contract_no}  " . substr($c->out_date,0,10) . " -> " . substr((string)$c->in_date,0,10)
        . "  status={$c->status_no}\n";
}

echo "\n=== RENTAL 'C' CONTRACTS for vehicle {$id} ===\n";
$rc = DB::table('contracts')->where('vehicle_id',$id)->where('contract_type','C')
    ->orderBy('out_date')->get(['contract_no','out_date','in_date']);
echo "count = " . $rc->count() . "  (first rental = " . substr((string)optional($rc->first())->out_date,0,10) . ")\n";

echo "\n=== ALL contract_type values for vehicle {$id} ===\n";
foreach (DB::table('contracts')->where('vehicle_id',$id)->select('contract_type', DB::raw('count(*) c'))->groupBy('contract_type')->get() as $r) {
    echo "  type {$r->contract_type}: {$r->c}\n";
}

echo "\n=== WORKSHOP-LOG rows (maintenances, origin sheet/manual) for vehicle {$id} ===\n";
$w = DB::table('maintenances')->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
    ->where('vehicle_id',$id)->orderBy('out_date')
    ->get(['origin','out_date','actual_in_date','maintenance_type','event_status']);
echo "count = " . $w->count() . "\n";
foreach ($w as $m) {
    echo "  [{$m->origin}] " . substr((string)$m->out_date,0,10) . " -> " . substr((string)$m->actual_in_date,0,10)
        . "  {$m->maintenance_type} ({$m->event_status})\n";
}

echo "\n=== CONTRACT-HEADER maintenances (origin=contract) for vehicle {$id} ===\n";
$ch = DB::table('maintenances')->where('origin','contract')->where('vehicle_id',$id)->count();
echo "count = {$ch}\n";
