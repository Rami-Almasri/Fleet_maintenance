<?php

namespace App\Services;

/**
 * Odometer Continuity Rules — the single referee for "does this new reading make sense given the last
 * one?" as a car moves through the maintenance mileage chain (test → dispatch/pickup → receive → return).
 *
 * The design goal is data safety WITHOUT nagging: real cars move a few km between a driver reading the
 * dial and typing it in, so a small forward drift is never an error. Only readings that can't be true
 * (a meter running BACKWARDS beyond the buffer) are flagged as a Discrepancy; the two legitimate-but-
 * worth-noting cases (a big pickup jump, a garage test drive) get a soft, non-blocking nudge instead.
 *
 * Nothing here throws or blocks — it only classifies. The workflow keeps flowing; the classification is
 * stored on the ticket (odometer_flags) so a Supervisor can spot a Discrepancy at a glance in the drawer.
 *
 * The status strings are a CONTRACT with the frontend badges (TicketActionModal live hint + drawer).
 */
class OdometerContinuityService
{
    /**
     * Technical tolerance (km). A movement within this buffer of the previous reading is treated as the
     * same reading — it absorbs the small, realistic drift between glancing at the dial and typing it in,
     * so honest handoffs never get flagged as "data errors".
     */
    public const TOLERANCE_KM = 5;

    /**
     * A pickup reading this far ABOVE the last recorded mileage is legitimate (the car was driven since
     * we last saw it) but large enough to be worth a "double-check you read it right" nudge to the driver.
     */
    public const PICKUP_WARN_KM = 50;

    // ── Flag statuses (contract with the UI) ────────────────────────────────────
    public const STATUS_VERIFIED    = 'verified';    // within tolerance / normal forward travel — all good
    public const STATUS_DISCREPANCY = 'discrepancy'; // ran backwards beyond tolerance — can't be right
    public const STATUS_TEST_DRIVE  = 'test_drive';  // garage OUT > IN — the garage drove it; confirm, don't block
    public const STATUS_CHECK       = 'check';       // pickup jumped a lot — probably fine, but re-read the dial
    public const STATUS_AUTHORIZED  = 'authorized_deviation'; // strict-match stage: 1..TOLERANCE km over — allowed WITH a note (audited on the oversight board)
    public const STATUS_EXACT       = 'exact_required';       // strict-match stage: backward, or > TOLERANCE over — a HARD block, not an overridable nudge

    // ── Stages (which continuity rule applies) ──────────────────────────────────
    public const STAGE_TEST        = 'test';        // inspector's start-of-drive reading vs the car's current mileage
    public const STAGE_PICKUP      = 'pickup';      // generic pickup reading vs the last recorded mileage (logistics round-trip)
    public const STAGE_PARK_PICKUP = 'park_pickup'; // maintenance driver collecting the car FROM OUR PARK for the garage — the car hasn't moved, so a strict match is expected
    public const STAGE_GARAGE_IN   = 'garage_in';   // garage intake (receive) reading vs the pickup reading
    public const STAGE_GARAGE_OUT  = 'garage_out';  // car-leaves-garage (return) reading vs the intake reading
    public const STAGE_RETURN      = 'return';      // final reading vs the last recorded, when there's no intake to compare
    public const STAGE_TRANSFER    = 'transfer';    // car driven from one garage to another — forward travel expected, big jump worth a re-read

    /**
     * Strict-match stages — an internal spot-check at OUR OWN PARK, where the car should NOT have moved
     * since the previous reading (the inspector's test capture, and the driver collecting the car for the
     * garage). An exact match is expected; a small forward drift (1..TOLERANCE_KM) is tolerated but must be
     * explained with a note (which then surfaces on the /oversight/mileage audit board); anything beyond —
     * or ANY backward reading — is a HARD block (a typo or an unauthorised long-distance move), not a soft
     * discrepancy. Mirror of STRICT_MATCH_STAGES in frontend/src/lib/odometerContinuity.js — keep in step.
     */
    public const STRICT_MATCH_STAGES = [self::STAGE_TEST, self::STAGE_PARK_PICKUP];

    /** Does this stage require the reading to match the previous one (exact, or +TOLERANCE with a note)? */
    public function stageRequiresExactMatch(string $stage): bool
    {
        return in_array($stage, self::STRICT_MATCH_STAGES, true);
    }

