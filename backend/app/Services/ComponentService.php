<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\ComponentEvent;
use App\Models\Maintenance;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asset Layer — THE single write choke point for vehicle_components / component_events.
 * No other code writes these tables; status/location/removal-leg fields are not mass-assignable
 * and are set only here by explicit assignment.
 *
 * Every mutating method: runs in a DB transaction (joins the caller's when one is open), locks the
 * slot row first, writes the component + its append-only events, and mirrors each event onto the
 * vehicle timeline via VehicleLogService (EVENT_COMPONENT_*). Guards enforce the locked invariants
 * (docs/Asset-Layer-Phase2-Final-Review.md §6):
 *   - one active component per slot (vehicle, catalog, position) — conflicts are 409, never guessed;
 *   - removal is atomic: reason + disposition + odometer together or 422 ("no disposition, no removal");
 *   - consumables never instantiate components; serialized catalogs require a serial;
 *   - retired is absorbing; warranty derives from the ORIGINAL install and is never reset;
 *   - asset writes never touch workflow_status.
 */
class ComponentService
{
    /** disposition → [status, location, keepVehicleId] — where a removed part's row lands. */
    private const DISPOSITION_OUTCOMES = [
        VehicleComponent::DISP_STORED            => [VehicleComponent::STATUS_IN_STOCK, VehicleComponent::LOC_WAREHOUSE, false],
        VehicleComponent::DISP_SCRAPPED          => [VehicleComponent::STATUS_RETIRED,  VehicleComponent::LOC_SCRAPPED,  true],
        VehicleComponent::DISP_RETURNED_SUPPLIER => [VehicleComponent::STATUS_RETIRED,  VehicleComponent::LOC_SUPPLIER,  true],
        VehicleComponent::DISP_WARRANTY_RETURN   => [VehicleComponent::STATUS_RETIRED,  VehicleComponent::LOC_SUPPLIER,  true],
        VehicleComponent::DISP_SOLD              => [VehicleComponent::STATUS_RETIRED,  VehicleComponent::LOC_SOLD,      true],
        VehicleComponent::DISP_SOLD_WITH_VEHICLE => [VehicleComponent::STATUS_RETIRED,  VehicleComponent::LOC_SOLD,      true],
    ];

    public function __construct(private VehicleLogService $log)
    {
    }

    // ───────────────────────────── workflow install (the Scenario-1 write) ─────────────────────────────

    /**
     * Fit a purchased part as a physical component. Called by PartWorkflowService::installPurchase
     * under the asset_layer flag (shadow: failures reported, never block billing; enforced: same
     * transaction, all-or-nothing).
     *
     * @param array $payload validated request data — reads installed_odometer, warranty_months and the
     *                       optional `component` (identity) + `predecessor` (removal decision) blocks.
     */
    public function installFromPurchase(PartPurchase $purchase, array $payload, User $actor): ?VehicleComponent
    {
        // Consumables never become components (standing rule) — and their PURCHASES are legitimate
        // (oil, coolant bought through the parts flow). A consumable install must therefore SKIP the
        // component path quietly, never block the billing install: without this early return, an
        // enforced-mode consumable purchase would 422 out of resolveCatalog and kill its own install.
        // An EXPLICIT consumable catalog id still aborts below (that is a caller error, not a buy).
        if (! data_get($payload, 'component.component_catalog_id') && $this->resolvesToConsumableOnly($purchase)) {
            logger()->info('asset_layer.consumable_skipped', ['part_purchase_id' => $purchase->id, 'category' => $purchase->category_key]);

            return null;
        }

        $catalog = $this->resolveCatalog($purchase, $payload);
        $identity = (array) ($payload['component'] ?? []);
        $position = $this->validatedPosition($catalog, $identity['position'] ?? null);

        return DB::transaction(function () use ($purchase, $payload, $actor, $catalog, $identity, $position) {
            $vehicle = Vehicle::findOrFail($purchase->vehicle_id);

            $predecessor = $this->lockSlot($catalog, $vehicle->id, $position);
            if ($predecessor) {
                $removal = (array) ($payload['predecessor'] ?? []);
                $this->closeOut(
                    $predecessor,
                    $removal,
                    $actor,
                    maintenanceId: $purchase->maintenance_id,
                    taskId: $purchase->maintenance_task_id,
                    fallbackOdometer: $payload['installed_odometer'] ?? null,
                    beingReplaced: true,
                );
            }

            $component = $this->makeComponent($catalog, [
                'serial_no'   => $identity['serial_no'] ?? null,
                'part_number' => $purchase->part_number,
                'brand'       => $identity['brand'] ?? null,
                'model'       => $identity['model'] ?? null,
                // "Varta Battery 12V" reads as a part; a bare "Varta" reads as a supplier. When no
                // model code is given, the catalog type carries the noun so the label is always a
                // thing rather than a brand.
                'label'       => trim(($identity['brand'] ?? '') . ' ' . ($identity['model'] ?? $catalog->name)) ?: $purchase->part_name,
                'quantity'    => $purchase->quantity ?: 1,
                'position'    => $position,

                'installed_at'        => Carbon::now(),
                'installed_odometer'  => $payload['installed_odometer'] ?? null,
                'installed_by'        => $actor->id,
                'installed_by_name'   => $actor->name ?: $actor->email,
                'technician_name'     => $identity['technician_name'] ?? null,
                'installer_vendor_id' => $purchase->maintenance?->vendor_id,
                'supplier_vendor_id'  => $purchase->source_vendor_id,
                'purchase_cost'       => $purchase->purchase_price,
                'currency'            => $purchase->currency ?: 'AED',
                'warranty_months'     => $payload['warranty_months'] ?? null,

                'source_part_purchase_id' => $purchase->id,
                'source_line_item_id'     => $purchase->maintenance_line_item_id,
                'source'                  => VehicleComponent::SOURCE_WORKFLOW,
            ], status: VehicleComponent::STATUS_ACTIVE, location: VehicleComponent::LOC_ON_VEHICLE, vehicleId: $vehicle->id);

            if ($predecessor) {
                $predecessor->replaced_by_component_id = $component->id;
                $predecessor->save();
            }

            $this->recordEvent($component, ComponentEvent::EVENT_INSTALLED, $actor, [
                'to_vehicle_id'       => $vehicle->id,
                'odometer'            => $payload['installed_odometer'] ?? null,
                'maintenance_id'      => $purchase->maintenance_id,
                'maintenance_task_id' => $purchase->maintenance_task_id,
                'note'                => "Installed from purchase #{$purchase->id} ({$purchase->part_name})",
            ]);

            return $component->fresh();
        });
    }

    // ───────────────────────────── spare install / intake ─────────────────────────────

    /**
     * Fit an in-stock spare onto a vehicle. Same slot rules as a workflow install; the ORIGINAL
     * warranty_until is preserved (warranty belongs to the physical part, never reset by re-install).
     *
     * @param array $data vehicle_id (required), position?, odometer?, maintenance_id?, maintenance_task_id?, predecessor?
     */
    public function install(VehicleComponent $component, array $data, User $actor): VehicleComponent
    {
        abort_unless($component->status === VehicleComponent::STATUS_IN_STOCK, 422, 'Only an in-stock spare can be installed. This component is ' . $component->status . '.');

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $this->guardVehicleInstallable($vehicle);
        $position = $this->validatedPosition($component->catalog, $data['position'] ?? $component->position);

        return DB::transaction(function () use ($component, $data, $actor, $vehicle, $position) {
            $predecessor = $this->lockSlot($component->catalog, $vehicle->id, $position, exceptId: $component->id);
            if ($predecessor) {
                $this->closeOut($predecessor, (array) ($data['predecessor'] ?? []), $actor,
                    maintenanceId: $data['maintenance_id'] ?? null,
                    taskId: $data['maintenance_task_id'] ?? null,
                    fallbackOdometer: $data['odometer'] ?? null,
                    beingReplaced: true,
                );
                $predecessor->replaced_by_component_id = $component->id;
                $predecessor->save();
            }

            $component->vehicle_id = $vehicle->id;
            $component->status     = VehicleComponent::STATUS_ACTIVE;
            $component->location   = VehicleComponent::LOC_ON_VEHICLE;
            $component->position   = $position;
            // A re-install stamps the new fitting on the event; the row keeps its ORIGINAL install
            // leg (cost basis, supplier, warranty clock) — that history is the part's identity.
            $component->save();

            $this->recordEvent($component, ComponentEvent::EVENT_INSTALLED, $actor, [
                'to_vehicle_id'       => $vehicle->id,
                'odometer'            => $data['odometer'] ?? null,
                'maintenance_id'      => $data['maintenance_id'] ?? null,
                'maintenance_task_id' => $data['maintenance_task_id'] ?? null,
                'note'                => $data['note'] ?? 'Spare installed from stock',
            ]);

            return $component->fresh();
        });
    }

    /**
     * Bring a part into the warehouse WITHOUT installing it — the Scenario-2 door ("diagnosis
     * changed, the purchased part must not disappear") and the direct stock-buy door.
     *
     * @param array $data either part_purchase_id (identity/provenance copied from the purchase)
     *                    or component_catalog_id + identity fields.
     */
    public function intake(array $data, User $actor): VehicleComponent
    {
        $purchase = ! empty($data['part_purchase_id']) ? PartPurchase::findOrFail($data['part_purchase_id']) : null;

        if ($purchase) {
            abort_if($purchase->isInstalled(), 409, 'This purchase was installed — it is already a component.');
            abort_if(VehicleComponent::where('source_part_purchase_id', $purchase->id)->exists(), 409, 'This purchase already entered the component ledger.');
        }

        $catalog = $purchase
            ? $this->resolveCatalog($purchase, $data)
            : ComponentCatalog::findOrFail($data['component_catalog_id']);

        $this->guardNotConsumable($catalog);

        return DB::transaction(function () use ($data, $actor, $purchase, $catalog) {
            $component = $this->makeComponent($catalog, [
                'serial_no'   => $data['serial_no'] ?? null,
                'part_number' => $data['part_number'] ?? $purchase?->part_number,
                'brand'       => $data['brand'] ?? null,
                'model'       => $data['model'] ?? null,
                'label'       => $data['label'] ?? $purchase?->part_name,
                'quantity'    => $data['quantity'] ?? ($purchase?->quantity ?: 1),

                'supplier_vendor_id' => $data['supplier_vendor_id'] ?? $purchase?->source_vendor_id,
                'purchase_cost'      => $data['purchase_cost'] ?? $purchase?->purchase_price,
                'currency'           => $data['currency'] ?? ($purchase?->currency ?: 'AED'),

                'source_part_purchase_id' => $purchase?->id,
                'source'                  => $purchase ? VehicleComponent::SOURCE_WORKFLOW : VehicleComponent::SOURCE_MANUAL,
            ], status: VehicleComponent::STATUS_IN_STOCK, location: VehicleComponent::LOC_WAREHOUSE, vehicleId: null);

            $this->recordEvent($component, ComponentEvent::EVENT_PURCHASED, $actor, [
                'note' => $purchase ? "Intake from purchase #{$purchase->id}" : 'Direct stock intake',
            ]);
            $this->recordEvent($component, ComponentEvent::EVENT_STORED, $actor, [
                'note' => $data['note'] ?? 'Stored in warehouse',
            ]);

            return $component->fresh();
        });
    }

    // ───────────────────────────── removal / transfer / disposal ─────────────────────────────

    /**
     * Standalone removal of an ACTIVE component (strip before sale, damaged part). Atomic:
     * reason + disposition + odometer or nothing — "what happened to the old part?" can never be skipped.
     *
     * @param array $removal removal_reason, disposition, removed_odometer?, removal_note?, maintenance_id?
     */
    public function remove(VehicleComponent $component, array $removal, User $actor): VehicleComponent
    {
        abort_unless($component->status === VehicleComponent::STATUS_ACTIVE, 422, 'Only an active component can be removed from a vehicle.');

        return DB::transaction(function () use ($component, $removal, $actor) {
            $locked = VehicleComponent::whereKey($component->id)->lockForUpdate()->first();
            abort_unless($locked->status === VehicleComponent::STATUS_ACTIVE, 409, 'Component changed state concurrently.');

            $this->closeOut($locked, $removal, $actor, maintenanceId: $removal['maintenance_id'] ?? null, taskId: null, fallbackOdometer: null);

            return $locked->fresh();
        });
    }

    /**
     * Move an ACTIVE component from its vehicle onto another — the ticketless asset operation.
     * One row, one `transferred` event carrying BOTH vehicles; identity/warranty/cost basis untouched.
     *
     * @param array $data to_vehicle_id, position?, from_odometer?, to_odometer?, note?
     */
    public function transfer(VehicleComponent $component, array $data, User $actor): VehicleComponent
    {
        abort_unless($component->status === VehicleComponent::STATUS_ACTIVE, 422, 'Only an active component can be transferred (install a spare from stock instead).');

        $target = Vehicle::findOrFail($data['to_vehicle_id']);
        abort_if($target->id === $component->vehicle_id, 422, 'The component is already on this vehicle.');
        $this->guardVehicleInstallable($target);
        $position = $this->validatedPosition($component->catalog, $data['position'] ?? $component->position);

        return DB::transaction(function () use ($component, $data, $actor, $target, $position) {
            // Target slot must be free — we never silently displace; a chained replacement is an
            // explicit install-with-predecessor, not a transfer.
            $occupying = $this->lockSlot($component->catalog, $target->id, $position, exceptId: $component->id);
            abort_if($occupying !== null, 409, "Slot occupied on the target vehicle by component #{$occupying?->id} — remove or replace it explicitly first.");

            $from = $component->vehicle_id;

            $component->vehicle_id = $target->id;
            $component->position   = $position;
            $component->save();

            $this->recordEvent($component, ComponentEvent::EVENT_TRANSFERRED, $actor, [
                'from_vehicle_id' => $from,
                'to_vehicle_id'   => $target->id,
                'odometer'        => $data['to_odometer'] ?? null,
                'note'            => $data['note'] ?? null,
                'meta'            => ['from_odometer' => $data['from_odometer'] ?? null, 'to_odometer' => $data['to_odometer'] ?? null],
            ]);

            return $component->fresh();
        });
    }

    /**
     * Dispose of an IN-STOCK component from the shelf (never installed again). Terminal dispositions only.
     *
     * @param array $data disposition (scrapped|returned_supplier|sold), note?
     */
    public function dispose(VehicleComponent $component, array $data, User $actor): VehicleComponent
    {
        abort_unless($component->status === VehicleComponent::STATUS_IN_STOCK, 422, 'Only an in-stock component can be shelf-disposed. Remove it from its vehicle first.');

        $disposition = $data['disposition'] ?? null;
        abort_unless(in_array($disposition, [
            VehicleComponent::DISP_SCRAPPED, VehicleComponent::DISP_RETURNED_SUPPLIER, VehicleComponent::DISP_SOLD,
        ], true), 422, 'Shelf disposal needs a terminal disposition: scrapped, returned_supplier or sold.');

        return DB::transaction(function () use ($component, $disposition, $data, $actor) {
            [$status, $location] = self::DISPOSITION_OUTCOMES[$disposition];

            $component->status      = $status;
            $component->location    = $location;
            $component->disposition = $disposition;
            $component->removal_note = $data['note'] ?? $component->removal_note;
            $component->save();

            $this->recordEvent($component, $this->terminalEventFor($disposition), $actor, [
                'note' => $data['note'] ?? "Shelf disposal: {$disposition}",
            ]);

            return $component->fresh();
        });
    }

    /**
     * Vehicle-sale settlement: every active component gets an explicit human decision —
     * keep with the vehicle or strip to stock. Nothing may disappear.
     *
     * @param array $decisions [component_id => 'sold_with_vehicle'|'stored', ...]
     * @return array<int, VehicleComponent>
     */
    public function settleForVehicleSale(Vehicle $vehicle, array $decisions, User $actor): array
    {
        return DB::transaction(function () use ($vehicle, $decisions, $actor) {
            $active = VehicleComponent::activeOn($vehicle->id)->lockForUpdate()->get();

            $missing = $active->pluck('id')->diff(array_keys($decisions));
            abort_if($missing->isNotEmpty(), 422,
                'Every active component needs a decision (keep with vehicle / remove to stock). Undecided: #' . $missing->implode(', #'));

            $settled = [];
            foreach ($active as $component) {
                $disposition = $decisions[$component->id];
                abort_unless(in_array($disposition, [VehicleComponent::DISP_SOLD_WITH_VEHICLE, VehicleComponent::DISP_STORED], true),
                    422, "Component #{$component->id}: settlement must be sold_with_vehicle or stored.");

                $this->closeOut($component, [
                    'removal_reason' => VehicleComponent::REASON_VEHICLE_SOLD,
                    'disposition'    => $disposition,
                    'removal_note'   => 'Vehicle sale settlement',
                ], $actor, maintenanceId: null, taskId: null, fallbackOdometer: $vehicle->odometer);

                $settled[] = $component->fresh();
            }

            return $settled;
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /**
     * Close a component's life on its vehicle — the ONE place a removal leg is written. Applies the
     * disposition outcome map, fires the removed (+ terminal / warranty_claimed) events. Throws 422
     * when reason or disposition is missing: no disposition, no removal.
     */
    private function closeOut(VehicleComponent $component, array $removal, User $actor, ?int $maintenanceId, ?int $taskId, ?int $fallbackOdometer, bool $beingReplaced = false): void
    {
        $reason      = $removal['removal_reason'] ?? null;
        $disposition = $removal['disposition'] ?? null;

        abort_unless($reason && $disposition, 422,
            "What happened to the old {$component->catalog?->name}? A removal needs a reason (" . implode('|', VehicleComponent::REMOVAL_REASONS) . ') and a disposition (' . implode('|', array_keys(self::DISPOSITION_OUTCOMES)) . ').');
        abort_unless(in_array($reason, VehicleComponent::REMOVAL_REASONS, true), 422, "Unknown removal reason [{$reason}].");
        abort_unless(isset(self::DISPOSITION_OUTCOMES[$disposition]), 422, "Unknown disposition [{$disposition}] — transfers use the transfer action.");

        // Continuity gate: an EXPLICIT removal reading below the install reading is a user error
        // (422). A best-effort FALLBACK reading (e.g. the vehicle's current odometer during a sale
        // settlement) that conflicts with a legacy install reading is dropped to "unknown" instead —
        // we never fail an action over data we guessed ourselves.
        $explicit = array_key_exists('removed_odometer', $removal) && $removal['removed_odometer'] !== null;
        $odometer = $explicit ? (int) $removal['removed_odometer'] : $fallbackOdometer;
        if ($odometer !== null && $component->installed_odometer !== null && $odometer < $component->installed_odometer) {
            abort_if($explicit, 422, "Removal odometer ({$odometer}) cannot be below the install odometer ({$component->installed_odometer}).");
            $odometer = null;
        }

        [$status, $location, $keepVehicleId] = self::DISPOSITION_OUTCOMES[$disposition];
        $fromVehicleId = $component->vehicle_id;

        $component->removed_at              = Carbon::now();
        $component->removed_odometer        = $odometer;
        $component->removed_by              = $actor->id;
        $component->removed_by_name         = $actor->name ?: $actor->email;
        $component->removal_reason          = $reason;
        $component->removal_note            = $removal['removal_note'] ?? null;
        $component->disposition             = $disposition;
        $component->removal_maintenance_id  = $maintenanceId;
        $component->status                  = $status;
        $component->location                = $location;
        $component->position                = $keepVehicleId ? $component->position : null;
        if (! $keepVehicleId) {
            $component->vehicle_id = null;
        }
        $component->save();

        $this->recordEvent($component, ComponentEvent::EVENT_REMOVED, $actor, [
            'from_vehicle_id'     => $fromVehicleId,
            'odometer'            => $odometer,
            'maintenance_id'      => $maintenanceId,
            'maintenance_task_id' => $taskId,
            'note'                => $removal['removal_note'] ?? null,
            // `replaced` is a HINT for the timeline sentence only: the successor row does not exist
            // yet at this point (the install writes it after this close-out returns), so the phrase
            // builder cannot read replaced_by_component_id and must be told.
            'replaced'            => $beingReplaced,
            'meta'                => ['removal_reason' => $reason, 'disposition' => $disposition],
        ]);

        if ($disposition === VehicleComponent::DISP_WARRANTY_RETURN) {
            $inWarranty = $component->warranty_until && $component->warranty_until->gte(Carbon::now()->startOfDay());
            $this->recordEvent($component, ComponentEvent::EVENT_WARRANTY_CLAIMED, $actor, [
                'from_vehicle_id' => $fromVehicleId,
                'maintenance_id'  => $maintenanceId,
                'note'            => 'Warranty return to supplier',
                'meta'            => ['in_warranty' => $inWarranty, 'warranty_until' => $component->warranty_until?->toDateString()],
            ]);
        } elseif ($status === VehicleComponent::STATUS_RETIRED) {
            $this->recordEvent($component, $this->terminalEventFor($disposition), $actor, [
                'from_vehicle_id' => $fromVehicleId,
                'maintenance_id'  => $maintenanceId,
            ]);
        } elseif ($disposition === VehicleComponent::DISP_STORED) {
            $this->recordEvent($component, ComponentEvent::EVENT_STORED, $actor, [
                'from_vehicle_id' => $fromVehicleId,
                'note'            => 'Removed to warehouse as spare',
            ]);
        }
    }

    /**
     * The slot lock: the single ACTIVE occupant of (catalog, vehicle, position), locked for update.
     * TWO active occupants = corrupt slot → 409, never a guess (final-review §1).
     */
    private function lockSlot(ComponentCatalog $catalog, int $vehicleId, ?string $position, ?int $exceptId = null): ?VehicleComponent
    {
        $occupants = VehicleComponent::forSlot($catalog->id, $vehicleId, $position)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get();

        abort_if($occupants->count() > 1, 409,
            'Slot conflict: components #' . $occupants->pluck('id')->implode(', #')
            . ' are all active in the same slot. Resolve manually (remove the wrong one) and retry.');

        return $occupants->first();
    }

    /** Build + save a component with the non-fillable fields set explicitly (the only legal way). */
    private function makeComponent(ComponentCatalog $catalog, array $attrs, string $status, string $location, ?int $vehicleId): VehicleComponent
    {
        $this->guardNotConsumable($catalog);
        $this->guardSerial($catalog, $attrs['serial_no'] ?? null);

        $component = new VehicleComponent(array_merge($attrs, ['component_catalog_id' => $catalog->id]));
        $component->vehicle_id = $vehicleId;
        $component->status     = $status;
        $component->location   = $location;

        // Trust markers (launch plan §7.1): which flag regime wrote this row, and whether it is
        // provisional (shadow observation) or born validated (enforced-mode blocking validation
        // passed at write time). Promotion of shadow rows happens ONLY via components:shadow-audit.
        $mode = (string) config('features.asset_layer', 'off');
        $component->write_mode        = $mode;
        $component->validation_status = $mode === 'enforced'
            ? VehicleComponent::VALIDATION_VALIDATED
            : VehicleComponent::VALIDATION_PROVISIONAL;

        $component->save();

        return $component;
    }

    private function guardNotConsumable(ComponentCatalog $catalog): void
    {
        abort_if($catalog->isConsumable(), 422,
            "{$catalog->name} is a consumable — it never becomes a component. Record the work as a service instead.");
    }

    private function guardSerial(ComponentCatalog $catalog, ?string $serial): void
    {
        if (! $catalog->isSerialized()) {
            return;
        }

        abort_if(blank($serial), 422, "{$catalog->name} is a serialized component — a serial number is required.");

        $duplicate = VehicleComponent::where('component_catalog_id', $catalog->id)
            ->where('serial_no', $serial)
            ->where('status', '!=', VehicleComponent::STATUS_RETIRED)
            ->exists();
        abort_if($duplicate, 409, "Serial [{$serial}] already exists as a live component of {$catalog->name} — the same physical part cannot exist twice.");
    }

    private function guardVehicleInstallable(Vehicle $vehicle): void
    {
        abort_if(in_array($vehicle->status, ['sold', 'disposed'], true), 422,
            "Vehicle {$vehicle->plate_no} is {$vehicle->status} — components cannot be installed on it.");
    }

    /**
     * Position validation per catalog scheme (final-review §1): positioned catalogs require a
     * position from their scheme in enforced mode; shadow accepts NULL and logs (measurement, not
     * enforcement). Positionless catalogs reject any position.
     */
    private function validatedPosition(ComponentCatalog $catalog, ?string $position): ?string
    {
        $allowed = $catalog->positionsFor();

        if ($allowed === []) {
            abort_if($position !== null, 422, "{$catalog->name} does not take a position.");
            return null;
        }

        if ($position === null) {
            if (config('features.asset_layer') === 'enforced') {
                abort(422, "{$catalog->name} requires a position (" . implode(' / ', $allowed) . ').');
            }
            logger()->warning('asset_layer.position_missing', ['catalog' => $catalog->slug]);
            return null;
        }

        abort_unless(in_array($position, $allowed, true), 422,
            "Invalid position [{$position}] for {$catalog->name} — expected " . implode(' / ', $allowed) . '.');

        return $position;
    }

    /**
     * Catalog resolution for a purchase (final-review §1): explicit id from the payload wins; else the
     * category's single non-consumable catalog entry; ambiguity or no match → 422 (the modal must pick).
     */
    private function resolveCatalog(PartPurchase $purchase, array $payload): ComponentCatalog
    {
        $explicit = data_get($payload, 'component.component_catalog_id') ?? ($payload['component_catalog_id'] ?? null);
        if ($explicit) {
            $catalog = ComponentCatalog::findOrFail($explicit);
            $this->guardNotConsumable($catalog);

            return $catalog;
        }

        $candidates = ComponentCatalog::active()
            ->where('category_key', (string) $purchase->category_key)
            ->where('tracking_mode', '!=', ComponentCatalog::TRACKING_CONSUMABLE)
            ->get();

        abort_if($candidates->isEmpty(), 422,
            "No component type matches category [{$purchase->category_key}] — pick one explicitly (component.component_catalog_id).");
        abort_if($candidates->count() > 1, 422,
            "Category [{$purchase->category_key}] maps to several component types (" . $candidates->pluck('slug')->implode(', ') . ') — pick one explicitly (component.component_catalog_id).');

        return $candidates->first();
    }

    /**
     * True when this purchase can only ever be a consumable: its snapshot part_class says so, or its
     * category maps exclusively to consumable catalog entries (e.g. 'fluids'). Such a purchase is a
     * service-side cost, not an asset — the component path must step aside, not abort.
     */
    private function resolvesToConsumableOnly(PartPurchase $purchase): bool
    {
        if ($purchase->part_class === 'consumable') {
            return true;
        }

        if (blank($purchase->category_key)) {
            return false; // unknown category: let resolveCatalog demand an explicit pick
        }

        $catalogs = ComponentCatalog::active()->where('category_key', $purchase->category_key)->get();

        return $catalogs->isNotEmpty() && $catalogs->every(fn ($c) => $c->isConsumable());
    }

    /**
     * The one-line sentence the Vehicle Timeline shows. Reads as plain English about the CAR
     * ("Battery installed — Bosch S5", "Brake Pads (set) removed — worn out"), because the timeline
     * is a vehicle biography, not a component log: the catalog type is what an operator scans for,
     * the brand/serial is the detail underneath it.
     */
    private function timelinePhrase(VehicleComponent $component, string $event, array $data): string
    {
        $type   = $component->catalog?->name ?: 'Component';
        $detail = $component->label && $component->label !== $type ? $component->label : null;

        $headline = match ($event) {
            ComponentEvent::EVENT_INSTALLED         => "{$type} installed",
            // "replaced" is the honest word only when this removal has a named successor; a plain
            // strip-and-store is a removal, and calling it a replacement would invent a part.
            ComponentEvent::EVENT_REMOVED           => ($data['replaced'] ?? false) ? "{$type} replaced" : "{$type} removed",
            ComponentEvent::EVENT_TRANSFERRED       => "{$type} transferred to another vehicle",
            ComponentEvent::EVENT_RETURNED_SUPPLIER => "{$type} returned to supplier",
            ComponentEvent::EVENT_WARRANTY_CLAIMED  => "{$type} returned under warranty",
            ComponentEvent::EVENT_SOLD              => "{$type} sold",
            ComponentEvent::EVENT_DISPOSED          => "{$type} scrapped",
            default                                 => "{$type} {$event}",
        };

        $suffix = $detail;
        if ($event === ComponentEvent::EVENT_REMOVED && ($reason = $data['meta']['removal_reason'] ?? null)) {
            $reasonText = str_replace('_', ' ', $reason);
            $suffix = $detail ? "{$detail} · {$reasonText}" : $reasonText;
        }

        return $suffix ? "{$headline} — {$suffix}" : $headline;
    }

    private function terminalEventFor(string $disposition): string
    {
        return match ($disposition) {
            VehicleComponent::DISP_RETURNED_SUPPLIER => ComponentEvent::EVENT_RETURNED_SUPPLIER,
            VehicleComponent::DISP_SOLD,
            VehicleComponent::DISP_SOLD_WITH_VEHICLE => ComponentEvent::EVENT_SOLD,
            default                                  => ComponentEvent::EVENT_DISPOSED,
        };
    }

    /**
     * Append one biography line + mirror it onto the vehicle timeline(s). Events are IN-transaction
     * (audit consistency); the timeline mirror is best-effort by VehicleLogService's own contract.
     */
    private function recordEvent(VehicleComponent $component, string $event, User $actor, array $data): ComponentEvent
    {
        $row = ComponentEvent::create([
            'vehicle_component_id' => $component->id,
            'event'                => $event,
            'from_vehicle_id'      => $data['from_vehicle_id'] ?? null,
            'to_vehicle_id'        => $data['to_vehicle_id'] ?? null,
            'odometer'             => $data['odometer'] ?? null,
            'maintenance_id'       => $data['maintenance_id'] ?? null,
            'maintenance_task_id'  => $data['maintenance_task_id'] ?? null,
            'actor_id'             => $actor->id,
            'actor_name'           => $actor->name ?: $actor->email,
            'at'                   => Carbon::now(),
            'note'                 => $data['note'] ?? null,
            'meta'                 => $data['meta'] ?? null,
        ]);

        $logEvent = match ($event) {
            ComponentEvent::EVENT_INSTALLED   => VehicleLogEvent::EVENT_COMPONENT_INSTALLED,
            ComponentEvent::EVENT_REMOVED     => VehicleLogEvent::EVENT_COMPONENT_REMOVED,
            ComponentEvent::EVENT_TRANSFERRED => VehicleLogEvent::EVENT_COMPONENT_TRANSFERRED,
            // purchased/stored: warehouse noise, not vehicle history.
            //
            // The TERMINAL dispositions (disposed / returned_supplier / warranty_claimed / sold) are
            // also deliberately absent. They fire in the same instant as the removal that produced
            // them, so mirroring both put two lines on the car's timeline for one physical act. What
            // the vehicle's biography records is "the part came off" — the removal line already
            // names the reason and the disposition. Where the part went afterwards is warehouse and
            // finance history, and it stays where it belongs: on the component's own dossier, which
            // keeps every one of these events in full.
            default                           => null,
        };

        if ($logEvent) {
            foreach (array_unique(array_filter([$data['from_vehicle_id'] ?? null, $data['to_vehicle_id'] ?? null])) as $vehicleId) {
                if ($vehicle = Vehicle::find($vehicleId)) {
                    $this->log->recordVehicle($vehicle, $logEvent, $actor, [
                        'source_tag'  => 'components',
                        'description' => $this->timelinePhrase($component, $event, $data),
                        'meta'        => ['component_id' => $component->id, 'component_event_id' => $row->id] + ($data['meta'] ?? []),
                    ]);
                }
            }
        }

        return $row;
    }
}
