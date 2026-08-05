<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
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
            $this->invoices->delete($partInvoice, $request->user());

            return ResponseHelper::SuccessResponse(null, 'Part invoice deleted');
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
            'recorded_at'   => optional($invoice->recorded_at)->toIso8601String(),
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
