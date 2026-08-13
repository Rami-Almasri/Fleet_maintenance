// Odometer Continuity Rules — the client-side twin of the backend OdometerContinuityService.
//
// It lets the photo-upload modal give the driver INSTANT feedback the moment they type a reading —
// "Verified", a big-jump nudge, a garage test-drive confirm, or a backward "Discrepancy" — so honest
// mistakes are caught before submit, not flagged after the fact. The backend re-evaluates and stores
// the authoritative verdict; this is purely for a friendly, in-the-moment experience.
//
// The status strings + thresholds MUST stay in lock-step with OdometerContinuityService (PHP).

export const TOLERANCE_KM = 5;      // small realistic drift we never treat as an error
export const PICKUP_WARN_KM = 50;   // a pickup jump beyond this earns a "double-check the dial" nudge
export const NOTE_THRESHOLD_KM = 10; // a gap beyond this (in EITHER direction) from the previous reading demands a written note
// The largest forward jump that can be true between two consecutive readings. Every other rule in this
// file bounds how far a reading may run BACKWARDS; this is the only one bounding forwards. A garage-out
// reading of 6,276,888 km against an intake of 62,769 was accepted as a "test drive" and corrupted three
// vehicles' mileage — this is the typo guard that stops it. Mirrors MAX_JUMP_KM in the PHP service.
export const MAX_JUMP_KM = 20000;

// Which continuity rule applies at each capture step.
export const STAGE = {
  TEST: 'test',             // inspector's start reading vs the car's current mileage (strict match — car in our park)
  PICKUP: 'pickup',         // generic pickup reading vs the last recorded mileage (logistics round-trip)
  PARK_PICKUP: 'park_pickup',// maintenance driver collecting the car FROM OUR PARK for the garage (strict match)
  GARAGE_IN: 'garage_in',   // garage intake (receive) vs the pickup reading — DRIVEN leg, must be strictly higher
  GARAGE_IN_RECOVERY: 'garage_in_recovery', // garage intake vs pickup, but the leg was a RECOVERY TOW — the car
                                             // doesn't accumulate mileage under its own power, so the SAME
                                             // reading is legitimate; only a decrease is still impossible.
  GARAGE_OUT: 'garage_out', // car leaves the garage (return) vs the intake reading
  PARK_ARRIVAL: 'park_arrival', // car driven back FROM the garage TO our park — a DRIVEN leg, so the arrival
                                 // reading must be strictly higher than the garage-OUT reading (it covered
                                 // real distance getting home). Mirrors the backend arriveAtPark() must-increase.
  RETURN: 'return',         // final reading vs the last recorded, when there's no intake to compare
  TRANSFER: 'transfer',     // car driven from one garage to another vs the last recorded mileage
  TEST_END: 'test_end',     // end-of-test-drive reading vs the start-of-drive anchor — the car WAS driven, so forward movement is expected, not a strict match
  REINSPECT: 'reinspect',   // final QA sign-off vs the park-arrival reading — the car is back at OUR PARK and shouldn't have moved, so a strict ±TOLERANCE cap applies
};

// Verdict statuses (contract with the badges in TicketDetailDrawer + labels.js).
export const STATUS = {
  VERIFIED: 'verified',
  DISCREPANCY: 'discrepancy',
  TEST_DRIVE: 'test_drive',
  CHECK: 'check',
  MUST_INCREASE: 'must_increase',            // strict-increase stage: reading is ≤ the previous one — a HARD block
  AUTHORIZED: 'authorized_deviation',        // strict-match stage: +1..TOLERANCE km — allowed WITH a note (audited on oversight)
  EXACT_MATCH: 'exact_required',             // strict-match stage: backward, or > TOLERANCE over — a HARD block
  IMPLAUSIBLE: 'implausible',                // forward jump beyond MAX_JUMP_KM — a typo, not a journey. HARD block on EVERY stage.
};

// Strict-match stages — an internal spot-check at OUR OWN PARK where the car shouldn't have moved since the
// previous reading (the inspector's test capture, and the driver collecting the car for the garage). An exact
// match is expected; a +1..TOLERANCE_KM drift is tolerated only WITH a note; anything beyond, or any backward
// reading, HARD-blocks submit. Mirror of STRICT_MATCH_STAGES in OdometerContinuityService.php — keep in step.
export const STRICT_MATCH_STAGES = new Set([STAGE.TEST, STAGE.PARK_PICKUP, STAGE.REINSPECT]);

/** Does `stage` require the reading to match the previous one (exact, or +TOLERANCE_KM with a note)? */
export function stageRequiresExactMatch(stage) {
  return STRICT_MATCH_STAGES.has(stage);
}

