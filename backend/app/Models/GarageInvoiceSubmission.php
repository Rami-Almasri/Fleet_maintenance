<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One garage-submitted invoice awaiting (or through) the team's audit — see the Garage Invoice Portal.
 * It holds a self-contained SNAPSHOT of what the garage keyed on the tokenised public page, so the review
 * shows exactly what they sent. Nothing here touches the ticket's real cost until it is accepted, at which
 * point the snapshot is pushed through the normal line-items pipeline (MaintenanceWorkflowService::syncLineItems).
 */
class GarageInvoiceSubmission extends Model
{
    use HasFactory;

    /** Link issued, garage has not submitted yet. */
    public const STATUS_PENDING   = 'pending';
    /** Garage submitted — the ticket is now "Awaiting Audit". */
    public const STATUS_SUBMITTED = 'submitted';
    /** Team accepted — the lines were applied to the ticket. */
    public const STATUS_ACCEPTED  = 'accepted';
    /** Team rejected the submission. */
    public const STATUS_REJECTED  = 'rejected';
    /** Superseded by a newly issued link, or manually revoked. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'maintenance_id',
        'vendor_id',
        'token',
        'expires_at',
        'status',
        'line_items',
        'parts_total',
        'labor_total',
        'itemized_total',
        'receipt_total',
        'variance',
        'variance_explanation',
        'garage_note',
        'receipt_photo_disk',
        'receipt_photo_key',
        'created_by',
        'submitted_at',
        'submitted_ip',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'expires_at'     => 'datetime',
        'submitted_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'line_items'     => 'array',
        'parts_total'    => 'decimal:2',
        'labor_total'    => 'decimal:2',
        'itemized_total' => 'decimal:2',
        'receipt_total'  => 'decimal:2',
        'variance'       => 'decimal:2',
    ];

    // The token is a secret — never expose it through array/JSON serialisation of the model.
    protected $hidden = ['token'];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The garage this link is scoped to (NULL = a legacy whole-ticket link covering every fault). */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The link can still be opened/submitted: issued, not yet used, not past its expiry. */
    public function isOpenForSubmission(): bool
    {
        return $this->status === self::STATUS_PENDING
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** A viewable URL for the uploaded receipt photo (temporary for S3, public URL otherwise). */
    public function receiptPhotoUrl(): ?string
    {
        if (! $this->receipt_photo_key) {
            return null;
        }
        $disk = Storage::disk($this->receipt_photo_disk ?: 'public');

        try {
            // S3 supports signed temporary URLs; the local/public disk exposes a plain URL.
            return ($this->receipt_photo_disk === 's3')
                ? $disk->temporaryUrl($this->receipt_photo_key, Carbon::now()->addMinutes(30))
                : $disk->url($this->receipt_photo_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
