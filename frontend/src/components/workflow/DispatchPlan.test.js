// Stage 3 — the Dispatch Plan. These lock the four layers and, more importantly, the ORDER of them:
// the call before the plan, the plan before the impact, the evidence last and collapsed. That ordering
// is the entire redesign; a change that quietly restores the old "evidence first" layout should fail here.
//
// Labels resolve against the REAL English table rather than echoing keys, so a dropped label is a
// failing test rather than a page that renders `workflow.garageRec.dispatchPlan.plan.title`.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import DispatchPlan from './DispatchPlan';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn() }));
jest.mock('../../i18n/I18nContext', () => {
  const { LABELS } = jest.requireActual('../../i18n/labels');
  const walk = (p) => p.split('.').reduce((n, k) => (n == null ? undefined : n[k]), LABELS.en);
  return {
    useI18n: () => ({
      lang: 'en',
      t: (k, v) => {
        const hit = walk(k);
        if (typeof hit !== 'string') return k;
        return v ? hit.replace(/\{(\w+)\}/g, (m, key) => (v[key] != null ? String(v[key]) : m)) : hit;
      },
    }),
  };
});

const outcomes = (cost, dur, success) => ({
  duration_days: { value: dur, basis: 'garage', sample: 40, confidence: 'high', support: {} },
  success_pct: { value: success, basis: 'garage', sample: 40, confidence: 'high', support: {} },
  comeback_pct: { value: 100 - success, basis: 'garage', sample: 40, confidence: 'high', support: {} },
  cost_aed: { value: cost, basis: 'garage', sample: 60, confidence: 'high', support: {} },
  queue_open: { value: 0, basis: 'live', sample: 0 },
  busy: false, start_in_days: 0, complete_in_days: dur,
  transport: { value: null, basis: 'unavailable', sample: 0 },
});

const PAYLOAD = {
  has_history: true,
  criteria: { model: 'YUKON', faults: ['engine', 'interior'], fault_labels: ['Engine', 'Interior'], fault_criticality: [] },
  metrics: {},
  per_fault: [
    {
      category_key: 'engine', label: 'Engine', symptom: 'Engine noise',
      criticality: 'major_mechanical', criticality_label: 'Major mechanical', weight: 1.3,
      winner: { vendor_id: 223, garage: 'Deals On Wheels auto', coverage_pct: 84, tier: 'exact', same_model: 6, at_garage: 42, confidence: 'high', evidence: [] },
      alternative: null,
      short_reason: [{ code: 'short_strongest_history', params: { label: 'Engine' }, text: 'strongest Engine history' }],
      winner_points: [], alt_pros: [], alt_cons: [], factors: { winner: [], alternative: [] },
    },
    {
      category_key: 'interior', label: 'Interior', symptom: 'Dashboard fault',
      criticality: 'cosmetic', criticality_label: 'Cosmetic', weight: 0.6,
      // The best shop for THIS fault is not the recommended one — the case the plan table exists for.
      winner: { vendor_id: 501, garage: '7 CYLINDER', coverage_pct: 90, tier: 'exact', same_model: 8, at_garage: 30, confidence: 'high', evidence: [] },
      alternative: null,
      short_reason: [{ code: 'short_strongest_history', params: { label: 'Interior' }, text: 'strongest Interior history' }],
      winner_points: [], alt_pros: [], alt_cons: [], factors: { winner: [], alternative: [] },
    },
  ],
  primary: [
    {
      vendor_id: 223, garage: 'Deals On Wheels auto', rank: 1, is_top: true, match_score: 90,
      matched: 16, total: 40, concentration: 80, confidence: 'high', warn: false,
      coverage: { covered: 2, total: 2, missing: [] },
      fault_coverage: [
        { category_key: 'engine', label: 'Engine', at_garage: 42, same_model: 6, tier: 'exact', pct: 84 },
        { category_key: 'interior', label: 'Interior', at_garage: 210, same_model: 0, tier: 'domain', pct: 55 },
      ],
      breakdown: { total: 90, components: [] }, outcomes: outcomes(550, 1, 55), business: { score: 70, factors: {}, badges: [] }, reasons: [],
    },
    {
      vendor_id: 501, garage: '7 CYLINDER', rank: 2, is_top: false, match_score: 62,
      matched: 8, total: 30, concentration: 40, confidence: 'medium', warn: false,
      coverage: { covered: 1, total: 2, missing: [] },
      fault_coverage: [
        { category_key: 'engine', label: 'Engine', at_garage: 9, same_model: 3, tier: 'general', pct: 59 },
        { category_key: 'interior', label: 'Interior', at_garage: 30, same_model: 8, tier: 'exact', pct: 90 },
      ],
      breakdown: { total: 62, components: [] }, outcomes: outcomes(300, 2, 75), business: { score: 60, factors: {}, badges: [] }, reasons: [],
    },
  ],
  also_consider: [],
  strategy: { mode: 'single', legs: [], summary: { final: 'Deals On Wheels auto', reason: 'Best available record for these faults.', axes_agree: true } },
  ticket: { model_label: 'GMC Yukon', faults_detail: [] },
};