// Strict-match stages that REVIEW instead of BLOCK. "Needs Test Drive" is the first time anyone physically
// walks up to the car and reads its dial — whatever it says is the truth about that car right now, and our
// stored mileage is the thing that may be stale. Refusing the entry can't change the dial; it only teaches
// the inspector to re-type our old number, which is what actually corrupts the chain. So here every reading
// goes through, and any deviation (forward past the buffer, or backward) is filed to the odometer approval
// board for a supervisor to settle afterwards. The MAX_JUMP_KM typo guard still stands.
// Mirror of REVIEW_NOT_BLOCK_STAGES in OdometerContinuityService.php — keep the two in step.
export const REVIEW_NOT_BLOCK_STAGES = new Set([STAGE.TEST]);

/** Is `stage` a strict-match stage where a deviation is reviewed after the fact instead of refused? */
export function stageReviewsInsteadOfBlocking(stage) {
  return REVIEW_NOT_BLOCK_STAGES.has(stage);
}

// Stages where the reading MUST be strictly higher than the previous one — no tolerance, no override.
// The car was GUARANTEED to have moved since the previous checkpoint (driven to the garage), so an equal
// or lower value is a typo or a mis-read, never a real reading. This mirrors the backend hard block in
// MaintenanceWorkflowService::assertMustIncrease(). Unlike the soft ±5 km tolerance cases, a violation
// here BLOCKS submit — the driver must fix the reading, not ack it.
export const STRICT_INCREASE_STAGES = new Set([STAGE.GARAGE_IN, STAGE.PARK_ARRIVAL]);

/** Does `stage` require the reading to strictly exceed the previous one (hard block, no override)? */
export function stageRequiresIncrease(stage) {
  return STRICT_INCREASE_STAGES.has(stage);
}

// Stages where the car MAY have moved since the previous checkpoint but isn't guaranteed to (a garage
// road test is optional; a final sign-off can land on the exact same reading as the checkpoint right
// before it, since arriveAtPark captures no odometer of its own; a recovery tow/garage transfer may find
// the car exactly where it last was recorded; an inspector can diagnose a fault without ever driving the
// car, so the end-of-test-drive reading can legitimately match the start). Equal is fine; only a
// DECREASE is physically impossible — an odometer never runs backwards, at any stage — so it's
// hard-blocked regardless. Mirrors the backend's assertNoDecrease(), now applied everywhere an odometer
// is captured.
export const NO_DECREASE_STAGES = new Set([STAGE.GARAGE_OUT, STAGE.RETURN, STAGE.PICKUP, STAGE.TRANSFER, STAGE.TEST_END, STAGE.GARAGE_IN_RECOVERY]);

/** Does `stage` forbid a lower reading than the previous one, while still allowing an equal one? */
export function stageForbidsDecrease(stage) {
  return NO_DECREASE_STAGES.has(stage);
}

// Stages that are an EXPECTED road trip between our site and a garage: the car is deliberately driven, so a
// mileage INCREASE is natural and legitimate — never a "discrepancy" to explain. For these we waive the
// ±10 km note/confirm nag on forward travel (the reason for the trip). A backward reading is still
// impossible, so the DISCREPANCY guard is kept even here (see needsConfirm/needsNote). A garage-to-garage
// TRANSFER belongs here too — the car is physically driven from one garage to the next, so its forward
// distance is expected and shouldn't demand a written "explain why" (which contradicted the "Verified —
// lines up" verdict shown for the very same reading).
// This is the single source of truth mirrored by OdometerContinuityService::stageIgnoresTolerance() (PHP).
export const GARAGE_TRANSFER_STAGES = new Set([STAGE.GARAGE_IN, STAGE.GARAGE_OUT, STAGE.TRANSFER, STAGE.PARK_ARRIVAL]);

/** Is `stage` a site↔garage / garage↔garage move where the tolerance nag is waived? */
export function stageIgnoresTolerance(stage) {
  return GARAGE_TRANSFER_STAGES.has(stage);
}

/**
 * Classify a typed reading against the previous one for a stage. The chosen `stage` is echoed back on the
 * verdict so a consumer (e.g. the hint) can tailor its wording per stage without re-deriving it.
 * @returns {{status:string, previous:?number, reading:number, delta:?number, stage:string}|null}
 *          null while the reading isn't a usable number yet (nothing to say).
 */
export function evaluateContinuity(reading, previous, stage) {
  const res = classifyContinuity(reading, previous, stage);
  return res ? { ...res, stage } : null;
}

