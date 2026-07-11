<?php
foreach (['contracts','maintenances','vehicles','customers','vehicle_registrations'] as $t) {
    $n = DB::table($t)->count();
    echo "== $t ($n rows) ==\n";
    $idx = DB::select("SHOW INDEX FROM $t");
    $cols = [];
    foreach ($idx as $i) { $cols[$i->Key_name][] = $i->Column_name; }
    foreach ($cols as $k => $c) { echo "   [$k] ".implode(',', $c)."\n"; }
}
echo "\n== EXPLAIN: aggregate contracts (negativeYield core) ==\n";
$rows = DB::select("EXPLAIN SELECT vehicle_id, COUNT(*) FROM contracts WHERE contract_type='C' AND out_date >= '2025-06-27' GROUP BY vehicle_id");
foreach ($rows as $r) { echo "   type={$r->type} key=".($r->key ?? 'NULL')." rows={$r->rows} extra=".($r->Extra ?? '')."\n"; }
echo "\n== EXPLAIN: maintenances group ==\n";
$rows = DB::select("EXPLAIN SELECT vehicle_id, SUM(cost) FROM maintenances WHERE vehicle_id IS NOT NULL AND out_date >= '2025-06-27' GROUP BY vehicle_id");
foreach ($rows as $r) { echo "   type={$r->type} key=".($r->key ?? 'NULL')." rows={$r->rows} extra=".($r->Extra ?? '')."\n"; }
echo "\n== EXPLAIN: contracts currentlyOpen ==\n";
$rows = DB::select("EXPLAIN SELECT vehicle_id FROM contracts WHERE state='open' AND in_date IS NULL AND vehicle_id IS NOT NULL");
foreach ($rows as $r) { echo "   type={$r->type} key=".($r->key ?? 'NULL')." rows={$r->rows} extra=".($r->Extra ?? '')."\n"; }
