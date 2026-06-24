import { useState } from 'react';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from './ui/Toast';
import Button from './ui/Button';
import Modal from './ui/Modal';
import DataTable, { SectionCard } from './ui/Table';
import MetricCard, { MetricGrid } from './ui/MetricCard';
import Icon from './ui/Icon';
import { Input, Select, Textarea } from './ui/Field';
import { aed2, fmtDate } from '../lib/format';

export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'online', label: 'Online' },
  { value: 'other', label: 'Other' },
];
const methodLabel = (v) => PAYMENT_METHODS.find((m) => m.value === v)?.label || v || '—';

const EMPTY = { amount: '', paid_on: '', method: 'cash', invoice_id: '', reference: '', notes: '' };

/**
 * Payments / receipts recorded against a contract — the collection side. Users with
 * billing.manage can record a payment (optionally tied to a specific invoice on the
 * contract) and edit/delete it. Receipts are numbered P-… on the server.
 */
export default function ContractPayments({ contract, onChanged }) {
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = can('billing.manage');
  const payments = contract.payments || [];
  const invoices = contract.invoices || [];

  const today = new Date().toISOString().slice(0, 10);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const set = (f) => (e) => setForm((s) => ({ ...s, [f]: e.target.value }));
  const err = (f) => errors[f]?.[0] || '';

  const total = contract.payments_total ?? payments.reduce((s, p) => s + Number(p.amount || 0), 0);

  const openNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, paid_on: today });
    setErrors({});
    setOpen(true);
  };

  const openEdit = (p) => {
    setEditing(p);
    setForm({
      amount: p.amount ?? '',
      paid_on: p.paid_on || '',
      method: p.method || 'cash',
      invoice_id: p.invoice_id || '',
      reference: p.reference || '',
      notes: p.notes || '',
    });
    setErrors({});
    setOpen(true);
  };

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        contract_id: contract.id,
        invoice_id: form.invoice_id || null,
        amount: form.amount === '' ? 0 : Number(form.amount),
        paid_on: form.paid_on || null,
        method: form.method || null,
        reference: form.reference || null,
        notes: form.notes || null,
      };
      if (editing) {
        await api.post(`/Payment/${editing.id}`, payload);
        toast.success('Payment updated');
      } else {
        await api.post('/Payment', payload);
        toast.success('Payment recorded');
      }
      setOpen(false);
      onChanged?.();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not save the payment');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (p) => {
    if (!window.confirm(`Delete payment ${p.payment_ref} (${aed2(p.amount)})? This cannot be undone.`)) return;
    try {
      await api.delete(`/Payment/${p.id}`);
      toast.success('Payment deleted');
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the payment');
    }
  };

  // Columns for the receipts list — amount right-aligned with tabular figures; the
  // actions column is only rendered for managers (preserves the permission gate).
  const columns = [
    {
      key: 'receipt', header: 'Receipt', cellClass: 'font-medium text-slate-900',
      render: (p) => (
        <>
          {p.payment_ref || '—'}
          {p.reference && <p className="mt-0.5 text-xs font-normal text-slate-400">Ref: {p.reference}</p>}
          {p.notes && <p className="mt-0.5 text-xs font-normal text-slate-400">{p.notes}</p>}
        </>
      ),
    },
    { key: 'date', header: 'Date', cellClass: 'text-slate-500', render: (p) => fmtDate(p.paid_on) },
    { key: 'method', header: 'Method', cellClass: 'capitalize text-slate-600', render: (p) => methodLabel(p.method) },
    {
      key: 'invoice', header: 'For invoice', cellClass: 'text-slate-500', tooltip: 'Invoice this receipt is tied to, or contract-level if none.',
      render: (p) => p.invoice_ref || <span className="text-slate-300">—</span>,
    },
    {
      key: 'amount', header: 'Amount', align: 'right', cellClass: 'tabular-nums font-medium text-emerald-600',
      render: (p) => aed2(p.amount),
    },
    ...(canManage ? [{
      key: 'actions', header: 'Actions', align: 'right',
      render: (p) => (
        <span className="inline-flex gap-2">
          <button onClick={() => openEdit(p)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Edit</button>
          <span className="text-slate-200">·</span>
          <button onClick={() => remove(p)} className="text-xs font-medium text-red-500 hover:text-red-600">Delete</button>
        </span>
      ),
    }] : []),
  ];

  return (
    <div className="space-y-5">
      {/* Summary — total collected against this contract. */}
      {payments.length > 0 && (
        <MetricGrid cols={3}>
          <MetricCard
            label="Total paid"
            value={aed2(total)}
            tone="emerald"
            icon={<Icon.Cash className="h-5 w-5" />}
            hint={`${payments.length} receipt${payments.length === 1 ? '' : 's'}`}
            tooltip="Sum of all payments / receipts recorded against this contract."
          />
        </MetricGrid>
      )}

      <SectionCard
        title="Payments / Receipts"
        subtitle="What has been collected against this contract — newest first."
        actions={canManage ? <Button variant="secondary" size="sm" onClick={openNew}><Icon.Plus className="h-4 w-4" /> Add payment</Button> : null}
      >
        {payments.length === 0 ? (
          <div className="px-5 py-10 text-center text-sm text-slate-400">
            No payments recorded yet{canManage ? ' — use “Add payment” above.' : '.'}
          </div>
        ) : (
          <DataTable
            columns={columns}
            rows={payments}
            rowKey={(p) => p.id}
            empty="No payments recorded yet."
          />
        )}
      </SectionCard>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? `Edit payment ${editing.payment_ref || ''}` : 'Record payment'}
        subtitle="Anchored to this contract — optionally tied to one of its invoices"
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={submit} loading={saving}>{editing ? 'Save changes' : 'Record payment'}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label="Amount (AED)" type="number" step="0.01" min="0.01" value={form.amount} onChange={set('amount')} error={err('amount')} required />
            <Input label="Paid on" type="date" value={form.paid_on} onChange={set('paid_on')} error={err('paid_on')} />
            <Select label="Method" value={form.method} onChange={set('method')} error={err('method')}>
              {PAYMENT_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label="Against invoice (optional)" value={form.invoice_id} onChange={set('invoice_id')} error={err('invoice_id')}>
              <option value="">— None (contract-level) —</option>
              {invoices.map((inv) => (
                <option key={inv.id} value={inv.id}>{inv.number} · {aed2(inv.total_after_vat)}</option>
              ))}
            </Select>
            <Input label="Reference (txn / cheque no)" value={form.reference} onChange={set('reference')} error={err('reference')} />
          </div>
          <Textarea label="Notes" rows={2} value={form.notes} onChange={set('notes')} error={err('notes')} />
        </div>
      </Modal>
    </div>
  );
}
