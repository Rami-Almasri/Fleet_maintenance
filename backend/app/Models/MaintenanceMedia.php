<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One Video Evidence row on a maintenance ticket — the garage's repair video, uploaded by a supervisor
 * (Waleed/Abdullah) as the permanent record the QA review is based on. See [[maintenance-workflow-engine]].
 *
 * Bytes live on S3 (when configured) or the local `public` disk; this row holds only the pointer +
 * metadata. `viewUrl()` returns a short-lived, signed URL so the video is never publicly listable.
 */
class MaintenanceMedia extends Model
{
    protected $table = 'maintenance_media';

    protected $fillable = [
        'maintenance_id', 'maintenance_task_id', 'kind', 'disk', 's3_key', 'content_type',
        'original_name', 'file_size', 'note', 'uploaded_by', 'uploaded_by_name',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /** The ticket this video belongs to (loose link, mirroring line items / tasks). */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    /** The specific FAULT this video documents (nullable — ticket-scoped videos leave it null). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /**
     * A short-lived, signed URL to watch the video. On S3 we sign a 30-minute GET; on the local `public`
     * disk we hand back the plain public URL (no signing available). Never throws — returns null when the
     * object can't be resolved (e.g. S3 not configured), so the UI degrades to "unavailable".
     */
    public function viewUrl(): ?string
    {
        $disk = $this->disk ?: 's3';
        try {
            if ($disk === 's3') {
                return Storage::disk('s3')->temporaryUrl($this->s3_key, now()->addMinutes(30));
            }
            return Storage::disk($disk)->url($this->s3_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
