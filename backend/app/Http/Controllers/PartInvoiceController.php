<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Services\PartInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supplier parts invoices — the paper behind what a part cost.
 *
 * A garage-supplied part is billed on that garage's maintenance invoice and must never be keyed here as
 * well (it would charge the ticket twice); PartInvoiceService enforces that on attach, so these endpoints
 * simply surface the refusal. Every write is a money action → parts.purchase on the routes.
 */
class PartInvoiceController extends Controller
{
    public function __construct(private PartInvoiceService $invoices) {}

    /** The invoice ledger — filterable by supplier, and by whether the paper still has an unexplained gap. */
    public function index(Request $request)
    {
        return $this->run(function () use ($request) {
            $q = PartInvoice::query()->with(['vendor:id,name', 'purchases:id,part_invoice_id,part_name,purchase_price,quantity,vehicle_id,maintenance_id'])
                ->latest('id');

            if ($vendor = $request->query('vendor_id')) {
                $q->where('vendor_id', $vendor);
            }
            if ($no = $request->query('invoice_no')) {
                $q->where('invoice_no', 'like', '%' . $no . '%');
            }
            if ($request->boolean('with_variance')) {
                $q->whereNotNull('variance_explanation');
            }
            // The second-eyes queue. Oldest first here and only here: everywhere else the newest
            // bill is the interesting one, but a bill nobody has checked gets MORE urgent with age,
            // not less.
            if ($request->boolean('awaiting_match')) {
                $q->awaitingMatch()->reorder('invoice_date')->orderBy('id');
            }
            if ($request->boolean('disputed')) {
                $q->disputed();
            }
            if ($from = $request->query('from')) {
                $q->whereDate('invoice_date', '>=', $from);
            }
            if ($to = $request->query('to')) {
                $q->whereDate('invoice_date', '<=', $to);
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'invoices' => $rows->getCollection()->map(fn (PartInvoice $i) => $this->present($i))->all(),
                'meta'     => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Part invoices retrieved');
        });
    }

    public function show(PartInvoice $partInvoice)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->present($partInvoice->load(['vendor:id,name', 'purchases.vehicle:id,plate_no'])),
            'Part invoice retrieved',
        ));
    }

    /**
     * Supplier purchases with no invoice keyed against them yet — what the invoice form offers to attach.
     * Garage-sourced buys are deliberately absent: they are billed on the garage's own invoice.
     */
    public function unbilled(Request $request)
    {
        return $this->run(function () use ($request) {
            $q = PartPurchase::query()
                ->with(['vehicle:id,plate_no', 'sourceVendor:id,name'])
                ->where('purchase_source', PartPurchase::SOURCE_SUPPLIER)
                ->whereNull('part_invoice_id')
                ->latest('id');

            if ($vehicle = $request->query('vehicle_id')) {
                $q->where('vehicle_id', $vehicle);
            }
            if ($ticket = $request->query('maintenance_id')) {
                $q->where('maintenance_id', $ticket);
            }
            if ($vendor = $request->query('vendor_id')) {
                $q->where('source_vendor_id', $vendor);
            }

            return ResponseHelper::SuccessResponse(
                $q->limit(min((int) $request->query('limit', 100), 300))->get()->map(fn (PartPurchase $p) => [
                    'id'           => $p->id,
                    'part_name'    => $p->part_name,
                    'part_number'  => $p->part_number,
                    'quantity'     => (float) ($p->quantity ?: 1),
                    'unit_price'   => (float) $p->purchase_price,
                    'gross'        => $p->grossCost(),
                    'currency'     => $p->currency,
                    'supplier'     => $p->sourceVendor?->name ?: $p->source_name,
                    'vehicle_id'   => $p->vehicle_id,
                    'plate_no'     => $p->vehicle?->plate_no,
                    'maintenance_id' => $p->maintenance_id,
                    'purchased_at' => optional($p->purchased_at)->toIso8601String(),
                ])->all(),
                'Unbilled supplier purchases retrieved',
            );
        });
    }

    /** Key a supplier invoice: header, the parts it covers, and the photo of the paper. */
    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $this->decodeJsonArrays($request);
            $data = $request->validate($this->rules());
            $invoice = $this->invoices->create($this->payload($data), $request->user(), $request->file('photo'));

            return ResponseHelper::SuccessResponse($this->present($invoice), 'Part invoice recorded', 201);
        });
    }

    /** POST (not PUT) because it may carry a replacement photo as multipart. */
    public function update(Request $request, PartInvoice $partInvoice)
    {
        return $this->run(function () use ($request, $partInvoice) {
            // Editing stops at approval. Checked before the payload is even read, so a refused edit
            // cannot half-apply — and checked HERE rather than only in the UI, because a hidden button
            // has never stopped anyone from calling the endpoint.
            $this->invoices->assertEditable($partInvoice, 'edited');

            $this->decodeJsonArrays($request);
            $data = $request->validate($this->rules(false));
            $invoice = $this->invoices->update($partInvoice, $this->payload($data), $request->user(), $request->file('photo'));

            return ResponseHelper::SuccessResponse($this->present($invoice), 'Part invoice updated');
        });
    }

    /** Remove the document. The purchases survive — deleting paper never deletes the fact of the spend. */
    public function destroy(Request $request, PartInvoice $partInvoice)
    {
        return $this->run(function () use ($request, $partInvoice) {
            // Same gate as editing, and for a sharper reason: an approved bill can already have payments
            // allocated against it, and deleting the document would leave that money pointing at nothing.
            $this->invoices->assertEditable($partInvoice, 'deleted');

            $this->invoices->delete($partInvoice, $request->user());

            return ResponseHelper::SuccessResponse(null, 'Part invoice deleted');
        });
    }

    /**
     * The OTHER way a part gets billed: on the garage's own invoice, beside the labour.
     *
     * One supplier trip buys parts and nothing else, so it becomes a PartInvoice. But a garage that
     * fits a part usually bills the part AND the work on one document — `maintenance_invoices`
     * models that directly, with `parts_total` and `labor_total` side by side on the same row.
     * PartInvoiceService deliberately REFUSES to let a garage-sourced part be keyed here as well,
     * because the ticket would then be charged for it twice.
     *
     * That rule is right, and it left a hole in the READING: the Parts page showed supplier bills
     * only, so a part billed by a garage was invisible on the one page named after parts. Somebody
     * asking "what have we been billed for this part" saw a fraction of the answer and had no way to
     * know it was a fraction.
     *
     * So this endpoint surfaces those bills WITHOUT merging the two entities. Read-only, on purpose:
     * a garage invoice is written on its ticket, through MaintenanceInvoiceService, and keeping one
     * write path per entity is what stops the double-count the refusal exists to prevent. What comes
     * back is a pointer — here is the bill, here is its ticket, go there to change it.
     */
    public function garageBilled(Request $request)
    {
        return $this->run(function () use ($request) {
            $q = MaintenanceInvoice::query()
                ->with([
                    'vendor:id,name',
                    'maintenance:id,vehicle_id',
                    'maintenance.vehicle:id,plate_no',
                    'lineItems' => fn ($l) => $l->where('kind', MaintenanceLineItem::KIND_PART),
                ])
                // Only bills that actually carry a part. A pure-labour invoice belongs to the ticket
                // and has no business on a page about parts.
                ->whereHas('lineItems', fn ($l) => $l->where('kind', MaintenanceLineItem::KIND_PART))
                ->latest('id');

            if ($vendor = $request->query('vendor_id')) {
                $q->where('vendor_id', $vendor);
            }
            if ($no = $request->query('invoice_no')) {
                $q->where('invoice_no', 'like', '%' . $no . '%');
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'invoices' => collect($rows->items())->map(fn ($i) => [
                    'id'             => $i->id,
                    'invoice_no'     => $i->invoice_no,
                    'garage'         => $i->vendor?->name ?: ($i->is_internal ? 'In-house' : null),
                    'is_internal'    => (bool) $i->is_internal,
                    'maintenance_id' => $i->maintenance_id,
                    'vehicle_id'     => $i->maintenance?->vehicle_id,
                    'plate'          => $i->maintenance?->vehicle?->plate_no,
                    // The split is the whole point of showing this row: it says how much of a mixed
                    // bill was the part and how much was the work.
                    'parts_total'    => (float) $i->parts_total,
                    'labor_total'    => (float) $i->labor_total,
                    'amount'         => (float) $i->amount,
                    'recorded_at'    => optional($i->recorded_at)->toIso8601String(),
                    'parts'          => $i->lineItems->map(fn (MaintenanceLineItem $l) => [
                        'id'          => $l->id,
                        'description' => $l->description,
                        'part_number' => $l->part_number,
                        'quantity'    => (float) ($l->quantity ?: 1),
                        'line_total'  => (float) $l->line_total,
                    ])->values()->all(),
                ])->all(),
                'meta' => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Garage-billed parts retrieved');
        });
    }

    /**
     * The second pair of eyes: somebody who did NOT key this bill looks at the photo and says
     * whether it agrees with the figures.
     *
     * Two rules, both refusals rather than warnings:
     *
     *   1. NOT THE RECORDER. The whole value of this stage is that a second person looked. Letting
     *      the person who typed the figures also confirm them turns the check into a formality and
     *      records a lie — that two people agreed when one did.
     *   2. A PHOTO MUST EXIST. There is nothing to match a bill against if the paper was never
     *      attached; "checked" would then mean "read the same numbers back", which is what this
     *      stage exists to stop.
     *
     * Disagreeing is a first-class outcome and needs a reason. Agreeing does not — being asked to
     * justify "it is correct" is how a check becomes something people click through.
     */
    public function match(Request $request, PartInvoice $partInvoice)
    {
        return $this->run(function () use ($request, $partInvoice) {
            $data = $request->validate([
                'result' => ['required', Rule::in(PartInvoice::MATCH_RESULTS)],
                'note'   => ['nullable', 'string', 'max:2000'],
            ]);

            if ($data['result'] === PartInvoice::MATCH_DISPUTED && trim((string) ($data['note'] ?? '')) === '') {
                abort(422, 'Say what does not agree — a dispute with no reason cannot be acted on.');
            }

            $user = $request->user();

            // Both refusals live on the model — see PartInvoice::whyCannotBeCheckedBy() for why each
            // one exists. 422 rather than 403 for either: neither is a permissions problem, they are
            // both "this particular check would not mean anything".
            if ($why = $partInvoice->whyCannotBeCheckedBy((int) $user->id)) {
                abort(422, $why);
            }

            $partInvoice->forceFill([
                'matched_at'      => now(),
                'matched_by'      => $user->id,
                'matched_by_name' => $user->name ?: $user->email,
                'match_result'    => $data['result'],
                'match_note'      => trim((string) ($data['note'] ?? '')) ?: null,
            ])->save();

            return ResponseHelper::SuccessResponse($this->present($partInvoice->fresh()), 'Invoice checked');
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    private function rules(bool $creating = true): array
    {
        return [
            'vendor_id'            => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            'supplier_name'        => ['nullable', 'string', 'max:255'],
            'invoice_no'           => ['nullable', 'string', 'max:120'],
            'invoice_date'         => ['nullable', 'date'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'tax_amount'           => ['nullable', 'numeric', 'min:0'],
            'discount_amount'      => ['nullable', 'numeric', 'min:0'],
            'stated_total'         => ['nullable', 'numeric', 'min:0'],
            'variance_explanation' => ['nullable', 'string', 'max:2000'],
            'notes'                => ['nullable', 'string', 'max:2000'],
            'purchase_ids'         => [$creating ? 'nullable' : 'sometimes', 'array'],
            'purchase_ids.*'       => ['integer'],
            'photo'                => ['nullable', 'image', 'max:8192'],
        ];
    }

    private function payload(array $data): array
    {
        return collect($data)->except('photo')->all();
    }

    /** Multipart can't nest arrays, so the client sends purchase_ids as a JSON string; decode it first. */
    private function decodeJsonArrays(Request $request): void
    {
        $value = $request->input('purchase_ids');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $request->merge(['purchase_ids' => is_array($decoded) ? $decoded : []]);
        }
    }

    /** The invoice as the UI reads it — header, money, the gap against the paper, and its parts. */
    private function present(PartInvoice $invoice): array
    {
        $invoice->loadMissing(['vendor:id,name', 'purchases']);

        return $invoice->statusPayload() + [
            'id'            => $invoice->id,
            'supplier'      => $invoice->supplierLabel(),
            'vendor_id'     => $invoice->vendor_id,
            'supplier_name' => $invoice->supplier_name,
            'invoice_no'    => $invoice->invoice_no,
            'invoice_date'  => optional($invoice->invoice_date)->toDateString(),
            'currency'      => $invoice->currency,
            'subtotal'      => (float) $invoice->subtotal,
            'tax_amount'    => (float) $invoice->tax_amount,
            'discount_amount' => (float) $invoice->discount_amount,
            'total_amount'  => (float) $invoice->total_amount,
            'stated_total'  => $invoice->stated_total !== null ? (float) $invoice->stated_total : null,
            'variance'      => $invoice->variance(),
            'variance_explanation' => $invoice->variance_explanation,
            'photo_url'     => $invoice->photoUrl(),
            'notes'         => $invoice->notes,
            'recorded_by'   => $invoice->recorded_by_name,
            'recorded_by_id' => $invoice->recorded_by,
            'recorded_at'   => optional($invoice->recorded_at)->toIso8601String(),
            // The second pair of eyes. `matched_at` null means nobody has looked yet — which is a
            // different thing from somebody having looked and disagreed (`match_result` disputed).
            'matched_at'      => optional($invoice->matched_at)->toIso8601String(),
            'matched_by'      => $invoice->matched_by_name,
            'matched_by_id'   => $invoice->matched_by,
            'match_result'    => $invoice->match_result,
            'match_note'      => $invoice->match_note,
            'items'         => $invoice->purchases->map(fn (PartPurchase $p) => [
                'purchase_id' => $p->id,
                'part_name'   => $p->part_name,
                'part_number' => $p->part_number,
                'quantity'    => (float) ($p->quantity ?: 1),
                'unit_price'  => (float) $p->purchase_price,
                'line_total'  => $p->grossCost(),
                'refunded'    => $p->refundedTotal(),
                'net'         => $p->netCost(),
                'vehicle_id'  => $p->vehicle_id,
                'plate_no'    => $p->vehicle?->plate_no,
                'maintenance_id' => $p->maintenance_id,
            ])->values()->all(),
        ];
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
