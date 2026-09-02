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
  bandsAmount,
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
// Singular, for naming ONE work item on a bill — the plural headings above are group labels.
const KIND_ONE_KEY = {
  fault: 'workflow.invoices.oneFault',
  service: 'workflow.invoices.oneService',
  inspection: 'workflow.invoices.oneInspection',
  damage: 'workflow.invoices.oneDamage',
};
const kindOf = (task) => (KIND_ORDER.includes(task?.kind) ? task.kind : 'fault');

// The garage that did a given piece of work — the fault's own current garage, else the ticket's.
const workGarageId = (task, ticket) => task.current_vendor_id || ticket?.vendor_id || null;

// The kinds the line editor owns. A bill also carries VAT, a discount and the odd adjustment, but those
// belong to the DOCUMENT rather than to a fault: they are written by the service from their own fields,
// never as work lines. Feeding one into the editor turned it into an unlinkable "part" called VAT, which
// tripped the Diagnosis-First gate and left Save disabled with nothing on screen saying why — i.e. any
// invoice carrying VAT or a discount could not be edited at all.
const WORK_KINDS = ['part', 'labor'];
const isWorkLine = (li) => WORK_KINDS.includes(li?.kind);

// Map a stored line-item (API shape) back to the editor's row shape.
const toEditorRow = (li) => ({
  kind: li.kind === 'labor' ? 'labor' : 'part',
  finding_text: li.finding_text || '',
  description: li.description || '',
  part_number: li.part_number || '',
  // Which part it is. A null one is a line billed before the picker existed — the editor shows its
  // wording and asks for a part to be chosen rather than dropping it.
  component_catalog_id: li.component_catalog_id || null,
  // WHERE the part came from — the purchase / request / required line this charge is billed from.
  // Dropping it on the way into the editor meant every edit re-submitted the line as an unbacked
  // price: the origin was wiped on save, the ledger lost its link to the purchase, and the row
  // re-rendered as a blank part picker instead of the settled fact it is.
  part_source: li.part_source || null,
  part_source_id: li.part_source_id || null,
  category_key: li.category_key || '',
  quantity: li.quantity != null ? String(li.quantity) : '1',
  unit_price: li.unit_price != null ? String(li.unit_price) : '',
  installed_on: li.installed_on || '',
  warranty_months: li.warranty_months != null ? String(li.warranty_months) : '',
  tire_brand: li.tire_brand || '',
  tire_dot: li.tire_dot || '',
  tire_tread_mm: li.tire_tread_mm != null ? String(li.tire_tread_mm) : '',
});

