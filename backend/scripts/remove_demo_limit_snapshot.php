<?php
/**
 * Removes the DEMO cabin filter created to show the "limit recorded at fitting" state.
 *
 * Run:  php artisan tinker scripts/remove_demo_limit_snapshot.php
 *
 * Deletes ONLY rows carrying serial_no = DEMO-LIMIT-SNAPSHOT, plus the component_events and
 * vehicle_log_events they generated. Nothing real is touched — the demo part was fitted to a slot
 * that was empty, so no genuine component was closed out to make room for it.
 */

use App\Models\ComponentEvent;
use App\Models\VehicleComponent;
use Illuminate\Support\Facades\DB;

$SERIAL = 'DEMO-LIMIT-SNAPSHOT';

$components = VehicleComponent::where('serial_no', $SERIAL)->get();

if ($components->isEmpty()) {
    echo "nothing to remove — no components with serial {$SERIAL}\n";
    return;
}

$ids = $components->pluck('id')->all();
echo 'removing components #' . implode(', #', $ids) . "\n";

DB::transaction(function () use ($ids) {
    // A demo row must never be left as somebody's recorded predecessor.
    VehicleComponent::whereIn('replaced_by_component_id', $ids)
        ->update(['replaced_by_component_id' => null]);

    // vehicle_log_events has no subject column — the component id lives in the meta JSON.
    $logs = DB::table('vehicle_log_events')
        ->where('event_type', 'LIKE', 'component%')
        ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.component_id'))"), array_map('strval', $ids))
        ->delete();
    echo "  vehicle_log_events deleted: {$logs}\n";

    $events = ComponentEvent::whereIn('vehicle_component_id', $ids)->delete();
    echo "  component_events deleted: {$events}\n";

    $rows = VehicleComponent::whereIn('id', $ids)->delete();
    echo "  vehicle_components deleted: {$rows}\n";
});

echo "done.\n";
