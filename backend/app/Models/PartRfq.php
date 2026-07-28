<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An RFQ — the procurement PROCESS header (Phase 2, blueprint §3a). It is NOT a single part: it covers
 * one or many part requirements through its {@see lines()}. Awarding happens per line, so a single RFQ
 * can be split across suppliers.
 */
class PartRfq extends Model
{
    protected $table = 'part_rfqs';

    public const STATUS_OPEN              = 'open';
    public const STATUS_PARTIALLY_AWARDED = 'partially_awarded';
    public const STATUS_AWARDED           = 'awarded';
    public const STATUS_CLOSED            = 'closed';
    public const STATUS_CANCELLED         = 'cancelled';
    public const STATUSES = [
        self::STATUS_OPEN, self::STATUS_PARTIALLY_AWARDED, self::STATUS_AWARDED,
        self::STATUS_CLOSED, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'status', 'needed_by_date', 'notes',
        'opened_by', 'opened_by_name', 'opened_at',
        'closed_by', 'closed_by_name', 'closed_at',
    ];

    protected $casts = [
        'needed_by_date' => 'date',
        'opened_at'      => 'datetime',
        'closed_at'      => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class);
    }
}
