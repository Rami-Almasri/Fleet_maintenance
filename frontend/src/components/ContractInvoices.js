import { useMemo, useState } from 'react';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from './ui/Toast';
import Button from './ui/Button';
import Badge from './ui/Badge';
import Modal from './ui/Modal';
import DataTable, { SectionCard } from './ui/Table';
import MetricCard, { MetricGrid } from './ui/MetricCard';
import Icon from './ui/Icon';
import { Input, Textarea } from './ui/Field';
import { aed2, fmtDate } from '../lib/format';

const VAT_DEFAULT = 5;
const EMPTY = { invoice_date: '', total_value: '', vat_percentage: '5', discount: '', period_from: '', period_to: '', notes: '' };

// Derive the VAT % a stored invoice was built with, so editing it doesn't silently
// re-rate the figures. after-discount base = value − discount.
function pctOf(inv) {
  const base = Number(inv.total_value || 0) - Number(inv.discount || 0);
  if (!base) return String(VAT_DEFAULT);
  return String(Math.round((Number(inv.vat_value || 0) / base) * 100));
}

/**
 * The invoices (charges) on a contract — both the legacy OfficeManager ones (read-only)
 * and the website's own manual ones (origin 'manual', editable). Users with billing.manage
 * can add a manual invoice to ANY contract (rental, maintenance, booking) and edit/delete
 * the manual ones. VAT and the total are computed live from value − discount.
 */