function classifyContinuity(reading, previous, stage) {
  const r = Number(reading);
  if (!Number.isFinite(r) || r <= 0) return null;

  const hasPrev = previous != null && Number.isFinite(Number(previous));
  if (!hasPrev) return { status: STATUS.VERIFIED, previous: null, reading: r, delta: null };

  const p = Number(previous);
  const delta = r - p;

  // UNIVERSAL forward guard, checked FIRST and on every stage — including the garage-transfer stages that
  // waive the tolerance nag, because "the car was driven" explains 500 km, never 6 million. Mirrors the
  // hard block in MaintenanceWorkflowService::recordOdometerFlag, so the modal refuses it before the
  // request is ever sent.
  if (delta > MAX_JUMP_KM) return { status: STATUS.IMPLAUSIBLE, previous: p, reading: r, delta };

  // Strict-match stage (internal park spot-check): the car shouldn't have moved since the previous reading.
  // Exact = clean; a +1..TOLERANCE drift is an authorized deviation that needs a note. A BACKWARD reading
  // is always a hard block (a typo — the car can't be behind where it last was). Takes priority over the
  // generic guards below.
  if (STRICT_MATCH_STAGES.has(stage)) {
    if (delta === 0) return { status: STATUS.VERIFIED, previous: p, reading: r, delta };
    // ANY forward drift is an authorized deviation, never a wall — small in-lot moves beyond TOLERANCE_KM
    // are real and can't be fixed by re-reading the dial, so the reading goes through with a mandatory
    // confirm + note. Past the buffer it also lands on the odometer approval board for a supervisor to
    // audit after the fact (see needsApproval). Mirrors the PHP service's strict-match branch.
    if (delta > 0) return { status: STATUS.AUTHORIZED, previous: p, reading: r, delta };
    return { status: STATUS.EXACT_MATCH, previous: p, reading: r, delta };
  }

  // Strict-increase stage (garage intake, park arrival): the car was GUARANTEED to have moved here (driven
  // to the garage, or driven back from it to our park), so the reading MUST be higher than the previous one.
  // Equal or lower is impossible — a hard block, taking priority over the tolerance buffer below (an equal
  // reading is NOT "close enough" here).
  if (stageRequiresIncrease(stage) && delta <= 0) {
    return { status: STATUS.MUST_INCREASE, previous: p, reading: r, delta };
  }
  // No-decrease stage (garage-out, final sign-off): the car MAY not have moved further, so equal is fine
  // — only an actual decrease is impossible and hard-blocked.
  if (stageForbidsDecrease(stage) && delta < 0) {
    return { status: STATUS.MUST_INCREASE, previous: p, reading: r, delta };
  }

  // Backward beyond tolerance can never be right — an odometer doesn't reverse.
  if (delta < -TOLERANCE_KM) return { status: STATUS.DISCREPANCY, previous: p, reading: r, delta };
  // Within the buffer either way → the same reading: Verified.
  if (Math.abs(delta) <= TOLERANCE_KM) return { status: STATUS.VERIFIED, previous: p, reading: r, delta };

  // Forward, beyond the buffer — legitimate movement; how we label it depends on the stage.
  if (stage === STAGE.PICKUP || stage === STAGE.TRANSFER) {
    return { status: delta > PICKUP_WARN_KM ? STATUS.CHECK : STATUS.VERIFIED, previous: p, reading: r, delta };
  }
  if (stage === STAGE.GARAGE_OUT || stage === STAGE.TEST_END) {
    return { status: STATUS.TEST_DRIVE, previous: p, reading: r, delta };
  }
  return { status: STATUS.VERIFIED, previous: p, reading: r, delta };
}

/**
 * Does this verdict need the driver's explicit acknowledgment before submitting? A backward reading
 * (likely a typo), a big pickup jump, and a garage test drive all ask for a conscious confirm — but
 * none of them BLOCK: ticking the box lets the workflow proceed exactly as before.
 */
export function needsConfirm(status, ignoreTolerance = false, stage = undefined) {
  // At a review-not-block stage a backward reading is ACCEPTED, so it flips from "hard block, no checkbox"
  // to exactly what the checkbox is for: a conscious "yes, that's really what the dial says" before the
  // entry goes off to the approval board.
  if (status === STATUS.EXACT_MATCH && stageReviewsInsteadOfBlocking(stage)) return true;
  // A must-increase / exact-match violation is a HARD block, not an overridable ack — the reading has to be
  // fixed, so we never offer a "confirm anyway" checkbox for it (see isHardBlocked, which blocks submit).
  if (status === STATUS.MUST_INCREASE || status === STATUS.EXACT_MATCH) return false;
  // A backward reading can't be true whatever the transition — always ask, even on a garage trip.
  if (status === STATUS.DISCREPANCY) return true;
  // On a site↔garage move the forward jump (big pickup / garage test drive) IS the expected event, so
  // we don't nag for it.
  if (ignoreTolerance) return false;
  return status === STATUS.CHECK || status === STATUS.TEST_DRIVE;
}

