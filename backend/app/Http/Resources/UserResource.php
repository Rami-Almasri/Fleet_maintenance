<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class UserResource extends JsonResource
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
            "email" => $this->email,
            "status" => $this->status,
            "roles" => $this->getRoleNames(),
            // super-admin / admin bypass checks server-side (Gate::before), so hand
            // the frontend the full permission set to mirror that everywhere.
            "permissions" => $this->hasAnyRole(['super-admin', 'admin'])
                ? Permission::pluck('name')
                : $this->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
