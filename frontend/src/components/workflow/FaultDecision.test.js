// The operational view is the one screen a supervisor actually reads, so it is tested against the REAL
// English labels rather than echoed keys. A test that asserts on `plain.repairedTimes` would pass while
// the sentence said something wrong, unreadable, or nothing at all — and "the supervisor can understand
// this in ten seconds" is precisely a claim about the sentence, not about the key.

import { render, screen } from '@testing-library/react';
import { LABELS } from '../../i18n/labels';
import FaultDecision from './FaultDecision';

// The real table, interpolated — the same resolution the app performs.
const t = (key, vars) => {
  const hit = key.split('.').reduce((n, k) => (n == null ? undefined : n[k]), LABELS.en);
  if (typeof hit !== 'string') return key;
  return vars ? hit.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? String(vars[k]) : m)) : hit;
};

const WINNER = {
  vendor_id: 223, garage: 'Deals On Wheels', coverage_pct: 84, tier: 'exact', same_model: 6, at_garage: 48,
  match_score: 96, confidence: 'medium',
  duration_days: { value: 1, basis: 'garage', sample: 40 },
  success_pct: { value: 96, basis: 'garage', sample: 40 },
  comeback_pct: { value: 4, basis: 'garage', sample: 40 },
  cost_aed: { value: 420, basis: 'garage', sample: 929 },
  fault_cost: { value: 550, basis: 'garage_fault', sample: 63 },
  start_in_days: 1,
};

const ALT = {
  vendor_id: 501, garage: 'RMR', coverage_pct: 59, tier: 'exact', same_model: 0, at_garage: 31,
  match_score: 62, confidence: 'low',
  duration_days: { value: 3, basis: 'garage', sample: 20 },
  success_pct: { value: 60, basis: 'garage', sample: 20 },
  comeback_pct: { value: 40, basis: 'garage', sample: 20 },
  cost_aed: { value: 250, basis: 'garage', sample: 187 },
  start_in_days: 0,
};

// The scorer's derivation, as the API presents it. Awarded values sum to `total` and maxima sum to 100
// — the backend guarantees both, and the card renders them with no arithmetic of its own, so a fixture
// that did not add up would be testing a screen the app can never produce.
const WINNER_BREAKDOWN = {
  total: 92,
  components: [
    { key: 'fault_matching', label: 'Fault matching', awarded: 38, max: 40, applicable: true, detail: '48 Suspension repairs, 6 on this model' },
    { key: 'vehicle_similarity', label: 'Vehicle similarity', awarded: 18, max: 20, applicable: true, detail: '6 on GMC Yukon · 40 on the same vehicle class' },
    { key: 'historical_success', label: 'Historical success', awarded: 16, max: 20, applicable: true, detail: '40 relevant repairs completed · 96% re-inspection pass rate (2 of 40 came back)' },
    { key: 'specialization', label: 'Specialization', awarded: 10, max: 10, applicable: true, detail: '52% of this garage\'s identified work is in Suspension (48 of 92 jobs)' },
    { key: 'confidence', label: 'Confidence', awarded: 10, max: 10, applicable: true, detail: '40 matching jobs — high confidence' },
  ],
  redistributed: false,
  note: null,
};

const ALT_BREAKDOWN = {
  total: 62,
  components: [
    { key: 'fault_matching', label: 'Fault matching', awarded: 24, max: 40, applicable: true, detail: '31 Suspension repairs, none on this model' },
    { key: 'vehicle_similarity', label: 'Vehicle similarity', awarded: 6, max: 20, applicable: true, detail: 'no record on GMC Yukon · 11 on the same vehicle class' },
    { key: 'historical_success', label: 'Historical success', awarded: 19, max: 20, applicable: true, detail: '20 relevant repairs completed · 60% re-inspection pass rate (8 of 20 came back)' },
    { key: 'specialization', label: 'Specialization', awarded: 8, max: 10, applicable: true, detail: '31% of this garage\'s identified work is in Suspension (31 of 100 jobs)' },
    { key: 'confidence', label: 'Confidence', awarded: 5, max: 10, applicable: true, detail: '20 matching jobs — medium confidence' },
  ],
  redistributed: false,
  note: null,
};

