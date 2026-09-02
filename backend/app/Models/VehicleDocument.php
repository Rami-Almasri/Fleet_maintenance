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

    /** The Mulkiya — the registration card itself. */
    public const KIND_MULKIYA = 'mulkiya';

    /**
     * ── Warranty paperwork ─────────────────────────────────────────────────────────────────────
     *
     * Filed here rather than in a warranty-only store because this table already owns everything a
     * stored file needs, and two upload paths means one of them eventually loses a file quietly.
     *
     * THESE KINDS BEHAVE DIFFERENTLY FROM THE MULKIYA, and the difference is not a bug. A Mulkiya is
     * a SLOT — one card in force, a replacement supersedes its predecessor. Warranty paperwork is a
     * DOSSIER — the certificate, the dealer's authorisation email and four photographs of a cracked
     * housing all coexist and none supersedes another. So warranty documents are never stamped
     * `superseded_at`, which means scopeCurrent() returns all of them: the right answer for a
     * dossier, and the wrong one for a slot.
     */
    public const KIND_WARRANTY_CERTIFICATE = 'warranty_certificate'; // the promise itself
    public const KIND_WARRANTY_CONTRACT    = 'warranty_contract';    // the dealer/OEM agreement
    public const KIND_WARRANTY_AUTH        = 'warranty_authorization'; // their go-ahead, in writing
    public const KIND_WARRANTY_CLAIM       = 'warranty_claim';       // claim forms + correspondence
    public const KIND_WARRANTY_EVIDENCE    = 'warranty_evidence';    // photos, diagnostic reports
    public const KIND_WARRANTY_INVOICE     = 'warranty_invoice';     // the repair invoice / credit note

    /** The kinds that belong to a warranty or a case — a dossier, never a slot. See above. */
    public const WARRANTY_KINDS = [
        self::KIND_WARRANTY_CERTIFICATE, self::KIND_WARRANTY_CONTRACT, self::KIND_WARRANTY_AUTH,
        self::KIND_WARRANTY_CLAIM, self::KIND_WARRANTY_EVIDENCE, self::KIND_WARRANTY_INVOICE,
    ];

    /** Every kind this trail accepts, with its human label. */
    public const KINDS = [
        self::KIND_MULKIYA             => 'Mulkiya (Vehicle Licence)',
        self::KIND_WARRANTY_CERTIFICATE => 'Warranty certificate',
        self::KIND_WARRANTY_CONTRACT   => 'Warranty contract',
        self::KIND_WARRANTY_AUTH       => 'Warranty authorization',
        self::KIND_WARRANTY_CLAIM      => 'Warranty claim document',
        self::KIND_WARRANTY_EVIDENCE   => 'Warranty evidence (photo / diagnostic report)',
        self::KIND_WARRANTY_INVOICE    => 'Warranty repair invoice',
    ];

    protected $fillable = [
        'vehicle_id', 'kind', 'warranty_id', 'warranty_claim_id',
        'disk', 'file_path', 'original_name', 'mime_type',
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

    /** The promise this scan is evidence of. Null on everything that is not warranty paperwork. */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_id');
    }

    /** The case this scan is evidence in. */
    public function warrantyCase(): BelongsTo
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }

    /** True for the dossier kinds — the ones that coexist rather than superseding each other. */
    public function isWarrantyPaperwork(): bool
    {
        return in_array($this->kind, self::WARRANTY_KINDS, true);
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
