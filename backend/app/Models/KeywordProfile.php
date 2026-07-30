<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured engineering knowledge behind one findings keyword (1:1 with [[FindingKeyword]]).
 *
 * See the create_keyword_profiles_table migration for the design. The important behavioural note:
 * `severity_estimate` is the model's independent read of how serious the fault is, on the app's one
 * severity scale, stored NEXT TO the admin's `finding_keywords.risk` rather than replacing it —
 * `disagreesWithRisk()` is what the admin screen uses to surface the conflict.
 */
class KeywordProfile extends Model
{
    protected $fillable = [
        'finding_keyword_id', 'vehicle_system', 'subsystem', 'repair_discipline',
        'severity_estimate', 'summary_en', 'summary_ar',
        'symptoms', 'components', 'likely_causes', 'repair_actions', 'related_faults',
        'evidence_sources', 'confidence', 'model', 'enriched_at',
    ];

    protected $casts = [
        'symptoms'         => 'array',
        'components'       => 'array',
        'likely_causes'    => 'array',
        'repair_actions'   => 'array',
        'related_faults'   => 'array',
        'evidence_sources' => 'array',
        // JSON columns that were missing their cast — writing an array to any of these threw
        // "Array to string conversion" at the driver, which is a confusing way to learn about a
        // missing cast. The columns were always JSON; only the model did not know it.
        'inspection_order' => 'array',
        'required_tools'   => 'array',
        'required_skills'  => 'array',
        'confidence'       => 'integer',
        'enriched_at'      => 'datetime',
    ];

    public function findingKeyword(): BelongsTo
    {
        return $this->belongsTo(FindingKeyword::class);
    }

    /**
     * True when the model's severity read differs from the admin's grade. Advisory only — nothing
     * acts on it, the admin screen just flags it so a mis-graded safety fault gets a second look.
     */
    public function disagreesWithRisk(): bool
    {
        return $this->severity_estimate
            && $this->findingKeyword
            && $this->severity_estimate !== $this->findingKeyword->risk;
    }
}
