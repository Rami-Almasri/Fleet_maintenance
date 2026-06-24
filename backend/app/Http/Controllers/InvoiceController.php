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
                ->with('contract')
                ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->input('contract_id')))
                ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->input('origin')))
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
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function store(StoreInvoiceRequest $request)
    {
        try {
            $invoice = $this->service->store($request->validated());

            return ResponseHelper::SuccessResponse(InvoiceResource::make($invoice), 'Invoice created successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function show(Invoice $invoice)
    {
        try {
            return ResponseHelper::SuccessResponse(InvoiceResource::make($invoice->load('contract')), 'Invoice retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
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
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
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
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
