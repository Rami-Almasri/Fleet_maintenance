<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ComponentCatalog;
use App\Models\ExpenseTypeMapping;
use App\Models\OdooMapping;
use App\Models\OdooReferenceRecord;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Services\Odoo\OdooClient;
use App\Services\Odoo\OdooMappingService;
use App\Support\ExpenseType;
use App\Support\OdooDocumentType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The mapping administration surface (§39) — and the only way a mapping is decided through the API.
 *
 * Four things are managed here, and they are genuinely four different shapes of decision:
 *
 *   Vehicle  ↔ Odoo analytic account  }  one FleetView row ↔ one Odoo row: odoo_mappings
 *   Part     ↔ Odoo product           }
 *   Supplier ↔ Odoo partner           }
 *   Expense type ↔ account + document type: expense_type_mappings (two answers, one row)
 *
 * `options` is the picker's data source, served from the LOCAL cache of Odoo master data — never a live
 * RPC per keystroke. `suggestions` proposes candidates and is careful to be nothing more than that:
 * confirming one is a separate call, because a suggestion the system could accept on its own would be
 * name matching wearing a mapping's clothes (§18).
 */
class OdooMappingController extends Controller
{
    /** The three mappable kinds, and how to resolve one by name. */
    private const KINDS = [
        'vehicle'  => [Vehicle::class, OdooMapping::MODEL_ANALYTIC],
        'part'     => [ComponentCatalog::class, OdooMapping::MODEL_PRODUCT],
        'supplier' => [Vendor::class, OdooMapping::MODEL_PARTNER],
    ];

    public function __construct(
        private OdooMappingService $mappings,
        private OdooClient $client,
    ) {
    }

