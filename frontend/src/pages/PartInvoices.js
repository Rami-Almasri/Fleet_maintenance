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
import { useSearchParams } from 'react-router-dom';
import api from '../api/client';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import Badge from '../components/ui/Badge';
import ActionMenu from '../components/ui/ActionMenu';
import SearchSelect from '../components/ui/SearchSelect';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import InvoiceMatchDesk from '../components/parts/InvoiceMatchDesk';
import GarageBilledParts from '../components/parts/GarageBilledParts';
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
 * The actions for one invoice row — and only the ones that are actually valid for the state it is in.
 *
 * Everything on the screen comes from `invoice.actions`, which the server computes from ONE state
 * machine (App\Support\FinancialDocumentStatus): the moves that are legal from where this document
 * stands, that this user holds the permission for, and that its editability allows. The UI's whole job
 * is to lay them out — it decides nothing about which are offered, so it cannot drift from what the API
 * will accept, and a button it never renders is still refused if the endpoint is called directly.
 *
 * The layout is the second half of the fix. A row used to show every legal move at once (Submit,
 * Approve, Cancel, Edit, Delete on a single draft), which gave a rare destructive action the same weight
 * as the one thing the invoice was waiting for. Now the PRIMARY action leads, Edit stays in reach because
 * it is the common correction, and the rest go behind ⋮.
 */
