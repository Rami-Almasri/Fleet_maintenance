<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the append-only workforce activity log: a login, a logout, a
 * page view, or a WRITE the user performed (create | update | delete, appended
 * by the RecordUserAction middleware — endpoint, record id and result only,
 * never the request body). UserActivityService turns streams of these into sessions, durations,
 * timelines, heat-maps and module usage. There is no updated_at — rows are never
 * mutated (heartbeats bump the user's snapshot + the trailing page row's timestamp).
 */
class UserActivityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'type', 'method', 'page', 'path', 'entity', 'entity_id',
        'description', 'status_code', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
