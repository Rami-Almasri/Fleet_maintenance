// Invoices panel — the "One Ticket → Many Invoices" surface on a maintenance ticket.
//
// A ticket worked in more than one garage carries more than one invoice: each is a single garage's bill,
// covering only the faults IT fixed, with its own total, receipt and reconciliation status. This panel
// lists those invoices and lets the team add / edit / delete / reconcile them; the ticket total (shown at
// the top) is the sum of them. The per-invoice editor reuses LineItemsEditor for the parts/labor lines and
// its receipt-validation mode, adds a fault multi-select (which faults this invoice covers) and a receipt
// photo. Every write posts to the backend and then asks the drawer to reload so the numbers stay in sync.

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import Modal from '../ui/Modal';
import ConfirmDialog from '../ui/ConfirmDialog';
import { Input, Textarea } from '../ui/Field';
import SearchSelect from '../ui/SearchSelect';
import LineItemsEditor, {
  serializeLineItems,
  lineItemsUnlinked,
  lineItemsHaveZeroCost,
  invoiceVarianceBlocked,
} from './LineItemsEditor';

const money = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// A ticket carries WORK of four kinds, and an oil change is not a fault. The invoice surface groups the
// work it covers by kind rather than calling all of it "faults" — same vocabulary the rest of the app
// reads off MaintenanceTask::KIND_META (task.kind / task.kind_meta).
const KIND_ORDER = ['fault', 'service', 'inspection', 'damage'];
const KIND_LABEL_KEY = {
  fault: 'workflow.invoices.kindFault',
  service: 'workflow.invoices.kindService',
  inspection: 'workflow.invoices.kindInspection',
  damage: 'workflow.invoices.kindDamage',
};
const kindOf = (task) => (KIND_ORDER.includes(task?.kind) ? task.kind : 'fault');

// The garage that did a given piece of work — the fault's own current garage, else the ticket's.
const workGarageId = (task, ticket) => task.current_vendor_id || ticket?.vendor_id || null;

// Map a stored line-item (API shape) back to the editor's row shape.
const toEditorRow = (li) => ({
  kind: li.kind === 'labor' ? 'labor' : 'part',
  finding_text: li.finding_text || '',
  description: li.description || '',
  part_number: li.part_number || '',
  // Which part it is. A null one is a line billed before the picker existed — the editor shows its
  // wording and asks for a part to be chosen rather than dropping it.
  component_catalog_id: li.component_catalog_id || null,
  category_key: li.category_key || '',
  quantity: li.quantity != null ? String(li.quantity) : '1',
  unit_price: li.unit_price != null ? String(li.unit_price) : '',
  installed_on: li.installed_on || '',
  warranty_months: li.warranty_months != null ? String(li.warranty_months) : '',
  tire_brand: li.tire_brand || '',
  tire_dot: li.tire_dot || '',
  tire_tread_mm: li.tire_tread_mm != null ? String(li.tire_tread_mm) : '',
});

