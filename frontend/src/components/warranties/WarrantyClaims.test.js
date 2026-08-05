// Claims: what may be recorded, and what may never be changed afterwards.
//
// A claim's window verdict (`was_in_window` / `window_evidence`) is computed by the server at the
// moment of filing, against the odometer of that day, and frozen. It is the answer to "was this
// still covered when it failed" — a question whose answer must not drift as the car keeps being
// driven. So the UI must never send it, never offer to edit it, and never re-open a settled claim.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import WarrantyClaims from './WarrantyClaims';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn(), post: jest.fn() }));

const mockToast = { success: jest.fn(), error: jest.fn() };
jest.mock('../ui/Toast', () => ({ useToast: () => mockToast }));
jest.mock('../../i18n/I18nContext', () => ({ useI18n: () => ({ t: (k) => k, lang: 'en' }) }));

const WARRANTY = { id: 7, subject: 'AC Compressor' };

const claim = (over = {}) => ({
  id: 1, warranty_id: 7, vehicle_id: 3,
  claimed_on: '2026-06-01', claim_odometer: 105000,
  failure_description: 'Compressor seized',
  was_in_window: true,
  window_evidence: '3 of 12 months, 5,000 of 20,000 km',
  outcome: 'pending', outcome_reason: null, resolved_on: null,
  recovered_amount: null, currency: 'AED', remedy: null,
  created_by_name: 'Ops', created_at: '2026-06-01T10:00:00+00:00',
  ...over,
});

const setup = async (claims, props = {}) => {
  api.get.mockResolvedValue({ data: { data: claims } });
  render(
    <WarrantyClaims warranty={WARRANTY} canFile canAdjudicate onClose={jest.fn()} onChanged={jest.fn()} {...props} />
  );
  if (claims.length) await screen.findByText(claims[0].failure_description);
};

beforeEach(() => jest.clearAllMocks());

describe('the frozen verdict', () => {
  it('shows the verdict exactly as it was recorded', async () => {
    await setup([claim()]);

    expect(screen.getByText('warranties.wasInWindow')).toBeInTheDocument();
    expect(screen.getByText(/3 of 12 months, 5,000 of 20,000 km/)).toBeInTheDocument();
    // Says out loud that this is history, not a live calculation.
    expect(screen.getByText('warranties.frozenNote')).toBeInTheDocument();
  });

  it('renders an out-of-window claim as such rather than hiding it', async () => {
    await setup([claim({ was_in_window: false, window_evidence: '14 of 12 months' })]);

    expect(screen.getByText('warranties.wasOutOfWindow')).toBeInTheDocument();
  });

  /** THE RULE: no control anywhere may edit a finalised verdict. */
  it('offers no way to edit the verdict', async () => {
    await setup([claim()]);

    const editable = screen.queryAllByRole('textbox').concat(screen.queryAllByRole('spinbutton'));
    for (const el of editable) {
      expect(el.value ?? '').not.toContain('of 20,000 km');
    }
    expect(screen.queryByDisplayValue(/3 of 12 months/)).not.toBeInTheDocument();
  });

  it('never sends the verdict when filing — the server decides it', async () => {
    await setup([]);
    api.post.mockResolvedValue({ data: { success: true } });

    fireEvent.click(screen.getByText('warranties.fileClaim'));
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'It failed again' } });
    fireEvent.click(screen.getByText('warranties.submitClaim'));

    await waitFor(() => expect(api.post).toHaveBeenCalled());
    const [, payload] = api.post.mock.calls[0];
    expect(payload).not.toHaveProperty('was_in_window');
    expect(payload).not.toHaveProperty('window_evidence');
    expect(payload.failure_description).toBe('It failed again');
  });
});

describe('filing', () => {
  it('requires a description', async () => {
    await setup([]);

    fireEvent.click(screen.getByText('warranties.fileClaim'));

    expect(screen.getByRole('button', { name: 'warranties.submitClaim' })).toBeDisabled();
  });

  it('reports a network failure honestly', async () => {
    await setup([]);
    api.post.mockRejectedValue(new Error('Network Error'));

    fireEvent.click(screen.getByText('warranties.fileClaim'));
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'x' } });
    fireEvent.click(screen.getByText('warranties.submitClaim'));

    await waitFor(() => expect(mockToast.error).toHaveBeenCalledWith('warranties.networkError'));
  });
});

describe('adjudication', () => {
  it('can record an answer while a claim is pending', async () => {
    await setup([claim()]);

    expect(screen.getByText('warranties.recordOutcome')).toBeInTheDocument();
  });

  /** A settled claim is closed: re-adjudicating would rewrite what a supplier actually did. */
  it('cannot re-open a claim that already has an answer', async () => {
    await setup([claim({ outcome: 'rejected', outcome_reason: 'Corrosion excluded after 6 months', resolved_on: '2026-06-20' })]);

    expect(screen.queryByText('warranties.recordOutcome')).not.toBeInTheDocument();
    expect(screen.getByText(/Corrosion excluded after 6 months/)).toBeInTheDocument();
  });

  it('will not submit a refusal without their reason', async () => {
    await setup([claim()]);

    fireEvent.click(screen.getByText('warranties.recordOutcome'));
    // Two selects live here (outcome, remedy); the outcome is the first.
    fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: 'rejected' } });

    expect(screen.getByRole('button', { name: 'common.save' })).toBeDisabled();
  });

  it('records an accepted outcome with what was recovered', async () => {
    await setup([claim()]);
    api.post.mockResolvedValue({ data: { success: true } });

    fireEvent.click(screen.getByText('warranties.recordOutcome'));
    const amount = screen.getByRole('spinbutton');
    fireEvent.change(amount, { target: { value: '1250' } });
    fireEvent.click(screen.getByText('common.save'));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/warranty-claims/1/resolve', expect.objectContaining({
      outcome: 'accepted',
      recovered_amount: 1250,
    })));
  });

  it('hides adjudication entirely without permission', async () => {
    await setup([claim()], { canAdjudicate: false });

    expect(screen.queryByText('warranties.recordOutcome')).not.toBeInTheDocument();
  });
});
