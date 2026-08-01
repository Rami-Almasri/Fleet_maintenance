// Render test for the redesigned assign-step recommendation UI: a hero "recommended decision" card + a
// Compare toggle that reveals the secondary sections. Uses the live API payload shape.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import GarageRecommendations from './GarageRecommendations';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn() }));
jest.mock('../../i18n/I18nContext', () => ({
  // t returns the key, but interpolates {pct}/{n} so specialization/matches text is assertable.
  useI18n: () => ({ t: (k, v) => (v ? `${k}:${Object.values(v).join(',')}` : k), lang: 'en' }),
}));

const PAYLOAD = {
  has_history: true,
  total_history: 24517,
  criteria: {
    model: 'YUKON',
    faults: ['engine', 'interior'],
    fault_labels: ['Engine', 'Interior'],
    fault_criticality: [
      { category_key: 'engine', label: 'Engine', tier: 'major_mechanical', tier_label: 'Major mechanical', weight: 1.3, escalated: false },
      { category_key: 'interior', label: 'Interior', tier: 'cosmetic', tier_label: 'Cosmetic', weight: 0.6, escalated: false },
    ],
  },
  fleet_outcomes: { duration_days: 1, comeback_pct: 39.9, cost_median: 391 },
  metrics: {
    coverage_pct: { label: 'Fault experience', kind: 'measured', short: 'How much proven history this garage has with this exact fault.', means: 'How well this history covers the fault', method: 'Graded on a ladder', source: 'Past repairs', caveat: null },
    criticality_weight: { label: 'Fault priority (×)', kind: 'policy', short: 'How much this fault outweighs the others.', means: 'How much this fault counts', method: 'Fixed multiplier per category', source: 'config', caveat: 'A business rule, not learned from data.' },
    duration_days: { label: 'Expected duration', kind: 'forecast', short: 'Typical days off the road at this garage.', means: 'Days off the road', method: 'Median', source: 'Completed repairs', caveat: null },
    success_pct: { label: 'Predicted first-time resolution', kind: 'forecast', short: 'How often a repair here holds for 90 days.', means: 'The mirror of repeat repair — the two always add up to 100%.', method: '100 minus repeat repair', source: 'Signatures', caveat: 'A proxy.' },
    comeback_pct: { label: 'Repeat repair probability (90 days)', kind: 'forecast', short: 'How often the same fault comes back within 90 days.', means: 'The mirror of first-time resolution — the two always add up to 100%.', method: '90-day recurrence', source: 'Signatures', caveat: null },
    cost_aed: { label: 'Estimated cost', kind: 'forecast', short: 'What this repair is likely to bill.', means: 'Likely bill', method: 'Attributed from the expense ledger', source: 'Expense ledger', caveat: 'Attributed, not invoiced.' },
    fault_cost: { label: 'Cost for this fault', kind: 'forecast', short: 'What this garage bills for this kind of fault.', means: 'Category price', method: 'Median of single-fault visits', source: 'Expense ledger', caveat: 'Shown only where earned.' },
  },
  per_fault: [
    {
      category_key: 'engine', label: 'Engine', symptom: 'Engine noise',
      criticality: 'major_mechanical', criticality_label: 'Major mechanical', weight: 1.3,
      winner: {
        vendor_id: 223, garage: 'Deals On Wheels auto', coverage_pct: 84, tier: 'exact', same_model: 6, at_garage: 42, match_score: 96, confidence: 'medium',
        duration_days: { value: 2, basis: 'garage', sample: 40 }, success_pct: { value: 96, basis: 'garage', sample: 40 },
        comeback_pct: { value: 4, basis: 'garage', sample: 40 }, cost_aed: { value: 420, basis: 'garage', sample: 929, confidence: 'high' },
        queue_open: 2, start_in_days: 1, duration_p90: { value: 5, basis: 'garage', sample: 40 },
        evidence: ['6 previous Yukon Engine repairs here', '36 similar Engine repairs here on other models'],
        // Phase 2: this garage HAS earned a price for engine work specifically.
        fault_cost: { value: 700, basis: 'garage_fault', sample: 63 },
      },
      alternative: {
        vendor_id: 501, garage: '7 CYLINDER', coverage_pct: 59, tier: 'exact', same_model: 2, at_garage: 9, match_score: 62, confidence: 'low',
        duration_days: { value: 1.5, basis: 'garage', sample: 20 }, success_pct: { value: 93, basis: 'garage', sample: 20 },
        comeback_pct: { value: 7, basis: 'garage', sample: 20 }, cost_aed: { value: 300, basis: 'garage', sample: 8 },
        queue_open: 0, start_in_days: 0, duration_p90: { value: 3, basis: 'garage', sample: 20 },
        evidence: ['2 previous Yukon Engine repairs here', '7 similar Engine repairs here on other models'],
      },
      reason: '6 previous Engine repairs on this exact model.',
      tradeoff: 'About 0.5 day(s) faster, but less Engine experience (59% coverage vs 84%).',
      winner_points: ['Has repaired Engine on Yukon before (6×)', 'Most experience with this fault (84% vs 59%)'],
      alt_pros: ['Can start sooner'],
      alt_cons: ['Less Engine experience (59% vs 84%)'],
      cost_compare: {
        winner: { garage: 'Deals On Wheels auto', value: 700, basis: 'garage_fault', sample: 63 },
        alternative: { garage: '7 CYLINDER', value: 300, basis: 'garage', sample: 187 },
        // Different grains, so the difference is taken at the rung they share.
        same_grain: false,
        comparison: {
          grain: 'garage', delta: -250, cheaper: 'alternative',
          winner: { value: 550, basis: 'garage', sample: 929 },
          alternative: { value: 300, basis: 'garage', sample: 187 },
        },
      },
      verdict: '7 CYLINDER is cheaper (AED 300 vs 550) and faster (0d vs 1d), but Deals On Wheels auto is more experienced with Engine (84% vs 59%).',
      short_reason: 'strongest Engine history',
      cost_confidence: { level: 'medium', reason: 'Both prices are comparable at garage level, but neither garage has enough priced history for this specific fault.' },
      factors: {
        winner: ['more experienced with Engine (84% vs 59%)', 'able to start sooner'],
        alternative: ['cheaper (AED 300 vs 550)', 'faster (0d vs 1d)'],
      },
    },
    {
      category_key: 'interior', label: 'Interior', symptom: 'Dashboard fault',
      criticality: 'cosmetic', criticality_label: 'Cosmetic', weight: 0.6,
      winner: {
        vendor_id: 223, garage: 'Deals On Wheels auto', coverage_pct: 55, tier: 'domain', same_model: 0, at_garage: 210, match_score: 96, confidence: 'high',
        duration_days: { value: 2, basis: 'garage', sample: 40 }, success_pct: { value: 96, basis: 'garage', sample: 40 },
        comeback_pct: { value: 4, basis: 'garage', sample: 40 }, cost_aed: { value: 420, basis: 'garage', sample: 929, confidence: 'high' },
        queue_open: 2, start_in_days: 1, duration_p90: { value: 5, basis: 'garage', sample: 40 },
        evidence: ['210 similar Interior repairs here on other models'],
      },
      alternative: null,
      reason: '210 previous Interior repairs, though none on this model.',
      tradeoff: null,
      winner_points: ['210 Interior repairs here, though none on this model', 'The only garage with a usable record for this fault'],
      alt_pros: [],
      alt_cons: [],
    },
  ],
  ticket: {
    model_label: 'GMC Yukon',
    faults_detail: [
      { symptom: 'Engine noise', category_key: 'engine', label: 'Engine' },
      { symptom: 'Dashboard fault', category_key: 'interior', label: 'Interior' },
    ],
  },
  strategy: {
    mode: 'single',
    headline: 'Send the whole ticket to Deals On Wheels auto (96/100).',
    reason: 'One garage covers both faults.',
    tradeoff: 'One pickup, one invoice.',
    why_not: null,
    actionable_note: null,
    rejected_reason: 'single_covers_all',
    confidence: 90, confidence_single: 90, confidence_gain: 0,
    legs: [],
    business_tradeoff: null,
    summary: {
      technical: { garage: 'Deals On Wheels auto', vendor_id: 223, match_score: 96 },
      business: null,
      final: 'Deals On Wheels auto',
      final_mode: 'single',
      reason: 'Best available repair record for these faults.',
      axes_agree: true,
    },
  },
  primary: [
    { vendor_id: 223, garage: 'Deals On Wheels auto', rank: 1, is_top: true, match_score: 96, matched: 16, total: 40, concentration: 89, confidence: 'high', warn: false,
      coverage: { covered: 2, total: 2, missing: [] },
      fault_coverage: [
        // exact tier — this fault on this very model
        { category_key: 'engine', label: 'Engine', at_garage: 42, same_model: 15, tier: 'exact', pct: 100 },
        // domain tier — plenty of Interior work, none of it on a Yukon
        { category_key: 'interior', label: 'Interior', at_garage: 210, same_model: 0, tier: 'domain', pct: 55 },
      ],
      breakdown: {
        total: 96,
        redistributed: false,
        note: null,
        components: [
          { key: 'fault_matching', label: 'Fault matching', question: 'q', awarded: 38, max: 40, applicable: true, detail: '1 of 2 faults with same-model history' },
          { key: 'vehicle_similarity', label: 'Vehicle similarity', question: 'q', awarded: 18, max: 20, applicable: true, detail: '15 repairs on this exact model' },
          { key: 'historical_success', label: 'Historical success', question: 'q', awarded: 20, max: 20, applicable: true, detail: '16 relevant repairs completed' },
          { key: 'specialization', label: 'Specialization', question: 'q', awarded: 9, max: 10, applicable: true, detail: '89% of this garage\'s work' },
          { key: 'confidence', label: 'Confidence', question: 'q', awarded: 10, max: 10, applicable: true, detail: '16 matching jobs — high confidence' },
        ],
      },
      why_not: null,
      outcomes: {
        duration_days: { value: 4.2, basis: 'garage', sample: 40, confidence: 'high', reason: null, support: { garage: 40, fleet: 6571 } },
        duration_p90: { value: 9, basis: 'garage', sample: 40, confidence: 'high', reason: null, support: { garage: 40, fleet: 6571 } },
        success_pct: { value: 96, basis: 'garage', sample: 40, confidence: 'high', reason: null, support: { garage: 40, fleet: 31120 } },
        comeback_pct: { value: 4, basis: 'garage', sample: 40, confidence: 'high', reason: null, support: { garage: 40, fleet: 31120 } },
        cost_aed: { value: 420, basis: 'garage', sample: 12, confidence: 'medium', reason: null, support: { garage: 12, fleet: 314 } },
        queue_open: { value: 2, basis: 'live', sample: 2 },
        busy: false,
        start_in_days: 1,
        complete_in_days: 5.2,
        transport: { value: null, basis: 'unavailable', sample: 0, confidence: null, support: {}, reason: 'Vendor location has not been configured, so transport impact cannot be calculated.' },
      },
      business: { score: 71, factors: {}, badges: ['no_queue'] },
      reasons: [{ t: '16 previous Yukon repairs', s: '16 jobs' }, { t: 'Current fault coverage: 2/2' }, { t: 'Highest Engine repair volume' }] },
    { vendor_id: 501, garage: '7 CYLINDER', rank: 2, is_top: false, match_score: 62, matched: 8, total: 30, concentration: 40, confidence: 'medium', warn: false,
      coverage: { covered: 1, total: 2, missing: ['Interior'] }, fault_coverage: [],
      breakdown: { total: 62, redistributed: false, note: null, components: [] },
      outcomes: {
        duration_days: { value: 1, basis: 'fleet', sample: 6571, confidence: 'low', reason: 'No timed repairs recorded for this garage.', support: { fleet: 6571 } },
        duration_p90: { value: 6, basis: 'fleet', sample: 6571, confidence: 'low', reason: 'No timed repairs recorded for this garage.', support: { fleet: 6571 } },
        success_pct: { value: 60.1, basis: 'fleet', sample: 31120, confidence: 'low', reason: 'Fewer than 30 attributable repairs at this garage.', support: { fleet: 31120 } },
        comeback_pct: { value: 39.9, basis: 'fleet', sample: 31120, confidence: 'low', reason: 'Fewer than 30 attributable repairs at this garage.', support: { fleet: 31120 } },
        cost_aed: { value: 250, basis: 'garage', sample: 9, confidence: 'low', reason: null, support: { garage: 9, fleet: 314 } },
        queue_open: { value: 0, basis: 'live', sample: 0 },
        busy: false,
        start_in_days: 0,
        complete_in_days: 1,
        transport: { value: null, basis: 'unavailable', sample: 0, confidence: null, support: {}, reason: 'Vendor location has not been configured, so transport impact cannot be calculated.' },
      },
      business: { score: 88, factors: {}, badges: ['cheapest', 'fastest'] },
      why_not: {
        gap: 34,
        summary: '34 points behind Deals On Wheels auto — mainly 20 fewer on Fault matching.',
        lost_because: ['No previous Interior repairs on this model (28 on other models)', 'Only 2 Engine repairs on this model, vs 15 at Deals On Wheels auto'],
        losses: [{ component: 'Fault matching', points: -20, detail: 'no Interior history' }],
        strengths: [
          { kind: 'cheapest', detail: 'Lowest estimated cost — AED 250' },
          { kind: 'fastest', detail: 'Fastest turnaround — 1 days' },
        ],
        better_at: [{ component: 'Historical success', points: 1.5, detail: 'faster turnaround' }],
      },
      reasons: [{ t: 'Strong Yukon focus' }] },
  ],
  also_consider: [
    { dimension: 'fault', label: 'Engine specialist', vendor_id: 164, garage: 'Alresala', jobs: 58, concentration: 91 },
  ],
};

