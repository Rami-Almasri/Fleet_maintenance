<?php

namespace App\Evidence;

use App\Models\User;

/**
 * Where a piece of evidence came from, and how far it can be trusted.
 *
 * THE TRUST AXIS. The three layers (fact / judgement / derived) say what KIND of statement something
 * is. They say nothing about how much to believe it — and those come apart in exactly one place that
 * matters: a fact measured by an instrument and a fact reported by the party whose work is being
 * graded are both facts. A garage reporting "I replaced the caliper" is a fact-class statement with
 * judgement-class reliability.
 *
 * Trust is therefore modelled independently of the layer, and computed rather than typed in — a
 * number a person can set is a number that will be set to whatever makes the record look good.
 *
 * THE SELF-REPORTING PENALTY IS THE POINT. It is the difference between a supplier-quality metric
 * that measures suppliers and one that measures how optimistic each supplier's data entry is.
 */
final class Provenance
{
    public const ACTOR_STAFF    = 'staff';      // our own employee
    public const ACTOR_GARAGE   = 'garage';     // external workshop
    public const ACTOR_CUSTOMER = 'customer';   // the renter
    public const ACTOR_SYSTEM   = 'system';     // a job, an import, a rule
    public const ACTOR_DEVICE   = 'device';     // a scan tool, a sensor

    public const METHOD_MEASURED = 'measured';  // an instrument produced a number
    public const METHOD_SCANNED  = 'scanned';   // read from the vehicle's own systems
    public const METHOD_VISUAL   = 'visual';    // someone looked at it
    public const METHOD_REPORTED = 'reported';  // someone said so
    public const METHOD_IMPORTED = 'imported';  // came from another system
    public const METHOD_COMPUTED = 'computed';  // the system worked it out

    public function __construct(
        public readonly string $actorType,
        public readonly string $captureMethod,
        public readonly ?int $observedBy = null,
        public readonly ?string $observedByName = null,
        public readonly ?int $organizationId = null,
        public readonly string $sourceSystem = 'fleet',
        public readonly bool $isSelfReported = false,
    ) {
    }

    /** Our own staff member, entering something they saw. */
    public static function staff(?User $user, string $method = self::METHOD_VISUAL): self
    {
        return new self(
            actorType: self::ACTOR_STAFF,
            captureMethod: $method,
            observedBy: $user?->id,
            observedByName: $user?->name,
        );
    }

    /**
     * An external workshop reporting on its own work.
     *
     * `isSelfReported` defaults TRUE here and is not a parameter, because there is no case where a
     * garage reporting into its own ticket is disinterested. Making it impossible to pass `false`
     * is deliberate — this is precisely the flag that would get switched off "just for this import".
     */
    public static function garage(int $vendorId, ?string $name = null, string $method = self::METHOD_REPORTED): self
    {
        return new self(
            actorType: self::ACTOR_GARAGE,
            captureMethod: $method,
            observedByName: $name,
            organizationId: $vendorId,
            sourceSystem: 'garage_portal',
            isSelfReported: true,
        );
    }

    /** The renter describing a symptom. Low trust as an observation, high value as a complaint. */
    public static function customer(?string $name = null): self
    {
        return new self(
            actorType: self::ACTOR_CUSTOMER,
            captureMethod: self::METHOD_REPORTED,
            observedByName: $name,
        );
    }

    /** A scheduled job, a rule, or an import. */
    public static function system(string $sourceSystem = 'fleet', string $method = self::METHOD_COMPUTED): self
    {
        return new self(
            actorType: self::ACTOR_SYSTEM,
            captureMethod: $method,
            observedByName: 'System',
            sourceSystem: $sourceSystem,
        );
    }

    /** A scan tool or sensor — the only actor that cannot have a motive. */
    public static function device(string $deviceName, string $method = self::METHOD_SCANNED): self
    {
        return new self(
            actorType: self::ACTOR_DEVICE,
            captureMethod: $method,
            observedByName: $deviceName,
            sourceSystem: 'device',
        );
    }

    /**
     * Trust, 0–100. Computed from HOW it was captured and WHO captured it, never stated.
     *
     * The method sets the ceiling: a measurement is a measurement whoever took it, and an opinion
     * stays an opinion however senior the person. The actor then adjusts within that, and
     * self-reporting takes a fixed penalty on top.
     */
    public function trustLevel(): int
    {
        $base = match ($this->captureMethod) {
            self::METHOD_SCANNED  => 95,    // the vehicle's own systems have no motive
            self::METHOD_MEASURED => 90,    // an instrument, but a human read and typed it
            self::METHOD_IMPORTED => 70,    // only as good as its origin, which we cannot see
            self::METHOD_COMPUTED => 85,    // reproducible, but only as good as its inputs
            self::METHOD_VISUAL   => 65,    // trained eyes, still an interpretation
            self::METHOD_REPORTED => 50,    // someone said so
            default               => 50,
        };

        $base += match ($this->actorType) {
            self::ACTOR_DEVICE   => 3,
            self::ACTOR_STAFF    => 0,
            self::ACTOR_SYSTEM   => 0,
            self::ACTOR_GARAGE   => -5,

            // Not untruthful — untrained, and describing a sensation rather than a component. The
            // penalty is heavier than a garage's because it applies to the OBSERVATION, whereas a
            // garage's discount is about motive: a professional with a motive is still a
            // professional, and must not rank below an untrained observer on a technical claim.
            self::ACTOR_CUSTOMER => -20,
            default              => -10,
        };

        // Applied last, and deliberately smaller than it first looks. A garage's measurement is
        // still a measurement and keeps most of its value; what it loses is the right to be treated
        // as independent evidence about its own work.
        if ($this->isSelfReported) {
            $base -= 12;
        }

        // Capped below 100 on principle, matching [[ConfidenceVector]]: a scan tool can read a
        // failed sensor or the wrong vehicle. Nothing this system records is beyond correction, and
        // a value of 100 invites downstream code to stop checking.
        return max(0, min(98, $base));
    }

    /** @return array<string,mixed> the provenance columns of a domain event */
    public function toColumns(): array
    {
        return [
            'observed_by'      => $this->observedBy,
            'observed_by_name' => $this->observedByName,
            'actor_type'       => $this->actorType,
            'organization_id'  => $this->organizationId,
            'capture_method'   => $this->captureMethod,
            'trust_level'      => $this->trustLevel(),
            'source_system'    => $this->sourceSystem,
            'is_self_reported' => $this->isSelfReported,
        ];
    }

    /** One line a person can read, for explanations and audit views. */
    public function describe(): string
    {
        $who = $this->observedByName ?: ucfirst($this->actorType);
        $how = match ($this->captureMethod) {
            self::METHOD_SCANNED  => 'read from the vehicle',
            self::METHOD_MEASURED => 'measured',
            self::METHOD_VISUAL   => 'observed',
            self::METHOD_REPORTED => 'reported',
            self::METHOD_IMPORTED => 'imported',
            self::METHOD_COMPUTED => 'computed',
            default               => $this->captureMethod,
        };

        return $who.' — '.$how.($this->isSelfReported ? ' (self-reported)' : '').', trust '.$this->trustLevel().'/100';
    }
}
