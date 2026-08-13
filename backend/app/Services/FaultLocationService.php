<?php

namespace App\Services;

use App\Models\DamageCatalog;
use App\Models\FaultCatalog;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskLocation;
use App\Models\VehicleLocation;
use App\Support\FaultPhrase;
use Illuminate\Support\Facades\DB;

/**
 * WHERE — the one owner of "which places is this event at, and does it need any?".
 *
 * Evidence class: D (Derived) / F (Fact).
 *   Produces — maintenance_task_locations rows (F: a human picked them), the rendered fault phrase (D).
 *   Consumes — vehicle_locations, fault_catalog.location_mode, damage_catalog.location_mode,
 *              config('vehicle_locations.policy').
 *
 * ── WHY EVERY WRITE GOES THROUGH HERE ────────────────────────────────────────────────────────────
 * Three rules have to hold together on every write and none of them survives being re-implemented per
 * caller: unknown slugs are refused rather than silently dropped, duplicates collapse, and a type
 * whose policy says `none` never accumulates places nobody asked for. There are already four writers
 * of findings (the inspector's report, garage findings, the inspector's pad, the routine-service
 * seed) and there will be more, so this is a service and not a trait. See
 * [[ticket-cost-journey]] for the same one-write-path rule applied to money.
 *
 * ── POLICY ───────────────────────────────────────────────────────────────────────────────────────
 * "Does this fault type have a where?" is a property of the TYPE, answered once. Resolution order is
 * most-specific-first and each step is a data lookup, never a hard-coded name:
 *   1. the catalog row's own `location_mode` column (a curator's in-app override)
 *   2. config('vehicle_locations.policy.by_catalog_slug') — the authored per-type answer
 *   3. config('vehicle_locations.policy.by_category')     — the authored per-category answer
 *   4. config('vehicle_locations.policy.default')
 * A fault type added tomorrow inherits its category's answer with no code change. That is the whole
 * point of the design; nothing here knows what a scratch is.
 */
class FaultLocationService
{
    public const MODE_REQUIRED = 'required';
    public const MODE_OPTIONAL = 'optional';
    public const MODE_NONE     = 'none';
    public const MODES = [self::MODE_REQUIRED, self::MODE_OPTIONAL, self::MODE_NONE];

    /** slug => VehicleLocation, memoised per request (the catalog is ~50 rows and read constantly). */
    private ?array $bySlug = null;

    /** Normalised type name|slug => {slug, category_key, location_mode}. See typeIndex(). */
    private ?array $typeIndex = null;

    // ── Vocabulary ────────────────────────────────────────────────────────────────────────────────

    /**
     * The active vocabulary keyed by slug. Falls back to the config file when the table has not been
     * seeded yet, so a fresh install, a unit test and a half-migrated environment all still render
     * and validate correctly instead of behaving as though no place on a car exists.
     *
     * @return array<string, VehicleLocation>
     */
    public function catalog(): array
    {
        if ($this->bySlug !== null) {
            return $this->bySlug;
        }

        $rows = [];
        try {
            foreach (VehicleLocation::query()->active()->ordered()->get() as $row) {
                $rows[$row->slug] = $row;
            }
        } catch (\Throwable $e) {
            $rows = []; // table missing (pre-migration) — the config fallback below covers it
        }

        if (! $rows) {
            foreach ((array) config('vehicle_locations.locations', []) as $entry) {
                if (empty($entry['slug'])) {
                    continue;
                }
                // Unsaved models: everything downstream reads attributes, never ids, on this path.
                $rows[$entry['slug']] = new VehicleLocation($entry + ['is_active' => true]);
            }
        }

        return $this->bySlug = $rows;
    }

    /** One place by slug, or null when the vocabulary does not know it. */
    public function find(?string $slug): ?VehicleLocation
    {
        $slug = trim((string) $slug);

        return $slug === '' ? null : ($this->catalog()[$slug] ?? null);
    }

