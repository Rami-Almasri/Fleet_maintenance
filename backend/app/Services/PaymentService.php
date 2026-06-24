<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / delete payments (receipts) recorded on the website — the collection
 * side the platform never had. Every payment is anchored to a contract; the customer is
 * inherited from it, and the invoice link is optional.
 */
class PaymentService
{
    /** Next website receipt number, e.g. "P-0001". */
    public function nextRef(): string
    {
        $prefix = 'P-';
        $max = (int) Payment::where('payment_ref', 'like', $prefix.'%')
            ->pluck('payment_ref')
            ->map(fn ($r) => (int) preg_replace('/\D/', '', (string) $r))
            ->max();

        return $prefix.str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    public function store(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $contract = Contract::findOrFail($data['contract_id']);

            $payment = Payment::create([
                'payment_ref' => $this->nextRef(),
                'contract_id' => $contract->id,
                'invoice_id'  => $data['invoice_id'] ?? null,
                'customer_id' => $contract->customer_id,
                'amount'      => round((float) ($data['amount'] ?? 0), 2),
                'paid_on'     => $data['paid_on'] ?? null,
                'method'      => $data['method'] ?? null,
                'reference'   => $data['reference'] ?? null,
                'notes'       => $data['notes'] ?? null,
                'recorded_by' => $data['recorded_by'] ?? null,
                'origin'      => 'manual',
            ]);

            return $payment->load(['contract', 'invoice', 'customer']);
        });
    }

    /** Partial update — only the keys actually sent are touched. */
    public function update(array $data, Payment $payment): Payment
    {
        foreach (['invoice_id', 'amount', 'paid_on', 'method', 'reference', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $payment->{$key} = $key === 'amount' ? round((float) $data[$key], 2) : ($data[$key] === '' ? null : $data[$key]);
            }
        }
        $payment->save();

        return $payment->refresh()->load(['contract', 'invoice', 'customer']);
    }

    public function destroy(Payment $payment): void
    {
        $payment->delete();
    }
}
