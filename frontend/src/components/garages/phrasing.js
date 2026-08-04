// HOW THE SCORECARD TALKS.
//
// One vocabulary, used by the matrix tooltip, the strength/problem chips and the headline on every
// garage card, so the same fact cannot be worded three ways on one screen.
//
// THE RULE THAT PRODUCED THIS FILE: one number per sentence.
//
// The tooltips and headlines used to read "17 of every 100 repairs come back within 90 days, against
// 43 across the fleet — 26 points better. Based on 30 repairs." Every figure in it is true and it
// carries SIX of them — 17, 100, 90, 43, 26, 30 — which is a paragraph to parse for one cell of a
// grid. Nobody hovering a cell wants to do that arithmetic; they want to know whether this garage is
// good at tyres.
//
// So the comparison is said as a RATIO in words — "less than half as often", "about twice as often" —
// and the only figure that survives is the sample, because "from 30 repairs" is what tells the reader
// how much to lean on the sentence. The exact percentages are still one toggle away in the matrix's
// Numbers view, in the area table on every garage card, and in the Data Origin panel.
//
// THE WORDING IS DERIVED FROM THE GRADE, NOT INDEPENDENTLY FROM THE RATIO. A cell graded "OK" (inside
// the 8-point materiality band) whose ratio happens to be 0.42 would otherwise be captioned "less than
// half as often" — the panel contradicting its own colour. The grade picks the direction; the ratio
// only picks how strongly to say it.

/**
 * The comparison clause for one repair area: how this garage's repeat-repair rate reads against the
 * fleet's rate in the SAME area.
 *
 * @param d  a `domains[]` row from the scorecard payload
 * @param t  the i18n resolver
 */
export function comparisonWords(d, t) {
  const g = (k) => t(`garages.cmp.${k}`);
  if (!d?.graded || d.fleet_comeback_pct == null || !(d.fleet_comeback_pct > 0)) return g('same');

  const r = d.comeback_pct / d.fleet_comeback_pct;

  if (d.grade === 'strong') {
    if (r < 0.5) return g('halfLess');
    if (r < 0.75) return g('muchLess');
    return g('less');
  }
  if (d.grade === 'weak') {
    if (r >= 2.5) return g('wayMore');
    if (r >= 1.8) return g('twiceMore');
    if (r >= 1.4) return g('muchMore');
    return g('more');
  }
  return g('same');
}

/** The same clause for a garage's OVERALL record, against its own case-mix expectation. */
function overallWords(rel, material, t) {
  const g = (k) => t(`garages.cmp.${k}`);
  const gap = Number(rel?.vs_expected_pts ?? 0);
  if (!(rel?.expected_pct > 0) || Math.abs(gap) < material) return g('same');
  const r = rel.comeback_pct / rel.expected_pct;
  if (gap < 0) return r < 0.5 ? g('halfLess') : r < 0.75 ? g('muchLess') : g('less');
  return r >= 2.5 ? g('wayMore') : r >= 1.8 ? g('twiceMore') : r >= 1.4 ? g('muchMore') : g('more');
}

/**
 * WHAT HAPPENED, as lines of counted repairs — for a tooltip, where a bar cannot go.
 *
 * "34 repairs · 2 never came back · 32 came back within a month · usually 7 days later" tells an
 * operator something they can repeat to the garage. "94% comeback rate" does not.
 *
 * @return array<string>
 */
export function outcomeLines(row, t) {
  const g = (k, v) => t(`garages.outcome.${k}`, v);
  const held = row?.held ?? 0;
  const fast = row?.back_30 ?? 0;
  const slow = row?.back_90 ?? 0;
  const total = held + fast + slow;
  if (!total) return [];

  const lines = [g('total', { n: total })];
  if (held) lines.push(g('held', { n: held }));
  if (slow) lines.push(g('back90', { n: slow }));
  if (fast) lines.push(g('back30', { n: fast }));
  if (row?.return_days != null && fast + slow > 0) lines.push(g('typical', { d: Math.round(row.return_days) }));

  return lines;
}

/**
 * The one-line verdict at the top of a garage card.
 *
 * Built HERE rather than on the server, which is where it used to live. The server sentence was
 * English-only and rendered raw, so an Arabic user read an English headline on an otherwise
 * translated card — and every rewording meant a backend deploy. The numbers behind it all travel in
 * the payload, so the sentence belongs on the side that owns the language.
 */
export function headlineFor(card, t, material = 8) {
  const g = (k, v) => t(`garages.headline.${k}`, v);
  if (!card || card.score?.value == null) return g('unrated');

  // COUNTED REPAIRS, NOT A RATE. "32 of its 34 suspension repairs came back within three months" is
  // a fact somebody can put to the garage; "94% comeback rate" is a statistic they have to translate
  // first, and translating it is the step that never happens.
  const back = (d) => (d.back_30 ?? 0) + (d.back_90 ?? 0);

  const problem = card.problems?.[0];
  if (problem) {
    const s = g('weakest', { area: problem.label, back: back(problem), n: problem.graded_jobs });
    const strength = card.strengths?.[0];
    return strength ? `${s} ${g('alsoStrong', { area: strength.label })}` : s;
  }

  const strength = card.strengths?.[0];
  if (strength) {
    return g('strongest', { area: strength.label, back: back(strength), n: strength.graded_jobs });
  }

  const words = overallWords(card.reliability, material, t);
  return words === t('garages.cmp.same')
    ? g('flat', { n: card.reliability?.n ?? 0 })
    : g('overall', { cmp: words, n: card.reliability?.n ?? 0 });
}
