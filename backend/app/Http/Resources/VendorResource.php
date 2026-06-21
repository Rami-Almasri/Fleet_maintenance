<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "type" => $this->type,
            "phone" => $this->phone,
            "email" => $this->email,
            "rating" => $this->rating,
            "notes" => $this->notes,
            "active" => $this->active,
            "insured_vehicles_count" => $this->whenCounted('registrations'),
            "origin" => $this->origin,
        ];
    }
}