    /**
     * A live connection check.
     *
     * Its own endpoint, pressed on purpose, because it makes a real network call — the dashboard's
     * passive `connection` block deliberately does not, so that an unreachable Odoo cannot make every
     * page wait for a TCP timeout.
     */
    public function health()
    {
        try {
            return ResponseHelper::SuccessResponse($this->client->health(), 'Odoo connection');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * List one kind of mappable record with its mapping state.
     *
     * `?unmapped=1` narrows it to the backlog — the working view, since the whole point of this screen
     * is to empty that list.
     */
    public function index(Request $request, string $kind)
    {
        try {
            [$class, $odooModel] = $this->kind($kind);

            $query = $class::query();

            // Vehicles are narrowed by the eligibility rule (§17) — a disposed car will never accrue a
            // new cost, so leaving it in the backlog would make the list permanently unfinishable. This
            // never affects whether an EXISTING cost may be posted.
            if ($class === Vehicle::class) {
                $query->whereNotIn('status', (array) config('odoo.vehicle_eligibility.exclude_statuses', []));
            }

            if ($search = trim((string) $request->query('search'))) {
                $query->where(function ($q) use ($class, $search) {
                    $q->where('name', 'like', "%{$search}%");
                    if ($class === Vehicle::class) {
                        $q->orWhere('plate_no', 'like', "%{$search}%")
                          ->orWhere('vin', 'like', "%{$search}%");
                    }
                });
            }

            $morph    = (new $class)->getMorphClass();
            $page     = $query->paginate(min(100, (int) $request->query('per_page', 25)));
            $mappings = OdooMapping::where('odoo_model', $odooModel)
                ->where('mappable_type', $morph)
                ->whereIn('mappable_id', collect($page->items())->pluck('id'))
                ->get()
                ->keyBy('mappable_id');

            $items = collect($page->items())->map(function ($record) use ($mappings, $class) {
                $mapping = $mappings->get($record->id);

                return [
                    'id'      => $record->id,
                    'label'   => $this->labelFor($record, $class),
                    'sublabel' => $class === Vehicle::class ? $record->vin : null,
                    'mapping' => $mapping ? [
                        // The six-way state the screen renders: mapped / changed / suggested / stale /
                        // none / unmapped. See OdooMapping::displayState() for why `changed` outranks
                        // `mapped` — both post, but only one means documents may have been coded against
                        // a different target than this row now names.
                        'state'          => $mapping->displayState(),
                        'status'         => $mapping->status,
                        'odoo_id'        => $mapping->odoo_id,
                        'odoo_ref'       => $mapping->odoo_ref,
                        'odoo_name'      => $mapping->odoo_name,
                        'matched_by'     => $mapping->matched_by,
                        // Who accepted it and when — a mapping is a decision, and decisions have owners.
                        'confirmed_by'   => $mapping->mapped_by,
                        'confirmed_at'   => optional($mapping->mapped_at)->toIso8601String(),
                        // The re-pointing trail.
                        'previous_odoo_id'   => $mapping->previous_odoo_id,
                        'previous_odoo_name' => $mapping->previous_odoo_name,
                        'changed_at'         => optional($mapping->changed_at)->toIso8601String(),
                        'change_count'       => (int) $mapping->change_count,
                        // When a master-data pull last CONFIRMED the Odoo record still exists.
                        'last_synced_at' => optional($mapping->last_synced_at)->toIso8601String(),
                        // What the VALIDATOR thinks — a suggested or stale mapping is not usable, and
                        // the screen must show that rather than a reassuring green tick.
                        'usable'         => $mapping->isUsable(),
                    ] : null,
                ];
            });

            if ($request->boolean('unmapped')) {
                $items = $items->filter(fn ($i) => ! ($i['mapping']['usable'] ?? false)
                    && ($i['mapping']['status'] ?? null) !== OdooMapping::STATUS_UNMAPPED)->values();
            }

            return ResponseHelper::SuccessResponse([
                'items' => $items,
                'meta'  => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ], 'Mappings');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The picker's options — cached Odoo master data, searchable. */
    public function options(Request $request, string $kind)
    {
        try {
            [, $odooModel] = $this->kind($kind);

            $rows = OdooReferenceRecord::forModel($odooModel)
                ->where('active', true)
                ->search($request->query('search'))
                ->orderBy('name')
                ->limit(min(100, (int) $request->query('limit', 50)))
                ->get();

            return ResponseHelper::SuccessResponse([
                'items' => $rows->map(fn (OdooReferenceRecord $r) => [
                    'odoo_id' => (int) $r->odoo_id,
                    'name'    => $r->name,
                    'code'    => $r->code,
                    'stale'   => $r->isStale(),
                ])->values(),
                // An empty picker almost always means the master data has never been pulled, not that
                // Odoo has no products. Saying so here saves an administrator a long hunt.
                'cache_empty' => $rows->isEmpty()
                    && OdooReferenceRecord::forModel($odooModel)->count() === 0,
            ], 'Odoo records');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Candidates for one record — proposals only. Confirming is a separate, explicit call. */
    public function suggestions(string $kind, int $id)
    {
        try {
            [$class] = $this->kind($kind);
            $record  = $class::findOrFail($id);

            $items = match ($kind) {
                'vehicle'  => $this->mappings->suggestVehicleMatches($record),
                'part'     => $this->mappings->suggestProductMatches($record),
                'supplier' => $this->mappings->suggestVendorMatches($record),
            };

            return ResponseHelper::SuccessResponse(['items' => $items], 'Suggestions');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Decide a mapping — or record that there is deliberately none.
     *
     * Sending `odoo_id: null` is the "no counterpart" answer, which takes the record OUT of the backlog
     * without pretending it is mapped. Both paths stamp who decided and when.
     */
    public function store(Request $request, string $kind, int $id)
    {
        try {
            [$class] = $this->kind($kind);
            $record  = $class::findOrFail($id);

            $data = $request->validate([
                'odoo_id'   => ['present', 'nullable', 'integer', 'min:1'],
                'odoo_ref'  => ['nullable', 'string', 'max:128'],
                'odoo_name' => ['nullable', 'string', 'max:255'],
                'notes'     => ['nullable', 'string', 'max:500'],
            ]);

            $mapping = $data['odoo_id'] === null
                ? $this->mappings->markUnmapped($record, $request->user(), $data['notes'] ?? null)
                : $this->mappings->map(
                    $record,
                    (int) $data['odoo_id'],
                    $data['odoo_ref'] ?? null,
                    $data['odoo_name'] ?? null,
                    $request->user(),
                    OdooMapping::MATCHED_MANUAL,
                    $data['notes'] ?? null,
                );

            return ResponseHelper::SuccessResponse([
                'status'    => $mapping->status,
                'odoo_id'   => $mapping->odoo_id,
                'odoo_name' => $mapping->odoo_name,
                'usable'    => $mapping->isUsable(),
            ], $data['odoo_id'] === null ? 'Recorded as having no Odoo counterpart' : 'Mapping saved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Withdraw a mapping entirely — it becomes an open question again. */
    public function destroy(string $kind, int $id)
    {
        try {
            [$class] = $this->kind($kind);
            $this->mappings->unmap($class::findOrFail($id));

            return ResponseHelper::SuccessResponse(null, 'Mapping removed');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Expense type ↔ account + document type ────────────────────────────────────────────────────

    public function expenseTypes()
    {
        try {
            $rows = ExpenseTypeMapping::orderBy('expense_type')->get();

            return ResponseHelper::SuccessResponse([
                'items' => $rows->map(fn (ExpenseTypeMapping $m) => [
                    'expense_type'       => $m->expense_type,
                    'label'              => $m->displayLabel(),
                    'odoo_account_id'    => $m->odoo_account_id,
                    'odoo_account_code'  => $m->odoo_account_code,
                    // Display only — never the identifier. See §2.
                    'odoo_account_name'  => $m->odoo_account_name,
                    'odoo_document_type' => $m->odoo_document_type,
                    'odoo_journal_id'    => $m->odoo_journal_id,
                    'active'             => $m->active,
                    'usable'             => $m->isUsable(),
                    // The EFFECTIVE requirements — the document type's defaults with this row's
                    // attachment override applied, so the screen shows what actually blocks.
                    'requirements'        => $m->requirements(),
                    'requires_attachment' => $m->requires_attachment,
                ])->values(),
                'document_types' => array_map(
                    fn ($t) => ['value' => $t, 'label' => OdooDocumentType::label($t)],
                    OdooDocumentType::ALL
                ),
            ], 'Expense type mappings');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Point an expense type at an account and a document type.
     *
     * The two are set independently and either may be changed alone — that is the §3/§4 requirement
     * that "REPAIR = VENDOR_BILL" never becomes an inseparable concept, expressed as an endpoint.
     */
    public function updateExpenseType(Request $request, string $expenseType)
    {
        try {
            if (! ExpenseType::isValid($expenseType)) {
                return ResponseHelper::FailureResponse(null, "Unknown expense type {$expenseType}.", 404);
            }

            $data = $request->validate([
                'odoo_account_id'    => ['sometimes', 'nullable', 'integer', 'min:1'],
                'odoo_account_code'  => ['sometimes', 'nullable', 'string', 'max:64'],
                'odoo_account_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
                'odoo_document_type' => ['sometimes', 'nullable', Rule::in(OdooDocumentType::ALL)],
                'odoo_journal_id'    => ['sometimes', 'nullable', 'integer', 'min:1'],
                // Three-state on purpose: null = follow the document type's default, which is the
                // normal case. Only a category Finance has singled out carries true or false.
                'requires_attachment' => ['sometimes', 'nullable', 'boolean'],
                'active'             => ['sometimes', 'boolean'],
                'notes'              => ['sometimes', 'nullable', 'string', 'max:500'],
            ]);

            $mapping = ExpenseTypeMapping::firstOrCreate(['expense_type' => $expenseType]);
            $mapping->fill($data + ['mapped_by' => $request->user()?->id, 'mapped_at' => now()])->save();

            return ResponseHelper::SuccessResponse([
                'expense_type'       => $mapping->expense_type,
                'odoo_account_id'    => $mapping->odoo_account_id,
                'odoo_account_name'  => $mapping->odoo_account_name,
                'odoo_document_type' => $mapping->odoo_document_type,
                'active'             => $mapping->active,
                'usable'             => $mapping->isUsable(),
            ], 'Expense type mapping saved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Accounts are picked from the same cached master data, but from a different Odoo model. */
    public function accountOptions(Request $request)
    {
        try {
            $rows = OdooReferenceRecord::forModel('account.account')
                ->where('active', true)
                ->search($request->query('search'))
                ->orderBy('code')
                ->limit(min(200, (int) $request->query('limit', 100)))
                ->get();

            return ResponseHelper::SuccessResponse([
                'items' => $rows->map(fn (OdooReferenceRecord $r) => [
                    'odoo_id' => (int) $r->odoo_id,
                    'name'    => $r->name,
                    'code'    => $r->code,
                ])->values(),
                'cache_empty' => OdooReferenceRecord::forModel('account.account')->count() === 0,
            ], 'Odoo accounts');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:class-string, 1:string} */
    private function kind(string $kind): array
    {
        if (! isset(self::KINDS[$kind])) {
            abort(404, "Unknown mapping kind {$kind}.");
        }

        return self::KINDS[$kind];
    }

    private function labelFor($record, string $class): string
    {
        return match ($class) {
            Vehicle::class => trim(($record->plate_no ?: '—') . ' · ' . trim($record->make . ' ' . $record->model)),
            default        => (string) $record->name,
        };
    }
}
