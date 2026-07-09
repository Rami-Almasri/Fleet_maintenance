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

// Which continuity rule applies at each capture step.
export const STAGE = {
  TEST: 'test',            // inspector's start reading vs the car's current mileage
  PICKUP: 'pickup',        // driver's pickup (dispatch) reading vs the last recorded mileage
  GARAGE_IN: 'garage_in',  // garage intake (receive) vs the pickup reading
  GARAGE_OUT: 'garage_out',// car leaves the garage (return) vs the intake reading
  RETURN: 'return',        // final reading vs the last recorded, when there's no intake to compare
  TRANSFER: 'transfer',    // car driven from one garage to another vs the last recorded mileage
};

// Verdict statuses (contract with the badges in TicketDetailDrawer + labels.js).
export const STATUS = {
  VERIFIED: 'verified',
  DISCREPANCY: 'discrepancy',
  TEST_DRIVE: 'test_drive',
  CHECK: 'check',
};

// Stages that are an EXPECTED road trip between our site and a garage (or garage-to-garage): the car is
// deliberately driven, so a mileage INCREASE is natural and legitimate — never a "discrepancy" to explain.
// For these we waive the ±10 km note/confirm nag on forward travel (the reason for the trip). A backward
// reading is still impossible, so the DISCREPANCY guard is kept even here (see needsConfirm/needsNote).
// This is the single source of truth mirrored by OdometerContinuityService::stageIgnoresTolerance() (PHP).
export const GARAGE_TRANSFER_STAGES = new Set([STAGE.GARAGE_IN, STAGE.GARAGE_OUT, STAGE.TRANSFER]);

/** Is `stage` a site↔garage / garage↔garage move where the tolerance nag is waived? */
export function stageIgnoresTolerance(stage) {
  return GARAGE_TRANSFER_STAGES.has(stage);
}

/**
 * Classify a typed reading against the previous one for a stage.
 * @returns {{status:string, previous:?number, reading:number, delta:?number}|null}
 *          null while the reading isn't a usable number yet (nothing to say).
 */
export function evaluateContinuity(reading, previous, stage) {
  const r = Number(reading);
  if (!Number.isFinite(r) || r <= 0) return null;

  const hasPrev = previous != null && Number.isFinite(Number(previous));
  if (!hasPrev) return { status: STATUS.VERIFIED, previous: null, reading: r, delta: null };

  const p = Number(previous);
  const delta = r - p;

  // Backward beyond tolerance can never be right — an odometer doesn't reverse.
  if (delta < -TOLERANCE_KM) return { status: STATUS.DISCREPANCY, previous: p, reading: r, delta };
  // Within the buffer either way → the same reading: Verified.
  if (Math.abs(delta) <= TOLERANCE_KM) return { status: STATUS.VERIFIED, previous: p, reading: r, delta };

  // Forward, beyond the buffer — legitimate movement; how we label it depends on the stage.
  if (stage === STAGE.PICKUP || stage === STAGE.TRANSFER) {
    return { status: delta > PICKUP_WARN_KM ? STATUS.CHECK : STATUS.VERIFIED, previous: p, reading: r, delta };
  }
  if (stage === STAGE.GARAGE_OUT) {
    return { status: STATUS.TEST_DRIVE, previous: p, reading: r, delta };
  }
  return { status: STATUS.VERIFIED, previous: p, reading: r, delta };
}

/**
 * Does this verdict need the driver's explicit acknowledgment before submitting? A backward reading
 * (likely a typo), a big pickup jump, and a garage test drive all ask for a conscious confirm — but
 * none of them BLOCK: ticking the box lets the workflow proceed exactly as before.
 */
export function needsConfirm(status, ignoreTolerance = false) {
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
  // Site↔garage moves accrue real distance by design — a big forward gap is the whole point of the trip,
  // so we don't demand a written explanation. (A backward reading still surfaces via needsConfirm.)
  if (ignoreTolerance) return false;
  const d = continuity?.delta;
  return d != null && Math.abs(d) > NOTE_THRESHOLD_KM;
}

// Visual tone per status (Tailwind palette family) — shared by the modal hint + drawer badge.
export const CONTINUITY_TONE = {
  [STATUS.VERIFIED]: 'emerald',
  [STATUS.DISCREPANCY]: 'red',
  [STATUS.TEST_DRIVE]: 'amber',
  [STATUS.CHECK]: 'amber',
};
