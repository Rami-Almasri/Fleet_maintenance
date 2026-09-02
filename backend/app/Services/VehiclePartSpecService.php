<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VehicleComponent;
use App\Models\VehiclePartSpec;
use App\Support\PartSpecs;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE write path for what a car takes, and the reader every "which one does it need?" surface
 * calls.
 *
 * Evidence class: DERIVED (observed rows) and FACT (manual rows) — the `source` column says which,
 * and the distinction is load-bearing rather than decorative.
 *   Produces: E-fitment (vehicle_part_specs)
 *   Consumes: vehicle_components.specs, service_records.specs
 *
 * ── The rule this class exists to hold ───────────────────────────────────────────────────────────
 *
 * Learning is automatic; overwriting a person is not. `learnFrom*` will fill an empty fitment and
 * will refresh one it wrote itself, and it will refuse — silently and by design — to touch a row a
 * human entered. A system observation is a suggestion, and a suggestion that quietly replaces a
 * decision is how a fleet ends up with numbers nobody chose and everybody half-trusts.
 */
class VehiclePartSpecService
{
    /**
     * What this car takes for one part type, or null when nobody knows yet.
     *
     * Null is a real and common answer, and callers must show it as one — "not recorded yet, what
     * went in?" — rather than falling back to a fleet average. There is no fleet average for oil.
     */
    public function expectedFor(int $vehicleId, int $catalogId): ?VehiclePartSpec
    {
        return VehiclePartSpec::with('catalog')
            ->where('vehicle_id', $vehicleId)
            ->where('component_catalog_id', $catalogId)
            ->first();
    }

    /**
     * Everything known about one car, keyed by catalog slug — the vehicle profile's spec sheet, and
     * the single query the oil-change and part-picker screens preload rather than asking per row.
     *
     * @return array<string,VehiclePartSpec>
     */
    public function sheetFor(int $vehicleId): array
    {
        return VehiclePartSpec::with('catalog')
            ->forVehicle($vehicleId)
            ->get()
            ->filter(fn (VehiclePartSpec $row) => $row->catalog !== null)
            ->keyBy(fn (VehiclePartSpec $row) => $row->catalog->slug)
            ->all();
    }

    /**
     * A person says what this car takes. Always lands — this is the top of the ladder.
     *
     * @param  array<string,mixed>  $specs  raw form input; validated against the part type here
     */
    public function set(
        int $vehicleId,
        ComponentCatalog $catalog,
        array $specs,
        ?User $actor = null,
        string $source = VehiclePartSpec::SOURCE_MANUAL,
        ?string $notes = null
    ): VehiclePartSpec {
        $clean = PartSpecs::validate($catalog, $specs);

        return DB::transaction(function () use ($vehicleId, $catalog, $clean, $actor, $source, $notes) {
            $row = VehiclePartSpec::firstOrNew([
                'vehicle_id'           => $vehicleId,
                'component_catalog_id' => $catalog->id,
            ]);

            $row->specs  = $clean;
            $row->source = in_array($source, VehiclePartSpec::SOURCES, true)
                ? $source
                : VehiclePartSpec::SOURCE_MANUAL;
            $row->notes             = $notes;
            $row->confirmed_at      = now();
            $row->confirmed_by      = $actor?->id;
            $row->confirmed_by_name = $actor?->name;

            // A hand-entered figure stands on its own. Clearing the learned-from links stops the
            // profile claiming a fitting as the origin of a number a person typed over it.
            $row->learned_from_component_id      = null;
            $row->learned_from_service_record_id = null;

            $row->save();

            return $row;
        });
    }

    /**
     * Copy the specs of a part that was just fitted onto the car's fitment sheet.
     *
     * Returns null when nothing was learned — no specs on the component, the part type carries none,
     * or a person has already answered this and outranks us. Callers ignore the return; it exists so
     * the tests can assert the refusal rather than infer it.
     */
    public function learnFromComponent(VehicleComponent $component): ?VehiclePartSpec
    {
        if (! $component->vehicle_id || ! $component->specs) {
            return null;
        }

        $catalog = $component->relationLoaded('catalog')
            ? $component->catalog
            : ComponentCatalog::find($component->component_catalog_id);

        return $this->observe(
            $component->vehicle_id,
            $catalog,
            $component->specs,
            componentId: $component->id
        );
    }

    /** The consumable sibling: the oil that went in becomes the oil this car takes. */
    public function learnFromServiceRecord(ServiceRecord $record, ?ComponentCatalog $catalog = null): ?VehiclePartSpec
    {
        if (! $record->vehicle_id || ! $record->specs) {
            return null;
        }

        return $this->observe(
            $record->vehicle_id,
            $catalog,
            $record->specs,
            serviceRecordId: $record->id
        );
    }

    /**
     * The observed write, with the ladder enforced in exactly one place.
     *
     * Invalid values are DROPPED rather than thrown on. This runs inside closing a ticket or saving
     * a service, and a fitment note is never worth failing that write: the part is fitted either
     * way, and the honest outcome of an unparseable spec is that the sheet stays empty.
     */
    private function observe(
        int $vehicleId,
        ?ComponentCatalog $catalog,
        array $rawSpecs,
        ?int $componentId = null,
        ?int $serviceRecordId = null
    ): ?VehiclePartSpec {
        if (! $catalog || ! PartSpecs::hasSpecs($catalog)) {
            return null;
        }

        try {
            $clean = PartSpecs::validate($catalog, $rawSpecs);
        } catch (\Throwable) {
            return null;
        }

        if ($clean === []) {
            return null;
        }

        return DB::transaction(function () use ($vehicleId, $catalog, $clean, $componentId, $serviceRecordId) {
            $row = VehiclePartSpec::where('vehicle_id', $vehicleId)
                ->where('component_catalog_id', $catalog->id)
                ->lockForUpdate()
                ->first();

            // Someone decided this already. An observation does not get to argue with them.
            if ($row && ! $row->isObserved()) {
                return null;
            }

            $row ??= new VehiclePartSpec([
                'vehicle_id'           => $vehicleId,
                'component_catalog_id' => $catalog->id,
            ]);

            $row->specs                         = $clean;
            $row->source                        = VehiclePartSpec::SOURCE_OBSERVED;
            $row->learned_from_component_id     = $componentId;
            $row->learned_from_service_record_id = $serviceRecordId;
            $row->save();

            return $row;
        });
    }

    /**
     * Is this about to be the wrong part? Returns the disagreements, or [] for "no reason to object".
     *
     * Only critical fields, only where both sides are known — see {@see PartSpecs::conflicts}. The
     * caller decides what to do with it, and the answer is always to WARN and let the save proceed:
     * the person at the counter can see the part and we cannot, and a hard block on a spec sheet
     * that may itself be an unconfirmed observation would stop real work over a guess.
     *
     * @param  array<string,mixed>|null  $proposed
     * @return array<int,array{key:string,label:string,expected:string,actual:string}>
     */
    public function checkFitting(int $vehicleId, ComponentCatalog $catalog, ?array $proposed, string $locale = 'en'): array
    {
        $expected = $this->expectedFor($vehicleId, $catalog->id);

        if (! $expected) {
            return [];
        }

        try {
            $clean = PartSpecs::validate($catalog, $proposed);
        } catch (\Throwable) {
            return [];
        }

        return PartSpecs::conflicts($catalog, $expected->specs, $clean, $locale);
    }
}
