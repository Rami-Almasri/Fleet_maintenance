<?php
/**
 * Undoes scripts/e2e_partflow_live.php — removes the E2E-LIVE-CF part request, purchase, component
 * and their events, and RE-OPENS the cabin filter that was closed out to make room for it.
 *
 * Run:  php artisan tinker scripts/remove_e2e_partflow_live.php
 */

use App\Models\ComponentEvent;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\VehicleComponent;
use Illuminate\Support\Facades\DB;

$TAG = 'E2E-LIVE-CF';

$components = VehicleComponent::where('serial_no', $TAG)->get();
$purchases  = PartPurchase::where('part_number', $TAG)->get();
$requests   = PartRequest::where('part_number', $TAG)->get();

if ($components->isEmpty() && $purchases->isEmpty() && $requests->isEmpty()) {
    echo "nothing to remove — no rows tagged {$TAG}\n";
    return;
}

$componentIds = $components->pluck('id')->all();
$purchaseIds  = $purchases->pluck('id')->all();
$requestIds   = $requests->pluck('id')->all();

echo 'components: ' . (implode(', #', $componentIds) ?: 'none') . "\n";
echo 'purchases : ' . (implode(', #', $purchaseIds) ?: 'none') . "\n";
echo 'requests  : ' . (implode(', #', $requestIds) ?: 'none') . "\n\n";

DB::transaction(function () use ($componentIds, $purchaseIds, $requestIds, $components) {
    // 1. Re-open whatever this run retired. The predecessor was a REAL fitted part; putting it back
    //    is the whole point of the undo, so it must happen before the demo rows disappear and the
    //    replaced_by pointer is lost.
    foreach ($components as $c) {
        $pred = VehicleComponent::where('replaced_by_component_id', $c->id)->first();
        if (! $pred) {
            continue;
        }

        $pred->forceFill([
            'replaced_by_component_id' => null,
            'removed_at'               => null,
            'removed_odometer'         => null,
            'removed_by'               => null,
            'removed_by_name'          => null,
            'removal_reason'           => null,
            'removal_note'             => null,
            'disposition'              => null,
            'removal_maintenance_id'   => null,
            'status'                   => VehicleComponent::STATUS_ACTIVE,
            'location'                 => VehicleComponent::LOC_ON_VEHICLE,
        ])->save();

        echo "  re-opened predecessor #{$pred->id} \"{$pred->label}\" -> active\n";
    }

    // 2. Timeline entries. vehicle_log_events has no subject column; the id lives in meta JSON.
    $logs = 0;
    foreach ([['component_id', $componentIds], ['part_purchase_id', $purchaseIds], ['part_request_id', $requestIds]] as [$key, $ids]) {
        if ($ids) {
            $logs += DB::table('vehicle_log_events')
                ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.{$key}'))"), array_map('strval', $ids))
                ->delete();
        }
    }
    echo "  vehicle_log_events deleted: {$logs}\n";

    if ($componentIds) {
        echo '  component_events deleted: ' . ComponentEvent::whereIn('vehicle_component_id', $componentIds)->delete() . "\n";
        echo '  vehicle_components deleted: ' . VehicleComponent::whereIn('id', $componentIds)->delete() . "\n";
    }

    if ($purchaseIds) {
        // The cost bridge — only written when the purchase hangs off a ticket.
        echo '  maintenance_line_items deleted: ' . DB::table('maintenance_line_items')
            ->whereIn('id', PartPurchase::whereIn('id', $purchaseIds)->pluck('maintenance_line_item_id')->filter())
            ->delete() . "\n";
        echo '  part_purchases deleted: ' . PartPurchase::whereIn('id', $purchaseIds)->delete() . "\n";
    }

    if ($requestIds) {
        echo '  part_requests deleted: ' . PartRequest::whereIn('id', $requestIds)->delete() . "\n";
    }
});

echo "\ndone.\n";