// ── Read-only invoice card ────────────────────────────────────────────────────────────────────────
function InvoiceCard({ invoice, canManage, onEdit, onDelete, onReconcile, busy, active = false, onHover }) {
  const { t } = useI18n();
  const reconciled = invoice.reconciliation_status === 'reconciled';
  const variance = invoice.variance;
  const hasVar = variance != null && Math.abs(variance) > 0.01;

  return (
    <div
      onMouseEnter={onHover ? () => onHover(invoice) : undefined}
      onMouseLeave={onHover ? () => onHover(null) : undefined}
      className={`rounded-xl border bg-white p-3.5 shadow-sm transition ${active ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200'}`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-slate-800">
              {invoice.is_internal
                ? t('workflow.invoices.internal')
                : (invoice.vendor_name || t('workflow.invoices.unassignedGarage'))}
            </span>
            {invoice.is_internal && (
              <Badge tone="violet">{t('workflow.invoices.internalBadge')}</Badge>
            )}
            {invoice.invoice_no && (
              <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500">{invoice.invoice_no}</span>
            )}
            <Badge tone={reconciled ? 'green' : 'amber'}>
              {reconciled ? t('workflow.invoices.reconciled') : t('workflow.invoices.pending')}
            </Badge>
          </div>
          {/* The work this invoice covers — each chip carries its OWN kind, so a service never reads
              as a fault. `faults` is the API's (historical) key for the covered work items. */}
          {invoice.faults?.length > 0 && (
            <div className="mt-1.5 flex flex-wrap gap-1">
              {invoice.faults.map((f) => (
                <span key={f.id} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
                  {f.kind_meta?.emoji
                    ? <span className="text-[9px]">{f.kind_meta.emoji}</span>
                    : <Icon.Wrench className="h-2.5 w-2.5" />}
                  {f.symptom}
                </span>
              ))}
            </div>
          )}
        </div>
        <div className="shrink-0 text-end">
          <div className="text-base font-bold tabular-nums text-slate-900">{money(invoice.amount)}</div>
          <div className="text-[11px] text-slate-400">
            {t('workflow.invoices.partsLabor', { parts: money(invoice.parts_total), labor: money(invoice.labor_total) })}
          </div>
        </div>
      </div>

      {/* Receipt reconciliation line */}
      {invoice.receipt_total != null && (
        <div className="mt-2 flex items-center gap-1.5 text-[11px]">
          <Icon.Invoice className="h-3 w-3 text-slate-400" />
          <span className="text-slate-500">{t('workflow.invoices.receipt')}: <span className="font-medium tabular-nums text-slate-700">{money(invoice.receipt_total)}</span></span>
          {hasVar ? (
            <span className="font-semibold text-amber-600">· {t('workflow.invoices.variance', { amount: money(Math.abs(variance)) })}</span>
          ) : (
            <span className="text-emerald-600">· {t('workflow.invoices.matches')}</span>
          )}
        </div>
      )}
      {invoice.variance_explanation && (
        <p className="mt-1 text-[11px] italic text-slate-500">“{invoice.variance_explanation}”</p>
      )}

      {/* Footer — receipt photo + actions */}
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5">
        {invoice.receipt_photo_url ? (
          <a href={invoice.receipt_photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:underline">
            <Icon.Invoice className="h-3.5 w-3.5" />{t('workflow.invoices.viewReceipt')}
          </a>
        ) : <span />}
        {canManage && (
          <div className="flex items-center gap-1.5">
            {!reconciled && (
              <Button size="sm" variant="success" loading={busy === `reconcile-${invoice.id}`} onClick={() => onReconcile(invoice)}>
                <Icon.Check className="h-3.5 w-3.5" />{t('workflow.invoices.markReconciled')}
              </Button>
            )}
            <Button size="sm" variant="secondary" onClick={() => onEdit(invoice)}>{t('common.edit')}</Button>
            <Button size="sm" variant="ghost" className="text-red-500" onClick={() => onDelete(invoice)}>{t('common.remove')}</Button>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Create / edit modal ───────────────────────────────────────────────────────────────────────────
function InvoiceEditor({ ticket, invoice, garages, findingsCatalog, presetTaskIds = null, onClose, onSaved }) {
  const { t } = useI18n();
  const editing = !!invoice;
  const faults = useMemo(() => ticket.tasks || [], [ticket]);

  // Which garage this bill is FROM, decided before the first paint so the work list is already scoped:
  // the invoice's own garage when editing; the garage that did the pre-ticked work when the desk opened
  // this form for one garage; the ticket's garage otherwise. Getting this right on render 1 matters —
  // the work list filters on it, and a wrong first value would drop the pre-ticked work.
  const [vendorId, setVendorId] = useState(() => {
    if (invoice?.vendor_id) return String(invoice.vendor_id);
    const preset = (presetTaskIds || []).map(String);
    if (preset.length) {
      const ids = [...new Set(
        (ticket.tasks || [])
          .filter((f) => preset.includes(String(f.id)))
          .map((f) => workGarageId(f, ticket))
          .filter(Boolean)
          .map(String),
      )];
      if (ids.length === 1) return ids[0];
    }
    return ticket.vendor_id ? String(ticket.vendor_id) : '';
  });
  const [isInternal, setIsInternal] = useState(!!invoice?.is_internal);
  const [invoiceNo, setInvoiceNo] = useState(invoice?.invoice_no || '');
  // A new invoice can be opened with a set of faults already ticked — the matching desk does this when
  // the user says "bill the faults nothing covers yet".
  const [taskIds, setTaskIds] = useState(() => new Set((invoice?.task_ids || presetTaskIds || []).map(String)));
  const [lineItems, setLineItems] = useState(() => (invoice?.line_items || []).map(toEditorRow));
  const [receiptTotal, setReceiptTotal] = useState(invoice?.receipt_total != null ? String(invoice.receipt_total) : '');
  const [variance, setVariance] = useState(invoice?.variance_explanation || '');
  const [notes, setNotes] = useState(invoice?.notes || '');
  const [photo, setPhoto] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  // A bill can only come from a garage that actually worked THIS car — the ticket's own garage plus any
  // garage a fault was moved to. Offering the whole vendor list invited a bill against a workshop that
  // never touched the car, which nothing downstream could catch.
  const ticketGarageIds = useMemo(() => {
    const ids = new Set();
    if (ticket.vendor_id) ids.add(String(ticket.vendor_id));
    (ticket.tasks || []).forEach((f) => {
      if (f.current_vendor_id) ids.add(String(f.current_vendor_id));
    });
    // Keep a garage already named on this invoice selectable, so an old bill stays editable.
    if (invoice?.vendor_id) ids.add(String(invoice.vendor_id));
    return ids;
  }, [ticket, invoice]);

  const garageOptions = useMemo(
    () => garages
      .filter((g) => ticketGarageIds.has(String(g.id)))
      .map((g) => ({ id: String(g.id), label: g.name, sub: g.phone || g.type })),
    [garages, ticketGarageIds],
  );

  const toggleFault = (id) => {
    const next = new Set(taskIds);
    const k = String(id);
    if (next.has(k)) next.delete(k); else next.add(k);
    setTaskIds(next);
  };

  // A GARAGE'S BILL LISTS ONLY THAT GARAGE'S WORK. Once the garage is named, the other garages' work
  // leaves this form entirely — it is billed on their own invoices, and showing it here only invites
  // someone to tick it. (An in-house bill has no garage to scope by, so it still sees everything.)
  const visibleFaults = useMemo(() => (
    (isInternal || !vendorId)
      ? faults
      : faults.filter((f) => String(workGarageId(f, ticket) ?? '') === String(vendorId))
  ), [faults, isInternal, vendorId, ticket]);

  // Never hide work silently: say how much belongs to the other garages.
  const elsewhereCount = faults.length - visibleFaults.length;

  // Switching the garage drops any ticked work that is no longer this garage's, so the payload can
  // never carry another garage's items just because they were ticked before the switch.
  useEffect(() => {
    if (isInternal || !vendorId) return;
    const allowed = new Set(visibleFaults.map((f) => String(f.id)));
    setTaskIds((current) => {
      const next = new Set([...current].filter((id) => allowed.has(id)));
      return next.size === current.size ? current : next;
    });
  }, [vendorId, isInternal, visibleFaults]);

  // Which faults are billed on ANOTHER invoice already — surfaced so the user knows selecting them here
  // moves them off that invoice (fault → one invoice).
  const faultOwner = useMemo(() => {
    const map = {};
    (ticket.invoices || []).forEach((inv) => {
      if (invoice && inv.id === invoice.id) return;
      (inv.task_ids || []).forEach((tid) => { map[String(tid)] = inv; });
    });
    return map;
  }, [ticket.invoices, invoice]);

  // ONE GARAGE PER BILL. Each garage hands us its own invoice for the work IT did, so a bill whose
  // ticked work spans two garages is not a real document — it is two. Named here rather than left to the
  // user's memory, because the whole point of the fault→invoice link is that cost lands on the garage
  // that earned it.
  const tickedWork = faults.filter((f) => taskIds.has(String(f.id)));
  const garagesOnTicked = useMemo(() => {
    const map = new Map();
    tickedWork.forEach((f) => {
      const id = workGarageId(f, ticket);
      if (id) map.set(String(id), f.current_garage || ticket.garage || `#${id}`);
    });
    return map;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [taskIds, faults, ticket]);
  const mixedGarages = !isInternal && garagesOnTicked.size > 1;

  // Ticking work from a single garage names the garage for you — the invoice belongs to whoever did it.
  useEffect(() => {
    if (isInternal || garagesOnTicked.size !== 1) return;
    const only = [...garagesOnTicked.keys()][0];
    setVendorId((current) => (current === only ? current : only));
  }, [garagesOnTicked, isInternal]);

  const rows = serializeLineItems(lineItems);
  const blocked =
    saving
    || lineItemsUnlinked(lineItems)
    || lineItemsHaveZeroCost(lineItems)
    // A receipt is optional on an invoice, but once entered a mismatch needs an explanation.
    || (receiptTotal !== '' && invoiceVarianceBlocked({ rows, receiptTotal, variance }))
    || mixedGarages
    || (rows.length === 0 && taskIds.size === 0); // nothing to record

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const fd = new FormData();
      fd.append('is_internal', isInternal ? '1' : '0');
      if (!isInternal && vendorId) fd.append('vendor_id', vendorId);
      if (invoiceNo.trim()) fd.append('invoice_no', invoiceNo.trim());
      if (notes.trim()) fd.append('notes', notes.trim());
      fd.append('task_ids', JSON.stringify([...taskIds].map(Number)));
      fd.append('line_items', JSON.stringify(rows));
      if (receiptTotal !== '') fd.append('receipt_total', String(Number(receiptTotal)));
      if (variance.trim()) fd.append('variance_explanation', variance.trim());
      if (photo) fd.append('receipt_photo', photo);

      const url = editing ? `/maintenance-invoices/${invoice.id}` : `/maintenance-tickets/${ticket.id}/invoices`;
      await api.post(url, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      onSaved();
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.invoices.saveError'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={editing ? t('workflow.invoices.editTitle') : t('workflow.invoices.addTitle')}
      subtitle={ticket.plate || `#${ticket.id}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button variant="primary" onClick={save} loading={saving} disabled={blocked}>
            {editing ? t('common.save') : t('workflow.invoices.create')}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {/* In-house / internal toggle — a routine service done by us has no third-party garage. */}
        <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5">
          <input
            type="checkbox"
            checked={isInternal}
            onChange={(e) => setIsInternal(e.target.checked)}
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span className="min-w-0">
            <span className="block text-sm font-medium text-slate-700">{t('workflow.invoices.internalToggle')}</span>
            <span className="block text-[11px] text-slate-500">{t('workflow.invoices.internalHint')}</span>
          </span>
        </label>

        {/* Garage + invoice number */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.invoices.garage')}</label>
            {isInternal ? (
              <div className="flex h-[42px] items-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 text-sm text-slate-400">
                {t('workflow.invoices.internal')}
              </div>
            ) : garageOptions.length === 0 ? (
              <div className="flex min-h-[42px] items-center rounded-lg border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {t('workflow.invoices.noGarageOnTicket')}
              </div>
            ) : (
              <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.pickGarage')} />
            )}
            {!isInternal && garageOptions.length > 0 && (
              <p className="mt-1 text-[11px] text-slate-400">{t('workflow.invoices.garageFromWork')}</p>
            )}
          </div>
          <Input label={t('workflow.invoices.invoiceNo')} value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} placeholder={t('e.g. INV-2043')} />
        </div>

        {/* Work multi-select — WHAT this invoice covers, grouped by its own kind. A ticket holds faults,
            planned services, damage and checks; an oil change is a service and is never listed as a fault. */}
        <div>
          <p className="mb-1.5 text-sm font-medium text-slate-700">{t('workflow.invoices.workCovered')}</p>
          {visibleFaults.length === 0 ? (
            <p className="text-xs text-slate-400">{t('workflow.invoices.noFaults')}</p>
          ) : (
            <div className="space-y-3">
              {KIND_ORDER.filter((k) => visibleFaults.some((f) => kindOf(f) === k)).map((kind) => (
                <div key={kind}>
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {t(KIND_LABEL_KEY[kind])}
                  </p>
                  <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                    {visibleFaults.filter((f) => kindOf(f) === kind).map((f) => {
                      const owner = faultOwner[String(f.id)];
                      const checked = taskIds.has(String(f.id));
                      return (
                        <label key={f.id} className={`flex cursor-pointer items-start gap-2 rounded-lg border px-2.5 py-2 text-sm ${checked ? 'border-indigo-300 bg-indigo-50/60' : 'border-slate-200 bg-white hover:bg-slate-50'}`}>
                          <input type="checkbox" checked={checked} onChange={() => toggleFault(f.id)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                          <span className="min-w-0">
                            <span className="block text-slate-700">
                              {f.kind_meta?.emoji && <span className="me-1 text-[11px]">{f.kind_meta.emoji}</span>}
                              {f.symptom}
                            </span>
                            {/* Only worth printing while the list still spans garages — once the bill is
                                scoped to one, every row would repeat the same name. */}
                            {f.current_garage && (isInternal || !vendorId) && (
                              <span className="block text-[11px] text-slate-400">{f.current_garage}</span>
                            )}
                            {owner && !checked && (
                              <span className="text-[11px] text-amber-600">{t('workflow.invoices.onOtherInvoice', { no: owner.invoice_no || `#${owner.id}` })}</span>
                            )}
                          </span>
                        </label>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
          {elsewhereCount > 0 && (
            <p className="mt-2 text-[11px] text-slate-400">
              {t('workflow.invoices.workElsewhere', { n: elsewhereCount })}
            </p>
          )}
          {mixedGarages && (
            <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-600/20">
              {t('workflow.invoices.garageMixed', { garages: [...garagesOnTicked.values()].join(' · ') })}
            </p>
          )}
        </div>

        {/* Parts + labor lines, reconciled against this invoice's receipt total. The garage is passed
            down because only the parts THIS garage supplied may be billed on its invoice. */}
        <LineItemsEditor
          value={lineItems}
          onChange={setLineItems}
          catalog={findingsCatalog}
          findings={ticket.findings || []}
          ticketId={ticket.id}
          vendorId={isInternal ? null : vendorId}
          isInternal={isInternal}
          requireReceipt
          receiptTotal={receiptTotal}
          onReceiptTotalChange={setReceiptTotal}
          variance={variance}
          onVarianceChange={setVariance}
        />

        {/* Receipt photo + notes */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.invoices.receiptPhoto')}</label>
            <input type="file" accept="image/*" onChange={(e) => setPhoto(e.target.files?.[0] || null)} className="block w-full text-xs text-slate-500 file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200" />
            {editing && invoice.receipt_photo_url && !photo && (
              <a href={invoice.receipt_photo_url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-[11px] font-semibold text-indigo-600 hover:underline">{t('workflow.invoices.currentReceipt')}</a>
            )}
          </div>
          <Textarea label={t('workflow.invoices.notes')} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
    </Modal>
  );
}

// ── Panel ───────────────────────────────────────────────────────────────────────────────────────────
// `activeInvoiceId` / `onHoverInvoice` are optional and let a host page cross-link this list with its own
// view of the work (the Invoice Matching desk highlights the faults a hovered bill covers). `addRequest`
// ({ nonce, taskIds }) opens the create modal from outside with those faults pre-ticked; a new nonce is
// what triggers it, so the same set can be asked for twice.
export default function InvoicesPanel({
  ticket, garages = [], findingsCatalog = [], canManage = false, onChanged,
  activeInvoiceId = null, onHoverInvoice, addRequest = null,
}) {
  const { t } = useI18n();
  const [editor, setEditor] = useState(null); // null | { invoice?, presetTaskIds? }
  const [toDelete, setToDelete] = useState(null);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

  const addNonce = addRequest?.nonce;
  useEffect(() => {
    if (!addNonce || !canManage) return;
    setEditor({ presetTaskIds: addRequest?.taskIds || [] });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [addNonce]);

  const invoices = ticket?.invoices || [];
  const total = invoices.reduce((a, inv) => a + Number(inv.amount || 0), 0);
  const allReconciled = invoices.length > 0 && invoices.every((i) => i.reconciliation_status === 'reconciled');

  const afterChange = async () => {
    setEditor(null);
    setToDelete(null);
    setBusy(null);
    await onChanged?.();
  };

  const reconcile = async (invoice) => {
    setBusy(`reconcile-${invoice.id}`);
    setError(null);
    try {
      await api.post(`/maintenance-invoices/${invoice.id}/reconcile`);
      await afterChange();
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.invoices.saveError'));
      setBusy(null);
    }
  };

  const del = async () => {
    if (!toDelete) return;
    setBusy(`delete-${toDelete.id}`);
    setError(null);
    try {
      await api.delete(`/maintenance-invoices/${toDelete.id}`);
      await afterChange();
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.invoices.saveError'));
      setBusy(null);
    }
  };

  return (
    <div className="space-y-3">
      {/* Header — ticket-level total + add */}
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-baseline gap-2">
          <span className="text-sm font-semibold text-slate-700">
            {invoices.length} {invoices.length === 1 ? t('workflow.invoices.one') : t('workflow.invoices.many')}
          </span>
          <span className="text-xs text-slate-400">·</span>
          <span className="text-sm font-bold tabular-nums text-slate-900">{money(total)}</span>
          {allReconciled && <Badge tone="green">{t('workflow.invoices.allReconciled')}</Badge>}
        </div>
        {canManage && (
          <Button size="sm" variant="primary" onClick={() => setEditor({})}>
            <Icon.Plus className="h-3.5 w-3.5" />{t('workflow.invoices.add')}
          </Button>
        )}
      </div>

      {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

      {invoices.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
          {t('workflow.invoices.empty')}
        </div>
      ) : (
        <div className="space-y-2.5">
          {invoices.map((inv) => (
            <InvoiceCard
              key={inv.id}
              invoice={inv}
              canManage={canManage}
              busy={busy}
              active={activeInvoiceId === inv.id}
              onHover={onHoverInvoice}
              onEdit={(i) => setEditor({ invoice: i })}
              onDelete={(i) => setToDelete(i)}
              onReconcile={reconcile}
            />
          ))}
        </div>
      )}

      {editor && (
        <InvoiceEditor
          ticket={ticket}
          invoice={editor.invoice}
          presetTaskIds={editor.presetTaskIds}
          garages={garages}
          findingsCatalog={findingsCatalog}
          onClose={() => setEditor(null)}
          onSaved={afterChange}
        />
      )}

      <ConfirmDialog
        open={!!toDelete}
        onClose={() => setToDelete(null)}
        onConfirm={del}
        loading={busy === `delete-${toDelete?.id}`}
        title={t('workflow.invoices.deleteTitle')}
        message={t('workflow.invoices.deleteMsg')}
        confirmText={t('common.remove')}
      />
    </div>
  );
}
