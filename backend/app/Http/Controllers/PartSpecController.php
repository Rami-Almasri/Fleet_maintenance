<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ComponentCatalog;
use App\Models\Vehicle;
use App\Models\VehiclePartSpec;
use App\Services\VehiclePartSpecService;
use App\Support\PartSpecs;
use Illuminate\Http\Request;

/**
 * Part specifications — the field dictionary, and what each car takes.
 *
 * TWO ENDPOINTS DO ALL THE WORK, and the split matters:
 *
 *   dictionary()  the FIELD DEFINITIONS, once per session. Every spec input in the app is BUILT
 *                 from this rather than hardcoded, which is the mechanism that keeps the form and
 *                 the validator agreeing: adding an option to config/part_specs.php makes it
 *                 selectable, and nothing else has to be edited to match.
 *
 *   forVehicle()  what THIS CAR takes. Preloaded by the oil-change screen, the part picker and the
 *                 vehicle profile so each row does not ask separately.
 *
 * Reading the dictionary needs no special permission beyond being signed in — it is reference data
 * with no fleet facts in it, and gating it would empty the spec inputs for exactly the technicians
 * they exist for. Reading a car's sheet needs vehicles.view; writing it needs vehicles.update,
 * because "what this car takes" is a claim about the vehicle.
 */
class PartSpecController extends Controller
{
    public function __construct(private VehiclePartSpecService $specs)
    {
    }

    /**
     * The field dictionary, plus the part-type → field-keys map so a caller can render specs for a
     * part without a second round trip.
     */
    public function dictionary(Request $request)
    {
        try {
            $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';

            $byPartType = ComponentCatalog::query()
                ->active()
                ->whereNotNull('spec_fields')
                ->get(['id', 'slug', 'spec_fields'])
                ->mapWithKeys(fn (ComponentCatalog $c) => [
                    $c->id => [
                        'slug'   => $c->slug,
                        // Resolved through PartSpecs, so a key that no longer exists in the
                        // dictionary is dropped here rather than reaching a form as a dead input.
                        'fields' => array_keys($c->specFields()),
                    ],
                ]);

            return ResponseHelper::SuccessResponse([
                'fields'        => PartSpecs::payload($locale),
                'by_part_type'  => $byPartType,
            ], 'Part spec dictionary retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * What this car takes, keyed by part-type slug.
     *
     * Every row carries `source` and a plain sentence saying where the figure came from. That is
     * not decoration: a value copied from the last fitting and a value someone read out of the
     * handbook look identical on screen, and only one of them is a reason to challenge a garage.
     */
    public function forVehicle(Vehicle $vehicle, Request $request)
    {
        try {
            $locale = $request->query('locale') === 'ar' ? 'ar' : 'en';

            $sheet = collect($this->specs->sheetFor($vehicle->id))
                ->map(fn (VehiclePartSpec $row) => [
                    'id'                   => $row->id,
                    'component_catalog_id' => $row->component_catalog_id,
                    'part_type'            => $row->catalog->displayName($locale),
                    'slug'                 => $row->catalog->slug,
                    'specs'                => $row->specs,
                    'summary'              => $row->summary($locale),
                    'detail'               => PartSpecs::describe($row->catalog, $row->specs, $locale),
                    'source'               => $row->source,
                    'is_observed'          => $row->isObserved(),
                    'provenance'           => $row->provenanceSentence(),
                    'confirmed_at'         => $row->confirmed_at?->toIso8601String(),
                    'confirmed_by_name'    => $row->confirmed_by_name,
                    'notes'                => $row->notes,
                ]);

            return ResponseHelper::SuccessResponse($sheet, 'Vehicle part specs retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * A person states what this car takes. Always wins over anything the system observed.
     *
     * `source` is accepted from the caller so the UI can distinguish "I read this in the handbook"
     * from "I am fairly sure" — but `observed` is refused here, because an observation is something
     * the system derives from a fitting and never something a form asserts.
     */
    public function setForVehicle(Vehicle $vehicle, ComponentCatalog $part, Request $request)
    {
        try {
            $data = $request->validate([
                'specs'  => ['required', 'array'],
                'source' => ['nullable', 'in:manual,manual_book'],
                'notes'  => ['nullable', 'string', 'max:500'],
            ]);

            $row = $this->specs->set(
                $vehicle->id,
                $part,
                $data['specs'],
                $request->user(),
                $data['source'] ?? VehiclePartSpec::SOURCE_MANUAL,
                $data['notes'] ?? null
            );

            return ResponseHelper::SuccessResponse([
                'id'      => $row->id,
                'specs'   => $row->specs,
                'summary' => $row->summary(),
                'source'  => $row->source,
            ], 'Saved what this vehicle takes', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Forget a fitment figure entirely — for a row entered against the wrong car. */
    public function forgetForVehicle(Vehicle $vehicle, ComponentCatalog $part)
    {
        try {
            VehiclePartSpec::where('vehicle_id', $vehicle->id)
                ->where('component_catalog_id', $part->id)
                ->delete();

            return ResponseHelper::SuccessResponse(null, 'Removed', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Is this the right part?" — asked by a form BEFORE it saves, so the answer arrives while the
     * person is still holding the part.
     *
     * Always a 200 with a (possibly empty) conflict list, never a 422. This endpoint reports a
     * disagreement; it does not have the standing to stop a fitting, because the spec sheet it
     * compares against may itself be an unconfirmed observation and the person at the counter can
     * see the part.
     */
    public function check(Vehicle $vehicle, ComponentCatalog $part, Request $request)
    {
        try {
            $conflicts = $this->specs->checkFitting(
                $vehicle->id,
                $part,
                $request->input('specs', []),
                $request->query('locale') === 'ar' ? 'ar' : 'en'
            );

            $expected = $this->specs->expectedFor($vehicle->id, $part->id);

            return ResponseHelper::SuccessResponse([
                'conflicts'        => $conflicts,
                'expected'         => $expected?->specs,
                'expected_summary' => $expected?->summary() ?: null,
                'expected_source'  => $expected?->source,
            ], 'Checked', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
