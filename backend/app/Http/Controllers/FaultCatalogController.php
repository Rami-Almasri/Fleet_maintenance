<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FaultCatalog;
use App\Models\MaintenanceTask;
use App\Services\SelectableFindings;
use App\Support\OntologyConcepts;
use App\Support\TextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Curation of the WHAT axis — the admin surface behind the "Fault Types" page.
 *
 * ── THE PROBLEM THIS SOLVES ──────────────────────────────────────────────────────────────────────
 * Adding a fault used to be a deploy. The Knowledge page could give a word MEANING — terms, synonyms,
 * risk grade, Arabic — but nothing there could make it appear in the picker, because selectability was
 * authored in `config/maintenance_findings.php` and a PHP file inside a built image cannot be edited
 * at runtime. So a fault added by the office was matchable, enrichable, and untappable: it looked
 * added and was invisible at the only step that matters. That is the gap this page closes.
 *
 * ── WHAT IS EDITABLE, AND WHAT IS NOT ────────────────────────────────────────────────────────────
 * Editable here: the fault's name (EN + AR), which category it files under, its default severity, and
 * whether it can be repaired on site. Creating a fault makes it selectable IMMEDIATELY — see
 * SelectableFindings, which unions these rows onto the authored config.
 *
 * NOT editable here: what the word MEANS. Synonyms, workshop slang, customer phrasing and deliberate
 * misspellings live in `database/seeders/ontology/*.php` — 1,400+ terms authored and reviewed in
 * commits, not typed into a form. A fault created here therefore starts with NO vocabulary: the
 * matcher cannot recognise it in a sentence, only match its own name.
 *
 * That gap is REPORTED, not hidden. Every row carries `has_vocabulary`, the page shows it, and
 * `findings:vocabulary-check` still fails on it — a curator can ship a tappable word today and the
 * team is told, in two places, that somebody still owes it words. Silently passing the check instead
 * would trade a visible gap for an invisible one.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ───────────────────────────────────────────────────────────
 * It never deletes a fault type that tasks point at. `maintenance_tasks.fault_catalog_id` is HOW a
 * historical fault was typed, and dropping the row would rewrite what those tickets said. Retirement
 * (`is_active=false`) is the answer: it leaves the picker and keeps answering for the tasks that
 * already reference it. A delete is allowed only for a row nothing has ever used — the "I just added
 * this by mistake" case.
 *
 * It also cannot unmake a word the CONFIG authors. Retiring a row that config re-asserts would flip
 * back on the next deploy, so the endpoint refuses and says where the word actually lives.
 *
 * Every write stamps `edited_in_app` so FaultCatalogSeeder stops re-asserting the authored wording
 * over a curator's decision — the same read-config/write-DB trade `vehicle_locations` makes.
 *
 * Reads are gated to maintenance.view (anyone who files a fault should see the vocabulary they file
 * into); writes to maintenance.manage, matching every other catalog admin surface.
 */
class FaultCatalogController extends Controller
{
    /** Severity is a PREFILL hint on the task, not the task's grade — these are the grades it offers. */
    private const SEVERITIES = ['routine', 'moderate', 'critical'];

    /**
     * Everything the page renders in one request: every fault type (active AND retired) with how many
     * tasks each has been used on, whether the ontology has words for it, and whether the config still
     * authors it — plus the categories it may be filed under.
     *
     * Usage counts are what make this page trustworthy to change things with: they turn "can I delete
     * this?" from a guess into an answer, and they come from the task table itself, not from anything
     * derived.
     */
    public function index(SelectableFindings $selectable)
    {
        // One grouped count, not one query per row — this table grows with every recorded fault.
        $usage = MaintenanceTask::query()
            ->whereNotNull('fault_catalog_id')
            ->selectRaw('fault_catalog_id, COUNT(*) as c')
            ->groupBy('fault_catalog_id')
            ->pluck('c', 'fault_catalog_id');

        // Which slugs the authored file still owns. A row the config asserts cannot be retired here,
        // because the next deploy would bring it straight back and the curator would not know why.
        $authored = collect((array) config('fault_catalog', []))
            ->pluck('slug')
            ->filter()
            ->flip();

        $rows = FaultCatalog::query()
            ->orderBy('category_key')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (FaultCatalog $f) => [
                'id'               => $f->id,
                'slug'             => $f->slug,
                'name'             => $f->name,
                'name_ar'          => $f->name_ar,
                'category_key'     => $f->category_key,
                'default_severity' => $f->default_severity,
                'on_site'          => (bool) $f->on_site,
                'is_active'        => (bool) $f->is_active,
                'edited_in_app'    => (bool) $f->edited_in_app,
                // Does the matcher know this word, or does it only match its own name?
                'has_vocabulary'   => OntologyConcepts::has($f->name),
                // Authored in the config file — renameable, but not retirable from here.
                'authored'         => $authored->has($f->slug),
                'usage_count'      => (int) ($usage[$f->id] ?? 0),
            ]);

