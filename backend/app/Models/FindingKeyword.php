<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One entry in the maintenance findings keyword library, with its baseline RISK grade.
 *
 * This is the admin-editable menu of quick-pick fault keywords (the Inspector / workshop tap them on
 * the test-drive report). config/maintenance_findings.php seeds it; this table is the runtime source
 * of truth — same config-seeds-DB pattern as [[FaultCause]]. The `risk` column reuses the app's one
 * severity vocabulary (critical / moderate / routine, see Maintenance::FAULT_SEVERITY_META) so a
 * keyword's default risk maps straight onto a ticket's fault_severity. See the
 * create_finding_keywords_table migration for the full design.
 *
 * AI KNOWLEDGE BASE. A keyword row is no longer just a string — it is the CONCEPT at the centre of
 * an automotive ontology:
 *  - [[KeywordTerm]] (many) — every surface form a human might type: synonyms, workshop slang,
 *    abbreviations, spelling variants, misspellings, Arabic. This is what free-text search matches.
 *  - [[KeywordProfile]] (one) — the engineering metadata: system, components, causes, repairs.
 *  - [[KeywordEnrichmentRun]] (many) — the audit trail of every AI call that wrote the above.
 *
 * The keyword string itself is untouched by all of this: it remains the stable analytics key that
 * persists onto `maintenances.findings`. The ontology is the *input* surface, never the stored one.
 */
class FindingKeyword extends Model
{
    /** Baseline risk grades — the SAME scale as Maintenance::FAULT_SEVERITIES, on purpose. */
    public const RISK_CRITICAL = 'critical';
    public const RISK_MODERATE = 'moderate';
    public const RISK_ROUTINE  = 'routine';
    public const RISKS         = [self::RISK_CRITICAL, self::RISK_MODERATE, self::RISK_ROUTINE];

    /**
     * Per-risk presentation, mirroring Maintenance::FAULT_SEVERITY_META so the keyword library and the
     * ticket board read identically (🔴 red / 🟡 amber / 🟢 green). `routine` is the low / "minor" tier.
     */
    public const RISK_META = [
        self::RISK_CRITICAL => ['emoji' => '🔴', 'label' => 'Critical', 'tone' => 'red',   'rank' => 3],
        self::RISK_MODERATE => ['emoji' => '🟡', 'label' => 'Moderate', 'tone' => 'amber', 'rank' => 2],
        self::RISK_ROUTINE  => ['emoji' => '🟢', 'label' => 'Routine',  'tone' => 'green', 'rank' => 1],
    ];

    protected $fillable = [
        'category_key', 'category_label', 'category_label_ar',
        'keyword', 'keyword_ar', 'risk', 'description',
        'is_active', 'sort_order',
        // "Does this word need a place on the car?" — null = use the authored chain. Only meaningful
        // for words no fault/damage catalog row owns; see [[FaultLocationService]]::typeIndex().
        'location_mode',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Every way a human might write this fault — the searchable surface. See [[KeywordTerm]]. */
    public function terms(): HasMany
    {
        return $this->hasMany(KeywordTerm::class)->orderByDesc('search_rank');
    }

    /** The structured engineering knowledge behind the fault. See [[KeywordProfile]]. */
    public function profile(): HasOne
    {
        return $this->hasOne(KeywordProfile::class);
    }

    /**
     * The repairs that address this fault, most typical first.
     *
     * Normalised [[ActionCatalog]] rows, never free text — `relevance` is 'typical' for the couple of
     * repairs that usually fix it and 'possible' for the rest, set by position in the ontology files.
     * This is what "usually fixed by" reads on the inspector's match card.
     */
    public function repairActions(): BelongsToMany
    {
        return $this->belongsToMany(ActionCatalog::class, 'fault_concept_actions')
            ->withPivot(['relevance', 'sort_order'])
            ->orderBy('fault_concept_actions.sort_order');
    }

    /** Audit trail of AI enrichment attempts, newest first. */
    public function enrichmentRuns(): HasMany
    {
        return $this->hasMany(KeywordEnrichmentRun::class)->latest();
    }

    /**
     * This fault's entry point into the knowledge graph — the universal (unscoped) fault node.
     * Make-specific nodes hang off the same keyword but are reached through [[OntologyGraphService]],
     * which knows how to prefer the narrowest scope that applies to the vehicle being asked about.
     */
    public function ontologyNode(): HasOne
    {
        return $this->hasOne(OntologyNode::class)
            ->where('type', OntologyNode::TYPE_FAULT)
            ->where('ontology_nodes.scope_key', \App\Support\VehicleScope::UNIVERSAL);
    }

    /** Human corrections recorded against this fault — the continuous-learning signal. */
    public function feedback(): HasMany
    {
        return $this->hasMany(OntologyFeedback::class);
    }

    /** Documentation cited for this fault as a whole. */
    public function evidence(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(EvidenceLink::class, 'evidenceable');
    }

    /**
     * The canonical English string is always a term in its own right, so a search for the exact
     * keyword matches even before any AI enrichment has run. Called on keyword create/update so a
     * hand-added keyword is immediately findable.
     */
    public function syncCanonicalTerms(): void
    {
        foreach ([['term' => $this->keyword, 'lang' => 'en', 'kind' => KeywordTerm::KIND_CANONICAL],
                  ['term' => $this->keyword_ar, 'lang' => 'ar', 'kind' => KeywordTerm::KIND_TRANSLATION]] as $spec) {
            if (blank($spec['term'])) {
                continue;
            }

            $this->terms()->updateOrCreate(
                ['normalized' => \App\Support\TextNormalizer::key($spec['term'])],
                [
                    'term'               => $spec['term'],
                    'lang'               => $spec['lang'],
                    'kind'               => $spec['kind'],
                    'confidence'         => 100,
                    'source'             => KeywordTerm::SOURCE_SEED,
                    'source_quality'     => 'workshop',
                    'workshop_frequency' => 'high',
                    'is_active'          => true,
                ]
            );
        }
    }

    /** Metadata for a given risk, falling back to Moderate for anything unexpected. */
    public static function riskMeta(?string $risk): array
    {
        return self::RISK_META[$risk] ?? self::RISK_META[self::RISK_MODERATE];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForCategory(Builder $q, ?string $categoryKey): Builder
    {
        return $q->where('category_key', $categoryKey);
    }

    public function scopeWithRisk(Builder $q, ?string $risk): Builder
    {
        return $q->where('risk', $risk);
    }
}
