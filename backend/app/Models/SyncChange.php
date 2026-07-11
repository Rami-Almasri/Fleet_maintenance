<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record-level change from a CMD sync: a new contract (operation=insert, full row in
 * `snapshot`) or an updated contract (operation=update, only the changed fields in `changes`
 * as {field:{old,new}}). Belongs to a SyncRun; powers the Sync Audit "news feed".
 */
class SyncChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sync_run_id', 'contract_id', 'contract_no', 'external_id',
        'operation', 'snapshot', 'changes', 'changed_count', 'created_at',
    ];

    protected $casts = [
        'snapshot'   => 'array',
        'changes'    => 'array',
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
