<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\AppSetting;
use App\Models\DamageCatalog;
use App\Models\FaultCatalog;
use App\Models\MaintenanceTaskLocation;
use App\Models\VehicleLocation;
use App\Models\VehicleLocationGroup;
use App\Services\FaultLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Curation of the WHERE axis — the admin surface behind the "Vehicle Locations" page.
 *
 * Three things are editable here, and they are the three things that decide what the location picker
 * does when an inspector taps a fault:
 *   1. THE PLACES        `vehicle_locations` — add, rename (EN + AR), re-group, re-grade precision,
 *                        alias, reorder, retire. What the picker offers.
 *   2. THE SECTIONS      `vehicle_location_groups` — the collapsible headings the places sit under.
 *   3. THE POLICY        `fault_catalog.location_mode` / `damage_catalog.location_mode` — whether a
 *                        given fault type demands a place, offers one, or has none at all. What makes
 *                        "Say where on the car — this fault cannot be filed without a location" appear.
 * Plus one rail: the maximum quantity a single fault row may claim.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ───────────────────────────────────────────────────────────
 * It never deletes a place that a fault points at. `maintenance_task_locations` holds restrictOnDelete
 * references and a place is WHERE a historical fault was — dropping the row would erase that fact from
 * every ticket that used it. Retirement (`is_active=false`) is the answer: the place disappears from
 * the picker and keeps answering for the tickets that already reference it. A delete is allowed only
 * for a place nothing has ever used, which is the "I just added this by mistake" case.
 *
 * Every write stamps `edited_in_app` so the config seeder stops re-asserting the authored wording over
 * a curator's decision — the same read-config/write-DB trade `component_catalog` makes.
 *
 * Reads are gated to maintenance.view (anyone who files a fault should be able to see the vocabulary
 * they are filing into); writes to maintenance.manage, matching every other catalog admin surface.
 */
class VehicleLocationController extends Controller
{
    /**
     * Everything the admin page renders, in one request: the sections, every place (active AND
     * retired, with how many faults each has been used on), the per-type policy table, the vocabulary
     * of precisions / inspection zones / area keys a place can point at, and the quantity rail.
     *
     * Usage counts are the whole reason this page can be trusted to change things: they are what turns
     * "can I delete this?" from a guess into an answer, and they come from the link table itself rather
     * than from anything derived.
     */
    public function index(FaultLocationService $service)
    {
        // One grouped count query, not one per place — this table grows with every located fault.
        $usage = MaintenanceTaskLocation::query()
            ->selectRaw('vehicle_location_id, COUNT(*) as c')
            ->groupBy('vehicle_location_id')
            ->pluck('c', 'vehicle_location_id');

        $locations = VehicleLocation::query()
            ->orderBy('group_key')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (VehicleLocation $l) => [
                'id'              => $l->id,
                'slug'            => $l->slug,
                'name'            => $l->name,
                'name_ar'         => $l->name_ar,
                'group_key'       => $l->group_key,
                'precision'       => $l->precision,
                'inspection_zone' => $l->inspection_zone,
                'area_key'        => $l->area_key,
                'aliases'         => $l->aliases ?: [],
                'is_active'       => (bool) $l->is_active,
                'edited_in_app'   => (bool) $l->edited_in_app,
                'sort_order'      => (int) $l->sort_order,
                // How many faults have been filed at this place, and therefore whether it may be
                // deleted at all. `in_use` is the flag the UI hides the delete button behind.
                'usage_count'     => (int) ($usage[$l->id] ?? 0),
                'in_use'          => (int) ($usage[$l->id] ?? 0) > 0,
            ]);

