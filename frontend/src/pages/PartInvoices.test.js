// The actions on an invoice row — state-aware, and only ever the ones that are valid.
//
// The ledger used to render one button per legal transition, so a draft showed Submit, Approve and
// Cancel side by side with Edit and Delete: five actions with no order of importance, one of which
// (Approve) skipped the review stage another one (Submit) existed to start.
//
// The contract these tests hold, not the markup:
//
//   · The row renders what the SERVER offers and nothing else. `actions` is computed once, per state and
//     per user, in App\Support\FinancialDocumentStatus — the page adds no rules of its own, so it cannot
//     offer a move the API would refuse.
//   · One primary action leads. The rest are secondary, and the destructive ones are never the headline.
//   · A row with nothing to do shows nothing to do, rather than a line of dead buttons.
//
// The enforcement half — that the API actually refuses what is not offered — is pinned in the backend
// (Tests\Crud\InvoiceActionAuthorizationTest). Hiding a button proves nothing on its own.

import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import PartInvoices from './PartInvoices';
import api from '../api/client';

jest.mock('../api/client', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }));
// t() returns the key, so assertions name the contract rather than a translation that may be reworded.
jest.mock('../i18n/I18nContext', () => ({
  useI18n: () => ({ t: (k, v) => (v ? k.replace(/\{(\w+)\}/g, (_, n) => v[n]) : k), lang: 'en' }),
}));
jest.mock('../components/ui/Toast', () => ({ useToast: () => ({ success: jest.fn(), error: jest.fn() }) }));
jest.mock('../hooks/usePermissions', () => ({ usePermissions: () => ({ can: () => true }) }));
// The two panels around the ledger fetch on their own and are tested separately.
jest.mock('../components/parts/InvoiceMatchDesk', () => () => null);
jest.mock('../components/parts/GarageBilledParts', () => () => null);

// The action lists below are exactly what the backend returns for each state — see
// FinancialDocumentStatus::ACTIONS. Copied rather than derived, so a change on either side shows up here.
const A = {
  submit: { key: 'submit', label: 'Submit for review', to: 'pending', primary: true, permission: 'maintenance.manage', danger: false, needs_reason: false },
  edit: { key: 'edit', label: 'Edit', to: null, primary: false, permission: 'parts.purchase', danger: false, needs_reason: false },
  del: { key: 'delete', label: 'Delete', to: null, primary: false, permission: 'parts.purchase', danger: true, needs_reason: false },
  cancel: { key: 'cancel', label: 'Cancel invoice', to: 'cancelled', primary: false, permission: 'maintenance.manage', danger: true, needs_reason: true },
  approve: { key: 'approve', label: 'Approve', to: 'approved', primary: true, permission: 'maintenance.manage', danger: false, needs_reason: false },
  ret: { key: 'return', label: 'Return for correction', to: 'draft', primary: false, permission: 'maintenance.manage', danger: false, needs_reason: true },
  pay: { key: 'pay', label: 'Record payment', to: 'paid', primary: true, permission: 'maintenance.manage', danger: false, needs_reason: false },
  unapprove: { key: 'unapprove', label: 'Withdraw approval', to: 'pending', primary: false, permission: 'maintenance.manage', danger: false, needs_reason: false },
};

// Invoice 33333 — the row the change was reported against: a draft, AED 2,223, not checked yet.
const invoice = (over = {}) => ({
  id: 9,
  invoice_no: '33333',
  invoice_date: '2026-08-20',
  supplier: 'Unnamed supplier',
  status: 'draft',
  status_label: 'Draft',
  stored_status: 'draft',
  editable: true,
  total_amount: 2223,
  outstanding: 0,
  variance: null,
  recorded_by: 'Test Admin',
  recorded_at: '2026-08-20T10:00:00+00:00',
  matched_at: null,
  photo_url: null,
  items: [{ purchase_id: 1, part_name: 'Brake Pad Set', quantity: 1, unit_price: 2223, line_total: 2223 }],
  actions: [A.submit, A.edit, A.del, A.cancel],
  ...over,
});

const renderLedger = (rows) => {
  api.get.mockImplementation((url) =>
    url === '/part-invoices'
      ? Promise.resolve({ data: { data: { invoices: rows } } })
      : Promise.resolve({ data: { data: [] } }),
  );
  return render(<MemoryRouter><PartInvoices /></MemoryRouter>);
};

/**
 * The action buttons ON the row — excluding the ⋮ trigger itself and the page's own "Record invoice".
 * This is the list a reader actually has to weigh up at a glance, which is the thing that was wrong.
 */
const inlineActions = () =>
  screen.queryAllByRole('button')
    .filter((b) => !(b.getAttribute('aria-label') || '').startsWith('More actions'))
    .map((b) => b.textContent.trim())
    .filter((label) => label !== '' && label !== 'Record invoice');

