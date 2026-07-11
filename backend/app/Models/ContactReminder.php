<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A follow-up to-do tied to a garage / vendor: "Call {vendor} about {subject}".
 * See the contact_reminders migration + ContactReminderController. Can link to the
 * exact invoice and/or maintenance ticket it's about for one-click drill-through.
 */
class ContactReminder extends Model
{
    public const STATUSES = ['open', 'done', 'snoozed'];

    protected $fillable = [
        'vendor_id', 'subject', 'body', 'due_at', 'status',
        'invoice_id', 'maintenance_id',
        'created_by', 'assigned_to', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** True when an open reminder's due moment has passed. */
    public function isOverdue(): bool
    {
        return $this->status === 'open'
            && $this->due_at !== null
            && Carbon::parse($this->due_at)->isPast();
    }
}
