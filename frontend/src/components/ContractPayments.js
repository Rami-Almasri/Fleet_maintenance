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
import { useI18n } from '../i18n/I18nContext';
import { aed2, fmtDate } from '../lib/format';

// `value` is the API enum; `label` is an English phrase key resolved through t() at render time.
export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'online', label: 'Online' },
  { value: 'other', label: 'Other' },
];
const methodLabel = (v, t) => {
  const found = PAYMENT_METHODS.find((m) => m.value === v);
  return found ? t(found.label) : (v || '—');
};

const EMPTY = { amount: '', paid_on: '', method: 'cash', invoice_id: '', reference: '', notes: '' };

/**
 * Payments / receipts recorded against a contract — the collection side. Users with
 * billing.manage can record a payment (optionally tied to a specific invoice on the
 * contract) and edit/delete it. Receipts are numbered P-… on the server.
 */
export default function ContractPayments({ contract, onChanged }) {
  const { can } = usePermissions();
  const { t } = useI18n();
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
        toast.success(t('Payment updated'));
      } else {
        await api.post('/Payment', payload);
        toast.success(t('Payment recorded'));
      }
      setOpen(false);
      onChanged?.();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(r?.message || r?.msg || t('Could not save the payment'));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (p) => {
    if (!window.confirm(t('Delete payment {ref} ({amount})? This cannot be undone.', { ref: p.payment_ref, amount: aed2(p.amount) }))) return;
    try {
      await api.delete(`/Payment/${p.id}`);
      toast.success(t('Payment deleted'));
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not delete the payment'));
    }
  };

  // Columns for the receipts list — amount right-aligned with tabular figures; the
  // actions column is only rendered for managers (preserves the permission gate).
  const columns = [
    {
      key: 'receipt', header: t('Receipt'), cellClass: 'font-medium text-slate-900',
      render: (p) => (
        <>
          {p.payment_ref || '—'}
          {p.reference && <p className="mt-0.5 text-xs font-normal text-slate-400">{t('Ref: {reference}', { reference: p.reference })}</p>}
          {p.notes && <p className="mt-0.5 text-xs font-normal text-slate-400">{p.notes}</p>}
        </>
      ),
    },
    { key: 'date', header: t('Date'), cellClass: 'text-slate-500', render: (p) => fmtDate(p.paid_on) },
    { key: 'method', header: t('Method'), cellClass: 'capitalize text-slate-600', render: (p) => methodLabel(p.method, t) },
    {
      key: 'invoice', header: t('For invoice'), cellClass: 'text-slate-500', tooltip: t('Invoice this receipt is tied to, or contract-level if none.'),
      render: (p) => p.invoice_ref || <span className="text-slate-300">—</span>,
    },
    {
      key: 'amount', header: t('Amount'), align: 'right', cellClass: 'tabular-nums font-medium text-emerald-600',
      render: (p) => aed2(p.amount),
    },
    ...(canManage ? [{
      key: 'actions', header: t('Actions'), align: 'right',
      render: (p) => (
        <span className="inline-flex gap-2">
          <button onClick={() => openEdit(p)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Edit')}</button>
          <span className="text-slate-200">·</span>
          <button onClick={() => remove(p)} className="text-xs font-medium text-red-500 hover:text-red-600">{t('Delete')}</button>
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
            label={t('Total paid')}
            value={aed2(total)}
            tone="emerald"
            icon={<Icon.Cash className="h-5 w-5" />}
            hint={payments.length === 1 ? t('1 receipt') : t('{n} receipts', { n: payments.length })}
            tooltip={t('Sum of all payments / receipts recorded against this contract.')}
          />
        </MetricGrid>
      )}

      <SectionCard
        title={t('Payments / Receipts')}
        subtitle={t('What has been collected against this contract — newest first.')}
        actions={canManage ? <Button variant="secondary" size="sm" onClick={openNew}><Icon.Plus className="h-4 w-4" /> {t('Add payment')}</Button> : null}
      >
        {payments.length === 0 ? (
          <div className="px-5 py-10 text-center text-sm text-slate-400">
            {canManage
              ? t('No payments recorded yet — use “Add payment” above.')
              : t('No payments recorded yet.')}
          </div>
        ) : (
          <DataTable
            columns={columns}
            rows={payments}
            rowKey={(p) => p.id}
            empty={t('No payments recorded yet.')}
          />
        )}
      </SectionCard>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? t('Edit payment {ref}', { ref: editing.payment_ref || '' }) : t('Record payment')}
        subtitle={t('Anchored to this contract — optionally tied to one of its invoices')}
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={submit} loading={saving}>{editing ? t('Save changes') : t('Record payment')}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label={t('Amount (AED)')} type="number" step="0.01" min="0.01" value={form.amount} onChange={set('amount')} error={err('amount')} required />
            <Input label={t('Paid on')} type="date" value={form.paid_on} onChange={set('paid_on')} error={err('paid_on')} />
            <Select label={t('Method')} value={form.method} onChange={set('method')} error={err('method')}>
              {PAYMENT_METHODS.map((m) => <option key={m.value} value={m.value}>{t(m.label)}</option>)}
            </Select>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label={t('Against invoice (optional)')} value={form.invoice_id} onChange={set('invoice_id')} error={err('invoice_id')}>
              <option value="">{t('— None (contract-level) —')}</option>
              {invoices.map((inv) => (
                <option key={inv.id} value={inv.id}>{inv.number} · {aed2(inv.total_after_vat)}</option>
              ))}
            </Select>
            <Input label={t('Reference (txn / cheque no)')} value={form.reference} onChange={set('reference')} error={err('reference')} />
          </div>
          <Textarea label={t('Notes')} rows={2} value={form.notes} onChange={set('notes')} error={err('notes')} />
        </div>
      </Modal>
    </div>
  );
}
