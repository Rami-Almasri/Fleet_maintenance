<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\ComponentCatalogResource;
use App\Models\ComponentCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The PARTS CATALOG — curating the one list of part names the whole app selects from.
 *
 * This is the write side of component_catalog. Until this controller existed the vocabulary was
 * config-authored and unreachable from the app, which had a predictable result: the required-parts
 * box stayed free text, every inspector typed his own wording, and nothing joined to anything.
 * A vocabulary nobody can correct is a vocabulary nobody uses.
 *
 * TWO RULES GOVERN EVERY WRITE HERE.
 *
 * 1. AN EDIT CLAIMS THE ROW. Any successful write stamps `edited_in_app`, after which
 *    ComponentCatalogSeeder skips the row forever. Deploys stop overwriting decisions people made
 *    in the app. This is one-way on purpose — there is no "give it back to the config" action,
 *    because the only thing that could mean is "discard my correction".
 *
 * 2. RETIRE, NEVER DELETE — when the type is in use. Every physical component holds a
 *    restrictOnDelete FK to its catalog row, and so does every warranty. Deleting a type that is
 *    fitted to cars would either fail at the database or, worse, orphan the history that says what
 *    those cars are made of. `destroy` therefore deletes only a type that was never used, and
 *    retires anything else — reporting honestly which of the two it did.
 *
 * Read access is deliberately wider than write: anyone who can raise a part request needs to SEE
 * the list, or the picker is empty for exactly the people it was built for.
 *
 * NO try/catch IN HERE. The global handler in bootstrap/app.php already shapes every uncaught
 * exception into the standard envelope, and it deliberately lets ValidationException through to
 * Laravel's native {message, errors} renderer because that is the shape the forms read to highlight
 * individual fields. Catching Throwable in the controller would route validation failures through
 * the generic envelope instead, and the form would degrade to a single toast with no idea which
 * field was wrong.
 */
class PartsCatalogController extends Controller
{
    /**
     * The whole vocabulary, plus the legend the page needs to render and filter it.
     *
     * Returns everything in one call and lets the page filter client-side. 132 rows of short text
     * is a few tens of kilobytes; paginating it server-side would cost a round trip per keystroke
     * for a list that fits comfortably in memory, and the picker needs the whole set anyway to
     * search aliases as you type.
     */
    public function index(Request $request)
    {
        $rows = ComponentCatalog::query()
            ->withCount('components')
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q')))
            ->when($request->filled('category'), fn ($q) => $q->where('category_key', $request->string('category')))
            ->when($request->filled('tracking_mode'), fn ($q) => $q->where('tracking_mode', $request->string('tracking_mode')))
            // Active first, then by category, then alphabetically — retired rows sink without
            // disappearing, because "why can't I find X?" is usually "someone retired X".
            ->orderByDesc('is_active')
            ->orderBy('category_key')
            ->orderBy('name')
            ->get();

        return ResponseHelper::SuccessResponse([
            'parts'      => ComponentCatalogResource::collection($rows),
            'categories' => $this->categoryLegend(),
            'tracking_modes' => [
                ['key' => ComponentCatalog::TRACKING_SERIALIZED, 'label' => 'Serialized', 'label_ar' => 'برقم تسلسلي',
                    'hint' => 'Individual identity — a serial number is required and quantity is always 1 (engine, battery, compressor).'],
                ['key' => ComponentCatalog::TRACKING_BATCH, 'label' => 'Batch', 'label_ar' => 'بالكمية',
                    'hint' => 'Quantity and position are tracked, serial optional (tyres, brake pads, filters).'],
                ['key' => ComponentCatalog::TRACKING_CONSUMABLE, 'label' => 'Consumable', 'label_ar' => 'مستهلك',
                    'hint' => 'Work performed, not an asset — never becomes a tracked component (oil, coolant, gas).'],
            ],
            'position_schemes' => [
                ['key' => null, 'label' => 'No position'],
                ['key' => ComponentCatalog::SCHEME_AXLE, 'label' => 'Front / rear'],
                ['key' => ComponentCatalog::SCHEME_AXLE_CORNER, 'label' => 'Corner (FL / FR / RL / RR)'],
            ],
            'counts' => [
                'total'      => $rows->count(),
                'active'     => $rows->where('is_active', true)->count(),
                'retired'    => $rows->where('is_active', false)->count(),
                'edited'     => $rows->where('edited_in_app', true)->count(),
                'missing_ar' => $rows->whereNull('name_ar')->count(),
            ],
        ], 'Parts catalog retrieved');
    }

    /** Add a part type to the vocabulary. */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['slug'] = $this->uniqueSlug($request->input('slug') ?: $data['name']);

        $part = ComponentCatalog::create($data + $this->editStamp($request));

