<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ComponentCatalog;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\Components\ComponentReadModel;
use Illuminate\Http\Request;

/**
 * Vehicle Installed Components — the READ API for a car's physical configuration.
 *
 * DELIBERATELY READ-ONLY. There is no store(), no update() and no destroy() on this controller, and
 * that is the feature, not an omission: the vehicle's configuration is derived from the maintenance
 * workflow, so the ONLY way a component appears on a car is PartWorkflowService::installPurchase
 * reaching the install step and calling ComponentService. Adding a write endpoint here would create
 * a second source of truth and let the record drift from the physical car — exactly what the Asset
 * Layer exists to prevent. Asset custody operations that are genuinely not installs (transfer between
 * cars, shelf disposal, vehicle-sale settlement) live on ComponentService and are reached from their
 * own workflow surfaces under components.manage.
 *
 * Every endpoint here is gated on components.view.
 */
class VehicleComponentController extends Controller
{
    public function __construct(private ComponentReadModel $read)
    {
    }

    /**
     * The component TYPE dictionary — what the install step offers as "what kind of thing is this?".
     *
     * This exists because a part's free-text name ("Radiator", "rad top tank") cannot be mapped to a
     * catalog entry by the system: ComponentService::resolveCatalog can only auto-resolve when the
     * purchase carries a category that maps to exactly ONE non-consumable type, which is rare and is
     * null entirely for parts requested from a ticket. Without an explicit choice at install time the
     * component write fails — silently, under shadow mode — and the vehicle's configuration never
     * updates even though the part was fitted. So the technician picks the type, once, at the moment
     * they are holding the part.
     *
     * Consumables are excluded: they never become components (standing rule).
     */
    public function catalog()
    {
        try {
            $types = ComponentCatalog::active()
                ->where('tracking_mode', '!=', ComponentCatalog::TRACKING_CONSUMABLE)
                ->orderBy('category_key')
                ->orderBy('name')
                ->get()
                ->map(fn (ComponentCatalog $c) => [
                    'id'                      => $c->id,
                    'slug'                    => $c->slug,
                    'name'                    => $c->name,
                    'category_key'            => $c->category_key,
                    'tracking_mode'           => $c->tracking_mode,
                    'requires_serial'         => $c->isSerialized(),
                    // Drives the Position dropdown: [] means the type takes no position at all, and
                    // sending one anyway is a 422.
                    'positions'               => $c->positionsFor(),
                    'default_warranty_months' => $c->default_warranty_months,
                    'expected_life_km'        => $c->expected_life_km,
                    'expected_life_months'    => $c->expected_life_months,
                ]);

            return ResponseHelper::SuccessResponse($types, 'Component catalog retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * One vehicle's configuration: what is fitted NOW, the consumables refreshed by routine service,
     * the full replacement history, and the roll-up. Backs the Installed Components tab.
     */
    public function forVehicle(Vehicle $vehicle)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->read->vehicleConfiguration($vehicle),
                'Vehicle installed components retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * One component's dossier — the drill-down behind a table row: provenance chain (ticket →
     * purchase order → invoice → supplier), the append-only biography, photos, inspections, and
     * both neighbours in the replacement chain.
     */
    public function show(VehicleComponent $component)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->read->dossier($component),
                'Component dossier retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The fleet-wide component dashboard: warranty expiring, past expected service life, recently
     * replaced, frequently replaced, total installed value and average age. Each card carries both
     * the true count and the rows behind it so every headline can be opened and audited.
     */
    public function dashboard(Request $request)
    {
        try {
            $limit = max(1, min(200, (int) $request->integer('limit', 25)));

            return ResponseHelper::SuccessResponse(
                $this->read->fleetSummary($limit),
                'Component dashboard retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
