<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "vehicle_id" => $this->vehicle_id,
            "chasis_no" => $this->chasis_no,

            "expiry_date" => $this->expiry_date?->toDateString(),
            "registration_days_left" => $this->registration_days_left,
            "valid_days" => $this->valid_days,
            "status" => $this->status,
            "fines_count" => $this->fines_count,
            "fines_amount" => $this->fines_amount,
            "mortgaged_by" => $this->mortgaged_by,
            "is_mortgaged" => $this->is_mortgaged === null ? null : (bool) $this->is_mortgaged,

            "insurance_company_id" => $this->insurance_company_id,
            "insurance_company" => $this->whenLoaded('insuranceCompany', fn () => $this->insuranceCompany?->name),
            "insurance_company_no" => $this->insurance_company_no,
            "insurance_no" => $this->insurance_no,
            "insurance_issue_date" => $this->insurance_issue_date?->toDateString(),
            "insurance_expiry" => $this->insurance_expiry?->toDateString(),
            "insurance_type" => $this->insurance_type,
            "insurance_bear_amount" => $this->insurance_bear_amount,
            "insurance_days_left" => $this->insurance_days_left,

            "origin" => $this->origin,
            "vehicle" => VehicleResource::make($this->whenLoaded('vehicle')),
        ];
    }
}