const load = (payload = PAYLOAD) => api.get.mockResolvedValue({ data: { data: payload } });
const renderPlan = (props = {}) => render(
  <DispatchPlan ticketId={9} garages={[]} selectedVendorId={null} onPick={() => {}} onResult={() => {}} {...props} />,
);

beforeEach(() => jest.clearAllMocks());

test('leads with the call — one sentence naming the garage, before any evidence', async () => {
  load();
  renderPlan();

  expect(await screen.findByText('Send all 2 faults to Deals On Wheels auto')).toBeInTheDocument();
  // The evidence is the thing the redesign demoted: offered, but not rendered until asked for.
  expect(screen.queryByText('At a glance')).not.toBeInTheDocument();
  expect(screen.getByText('The evidence behind this call')).toBeInTheDocument();
});

test('an untouched form is never framed as an override', async () => {
  // The bug this locks: the plan falls back to describing the recommendation when nothing is selected.
  // Sharing one flag with "has the supervisor accepted?" made that fallback accuse them of a choice
  // they had not made — "· your choice", "What your choice changes" — on a form they had not touched.
  load();
  renderPlan({ selectedVendorId: null });
  await screen.findByText('The plan, fault by fault');

  expect(screen.queryByText('· your choice')).not.toBeInTheDocument();
  expect(screen.getByText('What to expect')).toBeInTheDocument();
  expect(screen.queryByText('What your choice changes')).not.toBeInTheDocument();
  // …and the accept button is still offered, because nothing has been committed.
  expect(screen.getByText('Send to Deals On Wheels auto')).toBeInTheDocument();
});

test('a single-fault ticket does not say "all 1 faults"', async () => {
  load({
    ...PAYLOAD,
    per_fault: [PAYLOAD.per_fault[0]],
    criteria: { ...PAYLOAD.criteria, faults: ['engine'], fault_labels: ['Engine'] },
  });
  renderPlan();

  expect(await screen.findByText('Send this fault to Deals On Wheels auto')).toBeInTheDocument();
  expect(screen.queryByText(/all 1 faults/)).not.toBeInTheDocument();
});

test('a one-day turnaround reads as a singular', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });   // Deals On Wheels: duration 1
  await screen.findByText('What to expect');

  const tile = screen.getByText('Off the road').closest('div');
  expect(tile).toHaveTextContent('one day');
  expect(tile).not.toHaveTextContent('1 days');
});

test('a garage that only just edges ahead is called comparable, not named as a rival', async () => {
  // The real-world failure: Layer 0 recommended FUTURE TYRES while this column named a garage 4 points
  // ahead — on WEAKER evidence — so the panel argued with itself. per_fault.winner ranks on fault
  // points, the ticket-level call ranks on overall fit, and on one fault they can disagree trivially.
  load({
    ...PAYLOAD,
    per_fault: [{
      ...PAYLOAD.per_fault[0],
      // Ahead by 4 points, and only on garage-wide history against the chosen garage's same-model record.
      winner: { vendor_id: 501, garage: '7 CYLINDER', coverage_pct: 55, tier: 'domain', same_model: 0, at_garage: 48, confidence: 'medium', evidence: [] },
    }],
  });
  renderPlan({ selectedVendorId: 223 }); // chosen is exact/84 on engine
  await screen.findByText('The plan, fault by fault');

  expect(screen.getByText('Comparable to 7 CYLINDER')).toBeInTheDocument();
  expect(screen.queryByText(/pts better/)).not.toBeInTheDocument();
  // …and no weak-spot warning either — the two must agree.
  expect(screen.queryByText(/measurably weaker on/)).not.toBeInTheDocument();
});

