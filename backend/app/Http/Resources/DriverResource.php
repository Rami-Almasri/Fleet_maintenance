<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
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
            "user_id" => $this->user_id,
            "name" => $this->name,
            "license_no" => $this->license_no,
            "license_expiry" => $this->license_expiry,
            "phone" => $this->phone,
            "status" => $this->status,
            "origin" => $this->origin,
            "user" => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
