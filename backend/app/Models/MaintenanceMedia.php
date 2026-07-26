<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One Video Evidence row on a maintenance ticket — the garage's repair video, uploaded by a supervisor
 * (Waleed/Abdullah) as the permanent record the QA review is based on. See [[maintenance-workflow-engine]].
 *
 * Bytes live on the local `public` disk; this row holds only the pointer + metadata. `viewUrl()`
 * returns the file's public `/storage/...` URL. (`disk`/`s3_key` column names are legacy — they
 * hold the local disk name and relative path now that storage is local-only.)
 */
class MaintenanceMedia extends Model
{
    protected $table = 'maintenance_media';

    protected $fillable = [
        'maintenance_id', 'maintenance_task_id', 'maintenance_checkpoint_id', 'kind', 'disk', 's3_key',
        'content_type', 'original_name', 'file_size', 'note', 'uploaded_by', 'uploaded_by_name',
        'vehicle_component_id', 'component_event_id',
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

    /** The Maintenance Checkpoint this media evidences (nullable — non-checkpoint videos leave it null). */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(MaintenanceCheckpoint::class, 'maintenance_checkpoint_id');
    }

    /** Asset Layer: the physical component this media documents (nullable — portrait/evidence shots). */
    public function component(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class, 'vehicle_component_id');
    }

    /** Asset Layer: the specific install/removal event this media evidences (warranty/liability proof). */
    public function componentEvent(): BelongsTo
    {
        return $this->belongsTo(ComponentEvent::class, 'component_event_id');
    }

    /**
     * The public `/storage/...` URL to watch the video, served locally via the storage symlink.
     * Never throws — returns null when the object can't be resolved, so the UI degrades to
     * "unavailable" instead of a broken player.
     */
    public function viewUrl(): ?string
    {
        try {
            return Storage::disk($this->disk ?: 'public')->url($this->s3_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