const FAULT = {
  category_key: 'suspension', label: 'Suspension', symptom: 'Worn shock / strut',
  criticality: 'safety_critical', criticality_label: 'Safety critical', weight: 1.6,
  winner: { ...WINNER, breakdown: WINNER_BREAKDOWN },
  alternative: { ...ALT, breakdown: ALT_BREAKDOWN },
  cost_confidence: { level: 'high', reason: { code: 'cost_fault_deep', params: { n: 63 } } },
  verdict: {
    code: 'verdict_trade',
    params: { winner: 'Deals On Wheels', alternative: 'RMR' },
    parts: {
      winner_side: [{ code: 'faster_than', params: { a: '1', b: '3' }, text: 'faster (1d vs 3d)' }],
      alternative_side: [
        { code: 'cheaper_than', params: { a: 250, b: 550 }, text: 'cheaper (AED 250 vs 550)' },
        { code: 'holds_better_than', params: { a: 60, b: 96 }, text: 'better at making repairs hold' },
      ],
    },
  },
};

const draw = (fault, model = 'GMC Yukon', faultCount = 1) =>
  render(<FaultDecision fault={fault} model={model} onPick={() => {}} isSel={() => false} t={t} faultCount={faultCount} />);

const deep = (o) => JSON.parse(JSON.stringify(o));

test('each garage is described in five plain sentences, not percentages', () => {
  draw(FAULT);

  // How much they have actually done this repair — the count the coverage percentage was computed from.
  expect(screen.getByText('We have seen this garage repair this fault 48 times.')).toBeInTheDocument();
  expect(screen.getByText('6 of those were on a GMC Yukon.')).toBeInTheDocument();
  expect(screen.getByText('We have seen this garage repair this fault 31 times.')).toBeInTheDocument();
  expect(screen.getByText('None of those were on a GMC Yukon.')).toBeInTheDocument();

  // Turnaround, in words rather than a decimal.
  expect(screen.getByText('The repair usually takes about one day.')).toBeInTheDocument();
  expect(screen.getByText('The repair usually takes about 3 days.')).toBeInTheDocument();

  // Durability, bounded by the window the measurement actually uses — and stated in BOTH directions,
  // so the sentence cannot contradict the "Predicted first-time resolution" figure beside it. The
  // PERCENTAGE is the primary figure (it is what the reader compares against the other garage) and the
  // two halves are pinned together here: 60 + 40 is the check that they still sum to 100.
  expect(screen.getByText('Repairs here almost always hold — cars practically never need this repair again within 3 months.')).toBeInTheDocument();
  expect(screen.getByText('Only a 60% first-time fix rate — 40% needed the same repair again within 3 months.')).toBeInTheDocument();
  // The fraction survives, demoted to the secondary line rather than deleted.
  expect(screen.getByText('About 6 in 10 stayed fixed, 4 in 10 came back.')).toBeInTheDocument();

  // Money, with what the price is a price OF.
  expect(screen.getByText('Expect to pay about AED 550.')).toBeInTheDocument();
  expect(screen.getByText('Based on 63 past repairs of this kind here.')).toBeInTheDocument();
  expect(screen.getByText('Expect to pay about AED 250.')).toBeInTheDocument();
  expect(screen.getByText('Based on this garage\'s other work, not this repair specifically.')).toBeInTheDocument();
});

test('the trade-off names both garages in full sentences', () => {
  draw(FAULT);

  expect(screen.getByText('Deals On Wheels gets the car back about 2 days sooner.')).toBeInTheDocument();
  expect(screen.getByText('RMR is about AED 300 cheaper.')).toBeInTheDocument();
  expect(screen.getByText('Cars repaired at RMR come back less often.')).toBeInTheDocument();
});

test('none of the engine vocabulary reaches the plain-language sentences', () => {
  // The rule this guards, restated. It was never "the card may not contain a score" — it was that the
  // FIVE SENTENCES describing a garage must be readable without being taught the engine's vocabulary.
  // The score breakdown and the ranking arithmetic are now on the card too, deliberately and under
  // their own headings, because a ranking nobody can audit is a worse failure than a technical word.
  // So the ban is scoped to the prose, which is where it always belonged.
  draw(FAULT);
  const text = screen.getAllByTestId('garage-facts').map((n) => n.textContent).join(' ').toLowerCase();

  ['fault experience', 'first-time resolution', 'confidence', 'grain', 'same fault history',
    'garage history', 'coverage', 'basis', 'p90', '/100'].forEach((banned) => {
    expect(text).not.toContain(banned);
  });
});