beforeEach(() => jest.clearAllMocks());

// ── The reported row ───────────────────────────────────────────────────────────────────────────────

test('a draft row leads with Submit and keeps Approve, Cancel and Delete off the row', async () => {
  renderLedger([invoice()]);
  expect(await screen.findByText('33333')).toBeInTheDocument();

  // BEFORE: Submit / Approve / Cancel / Edit / Delete, all at once.
  // AFTER: the two actions a draft is actually for, and a menu for the rest.
  expect(inlineActions()).toEqual(['Edit', 'Submit']);

  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel invoice' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
});

test('the secondary actions are one click away, not gone', async () => {
  renderLedger([invoice()]);
  await screen.findByText('33333');

  await userEvent.click(screen.getByRole('button', { name: /More actions for invoice 33333/ }));
  const menu = screen.getByRole('menu');

  expect(within(menu).getByRole('menuitem', { name: 'Delete' })).toBeInTheDocument();
  expect(within(menu).getByRole('menuitem', { name: 'Cancel invoice' })).toBeInTheDocument();
  // Edit is already on the row and must not be offered twice.
  expect(within(menu).queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument();
});

test('submitting posts the move the server declared, and nothing else', async () => {
  api.post.mockResolvedValue({ data: { data: {} } });
  renderLedger([invoice()]);
  await screen.findByText('33333');

  await userEvent.click(screen.getByRole('button', { name: 'Submit' }));

  await waitFor(() =>
    expect(api.post).toHaveBeenCalledWith('/financial-documents/supplier-invoice/9/submit', {}));
});

// ── The other states ───────────────────────────────────────────────────────────────────────────────

test('a pending row leads with Approve and offers Return for correction', async () => {
  renderLedger([invoice({
    status: 'pending', status_label: 'Pending approval', stored_status: 'pending',
    actions: [A.approve, A.ret, A.edit, A.del, A.cancel],
  })]);
  await screen.findByText('33333');

  expect(inlineActions()).toEqual(['Edit', 'Approve']);

  await userEvent.click(screen.getByRole('button', { name: /More actions/ }));
  expect(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'Return for correction' })).toBeInTheDocument();
});

test('an approved row cannot be edited or deleted from the ledger', async () => {
  renderLedger([invoice({
    status: 'approved', status_label: 'Approved', stored_status: 'approved', editable: false,
    outstanding: 2223, actions: [A.pay, A.unapprove, A.cancel],
  })]);
  await screen.findByText('33333');

  // Editing an accepted obligation is an adjustment, not a rewrite — so it is not on the row at all.
  expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: /More actions/ }));
  const menu = screen.getByRole('menu');
  expect(within(menu).queryByRole('menuitem', { name: 'Delete' })).not.toBeInTheDocument();
  expect(within(menu).getByRole('menuitem', { name: 'Withdraw approval' })).toBeInTheDocument();
});

test('a settled row leads with nothing — cancelling is never a headline', async () => {
  renderLedger([invoice({
    status: 'paid', status_label: 'Paid', stored_status: 'paid', editable: false,
    actions: [{ ...A.cancel, primary: false }],
  })]);
  await screen.findByText('33333');

  expect(inlineActions()).toEqual([]);
  await userEvent.click(screen.getByRole('button', { name: /More actions/ }));
  expect(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'Cancel invoice' })).toBeInTheDocument();
});

test('a cancelled row offers no actions and no empty menu', async () => {
  renderLedger([invoice({
    status: 'cancelled', status_label: 'Cancelled', stored_status: 'cancelled', editable: false, actions: [],
  })]);
  await screen.findByText('33333');

  expect(inlineActions()).toEqual([]);
  expect(screen.queryByRole('button', { name: /More actions/ })).not.toBeInTheDocument();
});

// ── Permission ─────────────────────────────────────────────────────────────────────────────────────

test('a user offered only the paper actions sees only those', async () => {
  // What the server returns for someone holding parts.purchase but not maintenance.manage. The page
  // used to gate every button on parts.purchase and show this user Approve and Cancel — which the API
  // then refused with a 403.
  renderLedger([invoice({ actions: [A.edit, A.del] })]);
  await screen.findByText('33333');

  expect(inlineActions()).toEqual(['Edit']);
  await userEvent.click(screen.getByRole('button', { name: /More actions/ }));
  const menu = screen.getByRole('menu');
  expect(within(menu).getByRole('menuitem', { name: 'Delete' })).toBeInTheDocument();
  expect(within(menu).queryByRole('menuitem', { name: 'Submit' })).not.toBeInTheDocument();
});