beforeEach(() => api.get.mockReset());

// A ticket with no per-fault view — the older engine-first layout, which must still work.
const NO_PER_FAULT = { ...PAYLOAD, per_fault: [] };

test('the panel leads with a fault-by-fault scan table, not a single garage', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.perFault.overview')).toBeInTheDocument();
  // One row per fault, each naming its own winner and coverage.
  expect(screen.getAllByText('Engine noise').length).toBeGreaterThan(0);
  expect(screen.getAllByText('Dashboard fault').length).toBeGreaterThan(0);
  expect(screen.getAllByText('84%').length).toBeGreaterThan(0);
  expect(screen.getAllByText('55%').length).toBeGreaterThan(0);
});

test('each fault card compares the winner against a real alternative with its trade-off', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  expect(screen.getAllByText('workflow.garageRec.perFault.winner').length).toBe(2);
  expect(screen.getAllByText('workflow.garageRec.perFault.alternative').length).toBe(1);
  // The reason and the trade-off are both stated in facts.
  expect(screen.getByText(/6 previous Engine repairs on this exact model/)).toBeInTheDocument();
  expect(screen.getByText(/but less Engine experience/)).toBeInTheDocument();
  // A fault with no comparable alternative says so rather than inventing one.
  expect(screen.getByText('workflow.garageRec.perFault.noAlternative')).toBeInTheDocument();
});