// ── What this bill actually charged, work item by work item ───────────────────────────────────────
//
// A bill is not a total with some chips under it. Every line on it was charged FOR something — a fault
// that was repaired or a service that was carried out — and the whole point of keying it here is that the
// two can be read against each other. So the card groups the invoice's lines under the work they were
// attributed to (`maintenance_task_id`, set by the service from the line's Diagnosis-First symptom link),
// names the part each part-line fitted, and prints what that one piece of work came to.
//
// Three things are stated rather than hidden:
//   - work this bill covers with NO line against it — billed for nothing, which is a real finding;
//   - lines attributed to no work item — money on the bill that no repair explains;
//   - the VAT / discount bands, which belong to the document and to no fault.
function BilledWork({ invoice }) {
  const { t } = useI18n();
  const lines = useMemo(() => invoice.line_items || [], [invoice.line_items]);
  const work = useMemo(() => invoice.faults || [], [invoice.faults]);

  const groups = useMemo(() => {
    const workLines = lines.filter((li) => WORK_KINDS.includes(li.kind));
    const byTask = new Map();
    const orphans = [];

    workLines.forEach((li) => {
      // The task link is the strong claim (the server resolved it). Falling back to the finding text
      // catches a line whose symptom no longer matches a covered work item word-for-word.
      const match = li.maintenance_task_id
        ? work.find((f) => String(f.id) === String(li.maintenance_task_id))
        : work.find((f) => (f.symptom || '').trim().toLowerCase() === (li.finding_text || '').trim().toLowerCase());
      if (!match) { orphans.push(li); return; }
      if (!byTask.has(match.id)) byTask.set(match.id, { work: match, lines: [] });
      byTask.get(match.id).lines.push(li);
    });

    // Every covered work item gets a row, in the order the invoice lists them — including the ones with
    // no line, because "we were billed nothing for this" is exactly what the desk is here to catch.
    const rows = work.map((f) => byTask.get(f.id) || { work: f, lines: [] });

    return { rows, orphans };
  }, [lines, work]);

  const bands = [
    { key: 'vat', label: t('workflow.invoices.vat'), amount: Number(invoice.vat_total || 0) },
    { key: 'discount', label: t('workflow.invoices.discount'), amount: Number(invoice.discount_total || 0) },
  ].filter((b) => Math.abs(b.amount) > 0.005);

  if (groups.rows.length === 0 && groups.orphans.length === 0 && bands.length === 0) {
    return (
      <p className="mt-2.5 rounded-lg border border-dashed border-amber-300 bg-amber-50/60 px-2.5 py-2 text-[11px] text-amber-800">
        {t('workflow.invoices.nothingKeyed')}
      </p>
    );
  }

  const LineRow = ({ li }) => (
    <li className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-1">
      <span className={`rounded px-1 py-0.5 text-[9px] font-bold uppercase ${li.kind === 'labor' ? 'bg-cyan-100 text-cyan-700' : 'bg-blue-100 text-blue-700'}`}>
        {li.kind === 'labor' ? t('workflow.invoices.labor') : t('workflow.invoices.part')}
      </span>
      {/* WHICH PART. The catalog name is the identity; the billed wording is printed after it only when
          the garage wrote something different, so the two can be compared instead of guessed at. */}
      <span className="min-w-0 text-[11px] text-slate-700" dir="auto">{li.catalog_part_name || li.description}</span>
      {li.catalog_part_name && li.description && li.catalog_part_name !== li.description && (
        <span className="text-[10.5px] text-slate-400" dir="auto">“{li.description}”</span>
      )}
      {li.part_number && <span className="font-mono text-[10px] text-slate-400">{li.part_number}</span>}
      {/* A part with no catalog reference is money against a part nobody can identify — say so. */}
      {li.kind === 'part' && !li.component_catalog_id && (
        <span className="rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">{t('workflow.invoices.partUnidentified')}</span>
      )}
      <span className="ms-auto whitespace-nowrap text-[10.5px] tabular-nums text-slate-400">
        {li.kind === 'labor'
          ? t('workflow.invoices.laborHours', { n: Number(li.quantity || 0) })
          : `${Number(li.quantity || 0)} × ${money(li.unit_price)}`}
      </span>
      <span className="w-20 whitespace-nowrap text-end text-[11px] font-semibold tabular-nums text-slate-700">{money(li.line_total)}</span>
    </li>
  );

  return (
    <div className="mt-2.5 space-y-1.5 border-t border-slate-100 pt-2.5">
      {groups.rows.map(({ work: f, lines: own }) => {
        const subtotal = own.reduce((a, li) => a + Number(li.line_total || 0), 0);
        return (
          <div key={f.id} className="rounded-lg bg-slate-50/70 px-2.5 py-2">
            <div className="flex flex-wrap items-baseline gap-x-2">
              <span className="text-[11px] font-semibold text-slate-700" dir="auto">
                {f.kind_meta?.emoji ? <span className="me-1 text-[9px]">{f.kind_meta.emoji}</span> : <Icon.Wrench className="me-1 inline h-2.5 w-2.5 text-slate-400" />}
                {f.symptom}
              </span>
              {/* Its own kind, always — a planned service billed on a garage's paper is not a fault. */}
              <span className="rounded bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200">
                {t(KIND_ONE_KEY[kindOf(f)])}
              </span>
              <span className="ms-auto text-[11px] font-bold tabular-nums text-slate-800">{money(subtotal)}</span>
            </div>
            {own.length === 0 ? (
              <p className="mt-1 text-[10.5px] text-amber-700">{t('workflow.invoices.workNoLines')}</p>
            ) : (
              <ul className="mt-1 divide-y divide-slate-200/70">{own.map((li) => <LineRow key={li.id} li={li} />)}</ul>
            )}
          </div>
        );
      })}

      {/* Charged, but against no work this bill covers. */}
      {groups.orphans.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50/50 px-2.5 py-2">
          <p className="text-[11px] font-semibold text-amber-800">{t('workflow.invoices.linesUnattributed')}</p>
          <ul className="mt-1 divide-y divide-amber-200/60">{groups.orphans.map((li) => <LineRow key={li.id} li={li} />)}</ul>
        </div>
      )}

      {/* The document's own bands — they belong to the bill, not to any one repair. */}
      {bands.length > 0 && (
        <ul className="px-2.5">
          {bands.map((b) => (
            <li key={b.key} className="flex items-baseline justify-between py-0.5 text-[11px]">
              <span className="text-slate-500">{b.label}</span>
              <span className={`font-semibold tabular-nums ${b.amount < 0 ? 'text-emerald-600' : 'text-slate-700'}`}>{money(b.amount)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

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
        </div>
        <div className="shrink-0 text-end">
          <div className="text-base font-bold tabular-nums text-slate-900">{money(invoice.amount)}</div>
          <div className="text-[11px] text-slate-400">
            {t('workflow.invoices.partsLabor', { parts: money(invoice.parts_total), labor: money(invoice.labor_total) })}
          </div>
        </div>
      </div>

      {/* The bill, read against the work — what each fault or service was charged for, and by which
          part. Replaces the row of bare symptom chips, which named the work but never said what any of
          it cost, so a bill could cover five items and charge for one and the card looked identical. */}
      <BilledWork invoice={invoice} />

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
  const [lineItems, setLineItems] = useState(() => (invoice?.line_items || []).filter(isWorkLine).map(toEditorRow));
  // VAT and the discount are keyed as the paper prints them — both positive. The discount is STORED
  // negative, so it is shown back as its absolute value. Until now there was no field for either: the
  // API accepted them, the service wrote them as ledger lines, and no surface could enter or re-key one.
  const [vatAmount, setVatAmount] = useState(invoice?.vat_total ? String(Math.abs(invoice.vat_total)) : '');
  const [discountAmount, setDiscountAmount] = useState(invoice?.discount_total ? String(Math.abs(invoice.discount_total)) : '');
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
    || (receiptTotal !== '' && invoiceVarianceBlocked({ rows, receiptTotal, variance, vatAmount, discountAmount }))
    || mixedGarages
    || (rows.length === 0 && taskIds.size === 0); // nothing to record

  // A BILL WITH NO MONEY ON IT IS NOT A BILL. Ticking the work without keying a single line saved an
  // invoice for AED 0.00, and because a work item counts as billed the moment it points at an invoice,
  // the desk then reported the ticket fully Matched with nothing keyed against it. Not blocked — a
  // genuine zero (goodwill, warranty) is a real document — but never silent again.
  const noCharge = rows.length === 0 && bandsAmount({ vatAmount, discountAmount }) === 0;

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
      // Always sent — the service replaces each band wholesale and treats an ABSENT key as "not touching
      // it". Sending only non-empty values would make clearing a VAT impossible: blanking the field would
      // simply leave the old ledger row in place, and the total would not move.
      fd.append('vat_amount', String(Math.max(0, Number(vatAmount) || 0)));
      fd.append('discount_amount', String(Math.max(0, Number(discountAmount) || 0)));
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
          vatAmount={vatAmount}
          onVatAmountChange={setVatAmount}
          discountAmount={discountAmount}
          onDiscountAmountChange={setDiscountAmount}
        />

        {/* A bill with nothing on it. Said here, before the save, because afterwards the work reads as
            billed and only the AED 0.00 gives it away. */}
        {noCharge && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-600/20">
            {t('workflow.invoices.noChargeWarning')}
          </p>
        )}

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
//
// `showAdd` lets a host page turn OFF this panel's own add button. A page that already puts a per-garage
// "enter this garage's bill" button next to the work — the Invoice Matching desk does — otherwise shows
// two buttons that open the identical form, one of them blank, and the reader has to work out that the
// choice doesn't matter. One door per job.
export default function InvoicesPanel({
  ticket, garages = [], findingsCatalog = [], canManage = false, onChanged,
  activeInvoiceId = null, onHoverInvoice, addRequest = null, showAdd = true,
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
        {canManage && showAdd && (
          <Button size="sm" variant="primary" onClick={() => setEditor({})}>
            <Icon.Plus className="h-3.5 w-3.5" />{t('workflow.invoices.add')}
          </Button>
        )}
      </div>

      {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

      {invoices.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
          {t(showAdd ? 'workflow.invoices.empty' : 'workflow.invoices.emptyPerGarage')}
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
