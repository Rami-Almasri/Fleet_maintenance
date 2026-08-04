// Stage 3 — the Dispatch Plan. These lock the ORDER the card is read in: the call, then what to
// expect, then fault by fault, with the evidence last and collapsed. That ordering is the entire
// redesign; a change that quietly restores the old "evidence first" layout should fail here.
//
// They also lock what the card DOESN'T say. The dense version printed a "Recommended dispatch"
// eyebrow, a "/100" score, a "The plan, fault by fault" heading over three column headings, a
// criticality weight multiplier on every fault and a greyed "vs recommended" under every figure that
// had nothing to compare. Each of those has a test here asserting its absence, because every one of
// them is the kind of thing that creeps back one commit at a time.
//
// Labels resolve against the REAL English table rather than echoing keys, so a dropped label is a
// failing test rather than a page that renders `workflow.garageRec.dispatchPlan.plan.overridden`.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import DispatchPlan from './DispatchPlan';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn() }));
// The knowledge panel self-fetches its own frozen contract and is tested where it lives; here we only
// care that the plan mounts ONE per fault and binds it to the right fault.
jest.mock('../knowledge/RepairIntelligencePanel', () => ({ taskId, preview }) => (
  <div
    data-testid="repair-intel"
    data-task={taskId ?? ''}
    data-preview={preview ? `${preview.vehicleId}|${preview.symptom}` : ''}
  />
));
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

test('the dispatch card is gone — the step no longer asserts a call at the top', async () => {
  // Everything the deleted block locked lived on one white card: "Send all 2 faults to X", the
  // confidence word, the one-line trade-off, the expected downtime and repairs-hold figures, the
  // override line, and a row per fault with its coverage percentage and criticality chip. It was
  // removed by request, so this asserts its ABSENCE — the same way the old suite asserted the absence
  // of the eyebrow and the /100 score, and for the same reason: it creeps back one commit at a time.
  load();
  renderPlan({ selectedVendorId: 223 });
  await screen.findByText('The evidence behind this call');

  expect(screen.queryByText(/Send all 2 faults to/)).not.toBeInTheDocument();
  expect(screen.queryByText('Strong record')).not.toBeInTheDocument();
  expect(screen.queryByText(/Best available record for these faults/)).not.toBeInTheDocument();
  // The "what to expect" strip, both surviving figures.
  expect(screen.queryByText('Off the road')).not.toBeInTheDocument();
  expect(screen.queryByText('Repairs that hold')).not.toBeInTheDocument();
  // The fault-by-fault list: symptoms, coverage percentages, criticality words, "strongest for this".
  expect(screen.queryByText('Engine noise')).not.toBeInTheDocument();
  expect(screen.queryByText('Major mechanical')).not.toBeInTheDocument();
  expect(screen.queryByText('Strongest for this')).not.toBeInTheDocument();
  expect(screen.queryByText('84%')).not.toBeInTheDocument();
  // And the warnings that hung off it.
  expect(screen.queryByText(/measurably weaker on/)).not.toBeInTheDocument();
  expect(screen.queryByText(/This garage is selected/)).not.toBeInTheDocument();
});

test('no money survives anywhere on the step', async () => {
  // The cost tile went first, then the card that carried it. Nothing here quotes or estimates a price,
  // and no override is priced against the recommendation either.
  load();
  renderPlan({ selectedVendorId: 501 });
  await screen.findByText('The evidence behind this call');

  expect(screen.queryByText('Estimated cost')).not.toBeInTheDocument();
  expect(screen.queryByText(/Estimated repair budget/)).not.toBeInTheDocument();
  expect(screen.queryByText(/AED/)).not.toBeInTheDocument();
  expect(screen.queryByText(/than recommended/)).not.toBeInTheDocument();
});

test('a low-confidence call no longer warns on the step — the working is in the evidence', async () => {
  load({
    ...PAYLOAD,
    primary: [{ ...PAYLOAD.primary[0], confidence: 'low', match_score: 66 }, PAYLOAD.primary[1]],
  });
  renderPlan();
  await screen.findByText('The evidence behind this call');

  expect(screen.queryByText(/Limited history behind this call/)).not.toBeInTheDocument();
});