    /**
     * Stages that are a deliberate road trip between our site and a garage: the car IS driven, so a forward
     * mileage increase is natural and legitimate — never a "discrepancy" to explain and no ±tolerance
     * note/confirm nag. The internal spot-checks (test / pickup at the park / re-inspection) keep the rule.
     * A garage-to-garage TRANSFER belongs here too: the car is physically driven from one garage to the
     * next, so the distance it accrues is expected — a forward jump is the point of the trip, not something
     * to explain away. (Otherwise the reading reads "Verified — lines up" yet still demands a >10 km note,
     * a contradiction.) A BACKWARD reading still flags on every stage regardless, transfer included.
     * Mirror of GARAGE_TRANSFER_STAGES in frontend/src/lib/odometerContinuity.js — keep the two in step.
     */
    public const GARAGE_TRANSFER_STAGES = [self::STAGE_GARAGE_IN, self::STAGE_GARAGE_OUT, self::STAGE_TRANSFER];

    /** Is this a site↔garage / garage↔garage move where the forward-tolerance nag is waived? */
    public function stageIgnoresTolerance(string $stage): bool
    {
        return in_array($stage, self::GARAGE_TRANSFER_STAGES, true);
    }

    /**
     * Classify a reading against the previous one for a given stage.
     *
     * @param  int       $reading   the km the driver just captured
     * @param  int|null  $previous  the last recorded reading to compare against (null = this is the anchor)
     * @param  string    $stage     one of the STAGE_* constants
     * @return array{status:string, previous:?int, reading:int, delta:?int, tolerance:int}
     *         a self-describing flag; `delta` is reading − previous (null when there's no previous).
     */
    public function evaluate(int $reading, ?int $previous, string $stage): array
    {
        // No prior reading → nothing to compare; this reading anchors the chain.
        if ($previous === null) {
            return $this->flag(self::STATUS_VERIFIED, $previous, $reading, null);
        }

        $delta = $reading - $previous;

        // Strict-match stage (an internal spot-check at our own park): the car shouldn't have moved since
        // the previous reading. An exact match is clean; a 1..TOLERANCE forward drift is an "authorized
        // deviation" that must carry a note; ANYTHING else — backward, or beyond the buffer forward — is a
        // hard block (a typo or an unauthorised long move), NOT a soft discrepancy. Takes priority over the
        // generic guards below so a big/backward reading here never downgrades to an ack-able nudge.
        if ($this->stageRequiresExactMatch($stage)) {
            if ($delta === 0) {
                return $this->flag(self::STATUS_VERIFIED, $previous, $reading, $delta);
            }
            if ($delta >= 1 && $delta <= self::TOLERANCE_KM) {
                return $this->flag(self::STATUS_AUTHORIZED, $previous, $reading, $delta);
            }
            return $this->flag(self::STATUS_EXACT, $previous, $reading, $delta);
        }

        // Universal guard: an odometer physically cannot run backwards. A drop beyond the buffer is the
        // one thing that is always a data error, whatever the stage.
        if ($delta < -self::TOLERANCE_KM) {
            return $this->flag(self::STATUS_DISCREPANCY, $previous, $reading, $delta);
        }

        // Within the buffer either way → the same reading for our purposes: Verified.
        if (abs($delta) <= self::TOLERANCE_KM) {
            return $this->flag(self::STATUS_VERIFIED, $previous, $reading, $delta);
        }

        // Beyond the buffer, but FORWARD — legitimate movement. How we label it depends on the stage.
        switch ($stage) {
            case self::STAGE_PICKUP:
            case self::STAGE_TRANSFER:
                // Car was driven since we last saw it (or between garages) — fine, but a big jump is
                // worth a re-read of the dial.
                return $this->flag(
                    $delta > self::PICKUP_WARN_KM ? self::STATUS_CHECK : self::STATUS_VERIFIED,
                    $previous, $reading, $delta,
                );

            case self::STAGE_GARAGE_OUT:
                // The car left the garage having moved more than tolerance since intake → the garage
                // road-tested it. Legitimate; ask the driver to confirm, never block.
                return $this->flag(self::STATUS_TEST_DRIVE, $previous, $reading, $delta);

            default:
                // test / garage_in / plain return: forward travel is exactly what we expect. Verified.
                return $this->flag(self::STATUS_VERIFIED, $previous, $reading, $delta);
        }
    }

    /** Shape one flag record (also what gets JSON-stored on the ticket + read by the UI). */
    private function flag(string $status, ?int $previous, int $reading, ?int $delta): array
    {
        return [
            'status'    => $status,
            'previous'  => $previous,
            'reading'   => $reading,
            'delta'     => $delta,
            'tolerance' => self::TOLERANCE_KM,
        ];
    }
}
