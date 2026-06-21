<?php

namespace App\Services;

use App\Models\Customer;

class CustomerService
{
    public function __construct()
    {
        //
    }
    public function index()
    {
        // debit/credit/balance are cached on the row (kept in sync by ContractObserver),
        // so listing customers needs no per-row aggregation.
        $customer = Customer::withCount('contracts')->get();
        return $customer;
    }
    public function store(array $data)
    {
        // A customer added on the fly from the New Contract form has no OfficeManager
        // number yet. Give it a distinct "W-" prefixed one so it's identifiable and can
        // never collide with OM's authoritative numeric customer_no sequence.
        if (empty($data['customer_no'])) {
            $data['customer_no'] = $this->nextWebCustomerNo();
        }
        $customer = Customer::create($data);
        return $customer;
    }

    /** Next "W-#####" customer number for a web-created customer (global running sequence). */
    public function nextWebCustomerNo(): string
    {
        $prefix = 'W-';
        $max = (int) Customer::where('customer_no', 'like', $prefix.'%')
            ->pluck('customer_no')
            ->map(fn ($no) => (int) preg_replace('/\D/', '', $no))
            ->max();

        return $prefix.str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }
    public function update(array $data, Customer $customer)
    {
        $customer->update($data);
        return $customer->refresh();
    }
    public function destroy(Customer $customer)
    {
        $customer->delete();
    }
}