        return ResponseHelper::SuccessResponse(
            new ComponentCatalogResource($part->loadCount('components')),
            'Part added to the catalog',
            201
        );
    }

    /**
     * Edit a part type — its names, its Arabic term, its aliases, its warranty defaults.
     *
     * `slug` is NOT editable. It is the stable machine key the config seeder upserts on and that
     * code refers to types by; renaming it would silently create a duplicate on the next deploy and
     * break every reference at once. The display name is what people read, and that IS editable.
     */
    public function update(Request $request, ComponentCatalog $part)
    {
        $data = $this->validatePayload($request, $part);

        $part->update($data + $this->editStamp($request));

        return ResponseHelper::SuccessResponse(
            new ComponentCatalogResource($part->fresh()->loadCount('components')),
            'Part updated',
            200
        );
    }

    /**
     * Remove a part type — by retiring it if anything depends on it, by deleting it if nothing does.
     *
     * The distinction matters to the user, so the response says which happened rather than
     * reporting a generic success. A retired type stops appearing in pickers but keeps answering
     * "what is fitted to this car" for every component that already points at it.
     */
    public function destroy(Request $request, ComponentCatalog $part)
    {
        $count = $part->components()->count();

        if ($count > 0) {
            $part->update(['is_active' => false] + $this->editStamp($request));

            return ResponseHelper::SuccessResponse(
                new ComponentCatalogResource($part->fresh()->loadCount('components')),
                "Retired — hidden from pickers, but kept because {$count} fitted component(s) still refer to it",
                200
            );
        }

        $part->delete();

        return ResponseHelper::SuccessResponse(null, 'Part removed from the catalog', 200);
    }

    /** Bring a retired type back into the pickers. */
    public function restore(Request $request, ComponentCatalog $part)
    {
        $part->update(['is_active' => true] + $this->editStamp($request));

        return ResponseHelper::SuccessResponse(
            new ComponentCatalogResource($part->fresh()->loadCount('components')),
            'Part is active again',
            200
        );
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────────

    private function validatePayload(Request $request, ?ComponentCatalog $existing = null): array
    {
        $categories = array_column(config('maintenance_findings.categories', []), 'key');

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'name_ar'       => ['nullable', 'string', 'max:160'],
            // Sent as an array by the page; each entry is one thing someone might type.
            'aliases'       => ['nullable', 'array', 'max:40'],
            'aliases.*'     => ['string', 'max:120'],
            'category_key'  => ['required', Rule::in($categories)],
            'tracking_mode' => ['required', Rule::in(ComponentCatalog::TRACKING_MODES)],
            'default_part_number'     => ['nullable', 'string', 'max:80'],
            'default_warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'default_warranty_km'     => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'expected_life_km'        => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'expected_life_months'    => ['nullable', 'integer', 'min:0', 'max:600'],
            'position_scheme' => ['nullable', Rule::in(ComponentCatalog::POSITION_SCHEMES)],
            'is_active'       => ['nullable', 'boolean'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        // A consumable is never fitted anywhere, so a position vocabulary on one is meaningless and
        // would let a nonsense instance validate later. Rejected rather than silently dropped: the
        // user picked two settings that contradict, and only they can say which one they meant.
        if (($data['tracking_mode'] ?? null) === ComponentCatalog::TRACKING_CONSUMABLE
            && ! empty($data['position_scheme'])) {
            abort(422, 'A consumable has no position — it is work performed, not a part fitted to a corner of the car.');
        }

        // Changing how a type is tracked rewrites the rules for components that already exist under
        // the old rules (a serialized instance has a serial and qty 1; a consumable may not exist as
        // an instance at all). Renaming is always safe; re-classifying is not.
        if ($existing
            && array_key_exists('tracking_mode', $data)
            && $data['tracking_mode'] !== $existing->tracking_mode
            && $existing->components()->exists()) {
            abort(422, sprintf(
                'Cannot change tracking mode: %d component(s) are already recorded under the current "%s" rules. Retire this type and add a new one instead.',
                $existing->components()->count(),
                $existing->tracking_mode
            ));
        }

        // Trim, drop blanks, de-duplicate case-insensitively. An alias list is a search index; a
        // blank or a repeat in it is pure noise that would surface the same row twice.
        if (array_key_exists('aliases', $data)) {
            $data['aliases'] = collect($data['aliases'] ?? [])
                ->map(fn ($a) => trim((string) $a))
                ->filter()
                ->unique(fn ($a) => mb_strtolower($a))
                ->values()
                ->all();
        }

        $data['is_active'] = $request->boolean('is_active', $existing->is_active ?? true);

        return $data;
    }

    /**
     * Marks the row as user-owned and records who did it. Applied to EVERY write, including retire
     * and restore: those are decisions too, and a deploy must not quietly undo them either.
     */
    private function editStamp(Request $request): array
    {
        $user = $request->user();

        return [
            'edited_in_app'  => true,
            'edited_at'      => now(),
            'edited_by'      => $user?->id,
            'edited_by_name' => $user?->name,
        ];
    }

    /** A slug is permanent, so it only has to be unique once — at creation. */
    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'part';
        $slug = Str::limit($base, 110, '');
        $n = 2;

        while (ComponentCatalog::where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 106, '')."-{$n}";
            $n++;
        }

        return $slug;
    }

    /** The fault categories a part can belong to — same vocabulary as the findings catalog. */
    private function categoryLegend(): array
    {
        return collect(config('maintenance_findings.categories', []))
            ->map(fn ($c) => [
                'key'      => $c['key'] ?? null,
                'label'    => $c['label'] ?? $c['key'] ?? '',
                'label_ar' => $c['label_ar'] ?? null,
            ])
            ->filter(fn ($c) => $c['key'])
            ->values()
            ->all();
    }
}
