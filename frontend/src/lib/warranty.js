// Warranty vocabulary, shared by every surface that renders it.
//
// THE POINT OF THIS FILE is that the vehicle list, the vehicle page, the procurement gate and the
// case board must never disagree about what "expiring soon" looks like or which verdict is bad news.
// The tones and the sentence-builders live here once; nothing below re-derives a state.
//
// NOTHING HERE DECIDES ANYTHING. Every state and verdict is computed server-side against the car's
// current odometer (see WarrantyStatusService / WarrantyCoverageEngine) — a warranty bounded by
// distance ends when the car reaches a number, and the browser has no business guessing at that.
// These helpers only choose how to SAY what the server already decided.

export const WARRANTY_STATES = ['under_warranty', 'expiring_soon', 'expired', 'none'];

// Green = protected · amber = protected but the window is closing · slate = ended · gray = nothing
// recorded. `none` is deliberately NOT red: a car whose warranty booklet is still in the glovebox
// has not failed at anything, and colouring it like a problem would train people to ignore the
// colour on the cars that genuinely have none.
export const WARRANTY_STATE_TONE = {
  under_warranty: 'green',
  expiring_soon: 'amber',
  expired: 'slate',
  none: 'gray',
};

// The gate's three answers. `unknown` is amber, not gray: it stops a purchase exactly as hard as
// `covered` does, and a neutral colour would read as "no news".
export const VERDICT_TONE = {
  covered: 'green',
  not_covered: 'slate',
  unknown: 'amber',
};

// Case stages. Amber wherever somebody owes an action, blue while the provider has it, emerald at
// the end, slate for the terminal "ours to pay" — which is a success, not a failure.
export const STAGE_TONE = {
  identified: 'gray',
  coverage_review: 'amber',
  covered: 'green',
  not_covered: 'slate',
  authorization_requested: 'blue',
  authorized: 'green',
  sent_to_provider: 'blue',
  repair_in_progress: 'blue',
  repair_completed: 'cyan',
  claim_submitted: 'indigo',
  recovery_recorded: 'emerald',
  closed: 'slate',
};

/** Does this verdict stop a normal purchase? Mirrors WarrantyCoverage::BLOCKING on the server. */
export const blocksProcurement = (verdict) => verdict === 'covered' || verdict === 'unknown';

/**
 * "4 months / 21,500 km" — what is LEFT, which is the only number anyone can plan around.
 *
 * Either leg may be null and null means UNKNOWABLE, never zero. A car whose remaining cover reads
 * "0 km" because nobody submitted an odometer reading is exactly the car that gets written off as
 * expired while it is still under warranty, so an absent leg is simply omitted from the sentence.
 */
export const remainingText = (verdict, t) => {
  if (!verdict) return null;
  const bits = [];
  if (verdict.days_remaining !== null && verdict.days_remaining !== undefined) {
    bits.push(t('warrantyOps.vehicle.remainingDays', { n: verdict.days_remaining }));
  }
  if (verdict.km_remaining !== null && verdict.km_remaining !== undefined) {
    bits.push(t('warrantyOps.vehicle.remainingKm', { n: Number(verdict.km_remaining).toLocaleString() }));
  }
  return bits.length ? bits.join(' / ') : null;
};

/**
 * The engine's reason, as a sentence, composed from its CODE and params.
 *
 * The server never sends an English sentence about coverage ([[reason-code-contract]]) — it sends
 * `cover_not_itemised` plus `{part}`. That is what makes the Arabic a translation rather than a
 * second implementation, and it is why this function exists instead of a `reason_text` field.
 */
export const reasonText = (assessment, t) => {
  if (!assessment?.reason_code) return null;
  return t(`warrantyOps.reason.${assessment.reason_code}`, assessment.reason_params || {});
};