test('a low-confidence call carries a warning, not just a grey chip', async () => {
  load({
    ...PAYLOAD,
    primary: [{ ...PAYLOAD.primary[0], confidence: 'low', match_score: 66 }, PAYLOAD.primary[1]],
  });
  renderPlan();

  expect(await screen.findByText(/Limited history behind this call/)).toBeInTheDocument();
});

test('accepting the call selects the recommended garage', async () => {
  load();
  const onPick = jest.fn();
  renderPlan({ onPick });

  fireEvent.click(await screen.findByText('Send to Deals On Wheels auto'));
  expect(onPick).toHaveBeenCalledWith(223);
});

test('once selected the call becomes a confirmation rather than a button', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });

  expect(await screen.findByText(/This garage is selected/)).toBeInTheDocument();
  expect(screen.queryByText('Send to Deals On Wheels auto')).not.toBeInTheDocument();
});

test('the plan grades every fault under the CHOSEN garage, not the best one', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('The plan, fault by fault');

  // Deals On Wheels is exact on Engine but only domain on Interior — both stated, per fault.
  const rows = screen.getAllByRole('row').slice(1); // drop the header
  expect(rows).toHaveLength(2);
  expect(rows[0]).toHaveTextContent('Engine noise');
  expect(rows[0]).toHaveTextContent('84%');
  expect(rows[1]).toHaveTextContent('Dashboard fault');
  expect(rows[1]).toHaveTextContent('55%');
});

test('a fault someone else is better at names them and the size of the gap', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('The plan, fault by fault');

  // Engine: the chosen garage IS the strongest.
  expect(screen.getByText('Best available')).toBeInTheDocument();
  // Interior: 7 CYLINDER is 35 points better, and the row says so.
  expect(screen.getByText('7 CYLINDER')).toBeInTheDocument();
  expect(screen.getByText(/35 pts better/)).toBeInTheDocument();
});

test('the weak-spot warning names the faults this choice is worse at', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });

  expect(await screen.findByText(/measurably weaker on: Dashboard fault/)).toBeInTheDocument();
});

test('the impact strip states cost, downtime and first-time-fix for the plan as it stands', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('What to expect');

  expect(screen.getByText('Estimated cost')).toBeInTheDocument();
  expect(screen.getByText('Off the road')).toBeInTheDocument();
  const hold = screen.getByText('Repairs that hold').closest('div');
  expect(hold).toHaveTextContent('55%');
});

test('choosing a different garage shows what the choice costs, signed by whether it is better', async () => {
  load();
  renderPlan({ selectedVendorId: 501 });
  await screen.findByText('What your choice changes');

  // 7 CYLINDER: 250 cheaper, a day slower, 20 points better at holding.
  expect(screen.getByText(/−250/)).toBeInTheDocument();
  expect(screen.getByText(/\+1\b/)).toBeInTheDocument();
  expect(screen.getByText(/\+20/)).toBeInTheDocument();
});

test('no deltas are shown while the recommendation itself is selected', async () => {
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('What to expect');

  expect(screen.getAllByText('vs recommended')).toHaveLength(3);  // the label is on all three tiles…
  expect(screen.queryByText(/^[+−]\d/)).not.toBeInTheDocument();  // …but there is nothing to compare
});

test('the evidence stays collapsed until asked for, then opens in place', async () => {
  // In place, not on another page: sending the supervisor away to read the working would unmount the
  // form they were filling in. The fix for "too cramped" was the modal WIDTH, not the location.
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('The plan, fault by fault');

  expect(screen.queryByText('At a glance')).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('The evidence behind this call'));
  await waitFor(() => expect(screen.getByText('At a glance')).toBeInTheDocument());
  // …and it reuses the payload the plan already fetched rather than asking again.
  expect(api.get).toHaveBeenCalledTimes(1);
});

test('a split the engine wanted is stated as advice, never as an action', async () => {
  load({
    ...PAYLOAD,
    strategy: {
      mode: 'split',
      reason: 'Engine and Interior have different strongest garages.',
      legs: [{ vendor_id: 223 }, { vendor_id: 501 }],
      summary: { final: 'Deals On Wheels auto', reason: 'Split recommended.', axes_agree: false },
    },
  });
  renderPlan({ selectedVendorId: 223 });

  expect(await screen.findByText('The work would ideally be split')).toBeInTheDocument();
  expect(screen.getByText(/shown as advice, not an action/)).toBeInTheDocument();
});

test('a garage the engine never scored is called out instead of rendering a blank plan', async () => {
  load();
  renderPlan({ selectedVendorId: 999, garages: [{ id: 999, name: 'Brand New Garage' }] });

  expect(await screen.findByText(/Brand New Garage has no recorded history/)).toBeInTheDocument();
});

