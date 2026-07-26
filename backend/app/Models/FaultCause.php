<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the Symptom → Root-Cause knowledge base.
 *
 * Each row pairs a findings keyword (the "symptom") with one probable root cause. Approved rows are
 * the picker menu; user-typed rows arrive 'pending' for an admin to approve into the master list or
 * reject. The chosen cause is also stamped onto the finding (maintenances.findings JSON) so it
 * persists with the ticket and is ready to sync to Odoo. See [[maintenance-workflow-engine]] and the
 * create_fault_causes_table migration for the full design.
 */
class FaultCause extends Model
{
    /** Lifecycle states. */
    public const STATUS_APPROVED = 'approved'; // in the master list — shown in the picker
    public const STATUS_PENDING  = 'pending';  // user-submitted custom cause awaiting admin review
    public const STATUS_REJECTED = 'rejected'; // reviewed and declined — kept for audit, never shown
    public const STATUSES        = [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_REJECTED];

    /** Where the row came from. */
    public const SOURCE_SEED = 'seed'; // curated, from config/fault_causes.php
    public const SOURCE_USER = 'user'; // typed in during a diagnosis

    protected $fillable = [
        'symptom_key', 'symptom_label', 'category_key', 'fault_catalog_id',
        'root_cause', 'description',
        'status', 'source',
        'usage_count', 'odoo_ref',
        'submitted_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'usage_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Normalise a symptom keyword into its match key: lowercase + collapsed whitespace, so
     * "Engine  noise" and "engine noise" resolve to the same symptom.
     */
    public static function normalizeKey(?string $symptom): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $symptom)));
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeForSymptom(Builder $q, ?string $symptom): Builder
    {
        return $q->where('symptom_key', self::normalizeKey($symptom));
    }

    /** The Fault Catalog entry this symptom belongs to (Event Type layer; parallel to symptom_key for now). */
    public function faultCatalog(): BelongsTo
    {
        return $this->belongsTo(FaultCatalog::class, 'fault_catalog_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