/**
 * Does the reading deviate from the previous one by more than NOTE_THRESHOLD_KM (over OR under)? If so
 * the operator must WRITE why — a big gap is either a genuine event (a long test drive, a re-fuel run)
 * or a typo, and either way we want the explanation captured on the mileage chain, not lost. Returns
 * false when there's no previous reading to measure against (an anchor can't "deviate").
 */
export function needsNote(continuity, ignoreTolerance = false) {
  // A hard-blocked verdict (must-increase / exact-match) can't be explained away with a note — the reading
  // itself has to be fixed. Asking for a note here would misleadingly imply an override path exists.
  if (isHardBlocked(continuity)) return false;
  // At a review-not-block stage the note is INVITED, never demanded: a mandatory field is just a block
  // wearing another hat, and the whole point of this stage is that nothing stands between the inspector
  // and the number on the dial. The deviation still reaches a supervisor — via the approval board rather
  // than via a sentence typed under duress.
  if (stageReviewsInsteadOfBlocking(continuity?.stage)) return false;
  // A strict-match "authorized deviation" (+1..TOLERANCE at a park spot-check) ALWAYS demands a note — that
  // note is exactly what a supervisor audits on the Mileage Discrepancies board. It fires below the generic
  // 10 km threshold, so it's checked first.
  if (continuity?.status === STATUS.AUTHORIZED) return true;
  // Site↔garage moves accrue real distance by design — a big forward gap is the whole point of the trip,
  // so we don't demand a written explanation. (A backward reading still surfaces via needsConfirm.)
  if (ignoreTolerance) return false;
  const d = continuity?.delta;
  return d != null && Math.abs(d) > NOTE_THRESHOLD_KM;
}

/**
 * Is this verdict a HARD block that cannot be acknowledged away? A strict-increase violation
 * (must_increase) or a strict-match violation (exact_required) qualifies — the operator must correct
 * the reading before submit, with no confirm/note override offered. Callers OR this into their submit
 * gate alongside the soft ack/note requirements.
 */
export function isHardBlocked(continuity) {
  // The typo guard is UNIVERSAL and comes first — it holds even at a review-not-block stage, and even
  // there it is the one thing that must never reach vehicles.odometer. It was previously only enforced
  // server-side (the modal submitted and the request threw); now that "Needs Test Drive" waives every
  // other client-side gate, this is the gate that has to be real here.
  if (continuity?.status === STATUS.IMPLAUSIBLE) return true;
  // Otherwise nothing at a review-not-block stage blocks submit — a deviation there is recorded and sent
  // to the approval board, not refused.
  if (stageReviewsInsteadOfBlocking(continuity?.stage)) return false;
  return continuity?.status === STATUS.MUST_INCREASE || continuity?.status === STATUS.EXACT_MATCH;
}

/**
 * Will this (accepted) reading be sent to the odometer approval board? True for a forward drift past the
 * buffer at an at-our-park spot-check: the car moved when our records say it was standing still, so the
 * reading is taken but a supervisor is asked to confirm it afterwards. The modal says so up front, so the
 * driver knows the entry is going to be looked at rather than quietly swallowed.
 * Mirrors OdometerContinuityService::needsSupervisorReview() (PHP).
 */
export function needsApproval(continuity) {
  // A BACKWARD reading at a review-not-block stage is accepted rather than refused, so the approval board
  // is the only thing left standing between the dial and our stored mileage — say so in the modal.
  if (continuity?.status === STATUS.EXACT_MATCH && stageReviewsInsteadOfBlocking(continuity?.stage)) return true;
  return continuity?.status === STATUS.AUTHORIZED && (continuity?.delta ?? 0) > TOLERANCE_KM;
}

// Visual tone per status (Tailwind palette family) — shared by the modal hint + drawer badge.
export const CONTINUITY_TONE = {
  [STATUS.VERIFIED]: 'emerald',
  [STATUS.DISCREPANCY]: 'red',
  [STATUS.TEST_DRIVE]: 'amber',
  [STATUS.CHECK]: 'amber',
  [STATUS.MUST_INCREASE]: 'red',
  [STATUS.AUTHORIZED]: 'amber',
  [STATUS.EXACT_MATCH]: 'red',
  [STATUS.IMPLAUSIBLE]: 'red',
  // Not a STATUS — the badge key the hint swaps in for a backward reading at a review-not-block stage,
  // where the entry is accepted and routed to a supervisor. Amber (something to settle), never red (a wall).
  exact_required_review: 'amber',
};
