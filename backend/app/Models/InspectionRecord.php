<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One Vehicle Inspection record: a condition photo (stored in S3) and/or a manual
 * damage finding for a single body zone, captured at a point in the rental
 * life-cycle (pre-rental or post-return). Powers the before/after slider and the
 * Fleet Health reports. See InspectionController + the /inspection workflow.
 */
class InspectionRecord extends Model
{
    /** Where in the rental life-cycle the shot was taken. */
    public const PHASES = ['pre', 'post'];

    /** Controlled damage classifications (the "type" prompt on flagging). */
    public const DAMAGE_TYPES = ['scratch', 'dent', 'glass_crack', 'other'];

    /** Three-level severity scale (Low / Medium / High). */
    public const SEVERITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'contract_id', 'vehicle_id', 'inspector_id', 'inspector_name',
        'inspection_session_id', 'phase', 'body_part', 'checkpoint_type',
        's3_disk', 's3_key', 'mime_type', 'file_size', 'width', 'height',
        'damage_flagged', 'damage_type', 'severity', 'note', 'captured_at',
        'reviewed_by', 'reviewed_at', 'review_outcome',
    ];

    protected $casts = [
        'damage_flagged' => 'boolean',
        'file_size'      => 'integer',
        'width'          => 'integer',
        'height'         => 'integer',
        'captured_at'    => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /**
     * A short-lived signed URL to view the photo, or null when this record is a
     * pure damage finding with no image. Never exposes the bucket publicly.
     */
    public function temporaryUrl(int $minutes = 30): ?string
    {
        if (! $this->s3_key) {
            return null;
        }
        try {
            return Storage::disk($this->s3_disk ?: 's3')
                ->temporaryUrl($this->s3_key, now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            return null; // storage not configured / object missing — degrade gracefully
        }
    }

    /**
     * A viewable URL for the photo, disk-aware: an S3 disk hands back a short-lived SIGNED url
     * (bucket stays private); the local `public` disk (the demo fallback when AWS_* isn't set)
     * hands back its plain public `/storage/...` url. Null when there's no image or storage is
     * unconfigured — so the UI degrades to a placeholder instead of a broken image.
     */
    public function viewUrl(int $minutes = 60): ?string
    {
        if (! $this->s3_key) {
            return null;
        }
        $disk = $this->s3_disk ?: 's3';
        try {
            $storage = Storage::disk($disk);
            if ($disk === 's3') {
                return $storage->temporaryUrl($this->s3_key, now()->addMinutes($minutes));
            }
            return $storage->url($this->s3_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
