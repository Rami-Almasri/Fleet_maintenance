// The panel's contract: it EXPLAINS, it never decides.
//
// Three things are worth pinning here, and each is a rule the backend depends on the client honouring:
//
//   1. Blocking reasons render from CODE + PARAMS, not from the English the server happens to send.
//      That frozen English exists for the audit trail; if the UI rendered it, the Arabic interface
//      would be half-English and no translation could fix it.
//   2. The buttons are the server's `actions` list and nothing else. A client that decides for itself
//      which actions are legal drifts from the routes and starts showing buttons that 403.
//   3. A mapping that is only SUGGESTED must not read as resolved. That is the whole §18 guarantee at
//      the last inch — a green tick over an unconfirmed guess is how a wrong product gets posted.

import { render, screen, waitFor } from '@testing-library/react';
import FinancialPanel from './FinancialPanel';
import { listFinancialEvents } from '../../api/financial';

jest.mock('../../i18n/I18nContext', () => ({
  // tf(key, fallback, vars) — resolve to the English fallback with {placeholders} filled, which is
  // exactly what the real resolver does when no Arabic key has landed yet.
  useI18n: () => ({
    tf: (key, fallback, vars) =>
      String(fallback ?? key).replace(/\{(\w+)\}/g, (m, k) => (vars?.[k] != null ? String(vars[k]) : m)),
  }),
}));

// `mock`-prefixed because jest.mock() factories are hoisted above the file's own declarations, and
// only names matching /^mock/i may be referenced from inside one.
let mockPermissions = ['financial.view'];
jest.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ can: (p) => mockPermissions.includes(p) }),
}));

jest.mock('../../api/financial', () => ({
  listFinancialEvents: jest.fn(),
  validateFinancialEvent: jest.fn(),
  syncFinancialEvent: jest.fn(),
  retryFinancialEvent: jest.fn(),
  approveFinancialEvent: jest.fn(),
  cancelFinancialEvent: jest.fn(),
}));

const blockedEvent = {
  id: 1,
  expense_type: 'REPAIR',
  expense_type_label: 'Repair maintenance',
  amount: 2300,
  currency: 'AED',
  status: 'BLOCKED',
  status_label: 'Blocked',
  document_type: 'VENDOR_BILL',
  document_type_label: 'Vendor Bill',
  requirements: { supplier: true, invoice_number: true, invoice_date: true, attachment: true },
  invoice_number: 'GA-2026-0042',
  has_attachment: false,
  block_reasons: [
    { code: 'product_not_mapped', params: { description: 'Brake Pads' }, text: 'FROZEN ENGLISH — must not render' },
    { code: 'attachment_missing', params: {}, text: 'FROZEN ENGLISH — must not render' },
  ],
  vehicle: {
    id: 3, plate_no: 'G63-1', vin: 'W1NYC7GJ1RX502076',
    analytic_account: { state: 'mapped', usable: true, odoo_id: 7001, odoo_name: 'G63 analytic' },
  },
  vendor: {
    id: 4, name: 'Garage ABC',
    // Only SUGGESTED — proposed, never confirmed. Must not read as resolved.
    partner: { state: 'suggested', usable: false, odoo_id: 8001, odoo_name: 'Garage ABC' },
  },
  expense_account: { odoo_account_id: 4101, name: 'Fleets Maintenance | Repair Maintenance Expenses', resolved: true },
  lines: [
    { id: 11, kind: 'part', description: 'Brake Pads', needs_product_mapping: true, product: { state: 'unmapped', usable: false } },
  ],
  odoo: {},
  actions: [{ key: 'validate', label: 'Validate financial data', primary: false, danger: false }],
};

beforeEach(() => {
  mockPermissions = ['financial.view'];
  listFinancialEvents.mockResolvedValue({ items: [blockedEvent] });
});

test('a blocking reason renders from its code and params, not the frozen English', async () => {
  render(<FinancialPanel maintenanceId={7} />);

  // The code+params sentence, with the part name interpolated from `params`.
  expect(await screen.findByText(/Product .*Brake Pads.* is not mapped to an Odoo product/)).toBeInTheDocument();
  expect(screen.getByText(/A receipt or invoice document must be attached/)).toBeInTheDocument();

  // The audit-trail English must never reach the screen.
  expect(screen.queryByText(/FROZEN ENGLISH/)).not.toBeInTheDocument();
});

test('a suggested mapping is not shown as resolved', async () => {
  render(<FinancialPanel maintenanceId={7} />);

  // The confirmed one shows its Odoo record …
  expect(await screen.findByText('G63 analytic')).toBeInTheDocument();

  // … the merely-suggested one says so instead of showing the partner name as if it were settled.
  expect(screen.getByText(/suggested — not confirmed/i)).toBeInTheDocument();
  expect(screen.getByText(/no Odoo product/i)).toBeInTheDocument();
});

test('only the actions the server sent are offered', async () => {
  render(<FinancialPanel maintenanceId={7} />);

  expect(await screen.findByRole('button', { name: 'Validate financial data' })).toBeInTheDocument();
  // The server did not offer Send on a blocked event, so the client must not invent one.
  expect(screen.queryByRole('button', { name: /send to odoo/i })).not.toBeInTheDocument();
});

test('the panel is invisible to a user without financial.view', async () => {
  mockPermissions = [];
  const { container } = render(<FinancialPanel maintenanceId={7} />);

  await waitFor(() => expect(container).toBeEmptyDOMElement());
  // It is not a locked box with a padlock on it — it simply is not part of their screen.
  expect(listFinancialEvents).not.toHaveBeenCalled();
});

test('a synced event names the Odoo document and only links when a URL was configured', async () => {
  listFinancialEvents.mockResolvedValue({
    items: [{
      ...blockedEvent,
      status: 'SYNCED',
      status_label: 'Synced',
      block_reasons: [],
      has_attachment: true,
      odoo: { document_id: 18452, document_model: 'account.move', document_reference: 'BILL/2026/18452', url: null },
      actions: [],
    }],
  });

  render(<FinancialPanel maintenanceId={7} />);

  expect(await screen.findByText(/BILL\/2026\/18452/)).toBeInTheDocument();
  // §41 — no template configured means no link is invented.
  expect(screen.queryByRole('link', { name: /open in odoo/i })).not.toBeInTheDocument();
});