test('a percentage states what it is a percentage OF, without being clicked', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // The operator-facing name, not the engine's word for it.
  expect(screen.getAllByText('Fault experience').length).toBeGreaterThan(0);
  // The plain-language definition sits under the label — no popover needed.
  expect(screen.getAllByText(/How much proven history this garage has/).length).toBeGreaterThan(0);
  // And the repair counts behind the figure are printed with it, including in the scan table.
  expect(screen.getAllByText('6 previous Yukon Engine repairs here').length).toBeGreaterThan(1);
  expect(screen.getByText('36 similar Engine repairs here on other models')).toBeInTheDocument();
});

test('first-time fix and repeat repair are shown as one measurement, not two verdicts', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // Renamed away from "success"/"comeback", which read as a garage that fixes half of what it touches.
  expect(screen.getAllByText('Predicted first-time resolution').length).toBeGreaterThan(0);
  // The card carries ONE figure plus its mirror as a note, rather than two competing rows.
  expect(screen.getAllByText(/perFault\.mirror:4$/).length).toBeGreaterThan(0);
  expect(screen.queryByText('Repeat repair probability (90 days)')).not.toBeInTheDocument();
});

test('each fault card says why the winner won and why the alternative did not, as marks', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  expect(screen.getAllByText('workflow.garageRec.perFault.whyWon').length).toBe(2);
  expect(screen.getByText('workflow.garageRec.perFault.whyNotAlt:7 CYLINDER')).toBeInTheDocument();
  expect(screen.getByText('Has repaired Engine on Yukon before (6×)')).toBeInTheDocument();
  // The alternative's shortfall AND the one thing it is better at — a card listing only failings is
  // advocacy, not a comparison.
  expect(screen.getByText('Less Engine experience (59% vs 84%)')).toBeInTheDocument();
  expect(screen.getByText('Can start sooner')).toBeInTheDocument();
});

