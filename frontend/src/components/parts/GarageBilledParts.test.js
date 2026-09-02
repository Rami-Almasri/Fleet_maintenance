// Parts billed on a garage's invoice — the half of "what did this part cost" the Parts page could
// not see.
//
// The contract these tests hold, not the markup:
//
//   · A mixed bill shows the SPLIT. Parts and labour are separate figures on one document, and
//     collapsing them to a total is what made a garage-billed part unreadable on a parts page.
//   · Every row points at its ticket. This list is read-only by design — the bill is written through
//     MaintenanceInvoiceService on the ticket, and a second write path here would reopen the
//     double-count that PartInvoiceService's attach guard exists to prevent.
//   · The part lines are available but not in the way.
//   · Nothing billed yet says so, rather than rendering an empty box.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import GarageBilledParts from './GarageBilledParts';
import api from '../../api/client';

jest.mock('../../api/client', () => ({ get: jest.fn() }));
// t() returns the key, so assertions name the contract rather than a translation that may be reworded.
jest.mock('../../i18n/I18nContext', () => ({ useI18n: () => ({ t: (k, v) => (v ? `${k}:${JSON.stringify(v)}` : k), lang: 'en' }) }));

const bill = (over = {}) => ({
  id: 11,
  invoice_no: 'GRG-4412',
  garage: 'Al Rashid Auto',
  is_internal: false,
  maintenance_id: 903,
  vehicle_id: 55,
  plate: 'A-12345',
  parts_total: 340,
  labor_total: 150,
  amount: 490,
  recorded_at: '2026-08-20T10:00:00+00:00',
  parts: [
    { id: 1, description: 'AC Compressor', part_number: 'AC-900', quantity: 1, line_total: 340 },
  ],
  ...over,
});

const renderIt = () => render(<MemoryRouter><GarageBilledParts /></MemoryRouter>);

const resolveWith = (invoices) => api.get.mockResolvedValue({ data: { data: { invoices } } });

beforeEach(() => jest.clearAllMocks());

test('a mixed bill shows the part and the labour as separate figures', async () => {
  resolveWith([bill()]);
  renderIt();

  // The split IS the reason the row exists — a bill total alone tells you nothing about the part.
  expect(await screen.findByText(/340/)).toBeInTheDocument();
  expect(screen.getByText(/150/)).toBeInTheDocument();
  expect(screen.getByText(/490/)).toBeInTheDocument();
});

test('every bill points at the ticket where it is actually edited', async () => {
  resolveWith([bill()]);
  renderIt();

  const link = await screen.findByRole('link', { name: /Open the ticket/i });
  expect(link).toHaveAttribute('href', '/maintenance/903');
});

test('the vehicle is reachable from the bill', async () => {
  resolveWith([bill()]);
  renderIt();

  expect(await screen.findByRole('link', { name: 'A-12345' })).toHaveAttribute('href', '/vehicles/55');
});

test('the part lines are behind a toggle so a long bill does not bury the split', async () => {
  resolveWith([bill()]);
  renderIt();

  expect(await screen.findByText(/Show the parts/)).toBeInTheDocument();
  expect(screen.queryByText(/AC Compressor/)).not.toBeInTheDocument();

  fireEvent.click(screen.getByText(/Show the parts/));

  expect(await screen.findByText(/AC Compressor/)).toBeInTheDocument();
});

test('an in-house repair is marked as one', async () => {
  resolveWith([bill({ garage: null, is_internal: true })]);
  renderIt();

  expect(await screen.findByText('In-house')).toBeInTheDocument();
});

test('no garage-billed part says so rather than showing an empty box', async () => {
  resolveWith([]);
  renderIt();

  expect(await screen.findByText('No garage has billed a part yet')).toBeInTheDocument();
});

test('it reads the garage-billed endpoint, not the supplier ledger', async () => {
  resolveWith([]);
  renderIt();

  // The two roads a part is billed down are different endpoints on purpose; reading the wrong one
  // here would silently show supplier bills twice.
  await waitFor(() => expect(api.get).toHaveBeenCalledWith('/part-invoices/garage-billed', expect.anything()));
});
