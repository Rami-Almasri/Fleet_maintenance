<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $fillable = [
        'action', 'phase', 'status', 'total', 'processed', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'result'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Per-field auto-corrections recorded during this run (cleared stale data). */
    public function corrections(): HasMany
    {
        return $this->hasMany(SyncCorrection::class);
    }

    /** Record-level change feed for this run: new contracts (inserts) + field diffs (updates). */
    public function changes(): HasMany
    {
        return $this->hasMany(SyncChange::class);
    }
}
