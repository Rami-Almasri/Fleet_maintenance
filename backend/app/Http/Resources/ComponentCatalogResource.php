<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the parts vocabulary, shaped for the catalog page and the part picker.
 *
 * `usage_count` is loaded with withCount('components') by the controller and decides what the page
 * is allowed to offer: a type that is fitted to cars cannot be deleted (the instances hold
 * restrictOnDelete FKs), only retired. Exposing the number rather than a bare boolean lets the page
 * say WHY — "fitted to 34 cars" is an answer; a greyed-out button is not.
 */
class ComponentCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'name'          => $this->name,
            'name_ar'       => $this->name_ar,
            'aliases'       => $this->aliases ?? [],
            'category_key'  => $this->category_key,
            'tracking_mode' => $this->tracking_mode,
            'action_target' => $this->action_target,

            'default_part_number'     => $this->default_part_number,
            'default_warranty_months' => $this->default_warranty_months,
            'default_warranty_km'     => $this->default_warranty_km,
            'expected_life_km'        => $this->expected_life_km,
            'expected_life_months'    => $this->expected_life_months,

            'position_scheme' => $this->position_scheme,
            'positions'       => $this->positionsFor(),

            'is_active' => $this->is_active,
            'notes'     => $this->notes,

            // How many physical components exist of this type. Drives delete-vs-retire on the page.
            'usage_count' => $this->whenCounted('components', default: 0),

            // Provenance, so the page can show what is still shipping-default and what a human owns.
            // A user-edited row is one the seeder will never touch again — worth saying out loud.
            'edited_in_app'  => (bool) $this->edited_in_app,
            'edited_at'      => $this->edited_at?->toIso8601String(),
            'edited_by_name' => $this->edited_by_name,
        ];
    }
}