test('a fleet-average figure on a fault card SAYS so instead of only being greyed', async () => {
  // The failure this guards: 7 CYLINDER showed "AED 391" — the fleet median, because that garage has
  // too few priced repairs. Dimming it was not enough; it was read as that garage's own price.
  const payload = JSON.parse(JSON.stringify(PAYLOAD));
  payload.per_fault[0].alternative.cost_aed = { value: 391, basis: 'fleet', sample: 314 };
  payload.per_fault[0].alternative.duration_days = { value: 0, basis: 'garage', sample: 242 };
  api.get.mockResolvedValue({ data: { data: payload } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // Said in words — "fleet average, NOT this garage" — not merely dimmed.
  expect(screen.getByText(/costBasis\.line:314,workflow\.garageRec\.costBasis\.fleet/)).toBeInTheDocument();
  // A same-day median is a real measurement, but "0 days" reads as missing data.
  expect(screen.getAllByText('workflow.garageRec.outcomes.sameDay').length).toBeGreaterThan(0);
  expect(screen.queryByText('workflow.garageRec.outcomes.days:0')).not.toBeInTheDocument();
});

test('a cost is never shown without its sample size, confidence and grain', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // The winner earned a price for ENGINE specifically — that is what the engine card shows.
  expect(screen.getByText('Cost for this fault')).toBeInTheDocument();
  expect(screen.getAllByText('workflow.garageRec.outcomes.aed:700').length).toBeGreaterThan(0);
  // ...with the count behind it and the grain named in words, not as a basis key.
  expect(screen.getByText(/costBasis\.line:63,workflow\.garageRec\.costBasis\.garage_fault/)).toBeInTheDocument();
  // The whole-visit figure stays visible so the category price is not mistaken for the job total.
  expect(screen.getByText('workflow.garageRec.perFault.jobTotal:420')).toBeInTheDocument();

  // A garage with no earned category price falls back to its job-level figure, labelled as such.
  expect(screen.getAllByText('Estimated cost').length).toBeGreaterThan(0);
  expect(screen.getAllByText(/costBasis\.line:929,workflow\.garageRec\.costBasis\.garage$/).length).toBeGreaterThan(0);
});

test('the cost trade-off is shown per fault, both garages side by side', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  expect(screen.getByText('workflow.garageRec.costBasis.compareTitle')).toBeInTheDocument();
  // Each side keeps its own grain and sample beside its price.
  expect(screen.getByText(/costBasis\.repairs:63/)).toBeInTheDocument();
  expect(screen.getByText(/costBasis\.altCheaper:250,/)).toBeInTheDocument();
  // The trade-off names both garages and never resolves to "so pick the cheaper one".
  expect(screen.getByText(/7 CYLINDER is cheaper .*but Deals On Wheels auto is more experienced/)).toBeInTheDocument();
});

test('a price built from two different grains refuses to read as a like-for-like saving', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // AED 700 is engine-specific; AED 300 is that garage's all-work average. Subtracting them would
  // invent a AED 400 saving, so the mismatch is stated and the comparison drops to the shared rung.
  expect(screen.getByText('workflow.garageRec.costBasis.mixedGrain')).toBeInTheDocument();
});

test('the cost ladder shows which rungs were tried and which one was used', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');
  fireEvent.click(screen.getByText('workflow.garageRec.engineDetail.show'));

  expect(screen.getByText('workflow.garageRec.costBasis.ladderTitle')).toBeInTheDocument();
  // All four rungs are rendered — the ones we could NOT use are the informative part.
  ['garage_fault_model', 'garage_fault', 'garage', 'fleet'].forEach((rung) => {
    expect(screen.getAllByText(`workflow.garageRec.costBasis.${rung}`).length).toBeGreaterThan(0);
  });
  // And the figure is labelled a budget, not a quotation.
  expect(screen.getAllByText('workflow.garageRec.costBasis.budgetNote').length).toBeGreaterThan(0);
});

