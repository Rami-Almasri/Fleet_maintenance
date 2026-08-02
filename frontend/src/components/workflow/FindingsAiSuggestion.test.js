// The AI fault suggestion on the inspector's test-drive report.
//
// These lock the RULES rather than the layout, because the rules are what make this safe to put in
// front of the person whose findings drive severity, garage routing and the recommendation engine:
//
//   · a suggestion NEVER selects itself — confirmation is a deliberate tap
//   · a weak match is phrased as a question, a strong one as an answer
//   · a fault the ontology understands but the catalog cannot offer gets no Add button
//   · the Yes/No verdict is filed with the ticket and car it was given on, under `test_findings`
//
// Labels resolve against the REAL English table, so a dropped label fails here instead of shipping a
// raw key to a phone in a yard.

import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import FindingsAiSuggestion from './FindingsAiSuggestion';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ post: jest.fn() }));
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

const match = (over = {}) => ({
  keyword: {
    id: 5, keyword: 'Overheating', keyword_ar: 'ارتفاع حرارة المحرك',
    risk_label: 'Critical', risk_tone: 'red',
    category_label: 'Engine', category_label_ar: 'المحرك',
  },
  score: 96,
  confidence: 'strong',
  selectable: true,
  matches: [{ term: 'الموتر يسخن', how: 'exact' }],
  causes: ['Coolant leak', 'Water-pump failure'],
  fixes: [{ label: 'Replace thermostat', label_ar: 'تغيير الثرموستات' }],
  explanation: { reasons: [] },
  ...over,
});

const resolveWith = (matches) =>
  api.post.mockResolvedValue({ data: { data: { query: 'الموتر يسخن', normalized: 'الموتر يسخن', matches } } });

const setup = (props = {}) =>
  render(
    <FindingsAiSuggestion
      query="الموتر يسخن"
      onAdd={props.onAdd || jest.fn()}
      isSelected={props.isSelected || (() => false)}
      isLocked={props.isLocked || (() => false)}
      ticketId={171571}
      vehicleId={1864}
      context="test_findings"
      {...props}
    />,
  );

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
});
afterEach(() => jest.useRealTimers());

/** Advance past the debounce and let the resolve promise settle, inside act so React can flush. */
const runQuery = async () => {
  await act(async () => { jest.advanceTimersByTime(500); });
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finding-keywords/resolve', expect.anything()));
};

test('never selects the suggestion on its own — the inspector must confirm', async () => {
  const onAdd = jest.fn();
  resolveWith([match()]);
  setup({ onAdd });
  await runQuery();

  // The fault is on screen and the ticket is untouched.
  expect(await screen.findByText('Overheating')).toBeInTheDocument();
  expect(onAdd).not.toHaveBeenCalled();

  fireEvent.click(screen.getByRole('button', { name: /Add as finding/i }));
  expect(onAdd).toHaveBeenCalledWith('Overheating');
  expect(onAdd).toHaveBeenCalledTimes(1);
});

test('a strong match is stated, a weak one is asked', async () => {
  resolveWith([match()]);
  const { unmount } = setup();
  await runQuery();
  expect(await screen.findByText(/Suggested fault/i)).toBeInTheDocument();
  unmount();

  jest.clearAllMocks();
  resolveWith([match({ score: 55, confidence: 'possible' })]);
  setup();
  await runQuery();
  expect(await screen.findByText(/Not sure — did you mean/i)).toBeInTheDocument();
});

test('an understood-but-not-selectable fault offers no way to add it', async () => {
  const onAdd = jest.fn();
  resolveWith([match({ selectable: false, keyword: { ...match().keyword, id: 9, keyword: 'Water pump failure' } })]);
  setup({ onAdd });
  await runQuery();

  expect(await screen.findByText('Water pump failure')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Add as finding/i })).not.toBeInTheDocument();
  expect(screen.getByText(/the garage records it during the repair/i)).toBeInTheDocument();
  expect(onAdd).not.toHaveBeenCalled();
});

test('the verdict is filed against the real ticket and car, as a field observation', async () => {
  resolveWith([match()]);
  setup();
  await runQuery();
  await screen.findByText('Overheating');

  api.post.mockResolvedValue({ data: { message: 'ok' } });
  fireEvent.click(screen.getByRole('button', { name: 'No' }));

  await waitFor(() =>
    expect(api.post).toHaveBeenCalledWith('/finding-keywords/match-feedback', {
      text: 'الموتر يسخن',
      keyword_id: 5,
      correct: false,
      score: 96,
      // NOT 'match_tester' — this is ground truth from an inspection, and the corpus must be able
      // to tell it apart from an admin probing the library.
      context: 'test_findings',
      maintenance_id: 171571,
      vehicle_id: 1864,
    }),
  );
});

test('an already-selected fault cannot be added twice', async () => {
  const onAdd = jest.fn();
  resolveWith([match()]);
  setup({ onAdd, isSelected: (k) => k === 'Overheating' });
  await runQuery();

  const button = await screen.findByRole('button', { name: /Already added/i });
  expect(button).toBeDisabled();
  fireEvent.click(button);
  expect(onAdd).not.toHaveBeenCalled();
});

test('a lookup failure degrades to the manual path instead of blocking the picker', async () => {
  api.post.mockRejectedValue(new Error('offline'));
  setup();
  await act(async () => { jest.advanceTimersByTime(500); });

  expect(await screen.findByText(/Couldn’t reach the fault library/i)).toBeInTheDocument();
});

test('does not query the library for a fragment too short to be a sentence', async () => {
  resolveWith([match()]);
  setup({ query: 'br' });
  await act(async () => { jest.advanceTimersByTime(500); });

  expect(api.post).not.toHaveBeenCalled();
});