function RowActions({ invoice, onEdit, onDelete, onDone }) {
  const toast = useToast();
  const { t } = useI18n();
  const [busy, setBusy] = useState(null);

  const actions = invoice.actions || [];
  // A sentence per outcome, so each language reads naturally rather than being stitched from a verb.
  const DONE = {
    submit: t('Invoice submitted for review'),
    approve: t('Invoice approved'),
    return: t('Invoice returned for correction'),
    unapprove: t('Approval withdrawn'),
    pay: t('Invoice payment recorded'),
    cancel: t('Invoice cancelled'),
  };
  const FAILED = {
    submit: t('Could not submit the invoice.'),
    approve: t('Could not approve the invoice.'),
    return: t('Could not return the invoice.'),
    unapprove: t('Could not withdraw the approval.'),
    pay: t('Could not record the payment.'),
    cancel: t('Could not cancel the invoice.'),
  };
  // Asked before a move that cannot simply be undone. The wording says what happens, not "are you sure".
  const REASON_PROMPT = {
    cancel: t('Why is this invoice being cancelled? It stops being an obligation, and the record stays.'),
    return: t('What needs correcting? The person who keyed this bill will see it.'),
  };

  const act = async (key, body) => {
    setBusy(key);
    try {
      await api.post(`/financial-documents/supplier-invoice/${invoice.id}/${key}`, body || {});
      toast.success(DONE[key] || t('Invoice updated'));
      onDone?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || FAILED[key] || t('Could not update the invoice.'));
    } finally {
      setBusy(null);
    }
  };

  // Edit and Delete are page concerns (a modal, a list reload); everything else is a lifecycle move on
  // the shared financial-documents endpoint. One place decides which is which.
  const run = (action) => {
    if (action.key === 'edit') return onEdit();
    if (action.key === 'delete') return onDelete();
    if (action.needs_reason) {
      const reason = window.prompt(REASON_PROMPT[action.key] || t('Why?'));
      return reason && reason.trim() ? act(action.key, { reason: reason.trim() }) : undefined;
    }
    return act(action.key);
  };

  // What each action is CALLED on a row, where space is short and the state is already visible beside
  // it. The server's label is the full sentence and stays the fallback.
  const shortLabel = (action) => {
    if (action.key === 'pay') {
      return invoice.outstanding > 0 && SHOW_FINANCIALS
        ? t('Pay {amount}', { amount: aed(invoice.outstanding) })
        : t('Record payment');
    }
    return {
      submit: t('Submit'),
      approve: t('Approve'),
      return: t('Return for correction'),
      unapprove: t('Withdraw approval'),
      cancel: t('Cancel invoice'),
      edit: t('Edit'),
      delete: t('Delete'),
    }[action.key] || action.label;
  };

  const primary = actions.find((a) => a.primary);
  const edit = actions.find((a) => a.key === 'edit');
  // Everything the row does not lead with. Edit is pulled out of the menu only when it is not itself
  // the primary action, so it never appears twice.
  const overflow = actions.filter((a) => a !== primary && a !== edit);

  // A finished document (cancelled, or one this user may not act on) still shows its state and its
  // photo — it simply has nothing to be done to it, and an empty cell says that more honestly than a
  // row of disabled buttons.
  return (
    <div className="flex items-center justify-end gap-1.5">
      {edit && (
        <Button variant="ghost" size="sm" onClick={() => run(edit)}>{shortLabel(edit)}</Button>
      )}
      {primary && (
        // Never destructive — the server refuses to make a danger action primary, so this is always
        // the move the invoice is waiting for.
        <Button size="sm" loading={busy === primary.key} onClick={() => run(primary)}>
          {shortLabel(primary)}
        </Button>
      )}
      <ActionMenu
        label={t('More actions for invoice {no}', { no: invoice.invoice_no || invoice.id })}
        items={overflow.map((a) => ({
          key: a.key,
          label: shortLabel(a),
          danger: a.danger,
          disabled: busy === a.key,
          onSelect: () => run(a),
        }))}
      />
    </div>
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

  // Arriving from a link that names an invoice (the contract page lists the bills raised against a
  // contract and links each one here) — open that invoice rather than dropping the reader on a list
  // and making them find it again. Runs once the rows are in; an id that isn't here is ignored.
  const [params] = useSearchParams();
  const wanted = params.get('invoice');
  useEffect(() => {
    if (!wanted || !rows.length) return;
    const row = rows.find((r) => String(r.id) === String(wanted));
    if (row) setEditing(row);
  }, [wanted, rows]);

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
    // Deleting the paper is not undoable, so it is asked for once. The sentence says what SURVIVES,
    // because that is the part people get wrong: the spend is not being deleted, only the document.
    const ok = window.confirm(t('Delete invoice {no}? The parts it covers stay, and go back to being un-invoiced.', {
      no: row.invoice_no || `#${row.id}`,
    }));
    if (!ok) return;

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
          title={t('Bills for parts')}
          subtitle={t('Every bill a part appears on. A supplier sells parts on their own invoice and it is keyed here; a garage that fits a part bills it beside the labour on the ticket’s invoice — those are listed lower down, and changed on their ticket.')}
          actions={canManage && <Button onClick={() => setEditing(null)}>{t('Record invoice')}</Button>}
        />

        {/* The second stage: bills keyed by one person, waiting for a different person to check them
            against the photo. Above the ledger because it is work, and the ledger is a record. */}
        <InvoiceMatchDesk onChecked={load} />

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
                        {/* Whether a SECOND person has read this bill against its photo. Three
                            states, never two: nobody has looked yet, somebody looked and agreed,
                            somebody looked and did not. */}
                        <div className="mt-1">
                          {!r.matched_at ? (
                            <Badge tone="gray">{t('Not checked yet')}</Badge>
                          ) : r.match_result === 'disputed' ? (
                            <span title={r.match_note || ''}><Badge tone="red">{t('Did not match')}</Badge></span>
                          ) : (
                            <span title={t('checked by {name}', { name: r.matched_by || '—' })}>
                              <Badge tone="green">{t('Checked')}</Badge>
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        {/* Which actions belong on this row is decided by the server, per state and per
                            permission — see RowActions. Nothing here re-derives it, and nothing is
                            merely hidden: the endpoints refuse the same moves this list leaves out. */}
                        <div className="flex items-center justify-end gap-2">
                          {r.photo_url && (
                            <a href={r.photo_url} target="_blank" rel="noreferrer" className="text-sm text-sky-600 hover:underline">{t('Photo')}</a>
                          )}
                          <RowActions
                            invoice={r}
                            onEdit={() => setEditing(r)}
                            onDelete={() => remove(r)}
                            onDone={load}
                          />
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

        {/* The other road a part is billed down. Read-only — see GarageBilledParts for why a write
            button here would reopen the double-count the attach guard exists to prevent. */}
        <GarageBilledParts />
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
