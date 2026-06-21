<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auto-correction applied during a CMD sync: a stale field the API no longer carries
 * that we cleared (e.g. a removed in_date). Belongs to a SyncRun; powers the audit detail.
 */
class SyncCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sync_run_id', 'contract_id', 'contract_no', 'external_id',
        'field', 'old_value', 'action', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