    /**
     * The vocabulary shaped for a picker: groups, each with its ordered places.
     *
     * @return array<int, array{key:string, label:string, label_ar:?string, locations:array}>
     */
    public function groupedCatalog(): array
    {
        $byGroup = [];
        foreach ($this->catalog() as $loc) {
            $byGroup[$loc->group_key][] = [
                'key'             => $loc->slug,
                'label'           => $loc->name,
                'label_ar'        => $loc->name_ar,
                'precision'       => $loc->precision,
                'inspection_zone' => $loc->inspection_zone,
                'area_key'        => $loc->area_key,
                'aliases'         => $loc->aliases ?: [],
            ];
        }

        $out = [];
        foreach ((array) config('vehicle_locations.groups', []) as $group) {
            $key = $group['key'] ?? null;
            if (! $key || empty($byGroup[$key])) {
                continue;
            }
            $out[] = [
                'key'       => $key,
                'label'     => $group['label'] ?? $key,
                'label_ar'  => $group['label_ar'] ?? null,
                'locations' => $byGroup[$key],
            ];
            unset($byGroup[$key]);
        }

        // Anything in a group the config no longer declares still shows up rather than vanishing —
        // a retired group would otherwise hide places that historical faults still point at.
        foreach ($byGroup as $key => $locations) {
            $out[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', (string) $key)), 'label_ar' => null, 'locations' => $locations];
        }

        return $out;
    }

    // ── Policy ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Does this event type take a location, and is it required? See the class docblock for the order.
     *
     * @param  string|null $catalogSlug  the fault/damage catalog slug, when the type is known
     * @param  string|null $categoryKey  the findings category (engine / bodywork / tyres / …)
     * @param  string|null $storedMode   the catalog row's own location_mode column, when loaded
     */
    public function policyFor(?string $catalogSlug, ?string $categoryKey = null, ?string $storedMode = null): string
    {
        if (in_array($storedMode, self::MODES, true)) {
            return $storedMode;
        }

        $policy = (array) config('vehicle_locations.policy', []);

        $bySlug = (array) ($policy['by_catalog_slug'] ?? []);
        if ($catalogSlug && in_array($bySlug[$catalogSlug] ?? null, self::MODES, true)) {
            return $bySlug[$catalogSlug];
        }

        $byCategory = (array) ($policy['by_category'] ?? []);
        if ($categoryKey && in_array($byCategory[$categoryKey] ?? null, self::MODES, true)) {
            return $byCategory[$categoryKey];
        }

        return in_array($policy['default'] ?? null, self::MODES, true) ? $policy['default'] : self::MODE_OPTIONAL;
    }

    /**
     * The policy for a task, read from the catalog row it actually points at.
     *
     * Services and inspections are always `none`: planned work and checks happen to the whole car, so
     * there is no place to name — that rule is stated here rather than given a column nobody would
     * keep correct (see the add_location_mode_to_catalogs migration).
     */
    public function policyForTask(MaintenanceTask $task): string
    {
        if (in_array($task->kind, [MaintenanceTask::KIND_SERVICE, MaintenanceTask::KIND_INSPECTION], true)) {
            return self::MODE_NONE;
        }

        // Query-free: only an ALREADY-loaded catalog relation is consulted. This is called once per
        // task while serialising a board of them, and lazily resolving the catalog here would turn a
        // list endpoint into an N+1 — the exact trap catalogRef() in MaintenanceTaskResource documents.
        // Without the catalog row the task's own category_key still answers, which is the same answer
        // for every type in that category.
        $relation = MaintenanceTask::KIND_CATALOG_RELATIONS[$task->kind] ?? null;
        $row = ($relation && $task->relationLoaded($relation)) ? $task->getRelation($relation) : null;

        return $this->policyFor(
            $row->slug ?? null,
            $task->category_key ?: ($row->category_key ?? null),
            $row->location_mode ?? null,
        );
    }

    /**
     * The policy for a finding that has not become a task yet — the intake path.
     *
     * The finding names its type by TEXT (the picker's keyword), so the catalogs are matched on name
     * exactly the way EventClassificationService does. An unmatched text (a custom issue the inspector
     * typed) falls through to its category, then to the default: unknown means "offer the picker", not
     * "demand a place for something we cannot classify".
     */
    public function policyForText(?string $text, ?string $categoryKey = null): string
    {
        $row = $this->catalogRowForText($text);

        // The TYPE's own answer outranks the category it happens to sit in — that is what makes
        // "wipers need no location" a one-line config statement rather than a special case in code.
        return $this->policyFor(
            $row['slug'] ?? null,
            $row['category_key'] ?? $categoryKey,
            $row['location_mode'] ?? null,
        );
    }

    /**
     * The policy for EVERY keyword in the findings catalog, in one pass — what the picker needs to
     * know before the user taps anything, so the location box appears the instant a chip is selected
     * instead of after a round trip per selection.
     *
     * @param  array<int, array{key?:string, keywords?:array}> $categories  config('maintenance_findings.categories')
     * @return array<string, string>  keyword => required|optional|none
     */
    public function policyByKeyword(array $categories): array
    {
        $index = $this->typeIndex();
        $out   = [];

        foreach ($categories as $category) {
            $categoryKey = $category['key'] ?? null;
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $row = $index[$this->normText($keyword)] ?? null;
                $out[$keyword] = $this->policyFor(
                    $row['slug'] ?? null,
                    $categoryKey ?: ($row['category_key'] ?? null),
                    $row['location_mode'] ?? null,
                );
            }
        }

        return $out;
    }