test('decision factors lead each card, with BOTH garages stated as strengths', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  expect(screen.getByText('workflow.garageRec.perFault.whyWins:Deals On Wheels auto')).toBeInTheDocument();
  // The alternative gets POSITIVE statements too — a card where only the winner earns ticks is
  // advocacy, and a supervisor overruling the engine needs to see what they would be choosing.
  expect(screen.getByText('workflow.garageRec.perFault.whyRelevant:7 CYLINDER')).toBeInTheDocument();
  expect(screen.getByText('cheaper (AED 300 vs 550)')).toBeInTheDocument();
  expect(screen.getByText('more experienced with Engine (84% vs 59%)')).toBeInTheDocument();
});

test('the comparison itself carries a confidence level, separate from the prices', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // "AED 250 cheaper" reads identically off 60 fault-specific repairs or 9 assorted ones. The level
  // is what tells the supervisor how hard to lean on the difference.
  expect(screen.getByText(/costBasis\.confidenceChip:workflow\.garageRec\.confidence\.medium/)).toBeInTheDocument();
  expect(screen.getByText(/comparable at garage level, but neither garage has enough priced history/)).toBeInTheDocument();
});

test('the scan table carries the reason and the price reliability per fault', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // A three-fault car has to be readable without opening three cards.
  expect(screen.getByText('strongest Engine history')).toBeInTheDocument();
  // Price reliability as words, not a basis key.
  expect(screen.getAllByText('workflow.garageRec.costBasis.source.garage_fault').length).toBeGreaterThan(0);
});

test('a fault card can pick either the winner or the alternative directly', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  fireEvent.click(screen.getAllByText('workflow.garageRec.use')[1]);   // the Engine alternative
  expect(onPick).toHaveBeenCalledWith(501);
});

test('every number can explain what it is, how it is calculated and where it came from', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // Each metric carries an affordance; opening one reveals the full definition.
  const info = screen.getAllByLabelText('workflow.garageRec.metric.whatIsThis');
  expect(info.length).toBeGreaterThan(5);
  fireEvent.click(info[0]);

  expect(screen.getByText('workflow.garageRec.metric.means', { exact: false })).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.metric.method', { exact: false })).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.metric.source', { exact: false })).toBeInTheDocument();
});

test('a policy multiplier is visibly labelled as a business rule, not as evidence', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // The ×1.3 on Engine is configured policy — showing it like counted history would misrepresent it.
  expect(screen.getAllByText('×1.3').length).toBeGreaterThan(0);
  fireEvent.click(screen.getAllByLabelText('workflow.garageRec.metric.whatIsThis')[0]);
  expect(screen.getByText('workflow.garageRec.metric.kind.policy')).toBeInTheDocument();
  expect(screen.getByText(/A business rule, not learned from data/)).toBeInTheDocument();
});

test('the full engine view is collapsed behind a toggle once the fault view exists', async () => {
  api.get.mockResolvedValue({ data: { data: PAYLOAD } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.perFault.overview');

  // The score-led hero is evidence, not the answer — it must not be the first thing on the panel.
  expect(screen.queryByText('workflow.garageRec.selectRecommended')).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('workflow.garageRec.engineDetail.show'));
  expect(screen.getByText('workflow.garageRec.selectRecommended')).toBeInTheDocument();
});

test('with no per-fault view the engine card stays visible and needs no toggle', async () => {
  // Regression: gating the hero on the toggle would hide it entirely on tickets that have no
  // fault-first view to fall back on.
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.selectRecommended')).toBeInTheDocument();
  expect(screen.queryByText('workflow.garageRec.engineDetail.show')).not.toBeInTheDocument();
});

test('shows one hero recommendation with score, confidence, fault badges and short reasons', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  const onResult = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={onResult} />);

  // hero decision
  expect((await screen.findAllByText('Deals On Wheels auto')).length).toBeGreaterThan(0);
  expect(screen.getByText('96')).toBeInTheDocument();                 // score /100
  expect(screen.getByText('workflow.garageRec.confidence.high')).toBeInTheDocument();
  // fault categories rendered as separate badges
  expect(screen.getByText('Engine')).toBeInTheDocument();
  expect(screen.getByText('Interior')).toBeInTheDocument();
  // short reasons
  expect(screen.getByText('16 previous Yukon repairs')).toBeInTheDocument();

  // secondary sections are hidden until Compare
  expect(screen.queryByText('7 CYLINDER')).not.toBeInTheDocument();
  expect(screen.queryByText('Alresala')).not.toBeInTheDocument();

  await waitFor(() => expect(onResult).toHaveBeenCalledWith(NO_PER_FAULT));
});

test('Compare reveals other proven garages and specialists', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  fireEvent.click(screen.getByText('workflow.garageRec.compare'));
  expect(screen.getByText('workflow.garageRec.otherProven')).toBeInTheDocument();
  expect(screen.getByText('7 CYLINDER')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.specialists')).toBeInTheDocument();
  expect(screen.getByText('Alresala')).toBeInTheDocument();
});

test('Select recommended garage pre-fills the picker with the top vendor', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  fireEvent.click(screen.getByText('workflow.garageRec.selectRecommended'));
  expect(onPick).toHaveBeenCalledWith(223);
});

