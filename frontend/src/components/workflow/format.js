// Shared formatting for the recommendation surfaces.
//
// Small enough to be tempting to inline, which is exactly why it lives here: the Dispatch Plan and the
// evidence panel it expands into render the same figures side by side, and the moment one of them says
// "1 days" while the other says "one day", the whole panel reads as machine output.

/**
 * A turnaround in words.
 *
 * Two traps. A median of 0 is a real measurement — the car went and came back the same day — but
 * "0 days" reads as missing data. And exactly one day must not print as "1 days"; English needs the
 * singular, and the label table is the only place that can know that.
 */
export function days(n, gr) {
  if (n == null) return '—';
  if (n < 0.5) return gr('outcomes.sameDay');
  return Math.round(n * 10) / 10 === 1 ? gr('outcomes.day') : gr('outcomes.days', { n });
}
