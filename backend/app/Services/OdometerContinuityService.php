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

    /**
     * The largest forward jump that can be true between two consecutive readings in one mileage chain.
     *
     * THE GAP THIS CLOSES. Every rule above bounded how far a reading could run BACKWARDS; nothing bounded
     * how far it could run forwards. A garage-out reading of 6,276,888 km against an intake of 62,769 was
     * therefore classified `test_drive` — "legitimate, confirm but never block" — and written straight
     * through to `vehicles.odometer`. It was a two-digit typo, and it corrupted the mileage baseline,
     * cost-per-km and service-due chains for that car.
     *
     * 20,000 km is deliberately generous: it comfortably clears the longest real hop in the chain (a car
     * out on rental between two of our readings) while catching every transposition and repeated-digit
     * slip, which land orders of magnitude above it. This is a TYPO guard, not a mileage policy.
     */
    public const MAX_JUMP_KM = 20_000;

    // ── Flag statuses (contract with the UI) ────────────────────────────────────
    public const STATUS_VERIFIED    = 'verified';    // within tolerance / normal forward travel — all good
    public const STATUS_DISCREPANCY = 'discrepancy'; // ran backwards beyond tolerance — can't be right
    public const STATUS_TEST_DRIVE  = 'test_drive';  // garage OUT > IN — the garage drove it; confirm, don't block
    public const STATUS_CHECK       = 'check';       // pickup jumped a lot — probably fine, but re-read the dial
    public const STATUS_AUTHORIZED  = 'authorized_deviation'; // strict-match stage: ANY forward drift — allowed WITH a note; beyond TOLERANCE it also goes to the odometer approval board
    public const STATUS_EXACT       = 'exact_required';       // strict-match stage: a BACKWARD reading — a HARD block, not an overridable nudge
    public const STATUS_IMPLAUSIBLE = 'implausible';          // forward jump beyond MAX_JUMP_KM — a typo, not a journey. HARD block on every stage.

    // ── Stages (which continuity rule applies) ──────────────────────────────────
    public const STAGE_TEST        = 'test';        // inspector's start-of-drive reading vs the car's current mileage
    public const STAGE_PICKUP      = 'pickup';      // generic pickup reading vs the last recorded mileage (logistics round-trip)
    public const STAGE_PARK_PICKUP = 'park_pickup'; // maintenance driver collecting the car FROM OUR PARK for the garage — the car hasn't moved, so a strict match is expected
    public const STAGE_GARAGE_IN   = 'garage_in';   // garage intake (receive) reading vs the pickup reading
    public const STAGE_GARAGE_OUT  = 'garage_out';  // car-leaves-garage (return) reading vs the intake reading
    public const STAGE_RETURN      = 'return';      // final reading vs the last recorded, when there's no intake to compare
    public const STAGE_TRANSFER    = 'transfer';    // car driven from one garage to another — forward travel expected, big jump worth a re-read
    public const STAGE_TEST_END    = 'test_end';    // end-of-test-drive reading vs the start-of-drive anchor — the car WAS driven, so forward movement is expected, not a strict match
    public const STAGE_REINSPECT   = 'reinspect';   // final QA sign-off reading vs the park-arrival reading — the car is back at OUR PARK and shouldn't have moved, so a strict ±TOLERANCE cap applies

    /**
     * Strict-match stages — an internal spot-check at OUR OWN PARK, where the car should NOT have moved
     * since the previous reading (the inspector's test capture, and the driver collecting the car for the
     * garage). An exact match is expected; any FORWARD drift is tolerated but must be explained with a note
     * (which surfaces on the /oversight/mileage audit board, and beyond TOLERANCE_KM also lands on the
     * odometer approval queue). Only a BACKWARD reading is a hard block — an odometer cannot run backwards,
     * so that one is always a typo. Mirror of STRICT_MATCH_STAGES in frontend/src/lib/odometerContinuity.js.
     */
    public const STRICT_MATCH_STAGES = [self::STAGE_TEST, self::STAGE_PARK_PICKUP, self::STAGE_REINSPECT];

    /** Does this stage require the reading to match the previous one (exact, or +TOLERANCE with a note)? */
    public function stageRequiresExactMatch(string $stage): bool
    {
        return in_array($stage, self::STRICT_MATCH_STAGES, true);
    }

    /**
     * Strict-match stages that REVIEW instead of BLOCK.
     *
     * "Needs Test Drive" is the first time anyone actually walks up to the car and reads its dial. Whatever
     * the number says, it is the truth about that car right now — our stored mileage is the thing that may
     * be stale (a rental leg nobody closed, a yard move, an OM reading that never landed). Refusing the
     * entry doesn't make the dial change; it only teaches the inspector to re-type our old number, which is
     * the one outcome that actually corrupts the chain — exactly the lesson already learned for the forward
     * drift (see STATUS_AUTHORIZED above).
     *
     * So at this stage every reading is ACCEPTED and recorded, and any deviation — forward past the buffer
     * OR backward — is filed to the odometer approval board (/odometer-approvals) for a supervisor to
     * settle after the fact. The universal MAX_JUMP_KM typo guard still applies: a six-million-km entry is
     * not "a different mileage", it's a slipped finger, and it must never reach vehicles.odometer.
     *
     * The other strict-match stages keep the hard block: by then the car's mileage has already been
     * anchored by this very stage, so a backward reading there really is a mis-key.
     */
    public const REVIEW_NOT_BLOCK_STAGES = [self::STAGE_TEST];

    /** Is this a strict-match stage where a deviation goes to the approval board instead of being refused? */
    public function stageReviewsInsteadOfBlocking(string $stage): bool
    {
        return in_array($stage, self::REVIEW_NOT_BLOCK_STAGES, true);
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
     * Does this (accepted) flag deserve a supervisor's eyes on the odometer approval board?
     *
     * Yes for an authorized deviation that ran further than the technical buffer at an at-our-park
     * spot-check — the car moved when our records say it was standing still, which is a real operational
     * question ("who drove it, and why wasn't it logged?") even though the reading itself is accepted. A
     * 1..TOLERANCE_KM drift is just dial-reading noise and stays a note-only event, exactly as before.
     *
     * At a REVIEW_NOT_BLOCK stage ("Needs Test Drive") the board is also the landing place for a BACKWARD
     * reading, because nothing refuses it any more — the approval queue is the only thing standing between
     * "the inspector read the dial" and "our stored mileage is wrong". Pass $stage to get that behaviour;
     * omit it and the original forward-only rule applies.
     */
    public function needsSupervisorReview(array $flag, ?string $stage = null): bool
    {
        $status = $flag['status'] ?? null;

        if ($stage !== null && $this->stageReviewsInsteadOfBlocking($stage)) {
            // Backward (STATUS_EXACT at a strict-match stage) is now accepted — so it must be reviewed.
            if ($status === self::STATUS_EXACT) {
                return true;
            }
            // Forward drift keeps the buffer: 1..TOLERANCE_KM is dial-reading noise, not an event.
            return $status === self::STATUS_AUTHORIZED
                && (int) ($flag['delta'] ?? 0) > self::TOLERANCE_KM;
        }

        return $status === self::STATUS_AUTHORIZED
            && (int) ($flag['delta'] ?? 0) > self::TOLERANCE_KM;
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

        // Universal forward guard, checked FIRST and on every stage — including the garage-transfer stages
        // that waive the tolerance nag, because "the car was driven" explains 500 km, never 6 million.
        // This is the only rule here that bounds the UPWARD direction; everything below bounds the
        // downward one. A jump this large is a mis-typed dial, and it must not reach vehicles.odometer.
        if ($delta > self::MAX_JUMP_KM) {
            return $this->flag(self::STATUS_IMPLAUSIBLE, $previous, $reading, $delta);
        }

        // Strict-match stage (an internal spot-check at our own park): the car shouldn't have moved since
        // the previous reading. An exact match is clean; a 1..TOLERANCE forward drift is an "authorized
        // deviation" that must carry a note. A BACKWARD reading is always a hard block (a typo — the car
        // can't be behind where it last was), NOT a soft discrepancy.
        if ($this->stageRequiresExactMatch($stage)) {
            if ($delta === 0) {
                return $this->flag(self::STATUS_VERIFIED, $previous, $reading, $delta);
            }
            if ($delta > 0) {
                // A forward drift at an at-our-park spot-check is an AUTHORIZED DEVIATION at any size —
                // never a wall. It used to hard-block past TOLERANCE_KM ("the car shouldn't have moved"),
                // but the car sometimes genuinely did move those extra km (a yard shuffle, a fuel run,
                // someone else took it), and re-reading the dial cannot make a true reading go away — the
                // block only taught drivers to re-type the previous number, which is the one outcome that
                // actually corrupts the chain. So the reading is accepted WITH a mandatory note, and a
                // deviation beyond the buffer (see needsSupervisorReview) is filed to the odometer
                // approval board for a supervisor to audit after the fact. The universal MAX_JUMP_KM
                // guard above still catches the mis-typed dial.
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

            case self::STAGE_TEST_END:
                // The end-of-test-drive reading moved more than tolerance since the start-of-drive anchor →
                // the inspector actually drove the car. Legitimate; ask for a confirm, never block.
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