test('View details reveals the evidence breakdown', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  expect(screen.queryByText('workflow.garageRec.details.matches')).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('workflow.garageRec.viewDetails'));
  expect(screen.getByText('workflow.garageRec.details.matches')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.details.specialization')).toBeInTheDocument();
});

test('per-fault coverage is always visible and grades each fault by evidence tier', async () => {
  // On a multi-fault ticket this evidence decides the assignment, so it must not hide behind a toggle.
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  expect(screen.getByText('workflow.garageRec.faultCoverageTitle')).toBeInTheDocument();
  expect(screen.getByText('Engine noise')).toBeInTheDocument();
  expect(screen.getByText('Dashboard fault')).toBeInTheDocument();

  // Exact tier → the same-model sentence ("15 GMC Yukon Engine repairs").
  expect(screen.getByText(/workflow\.garageRec\.repairsModel:15,GMC Yukon,Engine/)).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.tier.exact', { exact: false })).toBeInTheDocument();
  // Domain tier → falls back to "210 Interior repairs at this garage" and is labelled as the weaker tier.
  expect(screen.getByText(/workflow\.garageRec\.repairsHere:210,Interior/)).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.tier.domain', { exact: false })).toBeInTheDocument();
});

test('the score breakdown explains where every point came from', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  // collapsed by default, one tap away — never an unexplained number
  expect(screen.queryByText('workflow.garageRec.breakdown.title')).not.toBeInTheDocument();
  fireEvent.click(screen.getByText('workflow.garageRec.breakdown.show'));

  expect(screen.getByText('workflow.garageRec.breakdown.title')).toBeInTheDocument();
  ['Fault matching', 'Vehicle similarity', 'Historical success', 'Specialization', 'Confidence']
    .forEach((label) => expect(screen.getByText(label)).toBeInTheDocument());

  // the awarded/max of each component, and the facts behind them
  expect(screen.getByText('38')).toBeInTheDocument();
  expect(screen.getByText('/40')).toBeInTheDocument();
  expect(screen.getByText('1 of 2 faults with same-model history')).toBeInTheDocument();
  // …and the components add up to the headline the card shows (also echoed in the decision summary)
  expect(screen.getAllByText('96/100').length).toBeGreaterThan(0);
});

test('a runner-up says why it lost and what it is still better at', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');
  fireEvent.click(screen.getByText('workflow.garageRec.compare'));

  expect(screen.getByText(/34 points behind Deals On Wheels auto/)).toBeInTheDocument();
  // Concrete evidence gaps, not "less history"
  expect(screen.getByText(/No previous Interior repairs on this model/)).toBeInTheDocument();
  expect(screen.getByText(/Only 2 Engine repairs on this model, vs 15/)).toBeInTheDocument();
  // …and the honest counterweight
  expect(screen.getByText('workflow.garageRec.whyNot.strengths')).toBeInTheDocument();
  expect(screen.getByText(/Lowest estimated cost — AED 250/)).toBeInTheDocument();
});

test('faults are tagged with the priority that decided their influence', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  // A brake-class fault and a scratch must never look like equal inputs — the multiplier is on screen.
  expect(screen.getByText('×1.3')).toBeInTheDocument();
  expect(screen.getByText('×0.6')).toBeInTheDocument();
});

test('expected outcomes are shown with the basis each figure came from', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  expect(screen.getByText('workflow.garageRec.outcomes.title')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.outcomes.days:4.2')).toBeInTheDocument();   // duration
  expect(screen.getByText('96%')).toBeInTheDocument();                                     // success
  expect(screen.getByText('workflow.garageRec.outcomes.aed:420')).toBeInTheDocument();     // cost
  // Every figure declares how much it can be trusted and what it rests on — a garage-derived number
  // and a fleet median are different claims and must not look alike.
  expect(screen.getAllByText(/supportRow:40,workflow\.garageRec\.scope\.garage/).length).toBeGreaterThan(0);
});

