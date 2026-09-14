// Warranty vocabulary, shared by the three surfaces that show it: the vehicle list chip, the car's
// warranty card, and the recommendation banner in the maintenance cycle.
//
// NOTHING HERE DECIDES ANYTHING. Whether a car is under warranty is computed server-side against its
// current odometer (cover ends on months OR kilometres, whichever comes first — see
// WarrantyStatusService). A browser comparing an expiry date to today would report cover on exactly
// the hard-driven cars whose warranties lapse first. These helpers only choose how to SAY what the
// server already decided.

export const WARRANTY_STATES = ['under_warranty', 'expiring_soon', 'expired', 'none'];

// Green = covered · amber = covered but running out · slate = ended · gray = nothing recorded.
//
// `none` is deliberately NOT red: a car whose warranty booklet is still in the glovebox has not
// failed at anything. Colouring it like a problem would train people to ignore the colour on the
// cars that genuinely have none.
export const WARRANTY_STATE_TONE = {
  under_warranty: 'green',
  expiring_soon: 'amber',
  expired: 'slate',
  none: 'gray',
};

const has = (n) => n !== null && n !== undefined;
const num = (n) => Number(n).toLocaleString();

/**
 * "930 days / 99,997 km" — what is LEFT, which is the only figure anyone can plan around.
 *
 * Either leg may be null, and null means UNKNOWABLE, never zero. A car whose remaining cover reads
 * "0 km" because nobody submitted an odometer reading is exactly the car that gets written off as
 * expired while it is still covered — so an absent leg is omitted from the sentence rather than
 * rendered as a number.
 */
export const remainingText = (verdict, t) => {
  if (!verdict) return null;
  const bits = [];
  if (has(verdict.days_remaining)) bits.push(t('warranty.remainingDays', { n: verdict.days_remaining }));
  if (has(verdict.km_remaining)) bits.push(t('warranty.remainingKm', { n: num(verdict.km_remaining) }));
  return bits.length ? bits.join(' / ') : null;
};

/**
 * "25,167 km over limit" — how far PAST the limit a car already is.
 *
 * The fleet's own warranty report prints this as a negative (`-25,167`), and it is the number
 * somebody standing next to the car actually needs: "expired" alone cannot distinguish a car that
 * went over last week — still worth a phone call — from one that went over two years ago.
 *
 * Rendered from a POSITIVE magnitude the server sends under its own key, so nothing here can
 * accidentally print "-25,167 km remaining".
 */
export const overText = (verdict, t) => {
  if (!verdict) return null;
  const bits = [];
  if (has(verdict.km_over)) bits.push(t('warranty.overKm', { n: num(verdict.km_over) }));
  if (has(verdict.days_over)) bits.push(t('warranty.overDays', { n: num(verdict.days_over) }));
  return bits.length ? bits.join(' / ') : null;
};

/**
 * The one line the card shows: what is left, or how far over. Never both — a warranty is on one
 * side of its limit or the other, and showing two numbers would invite somebody to subtract them.
 */
export const coverText = (verdict, t) => overText(verdict, t) || remainingText(verdict, t);

// ── Service contracts ────────────────────────────────────────────────────────────────────────────
// A different promise from a warranty: an allowance of scheduled servicing that is CONSUMED. It runs
// out three ways — date, odometer, and the count of services used — and the count is the one people
// actually hit, so it leads the sentence.

export const SERVICE_STATE_TONE = { active: 'green', expired: 'slate', ended: 'gray' };

/**
 * "3 of 5 services left · 26,312 km · 18 Feb 2028" — or, once it has run out, what finished it.
 *
 * Services first because it is the most concrete and the least arguable figure on the contract, and
 * because a contract with two years left on paper is finished the moment its fifth service is used.
 */
export const serviceCoverText = (verdict, contract, t) => {
  if (!verdict) return null;

  if (verdict.state !== 'active') {
    if (has(verdict.km_over)) return t('serviceContract.overKm', { n: num(verdict.km_over) });
    if (verdict.ended_by === 'services') return t('serviceContract.allUsed');
    return null;
  }

  const bits = [];
  if (has(verdict.services_remaining) && has(contract?.services_total)) {
    bits.push(t('serviceContract.servicesLeft', { n: verdict.services_remaining, total: contract.services_total }));
  }
  if (has(verdict.km_remaining)) bits.push(t('warranty.remainingKm', { n: num(verdict.km_remaining) }));
  if (has(verdict.days_remaining)) bits.push(t('warranty.remainingDays', { n: verdict.days_remaining }));
  return bits.length ? bits.join(' · ') : null;
};
