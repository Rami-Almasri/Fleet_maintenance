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
 * 2. RETIRE, NEVER DELETE — when the type is in use. Three tables hold a restrictOnDelete foreign
 *    key to a catalog row: vehicle_components, warranties and maintenance_required_parts. Deleting
 *    a referenced type would orphan the history that says what those cars are made of, so the
 *    database refuses it. `destroy` makes that refusal a BUSINESS answer instead of a SQL error: it
 *    counts the references first and returns 422 naming them. Retiring is its own action
 *    ({@see retire()}) because the user asked to delete, and quietly doing something else while
 *    reporting success is not an answer.
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
            ->withCount(['components', 'warranties', 'requiredParts', 'partRequests', 'partPurchases', 'lineItems'])
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
     * Delete a part type — only when nothing references it.
     *
     * Three tables point at component_catalog with restrictOnDelete: vehicle_components (what is
     * fitted to the cars), warranties (promises made about this part) and maintenance_required_parts
     * (what inspectors asked for). Deleting a referenced row is refused BY THE DATABASE, which
     * surfaced as a raw integrity-constraint error — a 500-shaped answer to a business question the
     * user could have been told plainly.
     *
     * So the check happens here, first, and the refusal is a 422 that NAMES what is in the way and
     * how much of it there is. "Fitted to 51 cars" is something a person can act on; SQLSTATE[23000]
     * is not. The alternative — retiring silently instead of deleting — was worse: the user asked to
     * delete and would have been told "done" about a row that is still there.
     *
     * Retiring stays available as its own deliberate action ({@see retire()}), which is what the
     * refusal points at.
     */
    public function destroy(Request $request, ComponentCatalog $part)
    {
        $references = $this->referenceCounts($part);

        if ($references !== []) {
            return ResponseHelper::FailureResponse(
                [
                    'references' => $references,
                    // What the caller should do instead, as data rather than prose to parse.
                    'can_retire' => true,
                    'retire_url' => "/api/parts-catalog/{$part->id}/retire",
                ],
                sprintf(
                    'Cannot delete "%s" — it is still referenced by %s. Retire it instead: it disappears from the pickers and the history keeps its meaning.',
                    $part->name,
                    $this->describeReferences($references)
                ),
                422
            );
        }

        $part->delete();

        return ResponseHelper::SuccessResponse(null, 'Part removed from the catalog', 200);
    }

    /**
     * Retire a part type: hide it from every picker while every row that already points at it keeps
     * working. This is the correct end state for a part the fleet has stopped using but once fitted.
     * Idempotent — retiring an already-retired type is a no-op that still succeeds.
     */
    public function retire(Request $request, ComponentCatalog $part)
    {
        $part->update(['is_active' => false] + $this->editStamp($request));

        return ResponseHelper::SuccessResponse(
            new ComponentCatalogResource($part->fresh()->loadCount('components')),
            'Retired — hidden from the pickers, and the history that refers to it is untouched',
            200
        );
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

    /**
     * Every row that would make a delete fail, counted, keyed by what it is.
     *
     * MUST list every table holding a restrictOnDelete foreign key to component_catalog. A new one
     * added without being registered here reintroduces exactly the bug this replaced: the app says
     * "deleting…" and the database answers with an integrity-constraint error.
     *
     * Zero counts are dropped so an empty array means "nothing is in the way" and the caller needs
     * no further interpretation.
     *
     * @return array<string, int>
     */
    private function referenceCounts(ComponentCatalog $part): array
    {
        return array_filter([
            'fitted_components' => $part->components()->count(),
            'warranties'        => $part->warranties()->count(),
            'required_parts'    => $part->requiredParts()->count(),
            // Added when purchases started recording WHICH catalog part was bought (see the
            // add_catalog_identity_to_part_requests_and_purchases migration). Money history is the
            // last thing that may be orphaned: a purchase whose part type vanished can no longer say
            // what was bought, and every repeat-buy answer built on it silently changes.
            'part_requests'     => $part->partRequests()->count(),
            'purchases'         => $part->partPurchases()->count(),
            // Billed repair lines. Same reasoning as purchases: a cost line whose part type was
            // deleted can no longer say what was fitted, and the lifespan/spend answers resting on
            // it change without anybody being told.
            'billed_lines'      => $part->lineItems()->count(),
        ]);
    }

    /** "51 fitted components and 2 warranties" — the sentence half of the refusal. */
    private function describeReferences(array $references): string
    {
        $labels = [
            'fitted_components' => 'fitted component',
            'warranties'        => 'warranty',
            'required_parts'    => 'required-part line',
            'part_requests'     => 'part request',
            'purchases'         => 'recorded purchase',
            'billed_lines'      => 'billed repair line',
        ];
        $plurals = ['warranties' => 'warranties'];

        $parts = [];
        foreach ($references as $key => $count) {
            $word = $count === 1
                ? $labels[$key]
                : ($plurals[$key] ?? $labels[$key].'s');
            $parts[] = "{$count} {$word}";
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    private function validatePayload(Request $request, ?ComponentCatalog $existing = null): array
    {
        $categories = array_column(config('maintenance_findings.categories', []), 'key');

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'name_ar'       => ['nullable', 'string', 'max:160'],
            // The part's OTHER NAMES. These carry weight: two records whose wording lands in this
            // list are treated as the same part, which is what makes the repeat-buy warning survive
            // someone typing "dynamo" instead of "Alternator". A symptom does not belong here and
            // neither does a name that fits two rows — both go in `aliases`. Nothing enforces that at
            // this layer because the person editing the catalog is the authority on what the part is
            // called; PartIdentityService independently refuses any surface it finds under two rows,
            // so the worst outcome of a debatable entry is a match not made.
            'identity_aliases'   => ['nullable', 'array', 'max:40'],
            'identity_aliases.*' => ['nullable', 'string', 'max:120'],
            // Sent as an array by the page; each entry is one thing someone might type.
            'aliases'       => ['nullable', 'array', 'max:40'],
            // `nullable` because Laravel's ConvertEmptyStringsToNull middleware turns a blank alias
            // row — which a UI with an empty input line sends routinely — into null. Rejecting the
            // whole request over one empty row would be a validation error the user cannot see the
            // cause of; the normaliser below drops blanks anyway.
            'aliases.*'     => ['nullable', 'string', 'max:120'],
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
        foreach (['aliases', 'identity_aliases'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = collect($data[$field] ?? [])
                    ->map(fn ($a) => trim((string) $a))
                    ->filter()
                    ->unique(fn ($a) => mb_strtolower($a))
                    ->values()
                    ->all();
            }
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
