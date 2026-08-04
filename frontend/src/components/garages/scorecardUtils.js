// Shared reading rules for the garage scorecard surfaces.
//
// One place so the score pill, the matrix cell and the leaderboard row can never
// disagree about what "good" looks like — a garage tinted green in one panel and
// amber in another is worse than no colour at all.
//
// THE COLOUR SCALE IS DIVERGING, not sequential: every number on this page is a
// comparison against the fleet, so it has a meaningful zero (on par) and two
// directions. Two hues, a NEUTRAL grey midpoint, and no hue in the middle —
// "slightly better than the fleet" must not look like a category of its own.
// Colour is never the only carrier: every cell prints its number and every chip
// prints its word.

/** Grades the backend can put on a (garage, repair area) cell. */
export const GRADES = {
  strong: { tone: 'green', word: 'strong' },
  on_par: { tone: 'slate', word: 'on par' },
  weak: { tone: 'red', word: 'problem' },
  thin: { tone: 'gray', word: 'too few repairs' },
  not_graded_exposure: { tone: 'gray', word: 'not graded' },
};

/**
 * Overall score → tone. 50 is "exactly what the fleet does on this mix of work",
 * so the bands are symmetric around it rather than around an arbitrary 70.
 */
export function scoreTone(v) {
  if (v == null) return 'gray';
  if (v >= 65) return 'green';
  if (v >= 55) return 'emerald';
  if (v > 45) return 'slate';
  if (v > 35) return 'amber';
  return 'red';
}

/** Bar fill for the scoreboard — same bands as scoreTone, as background classes. */
export function scoreBar(v) {
  if (v == null) return 'bg-slate-200';
  if (v >= 65) return 'bg-emerald-500';
  if (v >= 55) return 'bg-emerald-400';
  if (v > 45) return 'bg-slate-400';
  if (v > 35) return 'bg-amber-400';
  return 'bg-red-500';
}

/**
 * Diverging fill for a matrix cell, from the gap to the fleet in percentage
 * points of comeback rate. Negative = fewer repairs come back = better.
 *
 * The middle step is grey on purpose (see the header): inside the materiality
 * band the honest reading is "no difference", and tinting it green would invent
 * a finding out of noise.
 */
export function deltaCell(pts) {
  if (pts == null) return { bg: 'bg-slate-50', text: 'text-slate-400' };
  if (pts <= -25) return { bg: 'bg-emerald-600', text: 'text-white' };
  if (pts <= -15) return { bg: 'bg-emerald-500', text: 'text-white' };
  if (pts <= -8) return { bg: 'bg-emerald-200', text: 'text-emerald-900' };
  if (pts < 8) return { bg: 'bg-slate-200', text: 'text-slate-700' };
  if (pts < 15) return { bg: 'bg-red-200', text: 'text-red-900' };
  if (pts < 25) return { bg: 'bg-red-500', text: 'text-white' };
  return { bg: 'bg-red-600', text: 'text-white' };
}

// There was a deltaWords() here — "26 points better than the fleet" — for the matrix tooltip. The
// tooltip now says "come back less than half as often as at other garages" instead (phrasing.js):
// a point gap is a second number to interpret, and the tooltip's whole job was to spare the reader
// the one already in the cell. Removed with its labels rather than left for nobody.

/** Confidence band → tone. Low is not a failure, it is a warning about the sample. */
export const BAND_TONE = { high: 'green', medium: 'amber', low: 'gray' };
