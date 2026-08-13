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
            // Two lists, two jobs. `identity_aliases` are the part's other NAMES and are trusted to
            // prove two records are the same part (so picking one warns about the earlier buy logged
            // under another name); `aliases` are symptom wording and ambiguous trade names, searched
            // but never used as evidence. The page edits them as separate fields for that reason.
            'aliases'          => $this->aliases ?? [],
            'identity_aliases' => $this->identity_aliases ?? [],
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

            // How many physical components exist of this type. Kept as the headline number because
            // "fitted to N cars" is what the page shows in its In-use column.
            'usage_count' => $this->whenCounted('components', default: 0),

            // Everything holding a restrictOnDelete key to this row, so the page can grey out Delete
            // and offer Retire BEFORE the user clicks and gets a 422. The server still refuses
            // independently — this is the courtesy, not the guard.
            'references' => [
                'fitted_components' => $this->whenCounted('components', default: 0),
                'warranties'        => $this->whenCounted('warranties', default: 0),
                'required_parts'    => $this->whenCounted('requiredParts', default: 0),
                'part_requests'     => $this->whenCounted('partRequests', default: 0),
                'purchases'         => $this->whenCounted('partPurchases', default: 0),
            ],
            'can_delete' => ($this->components_count ?? 0) === 0
                && ($this->warranties_count ?? 0) === 0
                && ($this->required_parts_count ?? 0) === 0
                && ($this->part_requests_count ?? 0) === 0
                && ($this->part_purchases_count ?? 0) === 0,

            // Provenance, so the page can show what is still shipping-default and what a human owns.
            // A user-edited row is one the seeder will never touch again — worth saying out loud.
            'edited_in_app'  => (bool) $this->edited_in_app,
            'edited_at'      => $this->edited_at?->toIso8601String(),
            'edited_by_name' => $this->edited_by_name,
        ];
    }
}
