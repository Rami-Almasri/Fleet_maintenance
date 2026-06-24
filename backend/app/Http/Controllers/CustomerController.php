<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Customer;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\AccountingService;
use App\Services\CustomerService;

class CustomerController extends Controller
{
    private $customerService;
    public function __construct(CustomerService $customerService)
    {
        $this->customerService = $customerService;
    }
    public function index()
    {
        try {
            $customer = $this->customerService->index();
            $result = CustomerResource::collection($customer);
            return ResponseHelper::SuccessResponse($result, "Customer retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function store(StoreCustomerRequest $request)
    {
        try {
            $customer = $this->customerService->store($request->validated());
            $result = CustomerResource::make($customer);
            return ResponseHelper::SuccessResponse($result, "Customer created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function show(Customer $customer)
    {
        try {
            $customer->loadCount('contracts');
            $result = CustomerResource::make($customer);
            return ResponseHelper::SuccessResponse($result, "Customer retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Full customer profile: identity, financials, flags + a per-contract breakdown
     * (what they owe / overpaid on each contract).
     */
    public function profile(Customer $customer)
    {
        try {
            $contracts = $customer->contracts()->with('vehicle')->latest('id')->get();

            // Per-category ledger rolled up across ALL the customer's contracts: Charged vs
            // Settled vs Outstanding per OfficeManager charge category. Deposit is excluded
            // (a refundable hold OM keeps out of contract_debit/credit), so the outstanding
            // total ties exactly to the customer's Balance = Σ(contract_debit − contract_credit).
            $cats = [
                'Rent' => 'rents', 'Salik (tolls)' => 'salik', 'VAT' => 'vat',
                'Damages' => 'damages', 'Breaches' => 'breachs', 'Extra charges' => 'extra_charges',
                'Fuel' => 'fuel', 'KM' => 'km', 'GPS' => 'gps', 'CDW' => 'cdw',
                'Extra driver' => 'extra_driver', 'Co-driver' => 'co_driver',
            ];
            $ledger = [];
            foreach ($cats as $label => $key) {
                $charged = round((float) $contracts->sum($key . '_debit'), 2);
                $settled = round((float) $contracts->sum($key . '_credit'), 2);
                if ($charged == 0.0 && $settled == 0.0) {
                    continue;
                }
                $ledger[] = [
                    'label'       => $label,
                    'charged'     => $charged,
                    'settled'     => $settled,
                    'outstanding' => round($charged - $settled, 2),
                ];
            }
            $ledgerTotals = [
                'charged'     => round((float) $contracts->sum('contract_debit'), 2),
                'settled'     => round((float) $contracts->sum('contract_credit'), 2),
                'outstanding' => round((float) $contracts->sum('contract_debit') - (float) $contracts->sum('contract_credit'), 2),
            ];

            // Balancing row: OfficeManager posts some adjustments (discounts, etc.) OUTSIDE the
            // category columns, straight against the balance. Without a catch-all the category
            // rows wouldn't sum to the total. This row = Total − Σ(itemised categories), so the
            // ledger ALWAYS reconciles to the customer's Balance to the cent.
            $otherCharged = round($ledgerTotals['charged'] - array_sum(array_column($ledger, 'charged')), 2);
            $otherSettled = round($ledgerTotals['settled'] - array_sum(array_column($ledger, 'settled')), 2);
            if (abs($otherCharged) >= 0.01 || abs($otherSettled) >= 0.01) {
                $ledger[] = [
                    'label'       => 'Discounts & adjustments',
                    'charged'     => $otherCharged,
                    'settled'     => $otherSettled,
                    'outstanding' => round($otherCharged - $otherSettled, 2),
                ];
            }

            $data = [
                'customer'  => CustomerResource::make($customer->loadCount('contracts')),
                'contracts' => $contracts->map(fn ($c) => [
                    'id'            => $c->id,
                    'contract_no'   => $c->contract_no,
                    'contract_type' => $c->contract_type,
                    'state'         => $c->state,
                    'vehicle'       => $c->vehicle
                        ? trim(($c->vehicle->plate_no ? $c->vehicle->plate_no . ' · ' : '') . trim($c->vehicle->make . ' ' . $c->vehicle->model))
                        : null,
                    'vehicle_id'    => $c->vehicle_id,
                    'out_date'      => optional($c->out_date)->toDateString(),
                    'in_date'       => optional($c->in_date)->toDateString(),
                    'debit'         => $c->contract_debit,
                    'credit'        => $c->contract_credit,
                    'balance'       => $c->contract_balance,
                    'deposit'       => $c->contract_deposit,
                ])->values(),
                'stats' => [
                    'contracts_count' => $contracts->count(),
                    'open_count'      => $contracts->where('state', 'open')->whereNull('in_date')->count(),
                ],
                'category_ledger' => $ledger,
                'ledger_totals'   => $ledgerTotals,
            ];

            return ResponseHelper::SuccessResponse($data, "Customer profile retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Financial summary: total debit/credit and outstanding balance across all the
     * customer's contracts (handles customers with many contracts).
     */
    public function balance(Customer $customer, AccountingService $accounting)
    {
        try {
            $summary = $accounting->customerSummary($customer);
            $result = [
                'customer' => [
                    'id' => $customer->id,
                    'customer_no' => $customer->customer_no,
                    'name_en' => $customer->name_en,
                ],
                'summary' => $summary,
            ];
            return ResponseHelper::SuccessResponse($result, "Customer balance retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        try {
            $customer = $this->customerService->update($request->validated(), $customer);
            $result = CustomerResource::make($customer);
            return ResponseHelper::SuccessResponse($result, "Customer updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function destroy(Customer $customer)
    {
        try {
            $this->customerService->destroy($customer);
            return ResponseHelper::SuccessResponse(null, "Customer deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