test('every garage shows the full derivation of its score, with the facts that earned each component', () => {
  draw(FAULT);

  // Both sides, not just the winner — a breakdown only the recommended garage carries is advocacy.
  expect(screen.getAllByText('How this garage scored')).toHaveLength(2);
  expect(screen.getByText('92 / 100')).toBeInTheDocument();
  expect(screen.getByText('62 / 100')).toBeInTheDocument();

  // Components are named in the supervisor's words, not the engine's storage keys.
  expect(screen.getAllByText('Fault experience').length).toBeGreaterThan(0);
  expect(screen.getAllByText('Vehicle similarity').length).toBeGreaterThan(0);
  expect(screen.getAllByText('Historical success').length).toBeGreaterThan(0);
  expect(screen.getAllByText('Specialization').length).toBeGreaterThan(0);

  // Each component carries WHY it scored that — the counted events, not a restatement of the number.
  expect(screen.getAllByText('48 Suspension repairs, 6 on this model').length).toBeGreaterThan(0);
  expect(screen.getAllByText(/60% re-inspection pass rate \(8 of 20 came back\)/).length).toBeGreaterThan(0);
});

test('the card does not re-tell the comparison as point arithmetic', () => {
  // There used to be a "Why X ranked higher" section per fault: the two breakdowns subtracted
  // component by component. Both derivations are already on the card, side by side, and the
  // subtraction repeated the same comparison a third time on EVERY fault of the ticket.
  draw(FAULT);

  expect(screen.queryByText(/ranked higher/)).not.toBeInTheDocument();
  expect(screen.queryByText(/points higher overall/)).not.toBeInTheDocument();
  expect(screen.queryByText('+14 points')).not.toBeInTheDocument();
  expect(screen.queryByText('−3 points')).not.toBeInTheDocument();

  // What replaced it is what was always underneath: both totals, both derivations.
  expect(screen.getByText('92 / 100')).toBeInTheDocument();
  expect(screen.getByText('62 / 100')).toBeInTheDocument();
});

test('a score covering several faults says so, rather than posing as a verdict on this one', () => {
  draw(FAULT, 'GMC Yukon', 3);
  expect(screen.getAllByText('This score covers all 3 faults on this ticket, not this repair alone.')).toHaveLength(2);
});

test('a single-fault ticket does not caveat a score that is genuinely about this fault', () => {
  draw(FAULT);
  expect(screen.queryByText(/This score covers all/)).not.toBeInTheDocument();
});

