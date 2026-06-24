<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * CRUD for payments / receipts — the collection side of a contract, recorded on the
 * website. Every payment is anchored to a contract; the customer is inherited from it.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $service)
    {
    }

    /**
     * Payments, optionally scoped by ?contract_id= / ?customer_id= and a free-text
     * ?search= over the receipt ref, reference, method and customer name. Newest first.
     */
    public function index(Request $request)
    {
        try {
            $payments = Payment::query()
                ->with(['contract:id,contract_no,contract_type', 'customer:id,name_en,customer_no', 'invoice:id,invoice_ref,invoice_no'])
                ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->input('contract_id')))
                ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
                ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
                ->when($request->filled('search'), function ($q, $s) use ($request) {
                    $s = $request->input('search');
                    $q->where(function ($w) use ($s) {
                        $w->where('payment_ref', 'like', "%{$s}%")
                            ->orWhere('reference', 'like', "%{$s}%")
                            ->orWhere('method', 'like', "%{$s}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', "%{$s}%"))
                            ->orWhereHas('contract', fn ($c) => $c->where('contract_no', 'like', "%{$s}%"));
                    });
                })
                ->orderByRaw('paid_on IS NULL, paid_on DESC')
                ->orderByDesc('id')
                ->paginate(50);

            return ResponseHelper::SuccessResponse([
                'items'     => PaymentResource::collection($payments),
                'total'     => $payments->total(),
                'page'      => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ], 'Payments retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function store(StorePaymentRequest $request)
    {
        try {
            $data = $request->validated();
            $data['recorded_by'] = $request->user()?->name;
            $payment = $this->service->store($data);

            return ResponseHelper::SuccessResponse(PaymentResource::make($payment), 'Payment recorded successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function show(Payment $payment)
    {
        try {
            return ResponseHelper::SuccessResponse(
                PaymentResource::make($payment->load(['contract', 'invoice', 'customer'])),
                'Payment retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function update(UpdatePaymentRequest $request, Payment $payment)
    {
        try {
            $payment = $this->service->update($request->validated(), $payment);

            return ResponseHelper::SuccessResponse(PaymentResource::make($payment), 'Payment updated successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function destroy(Payment $payment)
    {
        try {
            $this->service->destroy($payment);

            return ResponseHelper::SuccessResponse(null, 'Payment deleted successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