export default function ContractInvoices({ contract, onChanged }) {
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = can('billing.manage');
  const invoices = useMemo(() => contract.invoices || [], [contract.invoices]);

  const today = new Date().toISOString().slice(0, 10);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [showMath, setShowMath] = useState(false);

  const set = (f) => (e) => setForm((s) => ({ ...s, [f]: e.target.value }));
  const err = (f) => errors[f]?.[0] || '';

  // Live VAT/total preview — mirrors the backend math exactly.
  const preview = useMemo(() => {
    const value = Number(form.total_value) || 0;
    const disc = Number(form.discount) || 0;
    const pct = form.vat_percentage === '' ? VAT_DEFAULT : Number(form.vat_percentage) || 0;
    const afterDiscount = +(value - disc).toFixed(2);
    const vat = +((afterDiscount * pct) / 100).toFixed(2);
    return { afterDiscount, vat, total: +(afterDiscount + vat).toFixed(2) };
  }, [form]);

  const totals = useMemo(() => ({
    billed: contract.invoices_total ?? invoices.reduce((s, i) => s + Number(i.total_after_vat || 0), 0),
    discount: contract.invoices_discount ?? invoices.reduce((s, i) => s + Number(i.discount || 0), 0),
    value: invoices.reduce((s, i) => s + Number(i.total_value || 0), 0),
    vat: invoices.reduce((s, i) => s + Number(i.vat_value || 0), 0),
  }), [contract, invoices]);

  const openNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, invoice_date: today });
    setErrors({});
    setOpen(true);
  };

  const openEdit = (inv) => {
    setEditing(inv);
    setForm({
      invoice_date: inv.date || '',
      total_value: inv.total_value ?? '',
      vat_percentage: pctOf(inv),
      discount: inv.discount ?? '',
      period_from: inv.period_from || '',
      period_to: inv.period_to || '',
      notes: inv.notes || '',
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
        invoice_date: form.invoice_date || null,
        total_value: form.total_value === '' ? 0 : Number(form.total_value),
        vat_percentage: form.vat_percentage === '' ? null : Number(form.vat_percentage),
        discount: form.discount === '' ? 0 : Number(form.discount),
        period_from: form.period_from || null,
        period_to: form.period_to || null,
        notes: form.notes || null,
      };
      if (editing) {
        await api.post(`/Invoice/${editing.id}`, payload);
        toast.success('Invoice updated');
      } else {
        await api.post('/Invoice', payload);
        toast.success('Invoice added');
      }
      setOpen(false);
      onChanged?.();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not save the invoice');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (inv) => {
    if (!window.confirm(`Delete invoice ${inv.number}? This cannot be undone.`)) return;
    try {
      await api.delete(`/Invoice/${inv.id}`);
      toast.success('Invoice deleted');
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the invoice');
    }
  };

  // Columns for the invoice list — numbers right-aligned with tabular figures so they
  // line up; the actions column is only rendered for managers (preserves permissions).
  const columns = [
    {
      key: 'number', header: 'Invoice', cellClass: 'font-medium text-slate-900',
      render: (inv) => (
        <>
          <span className="inline-flex items-center gap-1.5">
            {inv.number}
            <Badge tone={inv.origin === 'manual' ? 'indigo' : 'gray'}>{inv.origin === 'manual' ? 'Web' : 'OM'}</Badge>
          </span>
          {inv.notes && <p className="mt-0.5 text-xs font-normal text-slate-400">{inv.notes}</p>}
        </>
      ),
    },
    { key: 'date', header: 'Date', cellClass: 'text-slate-500', render: (inv) => fmtDate(inv.date) },
    { key: 'value', header: 'Value', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (inv) => aed2(inv.total_value) },
    {
      key: 'vat', header: 'VAT', align: 'right', tooltip: 'VAT charged on the post-discount base.',
      cellClass: 'tabular-nums text-slate-600', render: (inv) => aed2(inv.vat_value),
    },
    {
      key: 'discount', header: 'Discount', align: 'right', cellClass: 'tabular-nums text-amber-700',
      render: (inv) => (Number(inv.discount) > 0 ? `− ${aed2(inv.discount)}` : <span className="text-slate-300">—</span>),
    },
    {
      key: 'total', header: 'Total', align: 'right', tooltip: 'Total after VAT for this invoice.',
      cellClass: 'tabular-nums font-medium text-slate-900', render: (inv) => aed2(inv.total_after_vat),
    },
    ...(canManage ? [{
      key: 'actions', header: 'Actions', align: 'right',
      render: (inv) => (
        inv.editable ? (
          <span className="inline-flex gap-2">
            <button onClick={() => openEdit(inv)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Edit</button>
            <span className="text-slate-200">·</span>
            <button onClick={() => remove(inv)} className="text-xs font-medium text-red-500 hover:text-red-600">Delete</button>
          </span>
        ) : (
          <span className="text-xs text-slate-300">Synced</span>
        )
      ),
    }] : []),
  ];

  return (
    <div className="space-y-5">
      {/* Summary stats — ex-VAT charges, discount applied, and the total billed (with VAT). */}
      {invoices.length > 0 && (
        <MetricGrid cols={3}>
          <MetricCard
            label="Charges (ex-VAT)"
            value={aed2(totals.value)}
            tone="slate"
            icon={<Icon.Invoice className="h-5 w-5" />}
            tooltip="Sum of every ex-VAT charge (rent, Salik, damages, fuel…) on this contract."
          />
          <MetricCard
            label="Discount"
            value={totals.discount > 0 ? `− ${aed2(totals.discount)}` : aed2(0)}
            tone="amber"
            icon={<Icon.Percent className="h-5 w-5" />}
            tooltip="Discounts / credit notes applied before VAT."
          />
          <MetricCard
            label="Total billed"
            value={aed2(totals.billed)}
            tone="emerald"
            icon={<Icon.Coins className="h-5 w-5" />}
            tooltip="Net (ex-VAT) after discount, plus VAT on the discounted amount."
          />
        </MetricGrid>
      )}

      <SectionCard
        title="Invoices"
        subtitle="Charges billed on this contract. Website invoices (M-…) are editable; OfficeManager ones are read-only."
        actions={canManage ? <Button variant="secondary" size="sm" onClick={openNew}><Icon.Plus className="h-4 w-4" /> Add invoice</Button> : null}
      >
        {invoices.length === 0 ? (
          <div className="px-5 py-10 text-center text-sm text-slate-400">
            No invoices yet{canManage ? ' — use “Add invoice” above.' : '.'}
          </div>
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={invoices}
              rowKey={(inv) => inv.id ?? inv.number}
              empty="No invoices yet."
            />

            {/* How the Total billed is reached — the ex-VAT charges, the discount, then VAT on the
                post-discount base. Explains why it's NOT simply rent − discount. */}
            <div className="px-5 py-4">
              <button type="button" onClick={() => setShowMath((v) => !v)} className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700">
                <svg className={`h-3.5 w-3.5 transition-transform ${showMath ? 'rotate-90' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                How is the Total billed calculated?
              </button>
              {showMath && (
                <div className="mt-2 max-w-md rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                  <dl className="space-y-1 text-sm">
                    <div className="flex justify-between"><dt className="text-slate-500">Charges (ex-VAT)</dt><dd className="font-medium text-slate-800 tabular-nums">{aed2(totals.value)}</dd></div>
                    <div className="flex justify-between text-amber-700"><dt>Less: discount / credit notes</dt><dd className="font-medium tabular-nums">− {aed2(totals.discount)}</dd></div>
                    <div className="flex justify-between border-t border-slate-200 pt-1 text-slate-600"><dt>Net (ex-VAT)</dt><dd className="font-medium tabular-nums">{aed2(totals.value - totals.discount)}</dd></div>
                    <div className="flex justify-between text-slate-600"><dt>Plus: VAT <span className="text-slate-400">(on the post-discount amount)</span></dt><dd className="font-medium tabular-nums">+ {aed2(totals.vat)}</dd></div>
                    <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold text-slate-900"><dt>Total billed</dt><dd className="tabular-nums">{aed2(totals.billed)}</dd></div>
                  </dl>
                  <p className="mt-2 text-xs text-slate-500">
                    It isn't rent − discount: the “Charges (ex-VAT)” line bundles every ex-VAT charge (rent, Salik, damages, fuel…), and VAT is then added on the discounted amount.
                  </p>
                </div>
              )}
            </div>
          </>
        )}
      </SectionCard>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? `Edit invoice ${editing.number}` : 'Add invoice'}
        subtitle="VAT and the total are computed from value − discount"
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={submit} loading={saving}>{editing ? 'Save changes' : 'Add invoice'}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label="Invoice date" type="date" value={form.invoice_date} onChange={set('invoice_date')} error={err('invoice_date')} />
            <Input label="Value (before VAT)" type="number" step="0.01" min="0" value={form.total_value} onChange={set('total_value')} error={err('total_value')} required />
            <Input label="VAT %" type="number" step="0.01" min="0" max="100" value={form.vat_percentage} onChange={set('vat_percentage')} error={err('vat_percentage')} />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label="Discount" type="number" step="0.01" min="0" value={form.discount} onChange={set('discount')} error={err('discount')} />
            <Input label="Period from" type="date" value={form.period_from} onChange={set('period_from')} error={err('period_from')} />
            <Input label="Period to" type="date" value={form.period_to} onChange={set('period_to')} error={err('period_to')} />
          </div>
          <Textarea label="Notes" rows={2} value={form.notes} onChange={set('notes')} error={err('notes')} placeholder="What is this invoice for?" />

          {/* Live computed summary */}
          <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm ring-1 ring-inset ring-slate-100">
            <div className="flex justify-between py-0.5"><span className="text-slate-500">After discount</span><span className="font-medium text-slate-800 tabular-nums">{aed2(preview.afterDiscount)}</span></div>
            <div className="flex justify-between py-0.5"><span className="text-slate-500">VAT</span><span className="font-medium text-slate-800 tabular-nums">{aed2(preview.vat)}</span></div>
            <div className="mt-1 flex justify-between border-t border-slate-200 pt-1.5"><span className="font-semibold text-slate-700">Total (after VAT)</span><span className="font-bold text-slate-900 tabular-nums">{aed2(preview.total)}</span></div>
          </div>
        </div>
      </Modal>
    </div>
  );
}
