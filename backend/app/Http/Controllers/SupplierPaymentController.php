<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\PaymentAllocation;
use App\Models\SupplierPayment;
use App\Services\ProcurementReportService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supplier payments and the procurement reports built on them.
 *
 * Recording money leaving the account is a money action → maintenance.manage. Reading the reports sits
 * with maintenance.view, because "what do we owe" is a question the whole operation needs answered, not
 * only the people who can pay.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(
        private SupplierPaymentService $payments,
        private ProcurementReportService $reports,
    ) {}

    /** The payment ledger. */
    public function index(Request $request)
    {
        return $this->run(function () use ($request) {
            $q = SupplierPayment::with(['vendor:id,name', 'allocations'])->latest('payment_date');

            if ($vendor = $request->query('vendor_id')) {
                $q->where('vendor_id', $vendor);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($from = $request->query('from')) {
                $q->whereDate('payment_date', '>=', $from);
            }
            if ($to = $request->query('to')) {
                $q->whereDate('payment_date', '<=', $to);
            }
            if ($request->boolean('unallocated_only')) {
                // Money sent ahead of the paperwork — the queue finance chases.
                $q->whereRaw('amount > (select coalesce(sum(amount), 0) from payment_allocations
                              where payment_allocations.supplier_payment_id = supplier_payments.id)');
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'payments' => $rows->getCollection()->map(fn (SupplierPayment $p) => $this->present($p))->all(),
                'meta'     => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Payments retrieved');
        });
    }

    /** Record a payment and point it at the bills it settles (multipart: may carry transfer evidence). */
    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $this->decodeJsonArrays($request);
            $data = $request->validate([
                'vendor_id'    => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'payee_name'   => ['nullable', 'string', 'max:255'],
                'payment_date' => ['nullable', 'date'],
                'amount'       => ['required', 'numeric', 'gt:0'],
                'method'       => ['required', Rule::in(SupplierPayment::METHODS)],
                'reference'    => ['nullable', 'string', 'max:120'],
                'notes'        => ['nullable', 'string', 'max:2000'],
                'allocations'  => ['nullable', 'array'],
                'allocations.*.document_type' => ['required_with:allocations', Rule::in(PaymentAllocation::DOCUMENT_TYPES)],
                'allocations.*.document_id'   => ['required_with:allocations', 'integer'],
                'allocations.*.amount'        => ['nullable', 'numeric', 'gt:0'],
                'photo'        => ['nullable', 'image', 'max:8192'],
            ]);

            $payment = $this->payments->record(
                collect($data)->except('photo')->all(),
                $request->user(),
                $request->file('photo'),
            );

            return ResponseHelper::SuccessResponse($this->present($payment), 'Payment recorded', 201);
        });
    }

    /** Re-point a payment at the bills it covers (used when money was paid on account). */
    public function allocate(Request $request, SupplierPayment $supplierPayment)
    {
        return $this->run(function () use ($request, $supplierPayment) {
            $this->decodeJsonArrays($request);
            $data = $request->validate([
                'allocations'  => ['present', 'array'],
                'allocations.*.document_type' => ['required', Rule::in(PaymentAllocation::DOCUMENT_TYPES)],
                'allocations.*.document_id'   => ['required', 'integer'],
                'allocations.*.amount'        => ['nullable', 'numeric', 'gt:0'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->present($this->payments->allocate($supplierPayment, $data['allocations'], $request->user())),
                'Payment allocated',
            );
        });
    }

    /** Void a payment — the record stays, the debt comes back. */
    public function cancel(Request $request, SupplierPayment $supplierPayment)
    {
        return $this->run(function () use ($request, $supplierPayment) {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                $this->present($this->payments->cancel($supplierPayment, $request->user(), $data['reason'])),
                'Payment cancelled',
            );
        });
    }

    // ── Reports ──────────────────────────────────────────────────────────────────────────────────────

    public function overview(Request $request)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->reports->overview($request->query('from'), $request->query('to')),
            'Procurement overview retrieved',
        ));
    }

    public function payables()
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse($this->reports->payables(), 'Payables retrieved'));
    }

    public function suppliers(Request $request)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->reports->supplierPerformance($request->query('from'), $request->query('to')),
            'Supplier performance retrieved',
        ));
    }

    public function paymentsReport(Request $request)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->reports->paymentsMade($request->query('from'), $request->query('to')),
            'Payments report retrieved',
        ));
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    private function present(SupplierPayment $p): array
    {
        $p->loadMissing(['vendor:id,name', 'allocations']);

        return [
            'id'           => $p->id,
            'payee'        => $p->payeeLabel(),
            'vendor_id'    => $p->vendor_id,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'amount'       => (float) $p->amount,
            'currency'     => $p->currency,
            'method'       => $p->method,
            'method_label' => $p->methodLabel(),
            'reference'    => $p->reference,
            'notes'        => $p->notes,
            'photo_url'    => $p->photoUrl(),
            'status'       => $p->status,
            'cancelled_at' => optional($p->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $p->cancellation_reason,
            'allocated'    => $p->allocatedTotal(),
            'unallocated'  => $p->unallocatedTotal(),
            'allocations'  => $p->allocations->map(fn (PaymentAllocation $a) => [
                'id'            => $a->id,
                'document_type' => $a->document_type,
                'document_id'   => $a->document_id,
                'amount'        => (float) $a->amount,
            ])->all(),
            'recorded_by'  => $p->recorded_by_name,
            'recorded_at'  => optional($p->recorded_at)->toIso8601String(),
        ];
    }

    /** Multipart can't nest arrays, so the client sends `allocations` as a JSON string. */
    private function decodeJsonArrays(Request $request): void
    {
        $value = $request->input('allocations');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $request->merge(['allocations' => is_array($decoded) ? $decoded : []]);
        }
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
