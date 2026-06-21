<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "customer_no" => $this->customer_no,
            "name_en" => $this->name_en,
            "name_ar" => $this->name_ar,
            "nationality" => $this->nationality,
            "date_of_birth" => $this->date_of_birth,
            "mobile1" => $this->mobile1,
            "mobile2" => $this->mobile2,
            "whatsapp" => $this->whatsapp,
            "email" => $this->email,
            "sex" => $this->sex,
            "city" => $this->city,
            "address" => $this->address,
            "po_box" => $this->po_box,
            "passport_no" => $this->passport_no,
            "passport_expiry" => $this->passport_expiry,
            "license_no" => $this->license_no,
            "license_expiry" => $this->license_expiry,
            "id_no" => $this->id_no,
            "id_expiry" => $this->id_expiry,
            "residency_no" => $this->residency_no,
            "residency_expiry" => $this->residency_expiry,
            "traffic_file_no" => $this->traffic_file_no,
            "vat_number" => $this->vat_number,
            "makani" => $this->makani,
            "lat" => $this->lat,
            "lon" => $this->lon,
            // kept in sync with the customer's contracts by ContractObserver.
            // balance = debit - credit = outstanding amount the customer owes.
            "debit" => $this->debit,
            "credit" => $this->credit,
            "balance" => $this->balance,
            // Available wallet = money the customer paid beyond what they owe
            // (the size of a negative balance). Carries forward to the next contract.
            "available_wallet" => round(max(0, -(float) $this->balance), 2),
            "deposit" => $this->deposit,
            "contracts_count" => $this->contracts_count,
            "origin" => $this->origin,
        ];
    }
}
