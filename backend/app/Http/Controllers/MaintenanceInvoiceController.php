<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\MaintenanceInvoiceResource;
use App\Http\Resources\MaintenanceWorkflowResource;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Services\MaintenanceInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One Ticket → Many Invoices — CRUD for the individual garage bills on a maintenance ticket.
 *
 * A ticket worked in more than one garage carries more than one invoice, each covering only the faults
 * that garage fixed. These endpoints let the team add / edit / delete / reconcile those invoices; the
 * ticket's aggregate cost + reconciliation status roll up automatically (MaintenanceInvoiceService).
 * All writes are money actions → maintenance.manage on the routes.
 */
class MaintenanceInvoiceController extends Controller
{
    public function __construct(private MaintenanceInvoiceService $invoices)
    {
    }

    /** Every invoice on a ticket, newest first, with its garage, covered faults and line breakdown. */
    public function index(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $ticket->load(['invoices.vendor:id,name', 'invoices.tasks:id,maintenance_invoice_id,symptom,status', 'invoices.lineItems']);

            return ResponseHelper::SuccessResponse(
                MaintenanceInvoiceResource::collection($ticket->invoices),
                'Invoices retrieved',
                200,
            );
        });
    }

    /** Create a new invoice under the ticket (garage · covered faults · lines · receipt + photo). */
    public function store(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $this->decodeJsonArrays($request);
            $data = $request->validate($this->rules());
            $this->invoices->create($ticket, $this->payload($data), $request->user(), $request->file('receipt_photo'));

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($this->reload($ticket)),
                'Invoice created',
                201,
            );
        });
    }

    /** Edit an existing invoice — re-point faults, replace lines, re-key the receipt. */
    public function update(Request $request, MaintenanceInvoice $invoice)
    {
        return $this->run(function () use ($request, $invoice) {
            $this->decodeJsonArrays($request);
            $data = $request->validate($this->rules(false));
            $this->invoices->update($invoice, $this->payload($data), $request->user(), $request->file('receipt_photo'));

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($this->reload($invoice->maintenance)),
                'Invoice updated',
                200,
            );
        });
    }

    /** Delete an invoice — un-bill its faults and drop its cost lines; the ticket rolls back down. */
    public function destroy(MaintenanceInvoice $invoice)
    {
        return $this->run(function () use ($invoice) {
            $ticket = $this->invoices->delete($invoice, request()->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($this->reload($ticket)),
                'Invoice deleted',
                200,
            );
        });
    }

    /** Mark an invoice reconciled (finance matched it against the accounting API). */
    public function reconcile(MaintenanceInvoice $invoice)
    {
        return $this->run(function () use ($invoice) {
            $this->invoices->reconcile($invoice, request()->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($this->reload($invoice->maintenance)),
                'Invoice reconciled',
                200,
            );
        });
    }

    /**
     * Validation for an invoice write. `task_ids` are validated to be faults on THIS ticket in the service
     * (which has the ticket in hand); here we just check the shape + the line-item contract.
     */
    private function rules(bool $creating = true): array
    {
        return [
            'vendor_id'                 => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            // In-house / internal cost — no third-party garage. When true the service forces vendor_id null.
            'is_internal'               => ['nullable', 'boolean'],
            'invoice_no'                => ['nullable', 'string', 'max:120'],
            'notes'                     => ['nullable', 'string', 'max:2000'],
            'task_ids'                  => [$creating ? 'nullable' : 'sometimes', 'array'],
            'task_ids.*'                => ['integer'],
            'line_items'                => [$creating ? 'nullable' : 'sometimes', 'array'],
            'line_items.*.kind'         => ['required_with:line_items', Rule::in(MaintenanceLineItem::KINDS)],
            'line_items.*.description'  => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.finding_text' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.part_number'  => ['nullable', 'string', 'max:120'],
            'line_items.*.category_key' => ['nullable', 'string', 'max:64'],
            'line_items.*.quantity'     => ['nullable', 'numeric', 'min:0'],
            'line_items.*.unit_price'   => ['nullable', 'numeric', 'min:0'],
            'line_items.*.installed_on' => ['nullable', 'date'],
            'line_items.*.warranty_months' => ['nullable', 'integer', 'min:0'],
            'line_items.*.tire_brand'   => ['nullable', 'string', 'max:80'],
            'line_items.*.tire_dot'     => ['nullable', 'string', 'max:40'],
            'line_items.*.tire_tread_mm'=> ['nullable', 'numeric', 'min:0'],
            'receipt_total'             => ['nullable', 'numeric', 'min:0'],
            'variance_explanation'      => ['nullable', 'string', 'max:2000'],
            'receipt_photo'             => ['nullable', 'image', 'max:8192'],
        ];
    }

    /** Narrow the validated request to the service payload (drops the file — passed separately). */
    private function payload(array $data): array
    {
        return collect($data)->except('receipt_photo')->all();
    }

    /**
     * Multipart uploads (with the receipt photo) can't nest arrays cleanly, so the client sends
     * `line_items` and `task_ids` as JSON strings. Decode them back into arrays before validation so the
     * same rules apply whether the request arrived as JSON or as multipart form-data.
     */
    private function decodeJsonArrays(Request $request): void
    {
        foreach (['line_items', 'task_ids'] as $key) {
            $value = $request->input($key);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $request->merge([$key => is_array($decoded) ? $decoded : []]);
            }
        }
    }

    /** Reload the ticket with the standard eager set so the response mirrors show(). */
    private function reload(Maintenance $ticket): Maintenance
    {
        return $ticket->fresh(MaintenanceWorkflowController::eagerWith());
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
