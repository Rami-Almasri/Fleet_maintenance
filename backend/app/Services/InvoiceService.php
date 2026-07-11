<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / delete MANUAL invoices (origin = 'manual') straight from the website —
 * the platform, not OfficeManager, owning the charges from now on.
 *
 * A manual invoice carries no OM invoice_no (OM owns that integer sequence); it is
 * identified by a website ref like "M-0001" so the import can never collide with it.
 * The VAT math mirrors OfficeManager's: TotalAfterVat = (TotalValue − Discount) + VAT.
 */
class InvoiceService
{
    /** UAE standard VAT, used when the caller doesn't override it. */
    public const VAT_DEFAULT = 5.0;

    /**
     * Next website invoice number, e.g. "M-0001". A single running sequence across all
     * manual invoices — unique and obviously FleetView-originated (mirrors W- contracts).
     */
    public function nextRef(): string
    {
        $prefix = 'M-';
        $max = (int) Invoice::where('invoice_ref', 'like', $prefix.'%')
            ->pluck('invoice_ref')
            ->map(fn ($r) => (int) preg_replace('/\D/', '', (string) $r))
            ->max();

        return $prefix.str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    public function store(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $contract = Contract::with('vehicle')->findOrFail($data['contract_id']);

            $payload = $this->compute($data, $contract) + [
                'invoice_ref' => $this->nextRef(),
                'invoice_no'  => null,        // OM owns the integer sequence — never borrow one
                'origin'      => 'manual',
                'synced_at'   => null,
            ];

            $invoice = Invoice::create($payload);
            $this->syncItems($invoice, $data['items'] ?? []);

            return $invoice->load(['contract', 'items', 'vendor']);
        });
    }

    public function update(array $data, Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($data, $invoice) {
            $contract = $invoice->contract ?: Contract::with('vehicle')->find($invoice->contract_id);
            // ref / no / origin are immutable — only the figures, context and service items change.
            $invoice->update($this->compute($data, $contract));

            // Only re-sync the Service Log lines when the caller actually sent them.
            if (array_key_exists('items', $data)) {
                $this->syncItems($invoice, $data['items'] ?? []);
            }

            return $invoice->refresh()->load(['contract', 'items', 'vendor']);
        });
    }

    public function destroy(Invoice $invoice): void
    {
        $invoice->delete();
    }

    /**
     * Replace the invoice's Service Log lines with the given set (delete-and-recreate — simple and
     * always consistent). Blank descriptions are skipped; order is preserved via `sequence`.
     *
     * @param array<int,array{description?:?string, category_key?:?string}> $items
     */
    protected function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        $rows = [];
        $seq  = 0;
        foreach ($items as $it) {
            $desc = trim((string) ($it['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $rows[] = [
                'description'  => $desc,
                'category_key' => isset($it['category_key']) && $it['category_key'] !== '' ? (string) $it['category_key'] : null,
                'sequence'     => $seq++,
            ];
        }

        if ($rows) {
            $invoice->items()->createMany($rows);
        }
    }

    /**
     * Resolve the stored money fields from the form input + the parent contract.
     * Recomputes VAT and the totals so the row is always internally consistent.
     */
    protected function compute(array $data, ?Contract $contract): array
    {
        $value    = round((float) ($data['total_value'] ?? 0), 2);   // before VAT
        $discount = round((float) ($data['discount'] ?? 0), 2);
        $vatPct   = ($data['vat_percentage'] ?? '') !== '' ? (float) $data['vat_percentage'] : self::VAT_DEFAULT;

        $afterDiscount = round($value - $discount, 2);
        $vat           = round($afterDiscount * $vatPct / 100, 2);
        $afterVat      = round($afterDiscount + $vat, 2);

        return [
            'contract_id'          => $contract?->id,
            'customer_id'          => $contract?->customer_id,
            // Service Log anchors: the car (denormalised from the contract) + the garage that did it.
            'vehicle_id'           => $contract?->vehicle_id ?? $contract?->vehicle?->id,
            'vendor_id'            => ($data['vendor_id'] ?? '') !== '' ? (int) $data['vendor_id'] : null,
            'contract_serial'      => $contract?->contract_serial,
            'car_no'               => $contract?->vehicle?->plate_no ?? $contract?->vehicle?->vin,
            'invoice_date'         => $data['invoice_date'] ?? null,
            'total_value'          => $value,
            'discount'             => $discount,
            'total_after_discount' => $afterDiscount,
            'vat_value'            => $vat,
            'total_after_vat'      => $afterVat,
            'period_from'          => $data['period_from'] ?? null,
            'period_to'            => $data['period_to'] ?? null,
            'rent_days'            => ($data['rent_days'] ?? '') !== '' ? (int) $data['rent_days'] : null,
            'net_rate'             => ($data['net_rate'] ?? '') !== '' ? round((float) $data['net_rate'], 2) : null,
            'notes'                => $data['notes'] ?? null,
        ];
    }
}
