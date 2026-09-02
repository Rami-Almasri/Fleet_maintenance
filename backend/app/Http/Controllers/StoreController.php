<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\StoreItem;
use App\Models\StoreMovement;
use App\Models\StoreStockRequest;
use App\Services\StoreService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Storehouse API — what is on the shelf, what is owed to it, and every unit that moved.
 *
 * Two doors in and one door out:
 *   IN   stock requests (no vehicle) → approve → receive, and a direct receipt for the walk-in buy
 *        or the opening count.
 *   OUT  {@see issue} — a job takes a unit. That call is the ONLY way stock reaches a car, and it
 *        goes through StoreService so the decrement and the resulting purchase are one transaction.
 *
 * Permissions ride the existing parts vocabulary rather than inventing a store one: looking is
 * parts.view, asking for stock is parts.request, anything that moves a unit or prices it is
 * parts.purchase (the same bar as spending money), and adjudicating a request is parts.investigate.
 */
class StoreController extends Controller
{
    public function __construct(private StoreService $store) {}

    // ───────────────────────────── shelves ─────────────────────────────

    /** The shelf list, with the roll-up the header tiles read. */
    public function index(Request $request)
    {
        try {
            $q = StoreItem::query()->with('catalogPart:id,name,name_ar,slug,category_key')->orderBy('part_name');

            if ($term = trim((string) $request->query('q'))) {
                $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
                $q->where(fn ($w) => $w->where('part_name', 'like', $needle)->orWhere('part_number', 'like', $needle));
            }
            if ($request->boolean('in_stock')) {
                $q->inStock();
            }
            if ($request->boolean('low')) {
                $q->low();
            }
            if ($cat = $request->query('category_key')) {
                $q->where('category_key', $cat);
            }

            $items = $q->get();

            return ResponseHelper::SuccessResponse([
                'items'   => $items->map(fn (StoreItem $i) => $this->presentItem($i))->all(),
                'summary' => $this->summary(),
            ], 'Storehouse retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One shelf, with its full movement history — the evidence behind its level. */
    public function show(StoreItem $storeItem)
    {
        try {
            $movements = $storeItem->movements()
                ->with(['vehicle:id,plate_no', 'supplier:id,name', 'invoice:id,invoice_no,invoice_date,vendor_id,supplier_name,photo_disk,photo_key', 'invoice.vendor:id,name'])
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            return ResponseHelper::SuccessResponse([
                'item'      => $this->presentItem($storeItem->load('catalogPart:id,name,slug,category_key')),
                'movements' => $movements->map(fn (StoreMovement $m) => $this->presentMovement($m))->all(),
                'open_requests' => $storeItem->stockRequests()->outstanding()->orderByDesc('id')->get()
                    ->map(fn (StoreStockRequest $r) => $this->presentStockRequest($r))->all(),
                // Every time somebody corrected what this shelf costs, with both figures. Sits
                // beside the movements rather than among them: those are units, these are money.
                'price_corrections' => \App\Models\StorePriceCorrection::query()
                    ->where('store_item_id', $storeItem->id)
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn ($c) => [
                        'id'          => $c->id,
                        'old'         => $c->old_avg_unit_cost === null ? null : (float) $c->old_avg_unit_cost,
                        'new'         => (float) $c->new_avg_unit_cost,
                        'delta'       => $c->delta(),
                        'reason'      => $c->reason,
                        'actor_name'  => $c->actor_name,
                        'occurred_at' => optional($c->occurred_at)->toIso8601String(),
                    ])->all(),
            ], 'Storehouse item retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Do we already have this?" — asked by the part-request form the moment a part is picked, so
     * the answer arrives BEFORE anyone decides to buy one.
     */
    public function availability(Request $request)
    {
        try {
            $data = $request->validate([
                'component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],
                'part_name'            => ['nullable', 'string', 'max:255'],
                'part_number'          => ['nullable', 'string', 'max:255'],
            ]);

            if (empty($data['component_catalog_id']) && empty($data['part_name']) && empty($data['part_number'])) {
                return ResponseHelper::SuccessResponse(
                    ['in_stock' => false, 'qty_on_hand' => 0.0, 'item' => null, 'unit_cost' => null, 'currency' => 'AED'],
                    'Nothing to look up'
                );
            }

            return ResponseHelper::SuccessResponse(
                $this->store->availability(
                    $data['component_catalog_id'] ?? null,
                    $data['part_name'] ?? null,
                    $data['part_number'] ?? null,
                ),
                'Storehouse availability retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The whole ledger across every shelf — "what moved this week". */
    public function movements(Request $request)
    {
        try {
            $q = StoreMovement::query()
                ->with(['item:id,part_name,part_number', 'vehicle:id,plate_no', 'supplier:id,name', 'invoice:id,invoice_no,invoice_date,vendor_id,supplier_name,photo_disk,photo_key', 'invoice.vendor:id,name'])
                ->orderByDesc('id');

            if ($d = $request->query('direction')) {
                $q->where('direction', $d);
            }
            if ($r = $request->query('reason')) {
                $q->whereIn('reason', explode(',', $r));
            }
            if ($v = $request->query('vehicle_id')) {
                $q->where('vehicle_id', $v);
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'movements' => collect($rows->items())->map(fn (StoreMovement $m) => $this->presentMovement($m))->all(),
                'meta'      => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Stock movements retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ───────────────────────────── stock in ─────────────────────────────

    /**
     * Book stock in directly: a walk-in buy, or the opening count.
     *
     * POST (multipart) rather than JSON because it may carry the invoice PHOTO — the bill is part of
     * this request, not a follow-up someone is trusted to remember.
     */
    public function receive(Request $request)
    {
        try {
            $this->decodeInvoiceJson($request);

            $data = $request->validate([
                'component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],
                'part_name'            => ['required', 'string', 'max:255'],
                'part_number'          => ['nullable', 'string', 'max:255'],
                'category_key'         => ['nullable', 'string', 'max:60'],
                'quantity'             => ['required', 'numeric', 'gt:0'],
                // A buy has a price. Only an opening count may be silent about it, and it says so on
                // the shelf ("not costed") rather than charging a car zero later as if it were free.
                'unit_cost'            => [Rule::requiredIf(fn () => ($request->input('reason') ?: StoreMovement::REASON_RECEIPT) === StoreMovement::REASON_RECEIPT), 'nullable', 'numeric', 'min:0'],
                'currency'             => ['nullable', 'string', 'size:3'],
                'supplier_vendor_id'   => ['nullable', 'exists:vendors,id'],
                'location'             => ['nullable', 'string', 'max:120'],
                'min_qty'              => ['nullable', 'numeric', 'min:0'],
                'reason'               => ['nullable', Rule::in([StoreMovement::REASON_RECEIPT, StoreMovement::REASON_OPENING])],
                'note'                 => ['nullable', 'string', 'max:2000'],
                // Optional here and required by StoreService only when the price actually disagrees
                // with the shelf — the service owns that decision because it is the one holding the
                // row lock, and so the only place the comparison cannot race a concurrent receipt.
                'price_variance_note'  => ['nullable', 'string', 'max:2000'],
            ] + $this->invoiceRules($request));

            $data['invoice_photo'] = $request->file('invoice_photo');

            $item = $this->store->receiveDirect($data, $request->user());

            return ResponseHelper::SuccessResponse($this->presentItem($item), 'Stock received', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The supplier's bill, as it arrives with a receipt: either an existing invoice to add these units
     * to, or the header of a new one.
     *
     * The rules are shaped rather than merely optional — `invoice.invoice_no` is required unless the
     * caller named an existing invoice or is recording an opening count. The service refuses an
     * unpapered receipt regardless; stating it here is what makes the form say WHICH field is missing
     * instead of failing with a sentence.
     */
    private function invoiceRules(Request $request): array
    {
        $isReceipt = ($request->input('reason') ?: StoreMovement::REASON_RECEIPT) === StoreMovement::REASON_RECEIPT;
        $needsNew  = $isReceipt && ! $request->filled('part_invoice_id');

        return [
            'part_invoice_id'              => ['nullable', 'integer', 'exists:part_invoices,id'],
            'invoice'                      => [$needsNew ? 'required' : 'nullable', 'array'],
            'invoice.invoice_no'           => [$needsNew ? 'required' : 'nullable', 'string', 'max:120'],
            'invoice.vendor_id'            => ['nullable', 'integer', 'exists:vendors,id'],
            'invoice.supplier_name'        => ['nullable', 'string', 'max:255'],
            'invoice.invoice_date'         => [$needsNew ? 'required' : 'nullable', 'date'],
            'invoice.currency'             => ['nullable', 'string', 'size:3'],
            'invoice.tax_amount'           => ['nullable', 'numeric', 'min:0'],
            'invoice.discount_amount'      => ['nullable', 'numeric', 'min:0'],
            'invoice.stated_total'         => ['nullable', 'numeric', 'min:0'],
            'invoice.variance_explanation' => ['nullable', 'string', 'max:2000'],
            'invoice.notes'                => ['nullable', 'string', 'max:2000'],
            'invoice_photo'                => ['nullable', 'image', 'max:8192'],
        ];
    }

    /** Multipart cannot nest objects, so the client sends `invoice` as a JSON string. Decode it first. */
    private function decodeInvoiceJson(Request $request): void
    {
        $value = $request->input('invoice');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $request->merge(['invoice' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * Correct a count or write stock off. The note is REQUIRED: a quantity that changed with no
     * stated reason is indistinguishable from an accident, and this is the one movement with no
     * document behind it.
     */
    public function adjust(Request $request, StoreItem $storeItem)
    {
        try {
            $data = $request->validate([
                'direction' => ['required', Rule::in([StoreMovement::IN, StoreMovement::OUT])],
                'quantity'  => ['required', 'numeric', 'gt:0'],
                'reason'    => ['nullable', Rule::in([StoreMovement::REASON_ADJUSTMENT, StoreMovement::REASON_WRITE_OFF])],
                'note'      => ['required', 'string', 'max:2000'],
            ]);

            if (($data['reason'] ?? null) === StoreMovement::REASON_WRITE_OFF && $data['direction'] !== StoreMovement::OUT) {
                return ResponseHelper::FailureResponse(null, 'A write-off takes stock off the shelf — it cannot add any.', 422);
            }

            return ResponseHelper::SuccessResponse(
                $this->presentItem($this->store->adjust($storeItem, $data, $request->user())),
                'Stock adjusted'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Edit the shelf's own settings — where it sits, when it counts as low. Never its quantity. */
    public function update(Request $request, StoreItem $storeItem)
    {
        try {
            $data = $request->validate([
                'min_qty'   => ['nullable', 'numeric', 'min:0'],
                'location'  => ['nullable', 'string', 'max:120'],
                'notes'     => ['nullable', 'string', 'max:2000'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $storeItem->update($data);

            return ResponseHelper::SuccessResponse($this->presentItem($storeItem->fresh()), 'Storehouse item updated');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ───────────────────────────── stock requests ─────────────────────────────

    public function stockRequests(Request $request)
    {
        try {
            $q = StoreStockRequest::query()
                ->with(['item:id,part_name,qty_on_hand', 'supplier:id,name', 'catalogPart:id,name,slug'])
                ->orderByDesc('id');

            if ($s = $request->query('status')) {
                $q->whereIn('status', explode(',', $s));
            }
            if ($request->boolean('outstanding')) {
                $q->outstanding();
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'requests' => collect($rows->items())->map(fn (StoreStockRequest $r) => $this->presentStockRequest($r))->all(),
                'meta'     => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Stock requests retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Ask for a part to be put on the shelf — the door that needs no car. */
    public function storeStockRequest(Request $request)
    {
        try {
            $data = $request->validate([
                'component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],
                'part_name'            => ['required', 'string', 'max:255'],
                'part_number'          => ['nullable', 'string', 'max:255'],
                'category_key'         => ['nullable', 'string', 'max:60'],
                'quantity'             => ['nullable', 'numeric', 'gt:0'],
                'estimated_price'      => ['nullable', 'numeric', 'min:0'],
                'currency'             => ['nullable', 'string', 'size:3'],
                'reason'               => ['required', 'string', 'max:2000'],
                'notes'                => ['nullable', 'string', 'max:2000'],
                'supplier_vendor_id'   => ['nullable', 'exists:vendors,id'],
                'supplier_name'        => ['nullable', 'string', 'max:255'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->presentStockRequest($this->store->createStockRequest($data, $request->user())),
                'Stock request created',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function approveStockRequest(Request $request, StoreStockRequest $storeStockRequest)
    {
        try {
            $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                $this->presentStockRequest($this->store->approveStockRequest($storeStockRequest, $request->user(), $data['notes'] ?? null)),
                'Stock request approved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function rejectStockRequest(Request $request, StoreStockRequest $storeStockRequest)
    {
        try {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                $this->presentStockRequest($this->store->rejectStockRequest($storeStockRequest, $request->user(), $data['reason'])),
                'Stock request rejected'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function orderStockRequest(Request $request, StoreStockRequest $storeStockRequest)
    {
        try {
            $data = $request->validate([
                'supplier_vendor_id' => ['nullable', 'exists:vendors,id'],
                'supplier_name'      => ['nullable', 'string', 'max:255'],
                'unit_cost'          => ['nullable', 'numeric', 'min:0'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->presentStockRequest($this->store->markOrdered($storeStockRequest, $data, $request->user())),
                'Stock request marked as ordered'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The part arrived — the shelf goes UP here, and only here for a requested buy.
     *
     * The supplier's invoice is part of THIS step, not a later one. Receiving is the moment the fleet
     * takes ownership and owes money; a shelf raised now and papered "soon" is a cost with no
     * document behind it, which is exactly the gap this feature exists to close.
     */
    public function receiveStockRequest(Request $request, StoreStockRequest $storeStockRequest)
    {
        try {
            $this->decodeInvoiceJson($request);

            $data = $request->validate([
                'quantity'           => ['nullable', 'numeric', 'gt:0'],
                'unit_cost'          => ['required', 'numeric', 'min:0'],
                'currency'           => ['nullable', 'string', 'size:3'],
                'supplier_vendor_id' => ['nullable', 'exists:vendors,id'],
                'supplier_name'      => ['nullable', 'string', 'max:255'],
                'location'           => ['nullable', 'string', 'max:120'],
                'note'               => ['nullable', 'string', 'max:2000'],
                'price_variance_note' => ['nullable', 'string', 'max:2000'],
            ] + $this->invoiceRules($request));

            $data['invoice_photo'] = $request->file('invoice_photo');

            $req = $this->store->receiveStockRequest($storeStockRequest, $data, $request->user());

            return ResponseHelper::SuccessResponse([
                'request' => $this->presentStockRequest($req),
                'item'    => $req->item ? $this->presentItem($req->item) : null,
            ], 'Stock received into the storehouse');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ───────────────────────────── stock out ─────────────────────────────

    /**
     * Issue a part from the shelf to a part request — the whole point of the storehouse.
     *
     * The shelf goes down and the request is fulfilled from stock in one transaction; from there the
     * part follows the ordinary install path, so it lands on the ticket's cost and on the vehicle's
     * Installed Components exactly like a part bought for the job.
     */
    public function issue(Request $request, PartRequest $partRequest)
    {
        try {
            $data = $request->validate([
                'store_item_id' => ['required', 'integer', 'exists:store_items,id'],
                'quantity'      => ['nullable', 'numeric', 'gt:0'],
                'note'          => ['nullable', 'string', 'max:2000'],
            ]);

            $result = $this->store->issueToRequest(
                $partRequest,
                StoreItem::findOrFail($data['store_item_id']),
                $data,
                $request->user(),
            );

            return ResponseHelper::SuccessResponse([
                'purchase'  => new \App\Http\Resources\PartPurchaseResource($result['purchase']),
                'item'      => $this->presentItem($result['item']),
                'duplicate' => $result['verdict']['duplicate'] ?? false,
            ], 'Part issued from the storehouse', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** A part issued from stock that was never fitted, going back on the shelf. */
    public function returnToStore(Request $request, PartPurchase $partPurchase)
    {
        try {
            $data = $request->validate([
                'quantity' => ['nullable', 'numeric', 'gt:0'],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->presentItem($this->store->returnToStore($partPurchase, $data, $request->user())),
                'Part returned to the storehouse'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Put a shelf's cost right after a wrong price was blended into its average.
     *
     * Gated on parts.investigate rather than parts.purchase: the buyer whose receipt produced the
     * bad average already holds parts.purchase, so allowing the fix at that bar would let the same
     * authority both cause and erase the problem. See StoreService::correctPrice() for why this is
     * not queued behind a second approval step.
     */
    public function correctPrice(Request $request, StoreItem $storeItem)
    {
        try {
            $data = $request->validate([
                'avg_unit_cost' => ['required', 'numeric', 'min:0'],
                // Required, always. This is the one write that changes money with no document
                // behind it, so the sentence IS the evidence.
                'reason'        => ['required', 'string', 'min:3', 'max:2000'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->presentItem($this->store->correctPrice($storeItem, $data, $request->user())),
                'Shelf price corrected'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ───────────────────────────── presentation ─────────────────────────────

    private function presentItem(StoreItem $i): array
    {
        return [
            'id'                   => $i->id,
            'component_catalog_id' => $i->component_catalog_id,
            'catalog_name'         => $i->relationLoaded('catalogPart') ? $i->catalogPart?->name : null,
            'part_name'            => $i->part_name,
            'part_number'          => $i->part_number,
            'category_key'         => $i->category_key,
            'qty_on_hand'          => (float) $i->qty_on_hand,
            'min_qty'              => $i->min_qty === null ? null : (float) $i->min_qty,
            'is_low'               => $i->isLow(),
            'avg_unit_cost'        => $i->avg_unit_cost === null ? null : (float) $i->avg_unit_cost,
            'last_unit_cost'       => $i->last_unit_cost === null ? null : (float) $i->last_unit_cost,
            'stock_value'          => $i->stockValue(),
            'currency'             => $i->currency,
            'location'             => $i->location,
            'notes'                => $i->notes,
            'is_active'            => (bool) $i->is_active,
            'updated_at'           => optional($i->updated_at)->toIso8601String(),
        ];
    }

    private function presentMovement(StoreMovement $m): array
    {
        return [
            'id'          => $m->id,
            'item'        => $m->relationLoaded('item') && $m->item
                ? ['id' => $m->item->id, 'part_name' => $m->item->part_name, 'part_number' => $m->item->part_number]
                : null,
            'direction'   => $m->direction,
            'reason'      => $m->reason,
            'quantity'    => (float) $m->quantity,
            'qty_after'   => (float) $m->qty_after,
            'unit_cost'   => $m->unit_cost === null ? null : (float) $m->unit_cost,
            'value'       => $m->value(),
            'currency'    => $m->currency,
            // Present only on the receipts that moved this shelf's price far enough that somebody
            // had to account for it — the audit trail behind every jump in what a part costs.
            'price_variance_note' => $m->price_variance_note,
            'vehicle'     => $m->relationLoaded('vehicle') && $m->vehicle
                ? ['id' => $m->vehicle->id, 'plate' => $m->vehicle->plate_no]
                : null,
            'maintenance_id'   => $m->maintenance_id,
            'part_request_id'  => $m->part_request_id,
            'part_purchase_id' => $m->part_purchase_id,
            // The paper behind the price. Null on an opening count and a return — see StoreService's
            // paper rule for why those two are the only in-movements without one.
            'invoice'          => $m->relationLoaded('invoice') && $m->invoice
                ? [
                    'id'         => $m->invoice->id,
                    'invoice_no' => $m->invoice->invoice_no,
                    'supplier'   => $m->invoice->supplierLabel(),
                    'date'       => optional($m->invoice->invoice_date)->toDateString(),
                    'photo_url'  => $m->invoice->photoUrl(),
                ]
                : null,
            'supplier'    => $m->relationLoaded('supplier') && $m->supplier
                ? ['id' => $m->supplier->id, 'name' => $m->supplier->name]
                : null,
            'note'        => $m->note,
            'actor_name'  => $m->actor_name,
            'occurred_at' => optional($m->occurred_at)->toIso8601String(),
        ];
    }

    private function presentStockRequest(StoreStockRequest $r): array
    {
        return [
            'id'                   => $r->id,
            'status'               => $r->status,
            'store_item_id'        => $r->store_item_id,
            'on_hand_now'          => $r->relationLoaded('item') && $r->item ? (float) $r->item->qty_on_hand : null,
            'component_catalog_id' => $r->component_catalog_id,
            'part_name'            => $r->part_name,
            'part_number'          => $r->part_number,
            'category_key'         => $r->category_key,
            'quantity'             => (float) $r->quantity,
            'estimated_price'      => $r->estimated_price === null ? null : (float) $r->estimated_price,
            'unit_cost'            => $r->unit_cost === null ? null : (float) $r->unit_cost,
            'received_quantity'    => $r->received_quantity === null ? null : (float) $r->received_quantity,
            'currency'             => $r->currency,
            'reason'               => $r->reason,
            'notes'                => $r->notes,
            'supplier'             => $r->relationLoaded('supplier') && $r->supplier
                ? ['id' => $r->supplier->id, 'name' => $r->supplier->name]
                : null,
            'supplier_name'        => $r->supplier_name,
            'requested_by_name'    => $r->requested_by_name,
            'requested_at'         => optional($r->requested_at)->toIso8601String(),
            'approved_by_name'     => $r->approved_by_name,
            'approved_at'          => optional($r->approved_at)->toIso8601String(),
            'rejected_by_name'     => $r->rejected_by_name,
            'rejection_reason'     => $r->rejection_reason,
            'received_by_name'     => $r->received_by_name,
            'received_at'          => optional($r->received_at)->toIso8601String(),
        ];
    }

    /**
     * The header figures. `stock_value` sums only the shelves that have a priced receipt behind
     * them — a shelf counted but never costed contributes nothing rather than a zero pretending to
     * be a valuation, and `uncosted_items` says how many those are.
     */
    private function summary(): array
    {
        $items = StoreItem::query()->get(['qty_on_hand', 'min_qty', 'avg_unit_cost']);

        return [
            'item_count'     => $items->count(),
            'in_stock_count' => $items->where('qty_on_hand', '>', 0)->count(),
            'units_on_hand'  => round((float) $items->sum(fn ($i) => (float) $i->qty_on_hand), 2),
            'low_count'      => $items->filter(fn ($i) => $i->min_qty !== null && (float) $i->qty_on_hand <= (float) $i->min_qty)->count(),
            'stock_value'    => round((float) $items->sum(fn ($i) => $i->avg_unit_cost === null ? 0 : (float) $i->qty_on_hand * (float) $i->avg_unit_cost), 2),
            'uncosted_items' => $items->filter(fn ($i) => $i->avg_unit_cost === null && (float) $i->qty_on_hand > 0)->count(),
            'open_requests'  => StoreStockRequest::query()->outstanding()->count(),
        ];
    }
}
