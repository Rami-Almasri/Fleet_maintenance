<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

/**
 * CRUD for invoices. Reads return BOTH the legacy OfficeManager invoices and the
 * website's own manual ones (one ledger); writes only ever touch manual invoices —
 * an OM-synced row is read-only here (its source is the OfficeManager import).
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service)
    {
    }

    /**
     * Invoices, optionally scoped to one contract (?contract_id=) — newest first.
     */
    public function index(Request $request)
    {
        try {
            $invoices = Invoice::query()
                ->with(['contract', 'items', 'vendor'])
                ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->input('contract_id')))
                ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->input('origin')))
                ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
                ->when($request->boolean('pending'), fn ($q) => $q->pending())
                ->orderByRaw('invoice_date IS NULL, invoice_date DESC')
                ->orderByDesc('id')
                ->paginate(50);

            return ResponseHelper::SuccessResponse([
                'items'     => InvoiceResource::collection($invoices),
                'total'     => $invoices->total(),
                'page'      => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
            ], 'Invoices retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Track A — rental-invoice payment-status summary for the financial dashboard.
     * Aggregates the OM-synced ('api') invoices by derived payment_status, plus the
     * outstanding money, in a single grouped query (no rows loaded into PHP).
     * ?days= limits to invoices dated within the window (default: all time).
     */
    public function statusSummary(Request $request)
    {
        try {
            // Rental (OM-synced) invoices only. The headline covers invoices whose settlement
            // state we've captured (payment_status set on sync); invoices synced before the
            // status feature landed are reported separately as "unsynced" (they fill in as the
            // regular OfficeManager sync re-touches them) rather than muddying the counts.
            $base = Invoice::query()->where('origin', 'api')
                ->when($request->filled('days'), fn ($q) => $q->where('invoice_date', '>=', now()->subDays((int) $request->input('days'))));

            $counts = (clone $base)->whereNotNull('payment_status')
                ->selectRaw('payment_status as status, COUNT(*) as n')
                ->groupBy('payment_status')
                ->pluck('n', 'status');

            $bucket = fn ($k) => (int) ($counts[$k] ?? 0);
            $pending = $bucket(Invoice::PAY_NOT_PAID) + $bucket(Invoice::PAY_PARTIAL);

            return ResponseHelper::SuccessResponse([
                'paid'                => $bucket(Invoice::PAY_PAID),
                'partial'             => $bucket(Invoice::PAY_PARTIAL),
                'not_paid'            => $bucket(Invoice::PAY_NOT_PAID),
                'pending'             => $pending,
                'total'               => (int) array_sum($counts->all()),
                'unsynced'            => (int) (clone $base)->whereNull('payment_status')->count(),
                'outstanding_balance' => round((float) ((clone $base)->pending()->sum('balance_value')), 2),
            ], 'Invoice status summary retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreInvoiceRequest $request)
    {
        try {
            $invoice = $this->service->store($request->validated());

            return ResponseHelper::SuccessResponse(InvoiceResource::make($invoice), 'Invoice created successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(Invoice $invoice)
    {
        try {
            return ResponseHelper::SuccessResponse(InvoiceResource::make($invoice->load(['contract', 'items', 'vendor'])), 'Invoice retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        try {
            if ($invoice->origin !== 'manual') {
                return ResponseHelper::FailureResponse(null, 'Only website-created invoices can be edited; this one is synced from OfficeManager.', 422);
            }
            $invoice = $this->service->update($request->validated(), $invoice);

            return ResponseHelper::SuccessResponse(InvoiceResource::make($invoice), 'Invoice updated successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(Invoice $invoice)
    {
        try {
            if ($invoice->origin !== 'manual') {
                return ResponseHelper::FailureResponse(null, 'Only website-created invoices can be deleted; this one is synced from OfficeManager.', 422);
            }
            $this->service->destroy($invoice);

            return ResponseHelper::SuccessResponse(null, 'Invoice deleted successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
