<?php
/**
 * LIVE end-to-end run: part request -> approve -> purchase -> install, on vehicle 1910,
 * so the result is visible on /vehicles/1910?tab=components.
 *
 * This WRITES REAL ROWS to the live `laravel` database: a PartRequest, a PartPurchase, a
 * MaintenanceLineItem (only if tied to a ticket — this one is not), timeline events, and a
 * VehicleComponent. It also CLOSES OUT the cabin filter currently fitted, because that is what
 * fitting a new one means.
 *
 * Everything it creates is tagged E2E-LIVE-CF so scripts/remove_e2e_partflow_live.php can undo it.
 *
 * Run:  php artisan tinker scripts/e2e_partflow_live.php
 */

use App\Models\ComponentCatalog;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\Components\ComponentReadModel;
use App\Services\PartWorkflowService;

$TAG = 'E2E-LIVE-CF';

$vehicle = Vehicle::findOrFail(1910);
$catalog = ComponentCatalog::where('name', 'Cabin Filter')->firstOrFail();
$actor   = User::orderBy('id')->firstOrFail();
$svc     = app(PartWorkflowService::class);

echo "vehicle  : #{$vehicle->id} plate {$vehicle->plate_no}, odometer {$vehicle->odometer}\n";
echo "catalog  : {$catalog->name} => {$catalog->expected_life_months} mo / {$catalog->expected_life_km} km\n";
echo "actor    : {$actor->name}\n";
echo "asset_layer mode: " . config('features.asset_layer', 'off') . "\n\n";

$before = VehicleComponent::where('vehicle_id', $vehicle->id)
    ->where('component_catalog_id', $catalog->id)
    ->where('status', VehicleComponent::STATUS_ACTIVE)
    ->first();

echo $before
    ? "currently fitted: #{$before->id} \"{$before->label}\" (snapshot: " . var_export($before->expected_life_km, true) . " km)\n\n"
    : "currently fitted: nothing\n\n";

// ── 1. REQUEST ───────────────────────────────────────────────────────────────
$req = $svc->createRequest([
    'source'               => PartRequest::SOURCE_GARAGE,
    'vehicle_id'           => $vehicle->id,
    'part_name'            => 'Cabin Filter',
    'part_number'          => $TAG,
    'category_key'         => $catalog->category_key,
    'component_catalog_id' => $catalog->id,
    'quantity'             => 1,
    'reason'               => 'Cabin filter due — end-to-end verification of the limit snapshot',
], $actor);
echo "1. REQUEST   PartRequest #{$req->id}  status={$req->status}\n";

// ── 2. APPROVE ───────────────────────────────────────────────────────────────
$req = $svc->approve($req, $actor, 'Approved (E2E verification)');
echo "2. APPROVE   status={$req->status}\n";

// ── 3. PURCHASE ──────────────────────────────────────────────────────────────
$result   = $svc->purchase($req, [
    'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
    'source_name'     => 'E2E Verification Supplier',
    'purchase_price'  => 92.50,
    'currency'        => 'AED',
    'quantity'        => 1,
], $actor);
$purchase = $result['purchase'];
echo "3. PURCHASE  PartPurchase #{$purchase->id}  {$purchase->purchase_price} {$purchase->currency}"
    . ($purchase->requires_review ? '  [flagged as duplicate — expected, this car buys cabin filters often]' : '')
    . "\n";

// ── 4. INSTALL ───────────────────────────────────────────────────────────────
$svc->installPurchase($purchase, [
    'installed_odometer' => (int) $vehicle->odometer,
    'warranty_months'    => 6,
    'component'          => [
        'component_catalog_id' => $catalog->id,
        'brand'                => 'Bosch',
        'serial_no'            => $TAG,
    ],
    'predecessor'        => [
        'removal_reason' => 'worn_out',
        'disposition'    => 'scrapped',
    ],
], $actor);
echo "4. INSTALL   done\n\n";

// ── VERIFY ───────────────────────────────────────────────────────────────────
$new = VehicleComponent::where('source_part_purchase_id', $purchase->id)->first();

if (! $new) {
    echo "!! NO COMPONENT WAS CREATED.\n";
    echo "   asset_layer is in shadow mode, which swallows failures — check storage/logs/laravel.log\n";
    echo "   for 'asset_layer.install_failed' to see why.\n";
    return;
}

echo "NEW COMPONENT #{$new->id} \"{$new->label}\"\n";
echo "  expected_life_km     : " . var_export($new->expected_life_km, true) . "\n";
echo "  expected_life_months : " . var_export($new->expected_life_months, true) . "\n";

if ($before) {
    $before->refresh();
    echo "PREDECESSOR #{$before->id}\n";
    echo "  removed_at     : " . var_export((string) $before->removed_at, true) . "\n";
    echo "  removal_reason : " . var_export($before->removal_reason, true) . "\n";
    echo "  replaced_by    : " . var_export($before->replaced_by_component_id, true) . "\n";
}

echo "\n== exactly what /vehicles/1910?tab=components will render ==\n";
$out = app(ComponentReadModel::class)->vehicleConfiguration($vehicle->fresh());

foreach ($out['installed'] as $r) {
    if ($r['id'] === $new->id) {
        $sl = $r['service_life'];
        echo "INSTALLED tab: {$r['part_name']}\n";
        echo "  Limit: {$sl['expected_life_months']} months or " . number_format($sl['expected_life_km']) . " km"
            . "   [source: {$sl['limit_source']}]\n";
        echo "  {$sl['life_used_pct']}% used, basis {$sl['basis']}, status {$sl['status']}\n";
    }
}

if ($before) {
    foreach ($out['history'] as $r) {
        if ($r['id'] === $before->id) {
            $sl = $r['service_life'];
            echo "REPLACED tab: {$r['part_name']}\n";
            echo "  Lasted {$r['age_days']} d / " . number_format((int) $r['distance_km']) . " km\n";
            echo "  Limit at fitting: {$sl['expected_life_months']} months or " . number_format($sl['expected_life_km']) . " km"
                . "   [source: {$sl['limit_source']}]  -> used {$sl['life_used_pct']}%\n";
        }
    }
}

echo "\ncleanup: php artisan tinker scripts/remove_e2e_partflow_live.php\n";
