<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the maintenance findings keyword library, with its baseline RISK grade.
 *
 * This is the admin-editable menu of quick-pick fault keywords (the Inspector / workshop tap them on
 * the test-drive report). config/maintenance_findings.php seeds it; this table is the runtime source
 * of truth — same config-seeds-DB pattern as [[FaultCause]]. The `risk` column reuses the app's one
 * severity vocabulary (critical / moderate / routine, see Maintenance::FAULT_SEVERITY_META) so a
 * keyword's default risk maps straight onto a ticket's fault_severity. See the
 * create_finding_keywords_table migration for the full design.
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
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

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