        return ResponseHelper::SuccessResponse(
            [
                'groups'       => $this->groupPayload($locations),
                'locations'    => $locations,
                'policy'       => $this->policyPayload($service),
                'precisions'   => $this->precisionPayload(),
                // The inspection-diagram panels a place may point at. Sourced from the places that
                // already claim one rather than from a hard-coded list, so this stays honest about
                // what VehicleDiagram.js actually offers instead of drifting from it.
                'zones'        => $locations->pluck('inspection_zone')->filter()->unique()->sort()->values(),
                // The damage_catalog.area_key values a place may satisfy — read from the catalog that
                // owns them, so the picker here cannot offer an area no damage type speaks.
                'area_keys'    => $this->areaKeys($locations),
                'max_quantity' => $service->maxQuantity(),
                'defaults'     => [
                    // What the authored config says the cap is, so the settings box can show what
                    // "reset" would mean rather than just blanking the field.
                    'max_quantity' => (int) config('vehicle_locations.max_quantity', 40),
                ],
                'counts' => [
                    'total'    => $locations->count(),
                    'active'   => $locations->where('is_active', true)->count(),
                    'retired'  => $locations->where('is_active', false)->count(),
                    'sections' => VehicleLocationGroup::query()->count(),
                    'in_use'   => $locations->where('in_use', true)->count(),
                ],
            ],
            'Vehicle location vocabulary retrieved successfully',
            200
        );
    }

    // ── Places ────────────────────────────────────────────────────────────────────────────────────

    /** Add a place to the vocabulary. */
    public function store(Request $request, FaultLocationService $service)
    {
        $data = $this->validatePlace($request);

        $location = VehicleLocation::create($data + ['edited_in_app' => true]);
        $service->flush();

        return ResponseHelper::SuccessResponse(
            ['location' => $location],
            "\"{$location->name}\" added — inspectors can pick it now",
            201
        );
    }

    /**
     * Edit a place. `slug` is NOT editable: every fault ever filed here points at it by slug, and a
     * rename would detach them all. The name is what shows; change that.
     */
    public function update(Request $request, VehicleLocation $vehicleLocation, FaultLocationService $service)
    {
        $data = $this->validatePlace($request, $vehicleLocation);
        unset($data['slug']);

        $vehicleLocation->update($data + ['edited_in_app' => true]);
        $service->flush();

        return ResponseHelper::SuccessResponse(
            ['location' => $vehicleLocation->fresh()],
            "\"{$vehicleLocation->name}\" updated",
            200
        );
    }

    /**
     * Retire or restore a place. THE normal way to take a place out of the picker.
     *
     * Separate from update() because it is one click on a table row, and because it is the action the
     * UI offers in place of a delete for the (usual) case that faults already reference the row.
     */
    public function toggle(Request $request, VehicleLocation $vehicleLocation, FaultLocationService $service)
    {
        $active = $request->boolean('is_active', ! $vehicleLocation->is_active);

        $vehicleLocation->update(['is_active' => $active, 'edited_in_app' => true]);
        $service->flush();

        return ResponseHelper::SuccessResponse(
            ['location' => $vehicleLocation->fresh()],
            $active
                ? "\"{$vehicleLocation->name}\" is back in the picker"
                : "\"{$vehicleLocation->name}\" retired — it stays on the faults that already used it",
            200
        );
    }

    /**
     * Delete a place — ONLY one nothing has ever been filed at.
     *
     * Refused with an explanation otherwise, rather than letting the database raise a foreign-key
     * error the operator cannot read. A place in use is WHERE a historical fault was; that fact
     * outranks tidiness, and retirement achieves everything the person actually wanted.
     */
    public function destroy(VehicleLocation $vehicleLocation, FaultLocationService $service)
    {
        $used = MaintenanceTaskLocation::query()->where('vehicle_location_id', $vehicleLocation->id)->count();

        if ($used > 0) {
            return ResponseHelper::FailureResponse(
                ['usage_count' => $used],
                "\"{$vehicleLocation->name}\" is where {$used} recorded fault(s) are — retire it instead of deleting it, so those tickets keep their answer.",
                422
            );
        }

        $name = $vehicleLocation->name;
        $vehicleLocation->delete();
        $service->flush();

        return ResponseHelper::SuccessResponse(null, "\"{$name}\" deleted", 200);
    }

    /**
     * Re-order places within a section — the drag-to-reorder save.
     *
     * One transaction: a half-applied order is a picker in an arbitrary sequence, which is worse than
     * the order it had before the drag.
     */
    public function reorder(Request $request, FaultLocationService $service)
    {
        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:vehicle_locations,id'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['order']) as $i => $id) {
                // Spaced by 10 so a later single insert can be slotted between two rows without
                // rewriting the whole section.
                VehicleLocation::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        $service->flush();

        return ResponseHelper::SuccessResponse(null, 'Order saved', 200);
    }

    // ── Sections ──────────────────────────────────────────────────────────────────────────────────

    /** Add a picker section. */
    public function storeGroup(Request $request, FaultLocationService $service)
    {
        $data = $this->validateGroup($request);

        $group = VehicleLocationGroup::create($data);
        $service->flush();

        return ResponseHelper::SuccessResponse(['group' => $group], "Section \"{$group->label}\" added", 201);
    }

    /**
     * Rename / reorder / retire a section. `key` is fixed — places point at it by key, so a rename
     * there would empty the section rather than rename it.
     */
    public function updateGroup(Request $request, VehicleLocationGroup $group, FaultLocationService $service)
    {
        $data = $this->validateGroup($request, $group);
        unset($data['key']);

        $group->update($data);
        $service->flush();

        return ResponseHelper::SuccessResponse(['group' => $group->fresh()], "Section \"{$group->label}\" updated", 200);
    }

    /**
     * Delete a section — only an empty one. A section holding places cannot go: its key is what those
     * places name, and orphaning them would drop them out of the grouped picker entirely.
     */
    public function destroyGroup(VehicleLocationGroup $group, FaultLocationService $service)
    {
        $held = VehicleLocation::query()->where('group_key', $group->key)->count();

        if ($held > 0) {
            return ResponseHelper::FailureResponse(
                ['location_count' => $held],
                "\"{$group->label}\" still holds {$held} place(s). Move them to another section first, or just hide this one.",
                422
            );
        }

        $label = $group->label;
        $group->delete();
        $service->flush();

        return ResponseHelper::SuccessResponse(null, "Section \"{$label}\" deleted", 200);
    }

    /** Re-order the sections themselves. */
    public function reorderGroups(Request $request, FaultLocationService $service)
    {
        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:vehicle_location_groups,id'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['order']) as $i => $id) {
                VehicleLocationGroup::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        $service->flush();

        return ResponseHelper::SuccessResponse(null, 'Section order saved', 200);
    }

    // ── Policy: does this fault type even HAVE a where? ───────────────────────────────────────────

    /**
     * Set one fault/damage type's location mode — required | optional | none.
     *
     * This is the switch behind the message in the picker: `required` is what refuses a report with no
     * place, `none` is what removes the picker for a type where the answer is already in the name
     * ("Wiper / washer fault"). Stored on the catalog row, which outranks the authored config for that
     * one type and nothing else — see FaultLocationService's resolution order.
     */
    public function updatePolicy(Request $request, FaultLocationService $service)
    {
        $data = $request->validate([
            'catalog' => ['required', Rule::in(['fault', 'damage'])],
            'id'      => ['required', 'integer'],
            'mode'    => ['required', Rule::in(FaultLocationService::MODES)],
        ]);

        $model = $data['catalog'] === 'fault' ? FaultCatalog::class : DamageCatalog::class;
        $row   = $model::query()->findOrFail($data['id']);

        $row->update(['location_mode' => $data['mode']]);
        $service->flush();

        $wording = [
            FaultLocationService::MODE_REQUIRED => 'must say where on the car',
            FaultLocationService::MODE_OPTIONAL => 'may say where on the car',
            FaultLocationService::MODE_NONE     => 'is never asked where on the car',
        ][$data['mode']];

        return ResponseHelper::SuccessResponse(
            ['policy' => $this->policyPayload($service)],
            "\"{$row->name}\" {$wording}",
            200
        );
    }

    /**
     * Put one type back on the authored answer — the config's by_catalog_slug → by_category → default
     * chain for that row, recomputed now rather than remembered from when it was seeded.
     */
    public function resetPolicy(Request $request, FaultLocationService $service)
    {
        $data = $request->validate([
            'catalog' => ['required', Rule::in(['fault', 'damage'])],
            'id'      => ['required', 'integer'],
        ]);

        $model = $data['catalog'] === 'fault' ? FaultCatalog::class : DamageCatalog::class;
        $row   = $model::query()->findOrFail($data['id']);

        // storedMode null on purpose: resolve the AUTHORED answer, ignoring what the row now holds.
        $authored = $service->policyFor($row->slug, $row->category_key, null);
        $row->update(['location_mode' => $authored]);
        $service->flush();

        return ResponseHelper::SuccessResponse(
            ['policy' => $this->policyPayload($service)],
            "\"{$row->name}\" is back on the standard answer for {$row->category_key} ({$authored})",
            200
        );
    }

    // ── The quantity rail ─────────────────────────────────────────────────────────────────────────

    /**
     * The cap on "how many" for a single fault row. A sanity rail against a slipped keypress, not a
     * business rule — 40 scratches on one car is a total loss, not a data-entry event.
     */
    public function updateSettings(Request $request, FaultLocationService $service)
    {
        $data = $request->validate([
            'max_quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        AppSetting::put(FaultLocationService::SETTING_MAX_QUANTITY, (int) $data['max_quantity']);
        $service->flush();

        return ResponseHelper::SuccessResponse(
            ['max_quantity' => $service->maxQuantity()],
            "A fault can now claim up to {$data['max_quantity']}",
            200
        );
    }

    // ── Payload builders ──────────────────────────────────────────────────────────────────────────

    /**
     * The sections, each carrying how many places it holds — falling back to the authored config when
     * the table has not been seeded, so the page is never blank on a fresh install.
     */
    private function groupPayload($locations): array
    {
        $held = $locations->countBy('group_key');

        $rows = VehicleLocationGroup::query()->ordered()->get();

        if ($rows->isEmpty()) {
            return array_values(array_map(fn ($g) => [
                'id'              => null,
                'key'             => $g['key'] ?? '',
                'label'           => $g['label'] ?? ($g['key'] ?? ''),
                'label_ar'        => $g['label_ar'] ?? null,
                'is_active'       => true,
                'sort_order'      => (int) ($g['sort_order'] ?? 0),
                'location_count'  => (int) ($held[$g['key'] ?? ''] ?? 0),
                'from_config'     => true,
            ], (array) config('vehicle_locations.groups', [])));
        }

        return $rows->map(fn (VehicleLocationGroup $g) => [
            'id'             => $g->id,
            'key'            => $g->key,
            'label'          => $g->label,
            'label_ar'       => $g->label_ar,
            'is_active'      => (bool) $g->is_active,
            'sort_order'     => (int) $g->sort_order,
            'location_count' => (int) ($held[$g->key] ?? 0),
            'from_config'    => false,
        ])->all();
    }

    /**
     * The policy table: every fault and damage type, the mode in force, and WHERE that mode came from.
     *
     * `source` is the part that matters — a screen that shows "required" without saying whether a
     * person chose it or a category implied it is a black box, and the whole point of this page is
     * that the picker's behaviour is legible. See [[traceability-visibility-requirement]].
     */
    private function policyPayload(FaultLocationService $service): array
    {
        $policyConfig = (array) config('vehicle_locations.policy', []);
        $bySlug       = (array) ($policyConfig['by_catalog_slug'] ?? []);
        $byCategory   = (array) ($policyConfig['by_category'] ?? []);

        $out = [];

        foreach ([['fault', FaultCatalog::class], ['damage', DamageCatalog::class]] as [$kind, $model]) {
            foreach ($model::query()->orderBy('category_key')->orderBy('name')->get() as $row) {
                $authored = $service->policyFor($row->slug, $row->category_key, null);
                $stored   = $row->location_mode;
                $mode     = $service->policyFor($row->slug, $row->category_key, $stored);

                $out[] = [
                    'catalog'       => $kind,
                    'id'            => $row->id,
                    'slug'          => $row->slug,
                    'name'          => $row->name,
                    'name_ar'       => $row->name_ar,
                    'category_key'  => $row->category_key,
                    'is_active'     => (bool) $row->is_active,
                    'mode'          => $mode,
                    'authored_mode' => $authored,
                    // true when a curator has moved this type off the answer its category implies.
                    'overridden'    => $mode !== $authored,
                    'source'        => $this->modeSource($row->slug, $row->category_key, $stored, $authored, $bySlug, $byCategory),
                ];
            }
        }

        return $out;
    }

    /**
     * Which rule EXPLAINS this type's mode: the row itself, the per-type config line, its category, or
     * the global default. Mirrors FaultLocationService::policyFor's order — if these two ever disagree
     * the page is lying, so both read the same two config arrays.
     *
     * One deliberate difference. The seeder stamps every catalog row with the authored answer, so
     * technically the row always decides. Reporting that would mark all 91 types "set by hand" and
     * make the one type somebody actually changed invisible. A stored value that MATCHES the authored
     * answer therefore reports the authored rule that produced it; only a divergence is a human
     * decision, which is exactly what a curator needs to see.
     */
    private function modeSource(?string $slug, ?string $category, ?string $stored, string $authored, array $bySlug, array $byCategory): string
    {
        if (in_array($stored, FaultLocationService::MODES, true) && $stored !== $authored) {
            return 'row';
        }
        if ($slug && in_array($bySlug[$slug] ?? null, FaultLocationService::MODES, true)) {
            return 'type';
        }
        if ($category && in_array($byCategory[$category] ?? null, FaultLocationService::MODES, true)) {
            return 'category';
        }

        return 'default';
    }

    /** The precision vocabulary, with the one-line explanation of each — the picker's own legend. */
    private function precisionPayload(): array
    {
        $hints = [
            VehicleLocation::PRECISION_PANEL     => 'One body panel — front bumper, rear-left door.',
            VehicleLocation::PRECISION_CORNER    => 'One of the four wheel corners.',
            VehicleLocation::PRECISION_ZONE      => 'An area rather than a part — engine bay, underbody, dashboard.',
            VehicleLocation::PRECISION_COMPONENT => 'A named part — mirror, wipers, exhaust.',
            VehicleLocation::PRECISION_WHOLE     => 'Deliberately unspecific — "body", "rims", "cabin". An honest answer when nobody went panel by panel.',
        ];

        return array_map(fn ($p) => [
            'key'   => $p,
            'label' => Str::headline($p),
            'hint'  => $hints[$p] ?? null,
        ], VehicleLocation::PRECISIONS);
    }

    /**
     * The area keys a place may satisfy — the union of what `damage_catalog` actually speaks and what
     * places already claim, so an existing value is never silently unselectable.
     */
    private function areaKeys($locations): array
    {
        $fromCatalog = DamageCatalog::query()->whereNotNull('area_key')->distinct()->pluck('area_key');

        return $fromCatalog
            ->merge($locations->pluck('area_key'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // ── Validation ────────────────────────────────────────────────────────────────────────────────

    /**
     * A place's fields. `slug` is required on create and ignored on update (the caller strips it):
     * it is the key every stored fault points at, so it is generated once and never touched again.
     */
    private function validatePlace(Request $request, ?VehicleLocation $existing = null): array
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:120'],
            'name_ar'         => ['nullable', 'string', 'max:120'],
            'group_key'       => ['required', 'string', 'max:40'],
            'precision'       => ['required', Rule::in(VehicleLocation::PRECISIONS)],
            'inspection_zone' => ['nullable', 'string', 'max:40'],
            'area_key'        => ['nullable', 'string', 'max:40'],
            'aliases'         => ['nullable', 'array'],
            'aliases.*'       => ['string', 'max:120'],
            'is_active'       => ['nullable', 'boolean'],
            'sort_order'      => ['nullable', 'integer', 'min:0', 'max:65000'],
            // Offered on create for an admin who wants to match an existing convention; derived from
            // the name otherwise. Never accepted on update.
            'slug'            => [
                'nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('vehicle_locations', 'slug')->ignore($existing?->id),
            ],
        ], [
            'slug.regex' => 'A slug may only contain lowercase letters, numbers and underscores.',
        ]);

        // The section must exist. Checked against the service rather than with `exists:` so the
        // fallback case — a database whose groups table has not been seeded yet — still accepts the
        // authored sections instead of refusing every place on the page.
        $known = collect(app(FaultLocationService::class)->groups())->pluck('key');
        if (! $known->contains($data['group_key'])) {
            abort(422, 'That section does not exist. Create the section first, or pick an existing one.');
        }

        $data['aliases']    = array_values(array_filter(array_map('trim', (array) ($data['aliases'] ?? []))));
        $data['is_active']  = $request->boolean('is_active', $existing->is_active ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? $existing->sort_order ?? $this->nextSortOrder($data['group_key']));

        if (! $existing) {
            $data['slug'] = ($data['slug'] ?? null) ?: $this->uniqueSlug($data['name']);
        }

        return $data;
    }

    /** A section's fields. `key` is fixed after creation for the same reason a place's slug is. */
    private function validateGroup(Request $request, ?VehicleLocationGroup $existing = null): array
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:120'],
            'label_ar'   => ['nullable', 'string', 'max:120'],
            'is_active'  => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'key'        => [
                'nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('vehicle_location_groups', 'key')->ignore($existing?->id),
            ],
        ], [
            'key.regex' => 'A section key may only contain lowercase letters, numbers and underscores.',
        ]);

        $data['is_active']  = $request->boolean('is_active', $existing->is_active ?? true);
        $data['sort_order'] = (int) ($data['sort_order']
            ?? $existing->sort_order
            ?? ((int) VehicleLocationGroup::query()->max('sort_order') + 10));

        if (! $existing) {
            $data['key'] = ($data['key'] ?? null) ?: $this->uniqueKey($data['label']);
        }

        return $data;
    }

    /** Where a new place lands in its section: after everything already there. */
    private function nextSortOrder(string $groupKey): int
    {
        return (int) VehicleLocation::query()->where('group_key', $groupKey)->max('sort_order') + 10;
    }

    /** slug from a name, suffixed until free — "Front Bumper" → front_bumper, front_bumper_2, … */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'location';
        $slug = Str::limit($base, 60, '');
        $n    = 2;

        while (VehicleLocation::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 56, '')."_{$n}";
            $n++;
        }

        return $slug;
    }

    /** The same, for a section key. */
    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'section';
        $key  = Str::limit($base, 40, '');
        $n    = 2;

        while (VehicleLocationGroup::query()->where('key', $key)->exists()) {
            $key = Str::limit($base, 36, '')."_{$n}";
            $n++;
        }

        return $key;
    }
}
