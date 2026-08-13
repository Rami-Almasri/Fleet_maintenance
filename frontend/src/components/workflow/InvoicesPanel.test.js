// One Ticket → Many Invoices — the rules that keep each garage's bill honest.
//
// Two invariants are asserted here because both were real defects the desk exposed:
//   1. A BILL LISTS ONLY ITS OWN GARAGE'S WORK. A car worked in two garages owes two bills; showing the
//      other garage's faults in this form is how work ends up billed to whoever did not do it.
//   2. WORK IS NOT ALL "FAULTS". A ticket carries faults, planned services, damage and checks — an oil
//      change is a service, and listing it under a "Faults" heading tells the reader something false.

import { render, screen, fireEvent } from '@testing-library/react';
import InvoicesPanel from './InvoicesPanel';

jest.mock('../../api/client', () => ({ get: jest.fn(), post: jest.fn(), delete: jest.fn() }));

// t echoes the key (with values appended), so an assertion names the label key rather than its English.
jest.mock('../../i18n/I18nContext', () => ({
  useI18n: () => ({
    lang: 'en',
    t: (k, v) => (v ? `${k}:${Object.values(v).join(',')}` : k),
  }),
}));

const AJMAN = { id: 7, name: 'AJMAN STICAR SHOP', type: 'garage' };
const CYL = { id: 9, name: '7 CYLINDER', type: 'garage' };

// A real two-garage ticket: a fault fixed at one shop, a routine service done at the other.
const TICKET = {
  id: 38637,
  plate: '38637',
  vendor_id: AJMAN.id,
  garage: AJMAN.name,
  invoices: [],
  findings: [],
  tasks: [
    {
      id: 101,
      symptom: 'Knocking over bumps',
      status: 'completed',
      kind: 'fault',
      kind_meta: { emoji: '🔴', label: 'Fault', tone: 'red' },
      current_vendor_id: AJMAN.id,
      current_garage: AJMAN.name,
    },
    {
      id: 202,
      symptom: 'Tire Change',
      status: 'completed',
      kind: 'service',
      kind_meta: { emoji: '🔵', label: 'Service', tone: 'blue' },
      current_vendor_id: CYL.id,
      current_garage: CYL.name,
    },
  ],
};

const openEditor = (addRequest) => render(
  <InvoicesPanel
    ticket={TICKET}
    garages={[AJMAN, CYL]}
    findingsCatalog={[]}
    canManage
    onChanged={jest.fn()}
    addRequest={addRequest}
  />,
);

describe('invoice editor — a bill only ever lists its own garage’s work', () => {
  it('opens on the garage that did the pre-ticked work, and hides the other garage’s work', () => {
    // The matching desk opens the form for 7 CYLINDER with only its own item pre-ticked.
    openEditor({ nonce: 1, taskIds: [202] });

    expect(screen.getByText('Tire Change')).toBeInTheDocument();
    // The other garage's fault is not on this bill's list at all — not merely unticked.
    expect(screen.queryByText('Knocking over bumps')).not.toBeInTheDocument();
    // …and what is missing is stated rather than silently dropped.
    expect(screen.getByText('workflow.invoices.workElsewhere:1')).toBeInTheDocument();
  });

  it('keeps the pre-ticked work ticked (the desk’s selection survives the garage scoping)', () => {
    openEditor({ nonce: 2, taskIds: [202] });

    expect(screen.getByRole('checkbox', { name: /Tire Change/ })).toBeChecked();
  });

  it('re-scopes the list when the bill is switched to in-house (no garage to scope by)', () => {
    openEditor({ nonce: 3, taskIds: [202] });

    fireEvent.click(screen.getByRole('checkbox', { name: /internalToggle/ }));

    // With no garage on the bill there is nothing to scope by, so the whole ticket is offered again.
    expect(screen.getByText('Knocking over bumps')).toBeInTheDocument();
    expect(screen.getByText('Tire Change')).toBeInTheDocument();
  });
});

describe('invoice editor — work is grouped by its own kind', () => {
  it('lists a routine service under Services, never under Faults', () => {
    // No garage scoping (in-house) so both items are on screen and both headings must appear.
    openEditor({ nonce: 4, taskIds: [] });
    fireEvent.click(screen.getByRole('checkbox', { name: /internalToggle/ }));

    expect(screen.getByText('workflow.invoices.kindFault')).toBeInTheDocument();
    expect(screen.getByText('workflow.invoices.kindService')).toBeInTheDocument();
    // The heading over the whole list is WORK, not faults.
    expect(screen.getByText('workflow.invoices.workCovered')).toBeInTheDocument();
  });
});
