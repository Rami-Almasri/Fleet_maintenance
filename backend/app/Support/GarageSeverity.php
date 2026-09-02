<?php

namespace App\Support;

/**
 * The four-step attention ladder the Garage Intelligence signals are graded on, and the two rules
 * that decide whether a grade is worth telling anybody about.
 *
 * Deliberately pure: no database, no config lookup, no Laravel. The thresholds are passed IN (they
 * live in config/garage_intelligence.php and are read in exactly one place), so this class can be
 * unit-tested for every boundary without booting the application — and so the escalation rule that
 * prevents notification spam is testable in isolation from the thing that sends notifications.
 *
 *   normal → warning → high → critical
 *
 * ESCALATION IS THE ONLY THING THAT SPEAKS. A signal that rises tells somebody; a signal that holds
 * steady says nothing (the fleet already knows); a signal that falls updates the record silently.
 * That is the whole anti-spam design — see shouldNotify()/settleTo().
 */
final class GarageSeverity
{
    public const NORMAL   = 'normal';
    public const WARNING  = 'warning';
    public const HIGH     = 'high';
    public const CRITICAL = 'critical';

    /** Least → most urgent. The index IS the rank. */
    public const LADDER = [self::NORMAL, self::WARNING, self::HIGH, self::CRITICAL];

    /** Where a level sits on the ladder; an unrecognised level is treated as `normal` (rank 0). */
    public static function rank(?string $level): int
    {
        $i = array_search($level, self::LADDER, true);

        return $i === false ? 0 : $i;
    }

    /** The more urgent of two levels. */
    public static function max(?string $a, ?string $b): string
    {
        return self::rank($a) >= self::rank($b) ? self::normalise($a) : self::normalise($b);
    }

    public static function normalise(?string $level): string
    {
        return in_array($level, self::LADDER, true) ? $level : self::NORMAL;
    }

    /**
     * Grade a measurement against its three ascending thresholds.
     *
     * The comparison is `>=` at every step — "3 visits = Warning" means three visits IS the warning,
     * not one short of it. A threshold that is null or non-positive is treated as unconfigured and
     * simply cannot be reached, so removing a band never silently promotes a car to the next one.
     *
     * Thresholds are read in ascending order and the HIGHEST satisfied one wins, so a mis-ordered
     * config (critical below warning) still grades honestly rather than reporting the first match.
     */
    public static function grade(float $value, array $thresholds): string
    {
        $level = self::NORMAL;

        foreach ([self::WARNING, self::HIGH, self::CRITICAL] as $candidate) {
            $limit = $thresholds[$candidate] ?? null;
            if ($limit === null || $limit <= 0) {
                continue;   // band not configured — unreachable, never an implicit promotion
            }
            if ($value >= (float) $limit && self::rank($candidate) > self::rank($level)) {
                $level = $candidate;
            }
        }

        return $level;
    }

    /**
     * Does this reading deserve a notification?
     *
     * ONLY on a genuine climb: the car has reached a step it was not already known to be on. Equal
     * severity is silent (this is what stops five maintenance updates in an afternoon becoming five
     * identical alerts), and so is any fall.
     */
    public static function shouldNotify(?string $current, ?string $alreadyNotified): bool
    {
        return self::rank($current) > self::rank($alreadyNotified)
            && self::rank($current) > 0;   // `normal` is the absence of a condition, never an alert
    }

    /**
     * The level to REMEMBER as "already told them", given what we just measured.
     *
     * Always the CURRENT level — a climb is remembered at the new height, and a fall is remembered
     * at the new, lower level rather than the old peak. The fall half is the deliberate one: it is
     * what lets a car that recovers and then deteriorates again be alerted a second time.
     * Remembering the peak forever would silence every recurrence, which is the opposite of the point.
     *
     * The parameter is unused by design; it is kept so the call site reads as the pair of rules it is
     * (shouldNotify decides, settleTo records) rather than as a bare assignment.
     */
    public static function settleTo(?string $current, ?string $alreadyNotified = null): string
    {
        return self::normalise($current);
    }

    /** Did the reading move at all? Used to decide whether a de-escalation is worth persisting. */
    public static function changed(?string $a, ?string $b): bool
    {
        return self::normalise($a) !== self::normalise($b);
    }

    /**
     * The FleetAlert severity word for a ladder level. The notification layer only knows
     * critical|warning|info|success (see App\Notifications\FleetAlert::SEVERITIES), so `high` and
     * `critical` both present as `critical` — a car with five garage visits this month is not an
     * "info" and dressing it as a warning would flatten the two most urgent bands into one.
     */
    public static function alertSeverity(?string $level): string
    {
        return match (self::normalise($level)) {
            self::CRITICAL, self::HIGH => 'critical',
            self::WARNING              => 'warning',
            default                    => 'info',
        };
    }
}
