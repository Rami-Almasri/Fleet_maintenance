<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a complaint's timeline. Immutable once written — the append-only record of what happened
 * at each triage step. See [[complaint-entity]].
 */
class ComplaintEvent extends Model
{
    protected $table = 'complaint_events';

    // Timeline vocabulary (shared with the frontend timeline glyphs).
    public const TYPE_CREATED             = 'created';
    public const TYPE_NOTIFIED            = 'notified';
    public const TYPE_CONTACTED           = 'contacted';
    public const TYPE_DECISION            = 'decision';
    public const TYPE_INSPECTION_REQUESTED= 'inspection_requested';
    public const TYPE_MAINTENANCE_OPENED  = 'maintenance_opened';
    public const TYPE_RESOLVED            = 'resolved';
    public const TYPE_CLOSED              = 'closed';
    public const TYPE_NOTE                = 'note';

    protected $fillable = [
        'complaint_id', 'event_type', 'notes', 'meta', 'created_by', 'created_by_name',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
