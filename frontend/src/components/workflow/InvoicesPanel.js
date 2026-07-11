// Invoices panel — the "One Ticket → Many Invoices" surface on a maintenance ticket.
//
// A ticket worked in more than one garage carries more than one invoice: each is a single garage's bill,
// covering only the faults IT fixed, with its own total, receipt and reconciliation status. This panel
// lists those invoices and lets the team add / edit / delete / reconcile them; the ticket total (shown at
// the top) is the sum of them. The per-invoice editor reuses LineItemsEditor for the parts/labor lines and
// its receipt-validation mode, adds a fault multi-select (which faults this invoice covers) and a receipt
// photo. Every write posts to the backend and then asks the drawer to reload so the numbers stay in sync.

import { useMemo, useState } from 'react';
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

// Map a stored line-item (API shape) back to the editor's row shape.
const toEditorRow = (li) => ({
  kind: li.kind === 'labor' ? 'labor' : 'part',
  finding_text: li.finding_text || '',
  description: li.description || '',
  part_number: li.part_number || '',
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
function InvoiceCard({ invoice, canManage, onEdit, onDelete, onReconcile, busy }) {
  const { t } = useI18n();
  const reconciled = invoice.reconciliation_status === 'reconciled';
  const variance = invoice.variance;
  const hasVar = variance != null && Math.abs(variance) > 0.01;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
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
          {/* Faults this invoice covers */}
          {invoice.faults?.length > 0 && (
            <div className="mt-1.5 flex flex-wrap gap-1">
              {invoice.faults.map((f) => (
                <span key={f.id} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
                  <Icon.Wrench className="h-2.5 w-2.5" />{f.symptom}
                </span>
              ))}
            </div>
          )}
        </div>
        <div className="shrink-0 text-right">
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
function InvoiceEditor({ ticket, invoice, garages, findingsCatalog, onClose, onSaved }) {
  const { t } = useI18n();
  const editing = !!invoice;
  const faults = ticket.tasks || [];

  const [vendorId, setVendorId] = useState(invoice?.vendor_id ? String(invoice.vendor_id) : (ticket.vendor_id ? String(ticket.vendor_id) : ''));
  const [isInternal, setIsInternal] = useState(!!invoice?.is_internal);
  const [invoiceNo, setInvoiceNo] = useState(invoice?.invoice_no || '');
  const [taskIds, setTaskIds] = useState(() => new Set((invoice?.task_ids || []).map(String)));
  const [lineItems, setLineItems] = useState(() => (invoice?.line_items || []).map(toEditorRow));
  const [receiptTotal, setReceiptTotal] = useState(invoice?.receipt_total != null ? String(invoice.receipt_total) : '');
  const [variance, setVariance] = useState(invoice?.variance_explanation || '');
  const [notes, setNotes] = useState(invoice?.notes || '');
  const [photo, setPhoto] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const garageOptions = useMemo(() => garages.map((g) => ({ id: String(g.id), label: g.name, sub: g.phone || g.type })), [garages]);

  const toggleFault = (id) => {
    const next = new Set(taskIds);
    const k = String(id);
    if (next.has(k)) next.delete(k); else next.add(k);
    setTaskIds(next);
  };

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

  const rows = serializeLineItems(lineItems);
  const blocked =
    saving
    || lineItemsUnlinked(lineItems)
    || lineItemsHaveZeroCost(lineItems)
    // A receipt is optional on an invoice, but once entered a mismatch needs an explanation.
    || (receiptTotal !== '' && invoiceVarianceBlocked({ rows, receiptTotal, variance }))
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
            ) : (
              <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.pickGarage')} />
            )}
          </div>
          <Input label={t('workflow.invoices.invoiceNo')} value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} placeholder="e.g. INV-2043" />
        </div>

        {/* Fault multi-select — which faults this invoice covers */}
        <div>
          <p className="mb-1.5 text-sm font-medium text-slate-700">{t('workflow.invoices.faultsCovered')}</p>
          {faults.length === 0 ? (
            <p className="text-xs text-slate-400">{t('workflow.invoices.noFaults')}</p>
          ) : (
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
              {faults.map((f) => {
                const owner = faultOwner[String(f.id)];
                const checked = taskIds.has(String(f.id));
                return (
                  <label key={f.id} className={`flex cursor-pointer items-start gap-2 rounded-lg border px-2.5 py-2 text-sm ${checked ? 'border-indigo-300 bg-indigo-50/60' : 'border-slate-200 bg-white hover:bg-slate-50'}`}>
                    <input type="checkbox" checked={checked} onChange={() => toggleFault(f.id)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                    <span className="min-w-0">
                      <span className="block text-slate-700">{f.symptom}</span>
                      {owner && !checked && (
                        <span className="text-[11px] text-amber-600">{t('workflow.invoices.onOtherInvoice', { no: owner.invoice_no || `#${owner.id}` })}</span>
                      )}
                    </span>
                  </label>
                );
              })}
            </div>
          )}
        </div>

        {/* Parts + labor lines, reconciled against this invoice's receipt total */}
        <LineItemsEditor
          value={lineItems}
          onChange={setLineItems}
          catalog={findingsCatalog}
          findings={ticket.findings || []}
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
            <input type="file" accept="image/*" onChange={(e) => setPhoto(e.target.files?.[0] || null)} className="block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200" />
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
export default function InvoicesPanel({ ticket, garages = [], findingsCatalog = [], canManage = false, onChanged }) {
  const { t } = useI18n();
  const [editor, setEditor] = useState(null); // null | { invoice? }
  const [toDelete, setToDelete] = useState(null);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

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