test('a fleet-borrowed figure says so in the sentence, not by being greyed', () => {
  // The failure this guards: a garage with no timings of its own showed the fleet median, dimmed. A
  // dimmed number is read as a number — the caveat has to be words.
  const f = deep(FAULT);
  f.winner.duration_days = { value: 2, basis: 'fleet', sample: 6571 };
  f.winner.comeback_pct = { value: 39.9, basis: 'fleet', sample: 31120 };
  f.winner.fault_cost = null;
  f.winner.cost_aed = { value: 391, basis: 'fleet', sample: 314 };
  draw(f);

  expect(screen.getByText('We have no repair times from this garage — across the fleet this repair takes about 2 days.')).toBeInTheDocument();
  expect(screen.getByText(/hasn't done enough of this work for us to say whether its repairs last.*first-time fix rate is 60%, with a 40% comeback rate within 3 months/)).toBeInTheDocument();
  expect(screen.getByText('This is the fleet-wide average — this garage has no price of its own yet.')).toBeInTheDocument();
});

test('a thin price comparison says so instead of letting the difference be over-read', () => {
  const f = deep(FAULT);
  f.cost_confidence = { level: 'low', reason: { code: 'cost_garage_thin', params: { n: 9 } } };
  draw(f);

  expect(screen.getByText(/don't have enough pricing history to compare these garages confidently/)).toBeInTheDocument();
});

test('a garage with no record of this repair says that, rather than showing a zero', () => {
  const f = deep(FAULT);
  f.winner.at_garage = 0;
  f.winner.same_model = 0;
  f.winner.duration_days = null;
  f.winner.comeback_pct = { value: null, basis: 'unavailable', sample: 0 };
  f.winner.fault_cost = null;
  f.winner.cost_aed = { value: null, basis: 'unavailable', sample: 0 };
  draw(f);

  expect(screen.getByText('We have never seen this garage repair this fault.')).toBeInTheDocument();
  expect(screen.getByText('We don\'t know how long this garage takes for this repair.')).toBeInTheDocument();
  expect(screen.getByText('We can\'t yet tell whether repairs here last.')).toBeInTheDocument();
  expect(screen.getByText('We have no pricing history for this garage.')).toBeInTheDocument();
});

test('with no alternative the card says so instead of inventing a comparison', () => {
  const f = deep(FAULT);
  f.alternative = null;
  f.verdict = null;
  draw(f);

  expect(screen.getByText(/No other garage has a usable record for this repair/)).toBeInTheDocument();
  expect(screen.queryByText('Trade-off')).not.toBeInTheDocument();
});

test('two evenly matched garages produce a statement, not an empty trade-off box', () => {
  const f = deep(FAULT);
  f.verdict = { code: 'verdict_leads_all', params: { winner: 'Deals On Wheels', alternative: 'RMR' }, parts: { winner_side: [], alternative_side: [] } };
  draw(f);

  expect(screen.getByText('The two garages are close on everything we can measure.')).toBeInTheDocument();
});

test('a reason code with no plain phrasing is dropped, never rendered through the technical labels', () => {
  // A new engine code must not leak "more experienced with Engine (84% vs 59%)" onto this screen. The
  // sentence is missing rather than wrong — a gap is recoverable, a jargon regression is not.
  const f = deep(FAULT);
  f.verdict.parts.winner_side = [{ code: 'brand_new_code', params: { a: 84, b: 59 }, text: 'more experienced with Engine (84% vs 59%)' }];
  const { container } = draw(f);

  expect(container.textContent).not.toContain('84%');
  expect(container.textContent).not.toContain('brand_new_code');
  // …and the clauses that DO have a phrasing still render.
  expect(screen.getByText('RMR is about AED 300 cheaper.')).toBeInTheDocument();
});

test('a green tick is only given to the garage the ticket is actually going to', () => {
  // THE REPORTED BUG. The header said "Send this fault to FUTURE TYRES" while this card put ✅
  // Recommended next to Deals On Wheels — two answers to one question on one screen. When the engine
  // resolves the fault to a different garage, the card leads with the disagreement instead of a tick.
  const f = deep(FAULT);
  f.standing = 'displaced';
  draw(f);

  expect(screen.getByText('Strongest record for this repair: Deals On Wheels')).toBeInTheDocument();
  expect(screen.queryByText('Recommended: Deals On Wheels')).not.toBeInTheDocument();
  expect(screen.getByText(/clearly stronger record for this particular repair than the garage this ticket is heading to/)).toBeInTheDocument();
});

test('a ticket garage that has never done the repair is said out loud', () => {
  const f = deep(FAULT);
  f.standing = 'pick_absent';
  draw(f);

  expect(screen.getByText(/has never done this repair/)).toBeInTheDocument();
  expect(screen.queryByText('Recommended: Deals On Wheels')).not.toBeInTheDocument();
});

test('when the fault and the ticket agree, the tick is unqualified', () => {
  const f = deep(FAULT);
  f.standing = 'agrees';
  draw(f);

  expect(screen.getByText('Recommended: Deals On Wheels')).toBeInTheDocument();
  expect(screen.queryByText(/heading to/)).not.toBeInTheDocument();
});

test('with no model on the ticket the same-model line stays grammatical', () => {
  draw(FAULT, '');
  expect(screen.getByText('6 of those were on the same model.')).toBeInTheDocument();
  expect(screen.getByText('None of those were on the same model.')).toBeInTheDocument();
});
