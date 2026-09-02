<?php

namespace App\Models;

use App\Support\PartSpecs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WHAT THIS CAR TAKES — one row per (vehicle, part type).
 *
 * Not what is fitted (that is {@see VehicleComponent}) and not what a part type is in general (that
 * is {@see ComponentCatalog}). This is the fitment fact: plate 12345 takes 4.5 litres of 5W-30 and
 * a 12V 60Ah battery with the positive post on the right. It is what lets the oil-change screen
 * answer the question before it is asked, and what a fitting is checked against.
 *
 * ── The source ladder ────────────────────────────────────────────────────────────────────────────
 *
 * `source` is the whole trust model, and it is ordered:
 *
 *     manual_book  >  manual  >  observed
 *
 * `observed` is written automatically from the last part actually fitted. That makes it a record of
 * WHAT WAS DONE, never of what is right — if the wrong oil went in, this holds the wrong oil. Every
 * surface labels it as observed for exactly that reason, and it may never overwrite a value a person
 * entered. A human decision outranks a pattern; the system suggests, it does not decide.
 *
 * WRITE DISCIPLINE: `source` and the learned_from links are not fillable. They are set inside
 * {@see \App\Services\VehiclePartSpecService}, the single write path that owns the ladder — so the
 * rule "observed never beats manual" lives in one function and cannot be forgotten at a call site.
 */
class VehiclePartSpec extends Model
{
    public const SOURCE_OBSERVED    = 'observed';
    public const SOURCE_MANUAL      = 'manual';
    public const SOURCE_MANUAL_BOOK = 'manual_book';
    public const SOURCES = [self::SOURCE_OBSERVED, self::SOURCE_MANUAL, self::SOURCE_MANUAL_BOOK];

    /** Higher wins. Used by the service to decide whether an incoming write may land. */
    public const SOURCE_RANK = [
        self::SOURCE_OBSERVED    => 1,
        self::SOURCE_MANUAL      => 2,
        self::SOURCE_MANUAL_BOOK => 3,
    ];

    protected $fillable = [
        'vehicle_id', 'component_catalog_id', 'specs', 'notes',
    ];

    protected $casts = [
        'specs'        => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    public function learnedFromComponent(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class, 'learned_from_component_id');
    }

    public function learnedFromServiceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class, 'learned_from_service_record_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeForVehicle(Builder $query, int $vehicleId): Builder
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    /** Entered or approved by a person — the values a mismatch may be asserted against confidently. */
    public function scopeHumanConfirmed(Builder $query): Builder
    {
        return $query->whereIn('source', [self::SOURCE_MANUAL, self::SOURCE_MANUAL_BOOK]);
    }

    public function isObserved(): bool
    {
        return $this->source === self::SOURCE_OBSERVED;
    }

    public function rank(): int
    {
        return self::SOURCE_RANK[$this->source] ?? 0;
    }

    /** The one line — "5W-30 · Full synthetic · 4.5 L". */
    public function summary(string $locale = 'en'): string
    {
        return PartSpecs::summary($this->catalog, $this->specs, $locale);
    }

    /**
     * How this figure came to be here, as a sentence a person can act on.
     *
     * Plain language, not engine vocabulary: someone reading "5W-30" needs to know whether that is
     * the manual speaking or merely the last thing a garage poured in, and "observed" on its own
     * does not tell them.
     */
    public function provenanceSentence(): string
    {
        return match ($this->source) {
            self::SOURCE_MANUAL_BOOK => 'From the vehicle handbook'
                . ($this->confirmed_by_name ? ", entered by {$this->confirmed_by_name}" : '') . '.',
            self::SOURCE_MANUAL => 'Entered by '
                . ($this->confirmed_by_name ?: 'a member of staff') . '.',
            default => 'Copied from the last one fitted — nobody has confirmed it is the right one.',
        };
    }
}
