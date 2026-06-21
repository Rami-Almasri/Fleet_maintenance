<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contract_no' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('contracts', 'contract_no')
                    ->where(fn ($q) => $q->where('contract_type', $this->input('contract_type')))
                    ->ignore($this->route('contract')),
            ],
            'contract_type' => 'nullable|string|max:50',
            'state' => 'nullable|in:open,closed',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'customer_id' => 'nullable|exists:customers,id',

            // maintenance (contract_type = 'U')
            'vendor_id' => 'nullable|exists:vendors,id',
            'maintenance_tags' => 'nullable|array',
            'maintenance_tags.*' => 'string|max:50',
            'responsible' => 'nullable|string|max:150',
            'approved_by' => 'nullable|string|max:150',
            'expected_return_date' => 'nullable|date',
            'maintenance_notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.service_name' => 'required_with:items|string|max:200',
            'items.*.cost' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',

            'day_price' => 'nullable|numeric',
            'week_price' => 'nullable|numeric',
            'month_price' => 'nullable|numeric',
            'hour_price' => 'nullable|numeric',
            'year_price' => 'nullable|numeric',

            'out_date' => 'nullable|date',
            'out_time' => 'nullable|string|max:20',
            'out_milage' => 'nullable|integer|min:0',
            'out_fuel' => 'nullable|string|max:20',
            'opened_by' => 'nullable|string|max:100',

            'in_date' => 'nullable|date',
            'in_time' => 'nullable|string|max:20',
            'in_milage' => 'nullable|integer|min:0',
            'in_fuel' => 'nullable|string|max:20',
            'closed_by' => 'nullable|string|max:100',

            'days' => 'nullable|integer',
            'km' => 'nullable|integer',

            'rents_debit' => 'nullable|numeric',
            'breachs_debit' => 'nullable|numeric',
            'salik_debit' => 'nullable|numeric',
            'damages_debit' => 'nullable|numeric',
            'extra_charges_debit' => 'nullable|numeric',
            'co_driver_debit' => 'nullable|numeric',
            'km_debit' => 'nullable|numeric',
            'fuel_debit' => 'nullable|numeric',
            'gps_debit' => 'nullable|numeric',
            'cdw_debit' => 'nullable|numeric',
            'extra_driver_debit' => 'nullable|numeric',
            'vat_debit' => 'nullable|numeric',
            'deposit_debit' => 'nullable|numeric',

            'rents_credit' => 'nullable|numeric',
            'breachs_credit' => 'nullable|numeric',
            'salik_credit' => 'nullable|numeric',
            'damages_credit' => 'nullable|numeric',
            'extra_charges_credit' => 'nullable|numeric',
            'co_driver_credit' => 'nullable|numeric',
            'km_credit' => 'nullable|numeric',
            'fuel_credit' => 'nullable|numeric',
            'gps_credit' => 'nullable|numeric',
            'cdw_credit' => 'nullable|numeric',
            'extra_driver_credit' => 'nullable|numeric',
            'vat_credit' => 'nullable|numeric',
            'deposit_credit' => 'nullable|numeric',

            'contract_debit' => 'nullable|numeric',
            'contract_credit' => 'nullable|numeric',
            'contract_balance' => 'nullable|numeric',
            'contract_refunds' => 'nullable|numeric',
            'contract_discount' => 'nullable|numeric',
            'contract_bad_debts' => 'nullable|numeric',
            'contract_deposit' => 'nullable|numeric',
            'contract_commissions' => 'nullable|numeric',
            'contract_income' => 'nullable|numeric',

            'miles_allowed_pd' => 'nullable|numeric',
            'miles_allowed_pm' => 'nullable|numeric',
            'extra_mile_charge' => 'nullable|numeric',
            'cdw_rate' => 'nullable|numeric',
            'pai_rate' => 'nullable|numeric',
            'authorization_amount' => 'nullable|numeric',
            'insurance_type' => 'nullable|string|max:100',
            'trip_direction' => 'nullable|string|max:100',
            'under_claim' => 'nullable|boolean',
            'guarantor_no' => 'nullable|string|max:50',

            'contract_status_no' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
            'contract_serial' => 'nullable|string|max:50',
            'driver2' => 'nullable|string|max:100',
            'driver3' => 'nullable|string|max:100',
            'driver_out' => 'nullable|string|max:100',
            'driver_in' => 'nullable|string|max:100',
            'co_driver_cost' => 'nullable|numeric',
            'extra_driver_charge' => 'nullable|numeric',
            'gps_charge' => 'nullable|numeric',
            'fuel_charge' => 'nullable|numeric',
            'ra_vat_percentage' => 'nullable|numeric',
            'salesman_commission_no1' => 'nullable|string|max:50',
            'salesman_commission_value1' => 'nullable|numeric',
            'salesman_commission_no2' => 'nullable|string|max:50',
            'salesman_commission_value2' => 'nullable|numeric',
            'tax_inclusive' => 'nullable|boolean',
            'cdw_on_contract' => 'nullable|boolean',
            'credit_card_no' => 'nullable|string|max:50',
            'credit_card_expiry' => 'nullable|string|max:20',
            'authorization_date' => 'nullable|date',
            'out_date_hijri' => 'nullable|string|max:30',
            'in_date_hijri' => 'nullable|string|max:30',

            'remarks' => 'nullable|string',
            'sales_man1' => 'nullable|string|max:100',
            'sales_man2' => 'nullable|string|max:100',
            'source' => 'nullable|string|max:100',
        ];
    }
}
