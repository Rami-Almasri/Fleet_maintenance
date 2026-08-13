// Supplier Parts Invoices — the paper behind what a part cost.
//
// A ticket's repair cost comes from two different places, and this page owns one of them:
//
//     the SUPPLIER sold us the part   → the invoice keyed here
//     the GARAGE fitted it            → their maintenance invoice, on the ticket
//
// One supplier trip usually buys several parts on one document, so an invoice is a HEADER that several
// purchases attach to; its subtotal is derived from them and never typed. What is typed is what the paper
// says — and if the two disagree by more than a cent, the difference must be explained before it saves.
//
// Garage-supplied parts are deliberately absent from the attach list. They are already a line on that
// garage's own invoice, and keying them here too would charge the ticket twice; the backend refuses it.

import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import Badge from '../components/ui/Badge';
import SearchSelect from '../components/ui/SearchSelect';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Input, Textarea } from '../components/ui/Field';
import { aed, fmtAgo } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';
import { useI18n } from '../i18n/I18nContext';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

// Status tones, written out in full so Tailwind ships them (an interpolated class is purged from build).
const STATUS_TONE = {
  draft: 'bg-slate-100 text-slate-600 ring-slate-200',
  pending: 'bg-amber-50 text-amber-700 ring-amber-200',
  approved: 'bg-sky-50 text-sky-700 ring-sky-200',
  partially_paid: 'bg-cyan-50 text-cyan-700 ring-cyan-200',
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  partially_refunded: 'bg-violet-50 text-violet-700 ring-violet-200',
  refunded: 'bg-violet-50 text-violet-700 ring-violet-200',
  cancelled: 'bg-slate-100 text-slate-400 ring-slate-200 line-through',
};

