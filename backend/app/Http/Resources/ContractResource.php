<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "contract_no" => $this->contract_no,
            "contract_type" => $this->contract_type,
            "category" => $this->category,
            "state" => $this->state,
            "vehicle_id" => $this->vehicle_id,
            "customer_id" => $this->customer_id,

            // Exchange chaining (car swaps) — see ContractExchangeService
            "parent_contract_id" => $this->parent_contract_id,
            "carried_balance" => $this->carried_balance,
            "exchange_linked_at" => optional($this->exchange_linked_at)->toDateString(),
            "exchange_linked_by" => $this->exchange_linked_by,

            // maintenance (contract_type = 'U') — header lives on the `maintenances` table
            "vendor_id" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->vendor_id),
            "maintenance_tags" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->maintenance_tags ?? []),
            "responsible" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->responsible),
            "approved_by" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->approved_by),
            "expected_return_date" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->expected_return_date),
            "maintenance_notes" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->maintenance_notes),
            "vendor" => $this->whenLoaded('maintenance', fn () => $this->maintenance?->vendor
                ? ["id" => $this->maintenance->vendor->id, "name" => $this->maintenance->vendor->name]
                : null),
            // Resolved garage from the live workshop log (where the car is now / was last).
            // Only set on the single-contract show endpoint; absent (null) in list views.
            "current_garage" => $this->current_garage ?? null,
            "items" => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                "id" => $i->id,
                "service_name" => $i->service_name,
                "cost" => $i->cost,
                "notes" => $i->notes,
            ])->values()),
            "maintenance_total" => $this->whenLoaded('items', fn () => round((float) $this->items->sum('cost'), 2)),
            // invoices (charges) — OfficeManager-synced (origin 'api') + website-created
            // (origin 'manual'). Manual ones are editable in place; api ones are read-only.
            "invoices" => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($i) => [
                "id"              => $i->id,
                "invoice_no"      => $i->invoice_no,
                "invoice_ref"     => $i->invoice_ref,
                "number"          => $i->invoice_ref ?: ($i->invoice_no ? '#'.$i->invoice_no : null),
                "origin"          => $i->origin,
                "editable"        => $i->origin === 'manual',
                "date"            => optional($i->invoice_date)->toDateString(),
                "rent_days"       => $i->rent_days,
                "net_rate"        => $i->net_rate,
                "total_value"     => $i->total_value,
                "vat_value"       => $i->vat_value,
                "discount"        => $i->discount,
                "total_after_vat" => $i->total_after_vat,
                "period_from"     => optional($i->period_from)->toDateString(),
                "period_to"       => optional($i->period_to)->toDateString(),
                "notes"           => $i->notes,
            ])->values()),
            "invoices_total" => $this->whenLoaded('invoices', fn () => round((float) $this->invoices->sum('total_after_vat'), 2)),
            // Sum of discounts baked into the contract's invoices — surfaced so the Financials
            // card can show a "Discount applied" line and the billed math reads cleanly.
            "invoices_discount" => $this->whenLoaded('invoices', fn () => round((float) $this->invoices->sum('discount'), 2)),

            // payments / receipts (the collection side), website-recorded
            "payments" => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                "id"          => $p->id,
                "payment_ref" => $p->payment_ref,
                "invoice_id"  => $p->invoice_id,
                "invoice_ref" => optional($p->invoice)->invoice_ref ?: (optional($p->invoice)->invoice_no ? '#'.$p->invoice->invoice_no : null),
                "amount"      => $p->amount,
                "paid_on"     => optional($p->paid_on)->toDateString(),
                "method"      => $p->method,
                "reference"   => $p->reference,
                "notes"       => $p->notes,
                "recorded_by" => $p->recorded_by,
                "editable"    => $p->origin === 'manual',
            ])->values()),
            "payments_total" => $this->whenLoaded('payments', fn () => round((float) $this->payments->sum('amount'), 2)),

            // Native account summary — the website-controlled balance going forward:
            //   billed (sum of every invoice, after VAT) − paid (sum of every payment).
            // Surfaced only on the detail view (both relations loaded).
            "billed_total" => $this->whenLoaded('invoices', fn () => round((float) $this->invoices->sum('total_after_vat'), 2)),
            "outstanding_balance" => $this->when(
                $this->relationLoaded('invoices') && $this->relationLoaded('payments'),
                fn () => round((float) $this->invoices->sum('total_after_vat') - (float) $this->payments->sum('amount'), 2)
            ),

            "day_price" => $this->day_price,
            "week_price" => $this->week_price,
            "month_price" => $this->month_price,
            "hour_price" => $this->hour_price,
            "year_price" => $this->year_price,

            "out_date" => $this->out_date,
            "out_time" => $this->out_time,
            "out_milage" => $this->out_milage,
            "out_fuel" => $this->out_fuel,
            "opened_by" => $this->opened_by,

            "in_date" => $this->in_date,
            "in_time" => $this->in_time,
            "in_milage" => $this->in_milage,
            "in_fuel" => $this->in_fuel,
            "closed_by" => $this->closed_by,

            "days" => $this->days,
            "km" => $this->km,

            "rents_debit" => $this->rents_debit,
            "breachs_debit" => $this->breachs_debit,
            "salik_debit" => $this->salik_debit,
            "damages_debit" => $this->damages_debit,
            "extra_charges_debit" => $this->extra_charges_debit,
            "co_driver_debit" => $this->co_driver_debit,
            "km_debit" => $this->km_debit,
            "fuel_debit" => $this->fuel_debit,
            "gps_debit" => $this->gps_debit,
            "cdw_debit" => $this->cdw_debit,
            "extra_driver_debit" => $this->extra_driver_debit,
            "vat_debit" => $this->vat_debit,
            "deposit_debit" => $this->deposit_debit,

            "rents_credit" => $this->rents_credit,
            "breachs_credit" => $this->breachs_credit,
            "salik_credit" => $this->salik_credit,
            "damages_credit" => $this->damages_credit,
            "extra_charges_credit" => $this->extra_charges_credit,
            "co_driver_credit" => $this->co_driver_credit,
            "km_credit" => $this->km_credit,
            "fuel_credit" => $this->fuel_credit,
            "gps_credit" => $this->gps_credit,
            "cdw_credit" => $this->cdw_credit,
            "extra_driver_credit" => $this->extra_driver_credit,
            "vat_credit" => $this->vat_credit,
            "deposit_credit" => $this->deposit_credit,

            "cardoo_debit" => $this->cardoo_debit,
            "cardoo_credit" => $this->cardoo_credit,
            "cardoo_deposit" => $this->cardoo_deposit,

            "contract_debit" => $this->contract_debit,
            "contract_credit" => $this->contract_credit,
            "contract_balance" => $this->contract_balance,
            "contract_refunds" => $this->contract_refunds,
            "contract_discount" => $this->contract_discount,
            "contract_bad_debts" => $this->contract_bad_debts,
            "contract_deposit" => $this->contract_deposit,
            "contract_commissions" => $this->contract_commissions,
            "contract_income" => $this->contract_income,

            "miles_allowed_pd" => $this->miles_allowed_pd,
            "miles_allowed_pm" => $this->miles_allowed_pm,
            "extra_mile_charge" => $this->extra_mile_charge,
            "cdw_rate" => $this->cdw_rate,
            "pai_rate" => $this->pai_rate,
            "authorization_amount" => $this->authorization_amount,
            "insurance_type" => $this->insurance_type,
            "trip_direction" => $this->trip_direction,
            "under_claim" => $this->under_claim,
            "guarantor_no" => $this->guarantor_no,

            "contract_status_no" => $this->contract_status_no,
            "reference" => $this->reference,
            "contract_serial" => $this->contract_serial,
            "driver2" => $this->driver2,
            "driver3" => $this->driver3,
            "driver_out" => $this->driver_out,
            "driver_in" => $this->driver_in,
            "co_driver_cost" => $this->co_driver_cost,
            "extra_driver_charge" => $this->extra_driver_charge,
            "gps_charge" => $this->gps_charge,
            "fuel_charge" => $this->fuel_charge,
            "ra_vat_percentage" => $this->ra_vat_percentage,
            "salesman_commission_no1" => $this->salesman_commission_no1,
            "salesman_commission_value1" => $this->salesman_commission_value1,
            "salesman_commission_no2" => $this->salesman_commission_no2,
            "salesman_commission_value2" => $this->salesman_commission_value2,
            "tax_inclusive" => $this->tax_inclusive,
            "cdw_on_contract" => $this->cdw_on_contract,
            "credit_card_no" => $this->credit_card_no,
            "credit_card_expiry" => $this->credit_card_expiry,
            "authorization_date" => $this->authorization_date,
            "out_date_hijri" => $this->out_date_hijri,
            "in_date_hijri" => $this->in_date_hijri,

            "remarks" => $this->remarks,
            "sales_man1" => $this->sales_man1,
            "sales_man2" => $this->sales_man2,
            "source" => $this->source,
            "origin" => $this->origin,

            "customer" => CustomerResource::make($this->whenLoaded('customer')),
            "vehicle" => VehicleResource::make($this->whenLoaded('vehicle')),
        ];
    }
}
