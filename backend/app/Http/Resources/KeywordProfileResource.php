<?php

namespace App\Http\Resources;

use App\Models\FindingKeyword;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a fault concept's engineering profile.
 *
 * `severity_disagrees` is the one derived flag: true when the model's independent severity read
 * differs from the admin's risk grade. It is surfaced, never acted on — the admin owns the grade
 * (see [[KeywordProfile]]).
 */
class KeywordProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $estimateMeta = $this->severity_estimate
            ? FindingKeyword::riskMeta($this->severity_estimate)
            : null;

        return [
            'vehicle_system'    => $this->vehicle_system,
            'subsystem'         => $this->subsystem,
            'repair_discipline' => $this->repair_discipline,

            'severity_estimate' => $this->severity_estimate,
            'severity_label'    => $estimateMeta['label'] ?? null,
            'severity_tone'     => $estimateMeta['tone'] ?? null,
            'severity_emoji'    => $estimateMeta['emoji'] ?? null,
            'severity_disagrees'=> $this->disagreesWithRisk(),

            'summary_en'     => $this->summary_en,
            'summary_ar'     => $this->summary_ar,

            'symptoms'       => $this->symptoms ?? [],
            'components'     => $this->components ?? [],
            'likely_causes'  => $this->likely_causes ?? [],
            'repair_actions' => $this->repair_actions ?? [],
            'related_faults' => $this->related_faults ?? [],

            // --- Repair intelligence: what dealing with this fault actually takes ---
            // Book time and fleet actuals are kept apart on purpose: one is what documentation
            // says the job takes, the other is how long it has really tied our cars up. The gap
            // between them is itself a finding, so they are never averaged together.
            'complexity'           => $this->complexity,
            'labor_hours_min'      => $this->labor_hours_min !== null ? (float) $this->labor_hours_min : null,
            'labor_hours_max'      => $this->labor_hours_max !== null ? (float) $this->labor_hours_max : null,
            'fleet_labor_hours'    => $this->fleet_labor_hours_avg !== null ? (float) $this->fleet_labor_hours_avg : null,
            'fleet_case_count'     => (int) $this->fleet_case_count,
            'required_tools'       => $this->required_tools ?? [],
            'required_skills'      => $this->required_skills ?? [],
            'inspection_order'     => $this->inspection_order ?? [],

            // Money follows the app-wide rule: shipped, and hidden by the frontend's
            // SHOW_FINANCIALS flag like every other cost surface ([[financial-decoupling-flag]]).
            'cost_min'      => $this->cost_min !== null ? (float) $this->cost_min : null,
            'cost_max'      => $this->cost_max !== null ? (float) $this->cost_max : null,
            'cost_currency' => $this->cost_currency,

            // Vehicle scope of this profile: null = the universal entry, otherwise an override.
            'scope_key'   => $this->scope_key,
            'scope_label' => \App\Support\VehicleScope::label($this->scope_key),

            'evidence_sources' => $this->evidence_sources ?? [],
            'confidence'       => (int) $this->confidence,
            // How much of this entry rests on retrieved documentation vs the model's own prior.
            'grounding_score'  => (int) $this->grounding_score,
            'model'            => $this->model,
            'enriched_at'      => $this->enriched_at,
        ];
    }
}
