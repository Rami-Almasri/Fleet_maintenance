<?php

namespace App\Evidence;

use App\Models\DomainEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one way anything enters the canonical log.
 *
 * A single writer is what makes the guarantees enforceable rather than aspirational: the layer is
 * looked up rather than passed, trust is computed rather than accepted, and derived knowledge is
 * refused outright. Spread these rules across twenty call sites and the twenty-first will get one
 * wrong — and a log that is right 95% of the time cannot be replayed.
 *
 * CORRELATION. Events recorded inside `session()` share a correlation id, so a replay can reconstruct
 * everything one technician entered in one visit as a unit. Without it the log is a flat stream and
 * "what did this person conclude, from what, in one sitting?" becomes unanswerable.
 */
class EventRecorder
{
    private ?string $correlationId = null;

    /**
     * Record a fact or a judgement.
     *
     * @param  array<string,mixed>  $payload
     * @param  array{vehicle_id?:int|null,maintenance_id?:int|null,maintenance_task_id?:int|null,subject_type?:string|null,subject_id?:int|null}  $subject
     */
    public function record(
        string $eventType,
        array $payload,
        Provenance $provenance,
        array $subject = [],
        ?Carbon $occurredAt = null,
        ?DomainEvent $supersedes = null,
        ?string $supersedeReason = null,
        int $eventVersion = 1,
    ): DomainEvent {
        if (! isset(DomainEvent::LAYERS[$eventType])) {
            // Refused rather than defaulted. An unknown event type means either a typo or an event
            // nobody has classified — and silently filing it as a fact would put an unreviewed
            // statement into the layer that future models trust most.
            throw new InvalidArgumentException(
                "Unknown event type '{$eventType}'. Declare it in DomainEvent::LAYERS with its layer first."
            );
        }

        if ($supersedes && $supersedes->event_type !== $eventType) {
            throw new InvalidArgumentException(
                "A {$eventType} event cannot supersede a {$supersedes->event_type} event."
            );
        }

        return DomainEvent::create(array_merge(
            [
                'event_type'    => $eventType,
                'event_version' => $eventVersion,
                'layer'         => DomainEvent::layerFor($eventType),
                'payload'       => $payload,

                'vehicle_id'          => $subject['vehicle_id'] ?? null,
                'maintenance_id'      => $subject['maintenance_id'] ?? null,
                'maintenance_task_id' => $subject['maintenance_task_id'] ?? null,
                'subject_type'        => $subject['subject_type'] ?? null,
                'subject_id'          => $subject['subject_id'] ?? null,

                'supersedes_event_id' => $supersedes?->id,
                'supersede_reason'    => $supersedeReason,
                'correlation_id'      => $this->correlationId,

                // Defaulted rather than required: when nobody says otherwise, we learned it as it
                // happened. Callers importing history MUST pass the real date, or every imported
                // event lands today and the timeline is worthless.
                'occurred_at' => $occurredAt ?? now(),
                'recorded_at' => now(),
            ],
            $provenance->toColumns(),
        ));
    }

    /**
     * Correct a fact, or revise a judgement.
     *
     * Identical mechanics for both, which is the point: a corrected measurement and a changed
     * diagnosis are the same operation on the log, and both keep what was believed before.
     *
     * @param  array<string,mixed>  $payload
     */
    public function supersede(DomainEvent $previous, array $payload, Provenance $provenance, string $reason): DomainEvent
    {
        return $this->record(
            eventType: $previous->event_type,
            payload: $payload,
            provenance: $provenance,
            subject: [
                'vehicle_id'          => $previous->vehicle_id,
                'maintenance_id'      => $previous->maintenance_id,
                'maintenance_task_id' => $previous->maintenance_task_id,
                'subject_type'        => $previous->subject_type,
                'subject_id'          => $previous->subject_id,
            ],
            supersedes: $previous,
            supersedeReason: $reason,
            eventVersion: $previous->event_version,
        );
    }

    /**
     * Group everything recorded inside the callback as one capture session.
     *
     * Nested calls reuse the outer id, so a service that opens a session and calls another that does
     * the same still produces one coherent session rather than two overlapping ones.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function session(callable $callback)
    {
        $outer = $this->correlationId;
        $this->correlationId ??= (string) Str::uuid();

        try {
            return $callback();
        } finally {
            $this->correlationId = $outer;
        }
    }
}
