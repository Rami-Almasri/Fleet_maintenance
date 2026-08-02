// "What the garage will do" — the expected work behind a ticket's faults, now read on the TICKET
// rather than on the assign step. These lock the things that make the block safe to show the person
// authorising the work: it is typical work and says so, it never invents a repair for a hand-written
// finding, and the one measured line always travels with the count it was measured from.
//
// Labels resolve against the REAL English table rather than echoing keys, so a dropped label is a
// failing test rather than a card that renders `workflow.garageRec.dispatchPlan.outlook.title`.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import RepairOutlook from './RepairOutlook';
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
    // Counted from the fleet's own repair records, unlike everything above it.
    history: [{ label: 'Engine noise', relation: 'related_to', rate: 34, count: 72, scope: null }],
  },
  {
    // SAME CATEGORY as the row above. The per-fault garage cards would have collapsed these two into
    // one Engine row and kept only the first symptom — the reason this block is keyed per finding.
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

const load = (rows = OUTLOOK) => api.get.mockResolvedValue({ data: { data: rows } });
const renderOutlook = (props = {}) => render(<RepairOutlook ticketId={9} {...props} />);

beforeEach(() => jest.clearAllMocks());

test('reads the ticket’s own expected work, open the moment the ticket is opened', async () => {
  load();
  renderOutlook();

  // What the car is having done to it is the first thing a reader wants, not an advanced question
  // they opt into.
  expect(await screen.findByText('What the garage will do')).toBeInTheDocument();
  expect(api.get).toHaveBeenCalledWith('/maintenance-tickets/9/repair-outlook');
  expect(screen.getByText('Worn spark plugs / coils')).toBeInTheDocument();
  expect(screen.getByText('Clogged / faulty fuel injector')).toBeInTheDocument();
  expect(screen.getByText('Replace spark plugs')).toBeInTheDocument();

  // …and still collapsible for anyone who already knows the faults.
  fireEvent.click(screen.getByText('What the garage will do'));
  expect(screen.queryByText('Worn spark plugs / coils')).not.toBeInTheDocument();
});

test('every selected fault is described, including a second one in the same category', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  expect(screen.getByText('Rough idle / misfire')).toBeInTheDocument();
  expect(screen.getByText('Overheating')).toBeInTheDocument();
  expect(screen.getByText('Coolant leak')).toBeInTheDocument();
  expect(screen.getByText('Water-pump failure')).toBeInTheDocument();
});

test('a repair that only sometimes applies is marked, not listed as equally likely', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  // 'Clean throttle body' is a possible repair, not a typical one. Printed identically to the two
  // typical ones it reads as three jobs the car is about to have done.
  expect(screen.getByText('Clean throttle body').textContent).toContain('(sometimes)');
  expect(screen.getByText('Replace spark plugs').textContent).not.toContain('(sometimes)');
});

test('a hand-written finding admits it has no standard repair instead of inventing one', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  expect(screen.getByText('weird clunk i heard')).toBeInTheDocument();
  expect(screen.getByText(/we have no standard repair for it/)).toBeInTheDocument();
});

test('the outlook says it is typical work, not a diagnosis of this car', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  // Without this the block reads as a decision already taken, and work nobody has yet confirmed the
  // car needs gets authorised off it.
  expect(screen.getByText(/the garage confirms once it has looked at the car/)).toBeInTheDocument();
});

test('no engine vocabulary reaches the repair outlook', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  const block = screen.getByText(/the garage confirms once it has looked at the car/).closest('div');
  const text = block.textContent.toLowerCase();

  // A bare percentage is banned; a measured RATE printed beside its denominator is not, and the fleet
  // history line ("34% of 72 of these jobs…") is the case the ruling explicitly asks for — the origin
  // travels in the sentence. So '%' is not on this list, and '/100' still is.
  ['fault experience', 'first-time resolution', 'confidence', 'grain', 'coverage', 'basis', '/100']
    .forEach((banned) => expect(text).not.toContain(banned));
});

test('the measured fleet history is stated with the count it was measured from', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  // The one counted figure on this card. Without the denominator "34%" is a number a reader either
  // trusts blindly or ignores; with it, they can restate it in their own words.
  expect(screen.getByText(/34% of 72 of these jobs also involved Engine noise/)).toBeInTheDocument();
});

test('a fault with no measured history simply omits the line', async () => {
  load();
  renderOutlook();
  await screen.findByText('What the garage will do');

  // "Overheating" has curated causes and repairs but nothing counted behind it. An empty measured
  // line would read as "we looked and found nothing", which is a different claim.
  expect(screen.queryByText(/of these jobs were traced to/)).not.toBeInTheDocument();
});

test('renders nothing at all when no finding resolves to a known fault', async () => {
  load([]);
  const { container } = renderOutlook();

  // A ticket whose findings the ontology cannot describe gets no empty card on it.
  await waitFor(() => expect(api.get).toHaveBeenCalled());
  expect(container).toBeEmptyDOMElement();
});

test('a failed lookup is silent, never an error on a read-only block', async () => {
  api.get.mockRejectedValue(new Error('boom'));
  const { container } = renderOutlook();

  await waitFor(() => expect(api.get).toHaveBeenCalled());
  expect(container).toBeEmptyDOMElement();
});