test('the evidence is offered but stays collapsed until asked for, then opens in place', async () => {
  // In place, not on another page: sending the supervisor away to read the working would unmount the
  // form they were filling in. With the card gone this disclosure is the only place the engine's
  // ranking is stated at all, so it has to survive.
  load();
  renderPlan({ selectedVendorId: 223 });

  expect(await screen.findByText('The evidence behind this call')).toBeInTheDocument();
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

// REMOVED with the card: an unscored garage used to be called out on the plan header ("X has no
// recorded history"). There is no plan header any more, so it is simply a garage the evidence panel
// has nothing to say about.

test('degrades to the no-recommendation notice when nothing was scored', async () => {
  load({ ...PAYLOAD, primary: [], per_fault: [] });
  renderPlan();

  expect(await screen.findByText('No proven garage yet')).toBeInTheDocument();
});

// ─── "Previous Similar Repairs" ─────────────────────────────────────────────────────────────────
//
// The cohort the garage numbers were computed over, per fault. It lives HERE rather than on the ticket
// drawer because it is evidence about garages, offered at the moment a garage is still being chosen —
// and it is collapsed, because the call above has already used it.

const withFaults = () => ({
  ...PAYLOAD,
  ticket: {
    ...PAYLOAD.ticket,
    vehicle_id: 12,
    faults_detail: [
      { symptom: 'Rough idle / misfire', category_key: 'engine', label: 'Engine', task_id: 41 },
      // SAME CATEGORY as the row above. per_fault would have collapsed these two into one engine row
      // and kept only the first symptom — the reason this block is keyed per finding.
      { symptom: 'Overheating', category_key: 'engine', label: 'Engine', task_id: 42 },
      // Never promoted to a task: still describable, via the symptom preview.
      { symptom: 'weird clunk i heard', category_key: 'suspension', label: 'Suspension', task_id: null },
    ],
  },
});

test('prior repairs are offered but collapsed — the call is not buried under them', async () => {
  load(withFaults());
  renderPlan();

  expect(await screen.findByText('About these faults')).toBeInTheDocument();
  expect(screen.queryByText('Rough idle / misfire')).not.toBeInTheDocument();

  fireEvent.click(screen.getByText('About these faults'));
  expect(screen.getByText('Rough idle / misfire')).toBeInTheDocument();
});

test('the first click lists the faults and opens NONE of their detail', async () => {
  // Four faults meant four knowledge cards — match scores, matched wording, co-occurrence, causes,
  // fixes — rendered in one go. The list is the answer to "which faults"; the detail is a second ask.
  load(withFaults());
  renderPlan();
  fireEvent.click(await screen.findByText('About these faults'));

  expect(screen.getByText('Rough idle / misfire')).toBeInTheDocument();
  expect(screen.getByText('Overheating')).toBeInTheDocument();
  expect(screen.queryAllByTestId('repair-intel')).toHaveLength(0);
});

test('opening one fault shows that fault alone, and closes the one before it', async () => {
  load(withFaults());
  renderPlan();
  fireEvent.click(await screen.findByText('About these faults'));

  fireEvent.click(screen.getByText('Rough idle / misfire'));
  let panels = screen.getAllByTestId('repair-intel');
  expect(panels).toHaveLength(1);
  expect(panels[0]).toHaveAttribute('data-task', '41');

  // A SECOND fault in the same category — the reason this block is keyed per finding rather than per
  // category, which would have collapsed the two engine faults into one and dropped this symptom.
  fireEvent.click(screen.getByText('Overheating'));
  panels = screen.getAllByTestId('repair-intel');
  expect(panels).toHaveLength(1);
  expect(panels[0]).toHaveAttribute('data-task', '42');

  // …and clicking the open one closes it again.
  fireEvent.click(screen.getByText('Overheating'));
  expect(screen.queryAllByTestId('repair-intel')).toHaveLength(0);
});

test('a fault that never became a task falls back to a symptom preview', async () => {
  // Without the fallback the hand-written finding would render an empty panel bound to `undefined`.
  load(withFaults());
  renderPlan();
  fireEvent.click(await screen.findByText('About these faults'));
  fireEvent.click(screen.getByText('weird clunk i heard'));

  const panel = screen.getByTestId('repair-intel');
  expect(panel).toHaveAttribute('data-task', '');
  expect(panel).toHaveAttribute('data-preview', '12|weird clunk i heard');
});

test('no prior-repairs block at all when the ticket reports no faults', async () => {
  load();
  renderPlan();
  await screen.findByText('The evidence behind this call');

  expect(screen.queryByText('About these faults')).not.toBeInTheDocument();
});