test('degrades to the no-recommendation notice when nothing was scored', async () => {
  load({ ...PAYLOAD, primary: [], per_fault: [] });
  renderPlan();

  expect(await screen.findByText('No proven garage yet')).toBeInTheDocument();
});

// ─── "What the garage will do" ──────────────────────────────────────────────────────────────────
//
// The plan table ranks garages; it never said what the car was having done to it. These lock the four
// things that make that block safe to show the person authorising the work.

const OUTLOOK = [
  {
    symptom: 'Rough idle / misfire', category_key: 'engine', label: 'Engine', known: true,
    risk: 'moderate', risk_label: 'Moderate', risk_tone: 'amber',
    causes: ['Worn spark plugs / coils', 'Clogged / faulty fuel injector', 'Vacuum leak'],
    fixes: [
      { label: 'Replace spark plugs', label_ar: null, typical: true },
      { label: 'Replace ignition coil', label_ar: null, typical: true },
      { label: 'Clean throttle body', label_ar: null, typical: false },
    ],
  },
  {
    // SAME CATEGORY as the row above. per_fault would have collapsed these two into one engine row and
    // kept only the first symptom — the reason this block is keyed per finding.
    symptom: 'Overheating', category_key: 'engine', label: 'Engine', known: true,
    risk: 'critical', risk_label: 'Critical', risk_tone: 'red',
    causes: ['Coolant leak', 'Water-pump failure'],
    fixes: [{ label: 'Replace thermostat', label_ar: null, typical: true }],
  },
  {
    symptom: 'weird clunk i heard', category_key: 'suspension', label: 'Suspension', known: false,
    risk: null, risk_label: null, risk_tone: null, causes: [], fixes: [],
  },
];

const withOutlook = () => ({ ...PAYLOAD, repair_outlook: OUTLOOK });

test('the repair outlook stays collapsed until asked for', async () => {
  load(withOutlook());
  renderPlan();

  // Visible as an offer; its contents are not competing with the ten-second decision in Layer 0.
  expect(await screen.findByText('What the garage will do')).toBeInTheDocument();
  expect(screen.queryByText(/Worn spark plugs/)).not.toBeInTheDocument();

  fireEvent.click(screen.getByText('What the garage will do'));

  expect(screen.getByText(/Worn spark plugs \/ coils · Clogged \/ faulty fuel injector · Vacuum leak/)).toBeInTheDocument();
  expect(screen.getByText(/Replace spark plugs · Replace ignition coil · Clean throttle body/)).toBeInTheDocument();
});

test('every selected fault is described, including a second one in the same category', async () => {
  load(withOutlook());
  renderPlan();
  fireEvent.click(await screen.findByText('What the garage will do'));

  // Both engine faults, not just whichever came first.
  expect(screen.getByText('Rough idle / misfire')).toBeInTheDocument();
  expect(screen.getByText('Overheating')).toBeInTheDocument();
  expect(screen.getByText(/Coolant leak · Water-pump failure/)).toBeInTheDocument();
});

test('a hand-written finding admits it has no standard repair instead of inventing one', async () => {
  load(withOutlook());
  renderPlan();
  fireEvent.click(await screen.findByText('What the garage will do'));

  expect(screen.getByText('weird clunk i heard')).toBeInTheDocument();
  expect(screen.getByText(/we have no standard repair for it/)).toBeInTheDocument();
});

test('the outlook says it is typical work, not a diagnosis of this car', async () => {
  load(withOutlook());
  renderPlan();
  fireEvent.click(await screen.findByText('What the garage will do'));

  // Without this the block reads as a decision already taken, and the supervisor authorises work
  // nobody has yet confirmed the car needs.
  expect(screen.getByText(/the garage confirms once it has looked at the car/)).toBeInTheDocument();
});

test('no engine vocabulary reaches the repair outlook', async () => {
  load(withOutlook());
  renderPlan();
  fireEvent.click(await screen.findByText('What the garage will do'));

  // Scoped to the block itself — the rest of the plan legitimately shows coverage and tiers.
  const block = screen.getByText(/the garage confirms once it has looked at the car/).closest('div');
  const text = block.textContent.toLowerCase();

  ['fault experience', 'first-time resolution', 'confidence', 'grain', 'coverage', 'basis', '/100', '%']
    .forEach((banned) => expect(text).not.toContain(banned));
});
