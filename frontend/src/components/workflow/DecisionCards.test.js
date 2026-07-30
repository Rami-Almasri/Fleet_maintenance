// The Decision Card's product contract, not just its markup.
//
// These assertions encode the wording rules the platform has to keep to be trusted: the caveat is
// never hidden, the statistics stay behind a disclosure, an override cannot be recorded without a
// reason, and silence renders nothing at all.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import DecisionCards from './DecisionCards';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn(), post: jest.fn() }));
jest.mock('../../i18n/I18nContext', () => ({
  // tf() returns the English fallback, which is what the supervisor actually reads today.
  useI18n: () => ({
    t: (k) => k,
    tf: (k, fallback, vars) =>
      (vars ? Object.entries(vars).reduce((s, [n, v]) => s.replace(`{${n}}`, v), fallback) : fallback),
  }),
}));

const CARD = {
  id: 'comeback-warning',
  capability_id: 'comeback-warning',
  recommendation_id: 42,
  tier: 2,
  strength: 'should',
  confidence: 'moderate',
  observation: 'This is a comeback — occurrence 2 of COOLING on this vehicle, 74 days after the last one.',
  recommendation: 'Run a diagnostic reset before dispatching, and treat the garage conversation as rework.',
  reasoning: 'Fleet-wide, COOLING returns within 90 days in 46.7% of cases (n=534).',
  show_cases_not_statistics: false,
  evidence: {
    sample_size: 534,
    source_ids: [101, 102],
    is_proxy: true,
    proxy_note: 'return rate, not verified repair success',
    confidence: 'moderate',
  },
  actions: [
    { label: 'Start diagnostic reset', effect: 'diagnostic_reset' },
    { label: 'Dispatch anyway', effect: 'override', requires_reason: true },
  ],
  answered: null,
};

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

/** Silence is the normal case — most tickets have no comeback, and the UI must not leave a gap. */
test('renders nothing when history has nothing to say', async () => {
  api.get.mockResolvedValue({ data: { data: [] } });
  const { container } = render(<DecisionCards ticketId={7} />);

  await waitFor(() => expect(api.get).toHaveBeenCalled());
  expect(container).toBeEmptyDOMElement();
});

/** Advisory, never blocking: a failed fetch must leave the dispatch screen fully usable. */
test('renders nothing when the intelligence call fails', async () => {
  api.get.mockRejectedValue(new Error('down'));
  const { container } = render(<DecisionCards ticketId={7} />);

  await waitFor(() => expect(api.get).toHaveBeenCalled());
  expect(container).toBeEmptyDOMElement();
});

test('leads with the observation and the recommendation, and keeps the statistics behind a disclosure', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  render(<DecisionCards ticketId={7} />);

  expect(await screen.findByText(/occurrence 2 of COOLING/)).toBeInTheDocument();
  expect(screen.getByText(/Run a diagnostic reset/)).toBeInTheDocument();

  // The paragraph of numbers is NOT in the way of the decision…
  expect(screen.queryByText(/46\.7%/)).not.toBeInTheDocument();

  // …but it is one tap away, with the sample size alongside it.
  fireEvent.click(screen.getByText('Why this?'));
  expect(screen.getByText(/46\.7%/)).toBeInTheDocument();
  expect(screen.getByText(/Based on 534 historical cases/)).toBeInTheDocument();
});

/**
 * THE TRUST RULE. A proxy that presents as a measurement is how a platform loses a user on the
 * first case it gets wrong — so the caveat is visible without opening anything.
 */
test('the proxy caveat is shown without being opened', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  render(<DecisionCards ticketId={7} />);

  expect(await screen.findByText(/return rate, not verified repair success/)).toBeInTheDocument();
});

test('the strength verb comes from the evidence, not the card author', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  render(<DecisionCards ticketId={7} />);

  expect(await screen.findByText('Should')).toBeInTheDocument();
  expect(screen.getByText('Moderate evidence')).toBeInTheDocument();
});

test('accepting records the response against the recommendation that was shown', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  api.post.mockResolvedValue({ data: { data: {} } });
  render(<DecisionCards ticketId={7} />);

  fireEvent.click(await screen.findByText('Start diagnostic reset'));

  await waitFor(() =>
    expect(api.post).toHaveBeenCalledWith('/maintenance-tickets/7/decision-cards/42/respond', {
      response: 'accepted',
      reason: null,
    }));

  expect(await screen.findByText(/You accepted this recommendation/)).toBeInTheDocument();
});

/**
 * The override reason is the single most valuable field in the loop — the only place the platform
 * learns WHY it was wrong rather than merely THAT it was. So it cannot be skipped past.
 */
test('an override cannot be recorded without a reason', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  api.post.mockResolvedValue({ data: { data: {} } });
  render(<DecisionCards ticketId={7} />);

  fireEvent.click(await screen.findByText('Dispatch anyway'));

  const submit = screen.getByText('Record and continue');
  expect(submit).toBeDisabled();
  expect(api.post).not.toHaveBeenCalled();

  fireEvent.change(screen.getByPlaceholderText(/already re-diagnosed/), {
    target: { value: 'Garage already has the parts on the shelf.' },
  });
  fireEvent.click(screen.getByText('Record and continue'));

  await waitFor(() =>
    expect(api.post).toHaveBeenCalledWith('/maintenance-tickets/7/decision-cards/42/respond', {
      response: 'overridden',
      reason: 'Garage already has the parts on the shelf.',
    }));
});

/** Below the statistics floor the honest thing is to show the cases, not a rate built from four. */
test('a thin sample is described as anecdote rather than as a rate', async () => {
  api.get.mockResolvedValue({
    data: { data: [{ ...CARD, show_cases_not_statistics: true, evidence: { ...CARD.evidence, sample_size: 4 } }] },
  });
  render(<DecisionCards ticketId={7} />);

  fireEvent.click(await screen.findByText('Why this?'));
  expect(screen.getByText(/Only 4 comparable cases — treat as anecdote, not a rate/)).toBeInTheDocument();
});

/** An answered card becomes a receipt — visible, but no longer competing for attention. */
test('an already-answered card collapses to what was decided', async () => {
  api.get.mockResolvedValue({ data: { data: [{ ...CARD, answered: 'overridden' }] } });
  render(<DecisionCards ticketId={7} />);

  expect(await screen.findByText(/You overrode this recommendation/)).toBeInTheDocument();
  expect(screen.queryByText('Why this?')).not.toBeInTheDocument();
});

/** If the answer did not save, say so — a silently dropped response is a hole in the loop. */
test('a failed response is surfaced rather than swallowed', async () => {
  api.get.mockResolvedValue({ data: { data: [CARD] } });
  api.post.mockRejectedValue(new Error('offline'));
  render(<DecisionCards ticketId={7} />);

  fireEvent.click(await screen.findByText('Start diagnostic reset'));

  expect(await screen.findByText(/Your response was not saved/)).toBeInTheDocument();
});
