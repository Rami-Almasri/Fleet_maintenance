<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE VERSION of the accident ladder.
 *
 * The office edits a DRAFT and publishes it; publishing mints the next version and archives the one
 * before. A case is pinned to the version it was born on and stays there, so reordering the process
 * for tomorrow's crashes cannot move a case that is halfway through today's.
 *
 * @see the create_accident_workflow_tables migration for why versions rather than one editable list.
 */
class AccidentWorkflow extends Model
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = ['version', 'name', 'status', 'notes'];

    protected $casts = ['published_at' => 'datetime', 'version' => 'integer'];

    /** The rungs, in the order a case climbs them. */
    public function stages(): HasMany
    {
        return $this->hasMany(AccidentWorkflowStage::class, 'workflow_id')->orderBy('position');
    }

    /** Only the rungs a case can actually land on. A disabled stage is skipped, never assigned. */
    public function enabledStages(): HasMany
    {
        return $this->stages()->where('is_enabled', true);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AccidentCase::class, 'workflow_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Is this version still carrying live work? A version that owns cases can never be deleted — the
     * cases would lose the only description of the process they are running.
     */
    public function hasCases(): bool
    {
        return $this->cases()->exists();
    }

    /** The stage a new case is born on. */
    public function initialStage(): ?AccidentWorkflowStage
    {
        return $this->stages->firstWhere('is_initial', true)
            ?? $this->stages->where('is_enabled', true)->first();
    }
}