function StatusChip({ status, label }) {
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${STATUS_TONE[status] || STATUS_TONE.draft}`}>
      {label || status}
    </span>
  );
}

/**
 * The lifecycle buttons for one invoice. Which moves are legal comes from the SERVER
 * (`allowed_transitions`) rather than being re-derived here — one status machine, defined once, so the
 * UI can never offer a move the backend will refuse.
 */
function LifecycleActions({ invoice, onDone }) {
  const toast = useToast();
  const { t } = useI18n();
  const [busy, setBusy] = useState(null);
  const allowed = invoice.allowed_transitions || [];

  // One sentence per move, so both languages read naturally instead of being stitched from a verb.
  const DONE = {
    submit: t('Invoice submitted'),
    approve: t('Invoice approved'),
    pay: t('Invoice payment recorded'),
    cancel: t('Invoice cancelled'),
  };
  const FAILED = {
    submit: t('Could not submit the invoice.'),
    approve: t('Could not approve the invoice.'),
    pay: t('Could not record the payment.'),
    cancel: t('Could not cancel the invoice.'),
  };

  const act = async (verb, body) => {
    setBusy(verb);
    try {
      await api.post(`/financial-documents/supplier-invoice/${invoice.id}/${verb}`, body || {});
      toast.success(DONE[verb]);
      onDone?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || FAILED[verb]);
    } finally {
      setBusy(null);
    }
  };

  const cancel = () => {
    const reason = window.prompt(t('Why is this invoice being cancelled?'));
    if (reason && reason.trim()) act('cancel', { reason: reason.trim() });
  };

  return (
    <>
      {allowed.includes('pending') && invoice.stored_status === 'draft' && (
        <Button variant="ghost" size="sm" loading={busy === 'submit'} onClick={() => act('submit')}>{t('Submit')}</Button>
      )}
      {allowed.includes('approved') && (
        <Button variant="success" size="sm" loading={busy === 'approve'} onClick={() => act('approve')}>{t('Approve')}</Button>
      )}
      {allowed.includes('paid') && (
        <Button size="sm" loading={busy === 'pay'} onClick={() => act('pay')}>
          {invoice.outstanding > 0 ? t('Pay {amount}', { amount: aed(invoice.outstanding) }) : t('Pay')}
        </Button>
      )}
      {allowed.includes('cancelled') && (
        <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={cancel}>{t('Cancel')}</Button>
      )}
    </>
  );
}

// ── The invoice editor ────────────────────────────────────────────────────────────────────────────
function InvoiceModal({ open, invoice, onClose, onDone, suppliers }) {
  const toast = useToast();
  const { t } = useI18n();
  const [form, setForm] = useState({});
  const [picked, setPicked] = useState([]);      // purchase ids on this invoice
  const [available, setAvailable] = useState([]); // unbilled supplier purchases + this invoice's own
  const [photo, setPhoto] = useState(null);
  const [saving, setSaving] = useState(false);

  const editing = !!invoice;

  useEffect(() => {
    if (!open) return;
    setPhoto(null);
    setForm({
      vendor_id: invoice?.vendor_id || '',
      supplier_name: invoice?.supplier_name || '',
      invoice_no: invoice?.invoice_no || '',
      invoice_date: invoice?.invoice_date || '',
      tax_amount: invoice?.tax_amount ? String(invoice.tax_amount) : '',
      stated_total: invoice?.stated_total != null ? String(invoice.stated_total) : '',
      variance_explanation: invoice?.variance_explanation || '',
      notes: invoice?.notes || '',
    });
    setPicked((invoice?.items || []).map((i) => i.purchase_id));

    // Offer everything not yet billed, plus whatever this invoice already carries (so editing can drop a
    // line and put it back without leaving the form).
    api.get('/part-invoices/unbilled')
      .then((r) => {
        const unbilled = payload(r) || [];
        const mine = (invoice?.items || []).map((i) => ({
          id: i.purchase_id,
          part_name: i.part_name,
          part_number: i.part_number,
          quantity: i.quantity,
          unit_price: i.unit_price,
          gross: i.line_total,
          plate_no: i.plate_no,
        }));
        const seen = new Set(unbilled.map((p) => p.id));
        setAvailable([...unbilled, ...mine.filter((m) => !seen.has(m.id))]);
      })
      .catch(() => setAvailable([]));
  }, [open, invoice]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e?.target ? e.target.value : e }));
  const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

  // The money, derived exactly the way the backend derives it — so the form and the server never disagree.
  const subtotal = useMemo(
    () => round2(available.filter((p) => picked.includes(p.id)).reduce((s, p) => s + Number(p.gross || 0), 0)),
    [available, picked],
  );
  const total = round2(subtotal + Number(form.tax_amount || 0));
  const stated = form.stated_total === '' ? null : Number(form.stated_total);
  const variance = stated == null ? 0 : round2(total - stated);
  const needsExplanation = stated != null && Math.abs(variance) > 0.01 && !form.variance_explanation.trim();

  const submit = async () => {
    setSaving(true);
    try {
      const body = new FormData();
      Object.entries({
        vendor_id: form.vendor_id || '',
        supplier_name: form.supplier_name || '',
        invoice_no: form.invoice_no || '',
        invoice_date: form.invoice_date || '',
        tax_amount: form.tax_amount || 0,
        notes: form.notes || '',
      }).forEach(([k, v]) => body.append(k, v));
      // Only send the printed total when one was actually keyed — an empty string would clear it.
      if (form.stated_total !== '') {
        body.append('stated_total', form.stated_total);
        body.append('variance_explanation', form.variance_explanation || '');
      }
      body.append('purchase_ids', JSON.stringify(picked));
      if (photo) body.append('photo', photo);

      await api.post(editing ? `/part-invoices/${invoice.id}` : '/part-invoices', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      toast.success(editing ? t('Invoice updated') : t('Invoice recorded'));
      onDone?.();
      onClose?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not save the invoice.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? t('Invoice {no}', { no: invoice.invoice_no || '' }) : t('Record supplier invoice')}
      size="lg"
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <SearchSelect
            label={t('Supplier')}
            value={form.vendor_id || ''}
            onChange={(v) => setForm((f) => ({ ...f, vendor_id: v }))}
            options={suppliers.map((v) => ({ value: v.id, label: v.name }))}
            placeholder={t('Pick a supplier…')}
          />
          <Input
            label={t('Or supplier name (one-off)')}
            value={form.supplier_name || ''}
            onChange={set('supplier_name')}
            placeholder={t('ABC Auto Parts')}
          />
          <Input label={t('Invoice number')} value={form.invoice_no || ''} onChange={set('invoice_no')} placeholder="INV-2026-001" />
          <Input label={t('Invoice date')} type="date" value={form.invoice_date || ''} onChange={set('invoice_date')} />
        </div>

        {/* The parts on the document. Garage-sourced buys never appear here — they bill on the garage. */}
        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('Parts on this invoice')}
          </p>
          <div className="max-h-56 overflow-y-auto rounded-lg border border-slate-200">
            {available.length === 0 && (
              <p className="px-3 py-4 text-center text-sm text-slate-400">
                {t('No supplier purchases are waiting for an invoice.')}
              </p>
            )}
            {available.map((p) => (
              <label
                key={p.id}
                className="flex cursor-pointer items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0 hover:bg-slate-50"
              >
                <input type="checkbox" checked={picked.includes(p.id)} onChange={() => toggle(p.id)} className="h-4 w-4" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-800">{p.part_name}</span>
                  <span className="block text-[11px] text-slate-500">
                    {p.plate_no ? `${p.plate_no} · ` : ''}{p.quantity} × {aed(p.unit_price)}
                    {p.supplier ? ` · ${p.supplier}` : ''}
                  </span>
                </span>
                <span className="shrink-0 text-sm tabular-nums text-slate-700">{aed(p.gross)}</span>
              </label>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Input label={t('Tax / VAT')} type="number" min="0" step="0.01" value={form.tax_amount || ''} onChange={set('tax_amount')} placeholder="0.00" />
          <Input
            label={t('Total printed on the invoice')}
            type="number"
            min="0"
            step="0.01"
            value={form.stated_total || ''}
            onChange={set('stated_total')}
            placeholder={String(total)}
          />
        </div>

        {/* The variance gate, mirrored in the UI so it is understood before it is enforced. */}
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
          <div className="flex justify-between py-0.5"><span className="text-slate-600">{t('Parts attached')}</span><span className="tabular-nums">{aed(subtotal)}</span></div>
          {Number(form.tax_amount) > 0 && (
            <div className="flex justify-between py-0.5"><span className="text-slate-600">{t('Tax')}</span><span className="tabular-nums">{aed(form.tax_amount)}</span></div>
          )}
          <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>{t('Invoice total')}</span><span className="tabular-nums">{aed(total)}</span></div>
          {stated != null && Math.abs(variance) > 0.01 && (
            <p className="mt-1.5 text-[12px] font-medium text-amber-700">
              {t('The paper says {stated} — a difference of {diff}. Explain it below to save.', {
                stated: aed(stated),
                diff: aed(Math.abs(variance)),
              })}
            </p>
          )}
        </div>

        {stated != null && Math.abs(variance) > 0.01 && (
          <Textarea
            label={t('Why the totals differ')}
            rows={2}
            value={form.variance_explanation || ''}
            onChange={set('variance_explanation')}
            placeholder={t('e.g. supplier added a delivery charge')}
          />
        )}

        <div>
          <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('Photo of the invoice')}
          </label>
          <input type="file" accept="image/*" onChange={(e) => setPhoto(e.target.files?.[0] || null)} className="text-sm" />
          {invoice?.photo_url && !photo && (
            <a href={invoice.photo_url} target="_blank" rel="noreferrer" className="ms-2 text-sm text-sky-600 hover:underline">
              {t('view current')}
            </a>
          )}
        </div>

        <Textarea label={t('Notes')} rows={2} value={form.notes || ''} onChange={set('notes')} />

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('Cancel')}</Button>
          <Button loading={saving} disabled={needsExplanation} onClick={submit}>
            {editing ? t('Save invoice') : t('Record invoice')}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

// ── The ledger ────────────────────────────────────────────────────────────────────────────────────
export default function PartInvoices() {
  const toast = useToast();
  const { t } = useI18n();
  const { can } = usePermissions();
  const canManage = can('parts.purchase');

  const [rows, setRows] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(undefined); // undefined = closed, null = new, obj = edit

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/part-invoices');
      setRows(payload(res)?.invoices || []);
    } catch {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    api.get('/Vendor', { params: { per_page: 200 } })
      .then((r) => {
        const list = payload(r);
        const all = Array.isArray(list) ? list : (list?.data || list?.vendors || []);
        setSuppliers(all.filter((v) => v.type === 'parts_supplier' || v.type === 'supplier' || v.type === 'garage'));
      })
      .catch(() => setSuppliers([]));
  }, []);

  const remove = async (row) => {
    try {
      await api.delete(`/part-invoices/${row.id}`);
      toast.success(t('Invoice deleted — the purchases are untouched.'));
      load();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not delete the invoice.'));
    }
  };

  const totalBilled = useMemo(() => rows.reduce((s, r) => s + Number(r.total_amount || 0), 0), [rows]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Supplier Parts Invoices')}
          subtitle={t("What the supplier charged for a part — the document behind its price. Parts the garage supplied are billed on the garage's own invoice, not here.")}
          actions={canManage && <Button onClick={() => setEditing(null)}>{t('Record invoice')}</Button>}
        />

        {SHOW_FINANCIALS && !loading && rows.length > 0 && (
          <Card className="px-5 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Total billed by suppliers')}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{aed(totalBilled)}</p>
            <p className="mt-1 text-[11px] text-slate-500">
              {rows.length === 1
                ? t("Across 1 invoice. This is part cost only — labour is on the garages' invoices.")
                : t("Across {n} invoices. This is part cost only — labour is on the garages' invoices.", { n: rows.length })}
            </p>
          </Card>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Invoice')}</th>
                  <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Supplier')}</th>
                  <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Parts')}</th>
                  <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Total')}</th>
                  <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Recorded')}</th>
                  <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Actions')}</th>
                </tr>
              </thead>
              {loading ? (
                <TableSkeleton cols={6} />
              ) : (
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className="bg-white even:bg-slate-50/40 hover:bg-indigo-50/40">
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{r.invoice_no || '—'}</div>
                        <div className="text-xs text-slate-400">{r.invoice_date || ''}</div>
                        <div className="mt-1 flex flex-wrap items-center gap-1">
                          <StatusChip status={r.status} label={r.status_label} />
                          {r.outstanding > 0 && SHOW_FINANCIALS && (
                            <span className="text-[11px] text-slate-500">{t('{amount} owed', { amount: aed(r.outstanding) })}</span>
                          )}
                        </div>
                        {r.variance != null && Math.abs(r.variance) > 0.01 && (
                          <Badge tone="amber">{t('variance {amount}', { amount: aed(Math.abs(r.variance)) })}</Badge>
                        )}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-700">{r.supplier}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        {(r.items || []).slice(0, 3).map((i) => i.part_name).join(', ')}
                        {(r.items || []).length > 3 ? ` +${r.items.length - 3}` : ''}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-end tabular-nums text-slate-800">
                        {SHOW_FINANCIALS ? aed(r.total_amount) : '—'}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{r.recorded_by || '—'}</div>
                        <div className="text-xs text-slate-400">{fmtAgo(r.recorded_at) || ''}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex justify-end gap-2">
                          {r.photo_url && (
                            <a href={r.photo_url} target="_blank" rel="noreferrer" className="text-sm text-sky-600 hover:underline">{t('Photo')}</a>
                          )}
                          {canManage && <LifecycleActions invoice={r} onDone={load} />}
                          {/* Editing and deleting stop once the invoice is an accepted obligation —
                              a correction after approval is an adjustment, not a silent rewrite. */}
                          {canManage && r.editable && <Button variant="ghost" size="sm" onClick={() => setEditing(r)}>{t('Edit')}</Button>}
                          {canManage && r.editable && (
                            <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => remove(r)}>{t('Delete')}</Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>
            {!loading && rows.length === 0 && (
              <EmptyState
                title={t('No supplier invoices yet')}
                message={t('Record the invoice a supplier gave you, and attach the parts it covers.')}
              />
            )}
          </div>
        </Card>
      </div>

      <InvoiceModal
        open={editing !== undefined}
        invoice={editing || null}
        suppliers={suppliers}
        onClose={() => setEditing(undefined)}
        onDone={load}
      />
    </div>
  );
}
