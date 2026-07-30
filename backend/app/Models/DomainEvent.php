<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One recorded fact or judgement. Append-only, enforced in code.
 *
 * IMMUTABILITY IS GUARDED, NOT DOCUMENTED. `saving` and `deleting` throw on anything but a first
 * insert. A rule that lives only in a comment is a rule that survives until the first urgent fix at
 * the end of a long day — and the whole value of this table is that a reader never has to wonder
 * whether it was edited.
 *
 * The escape hatch is deliberately absent. Correcting a fact means recording a new one that
 * supersedes it, which costs one extra row and preserves what we believed and when.
 */
class DomainEvent extends Model
{
    // --- the two layers that may be recorded. Derived knowledge is never written here ------------
    public const LAYER_FACT      = 'fact';
    public const LAYER_JUDGEMENT = 'judgement';

    // --- Observed facts: what happened, independent of anyone's opinion --------------------------
    public const COMPLAINT_REPORTED       = 'ComplaintReported';
    public const INSPECTION_PERFORMED     = 'InspectionPerformed';
    public const MEASUREMENT_RECORDED     = 'MeasurementRecorded';
    public const DIAGNOSTIC_CODE_READ     = 'DiagnosticCodeRead';
    public const REPAIR_ACTION_PERFORMED  = 'RepairActionPerformed';
    public const PART_FITTED              = 'PartFitted';
    public const VEHICLE_RELEASED         = 'VehicleReleased';
    public const ODOMETER_READ            = 'OdometerRead';
    public const MEDIA_CAPTURED           = 'MediaCaptured';

    // --- Human judgements: conclusions someone reached from those facts --------------------------
    public const DIAGNOSIS_MADE           = 'DiagnosisMade';
    public const REPAIR_DECISION_MADE     = 'RepairDecisionMade';
    public const VERIFICATION_COMPLETED   = 'VerificationCompleted';
    public const OUTCOME_CLAIMED          = 'OutcomeClaimed';
    public const ACCEPTANCE_DECIDED       = 'AcceptanceDecided';

    /**
     * Which layer each event belongs to.
     *
     * RepairActionPerformed is a FACT. Fitting a part either happened or it did not; the decision to
     * fit it is the judgement, and that is a separate event. Filing the act as an opinion would
     * devalue the single most learnable column in the dataset.
     *
     * ComebackDetected is deliberately NOT here: a comeback is derived knowledge, recomputed from
     * these events whenever the rules change. Recording it would freeze today's definition of a
     * comeback into history forever.
     */
    public const LAYERS = [
        self::COMPLAINT_REPORTED      => self::LAYER_FACT,
        self::INSPECTION_PERFORMED    => self::LAYER_FACT,
        self::MEASUREMENT_RECORDED    => self::LAYER_FACT,
        self::DIAGNOSTIC_CODE_READ    => self::LAYER_FACT,
        self::REPAIR_ACTION_PERFORMED => self::LAYER_FACT,
        self::PART_FITTED             => self::LAYER_FACT,
        self::VEHICLE_RELEASED        => self::LAYER_FACT,
        self::ODOMETER_READ           => self::LAYER_FACT,
        self::MEDIA_CAPTURED          => self::LAYER_FACT,

        self::DIAGNOSIS_MADE          => self::LAYER_JUDGEMENT,
        self::REPAIR_DECISION_MADE    => self::LAYER_JUDGEMENT,
        self::VERIFICATION_COMPLETED  => self::LAYER_JUDGEMENT,
        self::OUTCOME_CLAIMED         => self::LAYER_JUDGEMENT,
        self::ACCEPTANCE_DECIDED      => self::LAYER_JUDGEMENT,
    ];

    public $timestamps = false;

    protected $fillable = [
        'event_type', 'event_version', 'layer',
        'vehicle_id', 'maintenance_id', 'maintenance_task_id', 'subject_type', 'subject_id',
        'payload',
        'observed_by', 'observed_by_name', 'actor_type', 'organization_id',
        'capture_method', 'trust_level', 'source_system', 'is_self_reported',
        'supersedes_event_id', 'supersede_reason', 'correlation_id',
        'occurred_at', 'recorded_at',
    ];

    protected $casts = [
        'payload'          => 'array',
        'event_version'    => 'integer',
        'trust_level'      => 'integer',
        'is_self_reported' => 'boolean',
        'occurred_at'      => 'datetime',
        'recorded_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Domain events are immutable. To correct one, record a new event that supersedes it.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Domain events cannot be deleted. History is the point of this table.'
            );
        });
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_event_id');
    }

    /**
     * Only the events nothing has replaced.
     *
     * Derived at read time rather than kept in a flag, because maintaining a flag would mean
     * updating an already-recorded row — and then immutability would be a convention rather than a
     * guarantee. The index on `supersedes_event_id` is what makes this cheap.
     */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('domain_events as newer')
                ->whereColumn('newer.supersedes_event_id', 'domain_events.id');
        });
    }

    public function scopeFacts(Builder $q): Builder
    {
        return $q->where('layer', self::LAYER_FACT);
    }

    public function scopeJudgements(Builder $q): Builder
    {
        return $q->where('layer', self::LAYER_JUDGEMENT);
    }

    /** Trustworthy enough to learn from. The threshold belongs to the consumer, not to this class. */
    public function scopeTrustedAtLeast(Builder $q, int $minimum): Builder
    {
        return $q->where('trust_level', '>=', $minimum);
    }

    /**
     * The full revision history of this statement, oldest first.
     *
     * For a judgement this is how the diagnosis evolved — often worth more than where it landed,
     * because a diagnosis that changed twice before the car was fixed says something about the
     * difficulty of the fault that the final answer alone hides.
     *
     * @return array<int,self>
     */
    public function history(): array
    {
        $chain = [$this];
        $cursor = $this;

        while ($cursor->supersedes_event_id) {
            $previous = self::find($cursor->supersedes_event_id);

            if (! $previous) {
                break;
            }

            array_unshift($chain, $previous);
            $cursor = $previous;
        }

        return $chain;
    }

    public static function layerFor(string $eventType): string
    {
        return self::LAYERS[$eventType] ?? self::LAYER_FACT;
    }
}