    /**
     * The fault/damage type a finding's TEXT names — {slug, category_key, location_mode} or null.
     *
     * @return array{slug:?string, category_key:?string, location_mode:?string}|null
     */
    public function catalogRowForText(?string $text): ?array
    {
        $key = $this->normText($text);

        return $key === '' ? null : ($this->typeIndex()[$key] ?? null);
    }

    /**
     * name|slug (normalised) => {slug, category_key, location_mode}, for BOTH type catalogs, built once.
     *
     * ── WHY THIS IS AN INDEX AND NOT A QUERY PER LOOKUP ──────────────────────────────────────────
     * Intake speaks TEXT: a finding carries the keyword the inspector tapped ("Wiper / washer fault"),
     * never a catalog id — that is resolved later, when the finding is promoted to a task. But the
     * per-type policy override is keyed by SLUG, so answering "does this finding need a place?" means
     * mapping text → type on every finding of every report. Done per lookup that is four queries per
     * fault; done here it is two for a whole report.
     *
     * ── AND WHY CONFIG IS A REAL SOURCE, NOT JUST A FALLBACK ─────────────────────────────────────
     * The config files ARE the authored catalogs (the tables are seeded from them), so merging them in
     * underneath the DB rows means the override still resolves in an environment whose catalogs have
     * not been seeded yet — a fresh install, a test, a half-migrated deploy. When the text→type link
     * silently disappears the failure is invisible and expensive: a wiper fault starts demanding a
     * location it has no answer for, and the inspector cannot file his report. DB rows still win, so a
     * curator's in-app edit is never overruled by the file.
     *
     * @return array<string, array{slug:?string, category_key:?string, location_mode:?string}>
     */
    private function typeIndex(): array
    {
        if ($this->typeIndex !== null) {
            return $this->typeIndex;
        }

        $index = [];

        // Config first (lowest precedence) — fault before damage, so a name in both reads as a fault.
        foreach (['fault_catalog', 'damage_catalog'] as $file) {
            foreach ((array) config($file, []) as $entry) {
                if (! is_array($entry) || empty($entry['slug'])) {
                    continue;
                }
                $row = [
                    'slug'          => $entry['slug'],
                    'category_key'  => $entry['category_key'] ?? null,
                    'location_mode' => null, // config carries no override; the policy tables answer that
                ];
                foreach ([$entry['name'] ?? null, $entry['slug']] as $key) {
                    $key = $this->normText($key);
                    if ($key !== '') {
                        $index[$key] ??= $row;
                    }
                }
            }
        }

        // Then the live rows, which overwrite — they carry the curator's `location_mode`.
        try {
            foreach ([FaultCatalog::class, DamageCatalog::class] as $model) {
                foreach ($model::query()->get(['slug', 'name', 'category_key', 'location_mode']) as $row) {
                    $entry = [
                        'slug'          => $row->slug,
                        'category_key'  => $row->category_key,
                        'location_mode' => $row->location_mode,
                    ];
                    foreach ([$row->name, $row->slug] as $key) {
                        $key = $this->normText($key);
                        if ($key !== '') {
                            $index[$key] = $entry;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Catalogs unavailable (pre-migration). The config half above still answers.
        }

        return $this->typeIndex = $index;
    }

    /** Lookup key for a type name: case- and whitespace-insensitive, so "Scratch" == " scratch ". */
    private function normText(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text)) ?? '');
    }

    // ── Validation ────────────────────────────────────────────────────────────────────────────────

    /**
     * Clean an incoming list of location slugs: known ones only, de-duplicated, order preserved.
     *
     * Silent about the unknown ONES ON PURPOSE at this layer — the API validates slugs against the
     * table and rejects a bad one with a message, so anything still unknown here is a retired place
     * or a stale client, and dropping it is better than failing an inspector's whole report over it.
     *
     * @param  array<int, string|array> $input  slugs, or {key|slug: …} objects from the picker
     * @return array<int, string>
     */
    public function normalizeSlugs(array $input): array
    {
        $catalog = $this->catalog();
        $out     = [];

        foreach ($input as $item) {
            $slug = is_array($item)
                ? (string) ($item['key'] ?? $item['slug'] ?? '')
                : (string) $item;
            $slug = trim($slug);

            if ($slug === '' || ! isset($catalog[$slug]) || in_array($slug, $out, true)) {
                continue;
            }
            $out[] = $slug;
        }

        return $out;
    }

    /** Clamp a quantity into the sane range (≥ 1, ≤ config max). Non-numeric input reads as 1. */
    public function normalizeQuantity($value): int
    {
        $max = (int) config('vehicle_locations.max_quantity', 40);
        $n   = is_numeric($value) ? (int) $value : 1;

        return max(1, min($max ?: 40, $n));
    }

    // ── Persistence ───────────────────────────────────────────────────────────────────────────────

    /**
     * Make the task's places exactly $slugs, in that order. Idempotent — safe to call on every write.
     *
     * A `none` type is emptied rather than refused: a location arriving for a type that has no place
     * is a stale client, not an operator error, and failing the write would lose the real report over
     * a field the operator never saw. Required-ness is enforced at intake (assertSatisfied), where
     * the person who can fix it is still on the screen.
     *
     * @param  array<int, string> $slugs
     * @return array<int, string> the slugs actually stored
     */
    public function sync(MaintenanceTask $task, array $slugs): array
    {
        $slugs = $this->normalizeSlugs($slugs);

        if ($this->policyForTask($task) === self::MODE_NONE) {
            $slugs = [];
        }

        return DB::transaction(function () use ($task, $slugs) {
            $ids = [];
            foreach ($slugs as $i => $slug) {
                $row = $this->find($slug);
                if (! $row || ! $row->exists) {
                    continue; // config-fallback vocabulary has no id to link — nothing to persist
                }
                $ids[$row->id] = $i;
            }

            MaintenanceTaskLocation::query()
                ->where('maintenance_task_id', $task->id)
                ->whereNotIn('vehicle_location_id', array_keys($ids) ?: [0])
                ->delete();

            foreach ($ids as $locationId => $order) {
                MaintenanceTaskLocation::query()->updateOrCreate(
                    ['maintenance_task_id' => $task->id, 'vehicle_location_id' => $locationId],
                    ['sort_order' => $order],
                );
            }

            $task->unsetRelation('locations');

            return $slugs;
        });
    }

    /**
     * Intake gate: refuse a finding whose type requires a place and was given none.
     *
     * Returns the offending finding texts rather than throwing, so the caller can name ALL of them in
     * one message instead of making the inspector resubmit once per fault.
     *
     * @param  array<int, array> $findings  entries carrying `text`, optional `category_key`, `locations`
     * @return array<int, string>           texts that still need a location
     */
    public function findingsMissingRequiredLocation(array $findings): array
    {
        $missing = [];

        foreach ($findings as $f) {
            $text = trim((string) (is_array($f) ? ($f['text'] ?? '') : $f));
            if ($text === '') {
                continue;
            }
            $mode = $this->policyForText($text, is_array($f) ? ($f['category_key'] ?? null) : null);
            if ($mode !== self::MODE_REQUIRED) {
                continue;
            }
            $locations = is_array($f) ? $this->normalizeSlugs((array) ($f['locations'] ?? [])) : [];
            if (! $locations) {
                $missing[] = $text;
            }
        }

        return $missing;
    }

    // ── Presentation ──────────────────────────────────────────────────────────────────────────────

    /**
     * The one sentence for a task — "2 scratches — rims and body".
     *
     * Reads the stored parts and hands them to {@see FaultPhrase}; it never builds the string itself,
     * so the UI, the API, a notification and a report cannot word the same fault differently.
     */
    public function describe(MaintenanceTask $task, string $locale = 'en'): string
    {
        return FaultPhrase::render(
            $this->typeLabel($task, $locale),
            (int) ($task->quantity ?: 1),
            $this->locationLabels($task, $locale),
            $locale,
        );
    }

    /**
     * The type label in the caller's language: the catalog's curated name when the event is typed,
     * otherwise the raw symptom text (a custom issue an inspector typed has no catalog row, and its
     * own wording is the most honest label we have).
     */
    public function typeLabel(MaintenanceTask $task, string $locale = 'en'): string
    {
        // Query-free for the same N+1 reason as policyForTask(). An unloaded catalog falls back to the
        // symptom text, which for a catalog-typed fault is the catalog's own name anyway (that is how
        // the finding was written), so the sentence is right either way.
        $relation = MaintenanceTask::KIND_CATALOG_RELATIONS[$task->kind] ?? null;
        $row = ($relation && $task->relationLoaded($relation)) ? $task->getRelation($relation) : null;

        if ($row) {
            $name = $locale === 'ar' ? ($row->name_ar ?: $row->name) : $row->name;
            if (filled($name)) {
                return (string) $name;
            }
        }

        return (string) $task->symptom;
    }

    /**
     * This task's place labels, in the order they were picked.
     *
     * @return array<int, string>
     */
    public function locationLabels(MaintenanceTask $task, string $locale = 'en'): array
    {
        return $this->locationRefs($task)
            ->map(fn (array $l) => $locale === 'ar' && $l['label_ar'] ? $l['label_ar'] : $l['label'])
            ->all();
    }

    /**
     * This task's places as API-shaped refs — {key, label, label_ar, group, precision}.
     *
     * Query-free when `locations` is eager-loaded; a caller that did not load it pays one query rather
     * than getting a silently empty answer, because "no locations" and "not loaded" mean very
     * different things and confusing them is how a fault silently loses its place on a board.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    public function locationRefs(MaintenanceTask $task): \Illuminate\Support\Collection
    {
        $links = $task->relationLoaded('locations')
            ? $task->locations
            : $task->locations()->with('location')->get();

        return $links
            ->sortBy('sort_order')
            ->map(fn (MaintenanceTaskLocation $l) => $l->location ? [
                'key'             => $l->location->slug,
                'label'           => $l->location->name,
                'label_ar'        => $l->location->name_ar,
                'group'           => $l->location->group_key,
                'precision'       => $l->location->precision,
                'inspection_zone' => $l->location->inspection_zone,
            ] : null)
            ->filter()
            ->values();
    }
}
