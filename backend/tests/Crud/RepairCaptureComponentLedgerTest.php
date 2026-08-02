<?php

namespace Tests\Crud;

use App\Models\ActionCatalog;
use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAction;
use App\Models\PartPurchase;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\ComponentService;
use App\Services\PartWorkflowService;
use Database\Seeders\ActionCatalogSeeder;
use Database\Seeders\ComponentCatalogSeeder;

/**
 * Repair Capture → Component Ledger.
 *
 * A part reaches a car when the workflow says so — and until now "the workflow" meant a PartPurchase
 * and nothing else, so every garage-supplied part was invisible to the asset layer. These lock the
 * new door and, more importantly, the ORDERING contract between the two doors: capture and purchase
 * describe the same physical replacement, arrive in either order, and must never produce two.
 */
class RepairCaptureComponentLedgerTest extends CrudTestCase
{
    private ComponentService $components;
    private PartWorkflowService $parts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ComponentCatalogSeeder::class);
        $this->seed(ActionCatalogSeeder::class);
        $this->components = app(ComponentService::class);
        $this->parts      = app(PartWorkflowService::class);
        config(['features.asset_layer' => 'shadow']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function vehicle(): Vehicle
    {
        return Vehicle::findOrFail($this->makeVehicle());
    }

    private function task(Vehicle $vehicle): MaintenanceTask
    {
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicle->id,
            'out_date'   => now()->subDays(2),
        ]);

        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'symptom'        => 'Battery light on',
            'category_key'   => 'electrical',
            'status'         => 'open',
        ]);
    }

    private function action(MaintenanceTask $task, string $actionSlug): MaintenanceTaskAction
    {
        $entry = ActionCatalog::where('slug', $actionSlug)->firstOrFail();

        return MaintenanceTaskAction::create([
            'maintenance_task_id' => $task->id,
            'maintenance_id'      => $task->maintenance_id,
            'vehicle_id'          => $task->vehicle_id,
            'action_catalog_id'   => $entry->id,
            'sequence'            => 1,
            'performed_by_name'   => 'Tech Ahmed',
            'performed_at'        => now(),
            'recorded_via'        => MaintenanceTaskAction::VIA_WORKFLOW,
        ]);
    }

    private function fit(MaintenanceTaskAction $action, array $payload = []): ?VehicleComponent
    {
        return $this->components->installFromAction(
            $action,
            ActionCatalog::findOrFail($action->action_catalog_id),
            $payload,
            $this->admin,
        );
    }

    // ── The new door ────────────────────────────────────────────────────────────────────────────

    public function test_a_replace_action_with_no_purchase_creates_a_component(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        $component = $this->fit($this->action($task, 'replace_alternator'));

        $this->assertNotNull($component, 'a replace action should reach the ledger with no purchase behind it');
        $this->assertSame($vehicle->id, $component->vehicle_id);
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $component->status);
        $this->assertSame('alternator', $component->catalog->slug);
        $this->assertSame($task->id, $component->source_maintenance_task_id);
    }

    public function test_the_two_provenance_axes_are_recorded_separately(): void
    {
        $task = $this->task($this->vehicle());

        $component = $this->fit($this->action($task, 'replace_alternator'), [
            'acquisition' => VehicleComponent::ACQ_GARAGE_SUPPLIED,
        ]);

        $this->assertSame(VehicleComponent::EV_REPAIR_CAPTURE, $component->evidence_channel);
        $this->assertSame(VehicleComponent::ACQ_GARAGE_SUPPLIED, $component->acquisition);
        // `source` is a different question and must be untouched by this feature.
        $this->assertSame(VehicleComponent::SOURCE_WORKFLOW, $component->source);
    }

    public function test_unknown_cost_is_null_never_zero(): void
    {
        $task = $this->task($this->vehicle());

        $component = $this->fit($this->action($task, 'replace_alternator'));

        $this->assertNull($component->purchase_cost, 'a zero would read as free and deflate every cost average');
    }

    public function test_acquisition_defaults_to_unknown_not_purchased(): void
    {
        $task = $this->task($this->vehicle());

        $component = $this->fit($this->action($task, 'replace_alternator'));

        $this->assertSame(VehicleComponent::ACQ_UNKNOWN, $component->acquisition);
        $this->assertNull($component->warranty_months, 'no warranty entitlement is invented for a part nobody can say we bought');
    }

    public function test_a_warranty_acquisition_does_grant_the_catalog_warranty(): void
    {
        $task = $this->task($this->vehicle());

        $component = $this->fit($this->action($task, 'replace_alternator'), [
            'acquisition' => VehicleComponent::ACQ_PURCHASED,
        ]);

        $this->assertSame(
            ComponentCatalog::where('slug', 'alternator')->value('default_warranty_months'),
            $component->warranty_months,
        );
    }

    public function test_a_serialized_part_may_be_reported_without_a_serial(): void
    {
        $task = $this->task($this->vehicle());

        // The alternator catalog is serialized; the purchase door demands a serial. A technician
        // reporting a fit has not read one off the casing, and inventing one is worse than null.
        $component = $this->fit($this->action($task, 'replace_alternator'));

        $this->assertNotNull($component);
        $this->assertNull($component->serial_no);
    }

    // ── What it deliberately refuses ────────────────────────────────────────────────────────────

    public function test_a_non_fitting_verb_writes_nothing(): void
    {
        $task = $this->task($this->vehicle());

        // Bleeding the brakes is work performed on a component, not a change of component.
        $this->assertNull($this->fit($this->action($task, 'bleed_brake_system')));
        $this->assertSame(0, VehicleComponent::where('source_maintenance_task_id', $task->id)->count());
    }

    public function test_a_consumable_target_writes_nothing(): void
    {
        $task = $this->task($this->vehicle());

        // Standing rule: oil is work, not an asset. It is MAPPED so the resolver recognises it —
        // which is what keeps it out of the "unmapped, go fix it" backlog.
        $this->assertNull($this->fit($this->action($task, 'replace_engine_oil')));
        $this->assertSame(0, VehicleComponent::where('source_maintenance_task_id', $task->id)->count());
    }

    public function test_an_unmapped_target_writes_nothing_and_does_not_throw(): void
    {
        $task = $this->task($this->vehicle());

        // `head_gasket` has no component_catalog entry — it is a repair, not a tracked asset.
        $this->assertNull($this->fit($this->action($task, 'replace_head_gasket')));
    }

    public function test_an_occupied_slot_is_deferred_rather_than_guessed(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        // A predecessor the ledger already knows about. Replacing it needs a disposition ("what
        // happened to the old one?") that the capture screen never asks for — so nothing is written
        // rather than a scrapping being invented.
        $catalog  = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $existing = new VehicleComponent([
            'component_catalog_id' => $catalog->id,
            'serial_no'            => 'SN-OLD',
            'source'               => VehicleComponent::SOURCE_MANUAL,
            'installed_at'         => now()->subYear(),
        ]);
        $existing->vehicle_id = $vehicle->id;
        $existing->status     = VehicleComponent::STATUS_ACTIVE;
        $existing->location   = VehicleComponent::LOC_ON_VEHICLE;
        $existing->save();

        $this->assertNull($this->fit($this->action($task, 'replace_alternator')));

        $this->assertSame('SN-OLD', $existing->fresh()->serial_no);
        $this->assertNull($existing->fresh()->removed_at, 'the predecessor must not be closed out on a guess');
        $this->assertSame(1, VehicleComponent::where('vehicle_id', $vehicle->id)->count());
    }

    // ── The ordering contract ───────────────────────────────────────────────────────────────────

    public function test_capture_twice_on_one_task_creates_one_component(): void
    {
        $task = $this->task($this->vehicle());

        // Re-capture deletes and recreates the action rows, so the second pass sees a NEW action id
        // for the same physical work. Keying the dedupe on the task is what survives that.
        $this->assertNotNull($this->fit($this->action($task, 'replace_alternator')));

        MaintenanceTaskAction::where('maintenance_task_id', $task->id)->delete();

        $this->assertNull($this->fit($this->action($task, 'replace_alternator')));
        $this->assertSame(1, VehicleComponent::where('source_maintenance_task_id', $task->id)->count());
    }

    public function test_capture_then_purchase_upgrades_in_place_instead_of_replacing(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        $captured = $this->fit($this->action($task, 'replace_alternator'));
        $this->assertSame(VehicleComponent::EV_REPAIR_CAPTURE, $captured->evidence_channel);

        $purchase = PartPurchase::create([
            'vehicle_id'          => $vehicle->id,
            'maintenance_id'      => $task->maintenance_id,
            'maintenance_task_id' => $task->id,
            'part_name'           => 'Denso Alternator',
            'part_number'         => 'ALT-9000',
            'category_key'        => 'electrical',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'      => 1250,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => now(),
        ]);

        $result = $this->components->installFromPurchase($purchase, [
            'component' => ['serial_no' => 'SN-NEW-1', 'component_catalog_id' => $captured->component_catalog_id],
        ], $this->admin);

        // THE POINT: same row, promoted — not a second alternator and not a replacement chain.
        $this->assertSame($captured->id, $result->id, 'the purchase must promote the captured row, not supersede it');
        $this->assertSame(VehicleComponent::EV_PURCHASE, $result->evidence_channel);
        $this->assertEquals(1250, (float) $result->purchase_cost);
        $this->assertSame('SN-NEW-1', $result->serial_no);
        $this->assertSame($purchase->id, $result->source_part_purchase_id);

        $this->assertSame(1, VehicleComponent::where('vehicle_id', $vehicle->id)->count(),
            'one physical replacement must never become two ledger rows');
        $this->assertNull($captured->fresh()->removed_at, 'the captured row must not be closed out as a predecessor');
    }

    public function test_purchase_then_capture_leaves_the_purchased_row_untouched(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        $purchase = PartPurchase::create([
            'vehicle_id'          => $vehicle->id,
            'maintenance_id'      => $task->maintenance_id,
            'maintenance_task_id' => $task->id,
            'part_name'           => 'Denso Alternator',
            'category_key'        => 'electrical',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'      => 1250,
            'quantity'            => 1,
            'purchased_at'        => now(),
        ]);

        $bought = $this->components->installFromPurchase($purchase, [
            'component' => ['serial_no' => 'SN-BOUGHT', 'component_catalog_id' => ComponentCatalog::where('slug', 'alternator')->value('id')],
        ], $this->admin);

        $this->assertSame(VehicleComponent::EV_PURCHASE, $bought->evidence_channel);

        // The technician now records the same replacement on the capture screen.
        $this->assertNull($this->fit($this->action($task, 'replace_alternator')));

        $this->assertSame(1, VehicleComponent::where('vehicle_id', $vehicle->id)->count());
        $this->assertEquals(1250, (float) $bought->fresh()->purchase_cost, 'the costed row must not be downgraded by a later report');
        $this->assertSame(VehicleComponent::EV_PURCHASE, $bought->fresh()->evidence_channel);
    }

    public function test_the_dedupe_key_is_the_task_not_the_vehicle(): void
    {
        $vehicle = $this->vehicle();

        $first     = $this->task($vehicle);
        $original  = $this->fit($this->action($first, 'replace_alternator'));
        $this->assertNotNull($original);

        // The same car legitimately has its alternator replaced again on a later visit. Free the
        // slot the way a real removal would, so the only thing that could suppress the second write
        // is the dedupe — and prove it does not, because it is keyed on the ticket line.
        $this->components->remove($original, [
            'removal_reason' => VehicleComponent::REASON_FAILED,
            'disposition'    => VehicleComponent::DISP_SCRAPPED,
        ], $this->admin);

        $second = $this->task($vehicle);
        $again  = $this->fit($this->action($second, 'replace_alternator'));

        $this->assertNotNull($again, 'a genuine second replacement must not be swallowed as a duplicate');
        $this->assertNotSame($original->id, $again->id);
        $this->assertSame(2, VehicleComponent::where('vehicle_id', $vehicle->id)
            ->orWhere('source_maintenance_task_id', $second->id)->count());
    }

    // ── Flag contract ───────────────────────────────────────────────────────────────────────────

    public function test_asset_layer_off_writes_nothing_from_capture(): void
    {
        config(['features.asset_layer' => 'off']);

        $task = $this->task($this->vehicle());

        // installFromAction itself is unconditional — the flag is the CALLER's contract — so this
        // has to go through the capture path to assert it.
        $entry = ActionCatalog::where('slug', 'replace_alternator')->firstOrFail();
        app(\App\Services\RepairCaptureService::class)->capture($task, [
            'actions'         => [['action_catalog_id' => $entry->id]],
            'claimed_outcome' => 'complete',
        ], $this->admin);

        $this->assertSame(0, VehicleComponent::where('source_maintenance_task_id', $task->id)->count());
    }

    public function test_capture_survives_an_asset_layer_failure(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        // An occupied slot is the realistic failure: the ledger cannot resolve it, and the repair
        // record must land anyway. Losing a technician's capture over a parts problem trades the
        // scarce evidence for the recoverable one.
        $catalog  = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $existing = new VehicleComponent([
            'component_catalog_id' => $catalog->id,
            'serial_no'            => 'SN-OCCUPIED',
            'source'               => VehicleComponent::SOURCE_MANUAL,
            'installed_at'         => now()->subYear(),
        ]);
        $existing->vehicle_id = $vehicle->id;
        $existing->status     = VehicleComponent::STATUS_ACTIVE;
        $existing->location   = VehicleComponent::LOC_ON_VEHICLE;
        $existing->save();

        $entry = ActionCatalog::where('slug', 'replace_alternator')->firstOrFail();
        app(\App\Services\RepairCaptureService::class)->capture($task, [
            'actions'         => [['action_catalog_id' => $entry->id]],
            'claimed_outcome' => 'complete',
        ], $this->admin);

        $this->assertSame('complete', $task->fresh()->claimed_outcome, 'the repair record is the scarcer evidence and must survive');
        $this->assertSame(1, MaintenanceTaskAction::where('maintenance_task_id', $task->id)->count());
    }

    // ── Install-leg parity: a warehouse part must behave like a purchased one ───────────────────

    public function test_installing_a_spare_from_stock_stamps_the_full_install_leg(): void
    {
        $vehicle = $this->vehicle();
        $catalog = ComponentCatalog::where('slug', 'radiator')->firstOrFail();

        $spare = $this->components->intake(
            ['component_catalog_id' => $catalog->id, 'serial_no' => 'STK-1', 'purchase_cost' => 780],
            $this->admin,
        );
        $this->assertNull($spare->installed_at, 'a shelf part has not been fitted yet');

        $fitted = $this->components->install($spare, ['vehicle_id' => $vehicle->id, 'odometer' => 141000], $this->admin);

        // Without these, age / service-life / warranty / cost-per-km are all silently unavailable
        // for every warehouse-sourced part — which is most of them once a stockroom exists.
        $this->assertNotNull($fitted->installed_at, 'install date must be stamped on the first fitting');
        $this->assertSame(141000, (int) $fitted->installed_odometer, 'the odometer reached the event but never the row');
        $this->assertSame($this->admin->id, (int) $fitted->installed_by);
        $this->assertNotNull($fitted->installed_by_name);
    }

    public function test_a_warehouse_part_gets_a_warranty_clock_that_starts_at_install(): void
    {
        $vehicle = $this->vehicle();
        $catalog = ComponentCatalog::where('slug', 'battery-12v')->firstOrFail();

        $spare  = $this->components->intake(['component_catalog_id' => $catalog->id, 'serial_no' => 'STK-BAT-1'], $this->admin);
        $fitted = $this->components->install($spare, ['vehicle_id' => $vehicle->id, 'odometer' => 100000], $this->admin);

        $this->assertSame($catalog->default_warranty_months, (int) $fitted->warranty_months);
        $this->assertNotNull($fitted->warranty_until, 'warranty_until derives from installed_at + warranty_months');
        // Counted from the day it went ON the car, not the day it was bought — a unit that sat on
        // the shelf for six months has not been using up its cover.
        $this->assertSame(
            now()->startOfDay()->addMonths($catalog->default_warranty_months)->toDateString(),
            $fitted->warranty_until->toDateString(),
        );
    }

    public function test_a_reinstall_never_restarts_the_original_install_leg(): void
    {
        $first   = $this->vehicle();
        $second  = $this->vehicle();
        $catalog = ComponentCatalog::where('slug', 'radiator')->firstOrFail();

        $spare  = $this->components->intake(['component_catalog_id' => $catalog->id, 'serial_no' => 'STK-2'], $this->admin);
        $fitted = $this->components->install($spare, ['vehicle_id' => $first->id, 'odometer' => 90000], $this->admin);

        $originalAt  = $fitted->fresh()->installed_at;
        $originalOdo = (int) $fitted->fresh()->installed_odometer;

        // Off the first car, into stock, onto a second car.
        $removed = $this->components->remove($fitted->fresh(), [
            'removal_reason' => VehicleComponent::REASON_UPGRADE,
            'disposition'    => VehicleComponent::DISP_STORED,
            'removed_odometer' => 95000,
        ], $this->admin);
        $again = $this->components->install($removed->fresh(), ['vehicle_id' => $second->id, 'odometer' => 200000], $this->admin);

        // THE INVARIANT: warranty belongs to the physical part and must not restart because it was
        // moved between cars. The fix must not have widened into a re-stamp on every install.
        $this->assertEquals($originalAt->toIso8601String(), $again->installed_at->toIso8601String());
        $this->assertSame($originalOdo, (int) $again->installed_odometer);
    }

    public function test_a_capture_installed_part_and_a_stock_installed_part_carry_the_same_lifecycle_fields(): void
    {
        $vehicle = $this->vehicle();
        $task    = $this->task($vehicle);

        $captured = $this->fit($this->action($task, 'replace_alternator'));

        $spare = $this->components->intake(
            ['component_catalog_id' => ComponentCatalog::where('slug', 'radiator')->value('id'), 'serial_no' => 'STK-3'],
            $this->admin,
        );
        $stocked = $this->components->install($spare, ['vehicle_id' => $vehicle->id, 'odometer' => 152400], $this->admin);

        foreach (['installed_at', 'installed_odometer', 'installed_by', 'installed_by_name'] as $field) {
            $this->assertNotNull($captured->$field, "capture-installed part is missing {$field}");
            $this->assertNotNull($stocked->$field, "stock-installed part is missing {$field}");
        }
    }

    // ── The mapping itself ──────────────────────────────────────────────────────────────────────

    public function test_action_targets_resolve_by_the_mapped_column_not_by_a_slug_transform(): void
    {
        // Each of these would be resolved WRONG by str_replace('_', '-'): the two vocabularies were
        // authored independently and disagree on the name of the same thing.
        $this->assertSame('battery-12v', ComponentCatalog::forActionTarget('battery')?->slug);
        $this->assertSame('radiator-fan', ComponentCatalog::forActionTarget('cooling_fan')?->slug);
        $this->assertSame('stabilizer-link', ComponentCatalog::forActionTarget('link_rod')?->slug);
        $this->assertSame('drive-belt', ComponentCatalog::forActionTarget('accessory_belt')?->slug);
        $this->assertSame('clutch-kit', ComponentCatalog::forActionTarget('clutch')?->slug);
        $this->assertSame('tie-rod-end', ComponentCatalog::forActionTarget('tie_rod')?->slug);
    }

    public function test_a_target_that_is_not_an_asset_resolves_to_nothing(): void
    {
        $this->assertNull(ComponentCatalog::forActionTarget('brake_system'));
        $this->assertNull(ComponentCatalog::forActionTarget('warning_light'));
        $this->assertNull(ComponentCatalog::forActionTarget(null));
    }

    public function test_no_two_component_types_claim_the_same_action_target(): void
    {
        $duplicates = ComponentCatalog::query()
            ->whereNotNull('action_target')
            ->selectRaw('action_target, COUNT(*) as n')
            ->groupBy('action_target')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('action_target');

        $this->assertTrue($duplicates->isEmpty(), 'ambiguous mapping: ' . $duplicates->implode(', '));
    }
}
