<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\FindingKeywordResource;
use App\Models\FindingKeyword;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administration of the findings keyword library — the quick-pick fault keywords the Inspector /
 * workshop tap, each carrying a RISK grade (critical / moderate / routine). This is the CRUD surface
 * behind the "Keyword Risk Library" admin page: list + filter, create, edit (incl. re-grading the
 * risk), and retire. Reads are gated to maintenance.view; writes to maintenance.manage (route-level).
 *
 * The keyword strings themselves are still what gets persisted onto maintenances.findings, so this
 * screen only curates the menu + its metadata — no ticket data moves when a keyword is edited.
 */
class FindingKeywordController extends Controller
{
    /**
     * The whole library, ordered by category then intra-category sort order. Optional filters:
     * `?category=engine`, `?risk=critical`, `?active=1|0`, `?q=` (keyword / description search).
     * Also returns the risk vocabulary (value → emoji/label/tone) and headline counts so the admin
     * screen can render the risk legend + badge the totals without a second request.
     */
    public function index(Request $request)
    {
        $request->validate([
            'category' => ['nullable', 'string', 'max:60'],
            'risk'     => ['nullable', Rule::in(FindingKeyword::RISKS)],
            'active'   => ['nullable', 'boolean'],
            'q'        => ['nullable', 'string', 'max:191'],
        ]);

        $rows = FindingKeyword::query()
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->string('category')))
            ->when($request->filled('risk'), fn ($q) => $q->withRisk($request->string('risk')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('keyword', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->orderBy('category_label')
            ->orderBy('sort_order')
            ->orderBy('keyword')
            ->get();

        // Risk totals across the WHOLE library (unfiltered) so the KPI tiles stay stable while filtering.
        $counts = FindingKeyword::query()
            ->selectRaw('risk, COUNT(*) as c')
            ->groupBy('risk')
            ->pluck('c', 'risk');

        return ResponseHelper::SuccessResponse(
            [
                'keywords'   => FindingKeywordResource::collection($rows),
                'risk_meta'  => FindingKeyword::RISK_META,
                'categories' => array_values(array_map(
                    fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'label_ar' => $c['label_ar'] ?? null],
                    config('maintenance_findings.categories', []),
                )),
                'counts' => [
                    'total'    => (int) $counts->sum(),
                    'critical' => (int) ($counts[FindingKeyword::RISK_CRITICAL] ?? 0),
                    'moderate' => (int) ($counts[FindingKeyword::RISK_MODERATE] ?? 0),
                    'routine'  => (int) ($counts[FindingKeyword::RISK_ROUTINE] ?? 0),
                ],
            ],
            'Findings keyword library retrieved successfully',
            200
        );
    }

    /** Add a new keyword to the library. */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $keyword = FindingKeyword::create($data);

        return ResponseHelper::SuccessResponse(new FindingKeywordResource($keyword), 'Keyword added to the library', 201);
    }

    /** Edit a keyword — rename, re-categorise, re-grade its risk, or toggle it on/off. */
    public function update(Request $request, FindingKeyword $findingKeyword)
    {
        $data = $this->validatePayload($request, $findingKeyword);

        $findingKeyword->update($data);

        return ResponseHelper::SuccessResponse(new FindingKeywordResource($findingKeyword->fresh()), 'Keyword updated', 200);
    }

    /** Remove a keyword from the library (does not touch any historical findings that used it). */
    public function destroy(FindingKeyword $findingKeyword)
    {
        $findingKeyword->delete();

        return ResponseHelper::SuccessResponse(null, 'Keyword removed from the library', 200);
    }

    /**
     * Shared validation. On update, `$existing` lets the (category, keyword) uniqueness rule ignore
     * the row itself. `category_label` is derived from the chosen category so the two never drift.
     */
    private function validatePayload(Request $request, ?FindingKeyword $existing = null): array
    {
        $categories = collect(config('maintenance_findings.categories', []));

        $data = $request->validate([
            'category_key' => ['required', 'string', 'max:60', Rule::in($categories->pluck('key')->all())],
            'keyword'      => [
                'required', 'string', 'max:191',
                Rule::unique('finding_keywords', 'keyword')
                    ->where(fn ($q) => $q->where('category_key', $request->input('category_key')))
                    ->ignore($existing?->id),
            ],
            'keyword_ar'  => ['nullable', 'string', 'max:191'],
            'risk'        => ['required', Rule::in(FindingKeyword::RISKS)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        // Keep the denormalised labels in lock-step with the category slug (single source: the config).
        $category = $categories->firstWhere('key', $data['category_key']);
        $data['category_label']    = $category['label'] ?? $data['category_key'];
        $data['category_label_ar'] = $category['label_ar'] ?? null;
        $data['is_active'] = $request->boolean('is_active', $existing->is_active ?? true);

        return $data;
    }
}
