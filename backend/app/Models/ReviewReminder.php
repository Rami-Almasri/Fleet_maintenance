<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's "come back to me about this request at this time", on the Inspection Review Queue.
 *
 * It is deliberately NOT a fact about the car: nothing here can be re-derived tomorrow from the fleet's
 * state, which is exactly why it is stored rather than scanned for. `review-reminders:dispatch` reads
 * `status = pending AND remind_at <= now` and pushes the notification.
 *
 * See the create_review_reminders_table migration for the two kinds and the cancellation rules.
 */
class ReviewReminder extends Model
{
    protected $table = 'review_reminders';

    /** The request is still awaiting review — cancelled automatically once it is approved or rejected. */
    public const KIND_PENDING_REVIEW = 'pending_review';
    /** The request was rejected and the reviewer asked to revisit it — survives the rejection. */
    public const KIND_REJECTED_REVISIT = 'rejected_revisit';

    public const KINDS = [self::KIND_PENDING_REVIEW, self::KIND_REJECTED_REVISIT];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    /** Why a reminder stopped mattering before it fired. */
    public const CANCELLED_BY_USER  = 'by_user';
    public const CANCELLED_REVIEWED = 'reviewed';    // someone approved/rejected the request
    public const CANCELLED_GONE     = 'ticket_gone'; // the ticket was deleted underneath it

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'user_id', 'kind',
        'remind_at', 'note', 'status', 'sent_at', 'cancelled_at', 'cancelled_reason',
    ];

    protected $casts = [
        'remind_at'    => 'datetime',
        'sent_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Still waiting to fire. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Pending AND the moment has arrived — the dispatcher's whole working set. */
    public function scopeDue(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query->pending()->where('remind_at', '<=', $at ?: now());
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** The person who asked to be reminded. Nobody else receives this. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