        // The categories a fault may be filed under, straight from the same list the picker draws its
        // headings from — a fault filed under a key the picker has no heading for would never render.
        $categories = collect($selectable->categories())
            ->map(fn ($c) => [
                'key'      => $c['key'] ?? null,
                'label'    => $c['label'] ?? ($c['key'] ?? ''),
                'label_ar' => $c['label_ar'] ?? null,
                'on_site'  => (bool) ($c['on_site'] ?? false),
            ])
            ->filter(fn ($c) => $c['key'] !== null)
            ->values();

        return ResponseHelper::SuccessResponse(
            [
                'faults'     => $rows,
                'categories' => $categories,
                'severities' => self::SEVERITIES,
                // Headline the page leads with: words an inspector can tap that mean nothing to the
                // matcher. This is the number that should be going down.
                'summary'    => [
                    'total'          => $rows->count(),
                    'active'         => $rows->where('is_active', true)->count(),
                    'no_vocabulary'  => $rows->where('is_active', true)->where('has_vocabulary', false)->count(),
                ],
            ],
            'Fault types retrieved successfully',
            200
        );
    }

    /**
     * Add a fault type. Selectable the moment it is saved.
     *
     * The slug is derived, not asked for: it is an internal identity nobody outside the codebase
     * should have to invent, and a hand-typed one is how two rows for one concept get created.
     */
    public function store(Request $request)
    {
        $data = $request->validate($this->rules(), $this->messages());

        $slug = $this->uniqueSlug($data['name']);

        $fault = FaultCatalog::create([
            'slug'             => $slug,
            'name'             => trim($data['name']),
            'name_ar'          => isset($data['name_ar']) ? trim($data['name_ar']) : null,
            'category_key'     => $data['category_key'],
            'default_severity' => $data['default_severity'],
            'on_site'          => (bool) ($data['on_site'] ?? false),
            'is_active'        => true,
            'edited_in_app'    => true,
            // Behind everything the config authors, so an added word does not jump the curated order.
            'sort_order'       => (int) (FaultCatalog::max('sort_order') ?? 0) + 10,
        ]);

        return ResponseHelper::SuccessResponse(
            [
                'id'             => $fault->id,
                'slug'           => $fault->slug,
                'has_vocabulary' => OntologyConcepts::has($fault->name),
            ],
            OntologyConcepts::has($fault->name)
                ? 'Fault type added — inspectors can select it now.'
                : 'Fault type added and selectable now. The matcher has no words for it yet, so it will '
                  . 'not be recognised in written notes until a concept is authored for it.',
            201
        );
    }

    /**
     * Rename / re-file / re-grade a fault type.
     *
     * The slug is NEVER changed, even when the name is. It is what `maintenance_tasks` and the config
     * file both point at, and rewriting it would orphan every task typed from this row.
     */
    public function update(Request $request, FaultCatalog $faultCatalog)
    {
        $data = $request->validate($this->rules($faultCatalog->id), $this->messages());

        $faultCatalog->update([
            'name'             => trim($data['name']),
            'name_ar'          => isset($data['name_ar']) ? trim($data['name_ar']) : null,
            'category_key'     => $data['category_key'],
            'default_severity' => $data['default_severity'],
            'on_site'          => (bool) ($data['on_site'] ?? false),
            'edited_in_app'    => true,
        ]);

        return ResponseHelper::SuccessResponse(
            ['has_vocabulary' => OntologyConcepts::has($faultCatalog->name)],
            'Fault type updated successfully',
            200
        );
    }

    /**
     * Retire or restore. Retiring takes the chip out of the picker and leaves every task that already
     * points at this row saying exactly what it always said.
     */
    public function toggle(FaultCatalog $faultCatalog)
    {
        $authored = collect((array) config('fault_catalog', []))->pluck('slug')->contains($faultCatalog->slug);

        // Refusing beats lying. Retiring an authored row appears to work and then silently reverts on
        // the next deploy, which is worse than being told the word lives somewhere else.
        if ($authored && $faultCatalog->is_active) {
            return ResponseHelper::FailureResponse(
                null,
                'This fault type is authored in the application config, so retiring it here would be '
                . 'undone by the next deployment. It has to be removed from config/fault_catalog.php.',
                422
            );
        }

        $faultCatalog->update([
            'is_active'     => ! $faultCatalog->is_active,
            'edited_in_app' => true,
        ]);

        return ResponseHelper::SuccessResponse(
            ['is_active' => (bool) $faultCatalog->is_active],
            $faultCatalog->is_active ? 'Fault type restored' : 'Fault type retired',
            200
        );
    }

    /**
     * Delete outright — allowed ONLY for a row no task has ever been typed from, which is the "added
     * by mistake" case. Anything else retires.
     */
    public function destroy(FaultCatalog $faultCatalog)
    {
        $used = MaintenanceTask::where('fault_catalog_id', $faultCatalog->id)->count();

        if ($used > 0) {
            return ResponseHelper::FailureResponse(
                ['usage_count' => $used],
                "This fault type is how {$used} recorded " . Str::plural('task', $used) . ' ' .
                ($used === 1 ? 'was' : 'were') . ' typed. Deleting it would rewrite what those tickets '
                . 'said — retire it instead, which removes it from the picker and keeps the history.',
                422
            );
        }

        if (collect((array) config('fault_catalog', []))->pluck('slug')->contains($faultCatalog->slug)) {
            return ResponseHelper::FailureResponse(
                null,
                'This fault type is authored in config/fault_catalog.php and the next deployment would '
                . 'recreate it. Remove it there instead.',
                422
            );
        }

        $faultCatalog->delete();

        return ResponseHelper::SuccessResponse(null, 'Fault type deleted', 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                // One concept, one row. A duplicate name is the bug the part-identity work exists to
                // prevent elsewhere, and it is just as damaging here: two chips, split recurrence.
                Rule::unique('fault_catalog', 'name')->ignore($ignoreId),
            ],
            'name_ar'          => ['nullable', 'string', 'max:120'],
            'category_key'     => ['required', 'string', Rule::in($this->categoryKeys())],
            'default_severity' => ['required', Rule::in(self::SEVERITIES)],
            'on_site'          => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.unique'        => 'A fault type with this name already exists — two rows for one concept '
                                    . 'split its history in half. Rename the existing one instead.',
            'category_key.in'    => 'That category is not one the findings picker draws a heading for, so a '
                                    . 'fault filed under it would never appear.',
            'default_severity.in' => 'Severity must be routine, moderate or critical.',
        ];
    }

    /** @return array<int, string> */
    private function categoryKeys(): array
    {
        return collect(app(SelectableFindings::class)->categories())
            ->pluck('key')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A slug nothing else holds. Derived from the name, suffixed only on collision — two faults named
     * closely enough to slug identically are rare, and a silent overwrite would be far worse.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(trim($name), '_') ?: 'fault';
        $base = Str::limit($base, 60, '');
        $slug = $base;
        $n    = 2;

        while (FaultCatalog::where('slug', $slug)->exists()) {
            $slug = "{$base}_{$n}";
            $n++;
        }

        return $slug;
    }
}
