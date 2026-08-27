<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One scanned document belonging to a car — today the Mulkiya (UAE Vehicle Licence).
 *
 * Rows are VERSIONS, not slots. The card in force is the one with `superseded_at = NULL`;
 * uploading a replacement supersedes it rather than overwriting it, so the licence a car was
 * operated under in any past month is still recoverable. See the migration for the reasoning.
 *
 * The bytes live on a storage disk; this row is the pointer. `viewUrl()` never throws — a card
 * whose object has gone missing degrades to "preview unavailable" instead of breaking the page.
 */
class VehicleDocument extends Model
{
    protected $table = 'vehicle_documents';

    /** The Mulkiya — the registration card itself. The only kind in use today. */
    public const KIND_MULKIYA = 'mulkiya';

    /** Every kind this trail accepts, with its human label. */
    public const KINDS = [
        self::KIND_MULKIYA => 'Mulkiya (Vehicle Licence)',
    ];

    protected $fillable = [
        'vehicle_id', 'kind', 'disk', 'file_path', 'original_name', 'mime_type',
        'file_size', 'width', 'height', 'note', 'uploaded_by', 'uploaded_by_name',
        'uploaded_at', 'superseded_at',
    ];

    protected $casts = [
        'file_size'     => 'integer',
        'width'         => 'integer',
        'height'        => 'integer',
        'uploaded_at'   => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Only the version in force (at most one per vehicle + kind). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** True while this is the card in force. */
    public function getIsCurrentAttribute(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * The URL to view the scan. Returns null when the object can't be resolved so the UI can
     * say "preview unavailable" instead of rendering a broken image.
     */
    public function viewUrl(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        try {
            return Storage::disk($this->disk ?: 'public')->url($this->file_path);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