test('a fleet-average figure is flagged rather than passed off as the garage\'s own', async () => {
  const fleetish = { ...NO_PER_FAULT, primary: [{ ...PAYLOAD.primary[1], rank: 1, is_top: true, why_not: null }] };
  api.get.mockResolvedValue({ data: { data: fleetish } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('7 CYLINDER');

  // A fleet fallback must SAY it is a fallback, and say why this garage had no number of its own.
  expect(screen.getByText('workflow.garageRec.outcomes.fleetWarn')).toBeInTheDocument();
  // Both duration figures (expected and risk-adjusted) carry the same fallback explanation.
  expect(screen.getAllByText('No timed repairs recorded for this garage.').length).toBe(2);
  expect(screen.getAllByText(/Fewer than 30 attributable repairs/).length).toBeGreaterThan(0);
  // …and it is marked low confidence rather than presented as a firm figure.
  expect(screen.getAllByText(/confidenceLabel.*confidence\.low/).length).toBeGreaterThan(0);
});

test('an unmeasurable outcome says so instead of showing a number', async () => {
  const noData = {
    ...NO_PER_FAULT,
    primary: [{
      ...PAYLOAD.primary[0],
      outcomes: { ...PAYLOAD.primary[0].outcomes, cost_aed: { value: null, basis: 'unavailable', sample: 0 } },
    }],
  };
  api.get.mockResolvedValue({ data: { data: noData } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  expect(screen.getAllByText('workflow.garageRec.outcomes.unavailable').length).toBeGreaterThan(0);
  expect(screen.queryByText(/outcomes\.aed/)).not.toBeInTheDocument();
  // The gap is explained, not merely blank.
  expect(screen.getAllByText(/workflow\.garageRec\.unavailableReason/).length).toBeGreaterThan(0);
});

test('the decision summary separates technical from business and names the final call', async () => {
  const split = {
    ...NO_PER_FAULT,
    strategy: {
      ...PAYLOAD.strategy,
      summary: {
        technical: { garage: 'Deals On Wheels auto', vendor_id: 223, match_score: 96 },
        business: { garage: 'GPT Garage', vendor_id: 777, advantages: ['cheaper (AED 850 less)', 'slower (+1 day)'], summary: 'x' },
        final: 'Deals On Wheels auto',
        final_mode: 'single',
        reason: 'The Major mechanical Engine fault outweighs the potential saving at GPT Garage.',
        axes_agree: false,
      },
    },
  };
  api.get.mockResolvedValue({ data: { data: split } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.summary.title')).toBeInTheDocument();
  // The two axes are named separately — engineering quality is never blended with commercial preference.
  expect(screen.getByText('workflow.garageRec.summary.technical')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.summary.business')).toBeInTheDocument();
  expect(screen.getByText('GPT Garage')).toBeInTheDocument();
  expect(screen.getByText('cheaper (AED 850 less)')).toBeInTheDocument();
  // …then the final call and the single reason that settled it.
  expect(screen.getByText('workflow.garageRec.summary.final')).toBeInTheDocument();
  expect(screen.getByText(/outweighs the potential saving/)).toBeInTheDocument();
  // When the axes disagree there is no "both agree" badge.
  expect(screen.queryByText('workflow.garageRec.summary.agree')).not.toBeInTheDocument();

  // Both axes are individually selectable: [0] is the technical pick, [1] the business alternative.
  fireEvent.click(screen.getAllByText('workflow.garageRec.use')[1]);
  expect(onPick).toHaveBeenCalledWith(777);
});

test('the summary says so when there is no business trade worth making', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findByText('workflow.garageRec.summary.title');

  expect(screen.getByText('workflow.garageRec.summary.none')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.summary.agree')).toBeInTheDocument();
});

test('each forecast shows its confidence and the counts it rests on', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  // "Confidence: High" next to the figure…
  expect(screen.getAllByText(/workflow\.garageRec\.confidenceLabel/).length).toBeGreaterThan(0);
  // …and the sample counts behind it ("Based on: 40 garage repairs · 6571 fleet repairs").
  expect(screen.getAllByText(/workflow\.garageRec\.basedOnCounts/).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/supportRow:40/).length).toBeGreaterThan(0);
  // The normal case and the risk case are SEPARATE figures — calibration shows the median forecast
  // running low, and widening one number into a range would blur that rather than state it.
  expect(screen.getByText('workflow.garageRec.outcomes.duration')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.outcomes.durationRisk')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.outcomes.days:4.2')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.rangeUpTo:9')).toBeInTheDocument();
});

test('an unavailable metric explains WHY rather than just hiding', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  // Transport has no data source at all — the panel names the fix, not just the gap.
  expect(screen.getByText('workflow.garageRec.outcomes.transport')).toBeInTheDocument();
  expect(screen.getByText(/Vendor location has not been configured/)).toBeInTheDocument();
  expect(screen.getAllByText('workflow.garageRec.outcomes.unavailable').length).toBeGreaterThan(0);
});

test('a cheaper, faster near-equal garage is surfaced as a trade-off the supervisor can take', async () => {
  const tradeoff = {
    ...NO_PER_FAULT,
    strategy: {
      mode: 'single',
      headline: 'Send the whole ticket to Deals On Wheels auto (96/100).',
      reason: 'One garage covers both faults.',
      tradeoff: 'One pickup, one invoice.',
      why_not: null,
      actionable_note: null,
      rejected_reason: 'single_covers_all',
      confidence: 90, confidence_single: 90, confidence_gain: 0,
      legs: [],
      business_tradeoff: {
        candidate_vendor_id: 501,
        candidate: '7 CYLINDER',
        technical_gap: 3,
        business_gain: 22,
        advantages: ['cheaper (AED 250)', 'faster (1 days)'],
        summary: '7 CYLINDER scores 3 points lower technically (93 vs 96) but is cheaper (AED 250) and faster (1 days).',
        detail: 'Technical evidence favours Deals On Wheels auto; operationally 7 CYLINDER is the cheaper call.',
        auto_switched: false,
      },
    },
  };
  api.get.mockResolvedValue({ data: { data: tradeoff } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.business.tradeoffTitle')).toBeInTheDocument();
  expect(screen.getByText(/scores 3 points lower technically/)).toBeInTheDocument();
  // The pick is never silently switched — but taking the trade-off is one tap.
  expect(screen.getByText('Deals On Wheels auto')).toBeInTheDocument();
  fireEvent.click(screen.getAllByText('workflow.garageRec.use')[0]);
  expect(onPick).toHaveBeenCalledWith(501);
});

test('a split recommendation overrides the hero and states its reason and trade-off', async () => {
  const split = {
    ...NO_PER_FAULT,
    strategy: {
      mode: 'split',
      headline: 'Send to Deals On Wheels auto for Engine (94/100) and GPT Garage for Bodywork (91/100).',
      reason: 'No single garage covers all faults strongly — Deals On Wheels auto has only 51% coverage on Bodywork.',
      tradeoff: 'Costs a second vehicle move and a second invoice.',
      why_not: 'Sending everything to Deals On Wheels auto would leave Bodywork at 51%.',
      actionable_note: 'Dispatch the first leg with its faults selected.',
      rejected_reason: null,
      confidence: 93, confidence_single: 72, confidence_gain: 21,
      legs: [
        { vendor_id: 223, garage: 'Deals On Wheels auto', match_score: 94, confidence: 100, faults: [{ category_key: 'engine', label: 'Engine' }] },
        { vendor_id: 777, garage: 'GPT Garage', match_score: 91, confidence: 93, faults: [{ category_key: 'bodywork', label: 'Bodywork' }] },
      ],
    },
  };
  api.get.mockResolvedValue({ data: { data: split } });
  const onPick = jest.fn();
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={onPick} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.strategy.splitTitle')).toBeInTheDocument();
  expect(screen.getByText(/Send to Deals On Wheels auto for Engine/)).toBeInTheDocument();
  // both legs are listed and directly selectable — the split is actionable, not just advice
  expect(screen.getByText('GPT Garage')).toBeInTheDocument();
  expect(screen.getByText(/No single garage covers all faults strongly/)).toBeInTheDocument();
  expect(screen.getByText(/Costs a second vehicle move/)).toBeInTheDocument();

  fireEvent.click(screen.getAllByText('workflow.garageRec.use')[1]);
  expect(onPick).toHaveBeenCalledWith(777);
});

test('a single-garage verdict explains why it did NOT split', async () => {
  const single = {
    ...NO_PER_FAULT,
    strategy: {
      mode: 'single',
      headline: 'Send the whole ticket to Deals On Wheels auto (96/100).',
      reason: 'The faults are the same kind of work — one workshop handles them in a single visit.',
      tradeoff: 'One pickup, one invoice, one point of accountability.',
      why_not: 'Splitting would score 97% vs 93% — not enough to pay for the move.',
      actionable_note: null,
      rejected_reason: 'same_domain',
      confidence: 93, confidence_single: 93, confidence_gain: 4,
      legs: [{ vendor_id: 223, garage: 'Deals On Wheels auto', match_score: 96, confidence: 93, faults: [] }],
    },
  };
  api.get.mockResolvedValue({ data: { data: single } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);

  expect(await screen.findByText('workflow.garageRec.strategy.singleTitle')).toBeInTheDocument();
  expect(screen.getByText(/the same kind of work/)).toBeInTheDocument();
  expect(screen.getByText(/not enough to pay for the move/)).toBeInTheDocument();
});

test('once selected, the CTA becomes a recorded-recommendation summary', async () => {
  api.get.mockResolvedValue({ data: { data: NO_PER_FAULT } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={223} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');

  expect(screen.getByText('workflow.garageRec.confirmSelected')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.auditNote')).toBeInTheDocument();
  // the plain "select" CTA is gone once confirmed
  expect(screen.queryByText('workflow.garageRec.selectRecommended')).not.toBeInTheDocument();
});

test('a low-confidence recommendation shows the review-alternatives warning', async () => {
  const low = { ...NO_PER_FAULT, primary: [{ ...PAYLOAD.primary[0], confidence: 'low', match_score: 34 }] };
  api.get.mockResolvedValue({ data: { data: low } });
  render(<GarageRecommendations ticketId={9} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  await screen.findAllByText('Deals On Wheels auto');
  expect(screen.getByText('workflow.garageRec.lowWarning')).toBeInTheDocument();
});

test('degrades gracefully when there is no history', async () => {
  api.get.mockResolvedValue({ data: { data: { has_history: false, total_history: 0, primary: [], also_consider: [] } } });
  render(<GarageRecommendations ticketId={2} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);
  expect(await screen.findByText('workflow.garageRec.none')).toBeInTheDocument();
});

test('no proven primary but specialists exist — explains why and surfaces the specialists directly', async () => {
  // primary empty (no garage has a proven record on this exact vehicle + fault) but a fault specialist
  // exists. The panel must NOT go blank under "Recommended for" — it explains and shows the specialist.
  const noPrimary = { ...NO_PER_FAULT, primary: [], also_consider: PAYLOAD.also_consider };
  api.get.mockResolvedValue({ data: { data: noPrimary } });
  render(<GarageRecommendations ticketId={7} selectedVendorId={null} onPick={() => {}} onResult={() => {}} />);

  // the "Recommended for: Engine/Interior" fault labels still render
  expect(await screen.findByText('Engine')).toBeInTheDocument();
  // explanation, not a blank panel — and the specialist is reachable without a Compare click
  expect(screen.getByText('workflow.garageRec.noPrimaryTitle')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.noPrimary')).toBeInTheDocument();
  expect(screen.getByText('workflow.garageRec.specialists')).toBeInTheDocument();
  expect(screen.getByText('Alresala')).toBeInTheDocument();
});
