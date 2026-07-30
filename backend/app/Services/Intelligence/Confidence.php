<?php

namespace App\Services\Intelligence;

/**
 * How much weight a Decision Card is allowed to carry.
 *
 * COMPUTED, NEVER AUTHORED. A capability supplies Evidence; this enum is derived from it by
 * Evidence::confidence(). No capability may assert its own confidence, because that is precisely
 * where optimism leaks into the UI — the author who wrote the query is the last person who should
 * grade it.
 *
 * The band drives the LANGUAGE, not just a badge. The platform is never permitted to sound more
 * certain than its evidence: one confidently wrong routing recommendation costs more trust than
 * fifty correctly-hedged ones earn.
 */
enum Confidence: string
{
    case Strong = 'strong';
    case Moderate = 'moderate';
    case Limited = 'limited';

    /** The verb strength a card may use. Enforced when the card renders its recommendation. */
    public function strength(): string
    {
        return match ($this) {
            self::Strong   => 'must',      // "Route this to Road Force."
            self::Moderate => 'should',    // "Road Force is the stronger choice for this fault."
            self::Limited  => 'consider',  // "Limited historical evidence — 4 comparable cases."
        };
    }

    /** Lower is weaker. Used to take the minimum across several evidence inputs. */
    public function rank(): int
    {
        return match ($this) {
            self::Limited  => 0,
            self::Moderate => 1,
            self::Strong   => 2,
        };
    }

    public static function weakest(self ...$bands): self
    {
        $weakest = self::Strong;
        foreach ($bands as $band) {
            if ($band->rank() < $weakest->rank()) {
                $weakest = $band;
            }
        }

        return $weakest;
    }

    /** Multiplier used by the Decision Engine's within-tier score. */
    public function weight(): float
    {
        return match ($this) {
            self::Strong   => 1.0,
            self::Moderate => 0.7,
            self::Limited  => 0.4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Strong   => 'strong evidence',
            self::Moderate => 'moderate evidence',
            self::Limited  => 'limited historical evidence',
        };
    }
}
