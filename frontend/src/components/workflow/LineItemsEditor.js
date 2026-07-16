// Structured Parts + Labor editor — the maintenance-action breakdown the garage step (and the
// deferred "Edit parts & labor" action) use to itemise a ticket's cost. Each row is a PART
// (name · part no. · qty · unit price · install date · warranty) or a LABOR charge (description ·
// hours · rate). The component owns no money rules: it just collects the structured inputs the
// backend re-sums into parts_total + labor_total = cost, and that later map onto Odoo BOM / Expense
// lines. Totals are shown live so the entry UX has the requested auto-sum.
//
// Value contract (array of rows) is exactly what the API consumes — see MaintenanceWorkflowService
// ::applyLineItems: { kind:'part'|'labor', description, finding_text, part_number?, category_key?,
// quantity, unit_price, installed_on?, warranty_months? }. `quantity` doubles as labor HOURS,
// `unit_price` as the hourly RATE.
//
// Diagnosis-First: every line MUST be linked to a finding/symptom already on the ticket (the
// "Link to symptom" dropdown), so there are no ghost costs — every dirham maps to a diagnosed
// fault. With no findings on the ticket the Add buttons are disabled (nothing to attribute spend to).

import { useMemo } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import { Input } from '../ui/Field';

// A stable client-side row key so React doesn't lose focus as rows are added/removed. We never send
// it to the server — it's stripped on submit by the caller (only the contract keys are forwarded).
let _seq = 0;
const nextKey = () => `li-${++_seq}`;

// The findings-catalog category key for tyres — when a part is filed under it, the editor reveals a
// small Tire Details capture (brand / DOT / tread) so a fitted tyre leaves an audit trail.
const TIRE_CATEGORY = 'tyres';

const emptyPart = () => ({ _k: nextKey(), kind: 'part', finding_text: '', description: '', part_number: '', category_key: '', quantity: '1', unit_price: '', installed_on: '', warranty_months: '', tire_brand: '', tire_dot: '', tire_tread_mm: '' });
// Labor tracks total HOURS spent on the fault and the TOTAL COST of the job — not an hourly rate.
// `quantity` stores the hours and `unit_price` stores an internally-derived rate (total ÷ hours) so
// the shared `lineAmount` (quantity × unit_price) still reduces to the total the user actually typed;
// the rate itself is never shown.
const emptyLabor = () => ({ _k: nextKey(), kind: 'labor', finding_text: '', description: '', quantity: '1', unit_price: '' });

const money = (n) => (Number.isFinite(n) ? `AED ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'AED 0.00');
const lineAmount = (row) => Math.max(0, Number(row.quantity) || 0) * Math.max(0, Number(row.unit_price) || 0);

// A receipt total is treated as "matching" the itemised sum when they agree to within a cent.
const VARIANCE_TOLERANCE = 0.01;

export default function LineItemsEditor({
  value = [],
  onChange,
  catalog = [],
  findings = [],
  // Invoice-validation mode — when true, the editor also collects the garage's printed "Receipt total"
  // and reconciles it against the itemised sum (mandatory variance note on any mismatch). Off by default
  // so the plain garage "mark ready" step keeps the light editor.
  requireReceipt = false,
  receiptTotal = '',
  onReceiptTotalChange,
  variance = '',
  onVarianceChange,
}) {
  const { t } = useI18n();

  // Ensure every incoming row carries a client key (rows loaded from the server won't have one).
  const rows = useMemo(() => value.map((r) => (r._k ? r : { ...r, _k: nextKey() })), [value]);
  const parts = rows.filter((r) => r.kind === 'part');
  const labor = rows.filter((r) => r.kind === 'labor');

  const partsTotal = parts.reduce((a, r) => a + lineAmount(r), 0);
  const laborTotal = labor.reduce((a, r) => a + lineAmount(r), 0);
  const grandTotal = partsTotal + laborTotal;

  // Receipt reconciliation (invoice-form mode): the signed variance = itemised − receipt, rounded to the
  // cent and treated as zero within tolerance. `receiptEntered` gates the whole verdict/variance UI.
  const receiptNum = Number(receiptTotal);
  const receiptEntered = receiptTotal !== '' && receiptTotal != null && Number.isFinite(receiptNum);
  const rawVariance = receiptEntered ? Math.round((grandTotal - receiptNum) * 100) / 100 : 0;
  const varianceAmount = Math.abs(rawVariance) <= VARIANCE_TOLERANCE ? 0 : rawVariance;

  // The ticket's diagnostic findings (inspector + garage), deduped by text — the only things a line
  // may be attributed to. Drives the "Link to symptom" dropdown AND the add-button gate.
  const findingOptions = useMemo(() => {
    const seen = new Set();
    return (findings || [])
      .map((f) => (typeof f === 'string' ? { text: f } : f))
      .filter((f) => f && (f.text || '').trim())
      .filter((f) => { const k = f.text.trim().toLowerCase(); if (seen.has(k)) return false; seen.add(k); return true; })
      .map((f) => ({ value: f.text, label: f.text, source: f.source }));
  }, [findings]);
  const hasFindings = findingOptions.length > 0;
  // With exactly one finding there's no ambiguity — pre-link new rows to it; otherwise force a choice.
  const defaultFinding = findingOptions.length === 1 ? findingOptions[0].value : '';

  const categoryOptions = useMemo(
    () => (catalog || []).map((c) => ({ value: c.key, label: c.label })),
    [catalog],
  );

  // Keyword → category lookup, built from the catalog (each category lists the symptom keywords it owns).
  // Lets a part's Category fill itself from the symptom it's already linked to — no second manual pick.
  // A free-text / custom finding that isn't a catalog keyword has no category to infer, so it stays manual.
  const categoryForFinding = useMemo(() => {
    const map = {};
    (catalog || []).forEach((c) => (c.keywords || []).forEach((kw) => {
      const k = (kw || '').trim().toLowerCase();
      if (k) map[k] = c.key;
    }));
    return map;
  }, [catalog]);
  const deriveCategory = (findingText) => categoryForFinding[(findingText || '').trim().toLowerCase()] || '';
  const categoryLabel = (key) => categoryOptions.find((c) => c.value === key)?.label || key;

  const set = (next) => onChange?.(next);
  const update = (k, patch) => set(rows.map((r) => (r._k === k ? { ...r, ...patch } : r)));
  const remove = (k) => set(rows.filter((r) => r._k !== k));
  // Linking a symptom auto-derives the part's category from it (when the symptom is a known catalog
  // keyword); a no-match link leaves the existing manual choice alone.
  const linkFinding = (k, text) => {
    const derived = deriveCategory(text);
    update(k, derived ? { finding_text: text, category_key: derived } : { finding_text: text });
  };
  const addPart = () => hasFindings && set([...rows, { ...emptyPart(), finding_text: defaultFinding, category_key: deriveCategory(defaultFinding) }]);
  const addLabor = () => hasFindings && set([...rows, { ...emptyLabor(), finding_text: defaultFinding }]);

  // Labor total-cost display — the number the user actually typed, reconstructed from quantity ×
  // unit_price. Blank while the row is untouched so the input doesn't show "0" before entry.
  const laborTotalDisplay = (row) => (row.quantity === '' && row.unit_price === '' ? '' : String(Math.round(lineAmount(row) * 100) / 100));
  // Changing HOURS keeps the total cost fixed and re-solves the hidden rate (total ÷ hours) so the
  // backend's quantity × unit_price recompute still lands on the number the user entered.
  const setLaborHours = (row, hoursStr) => {
    const hours = Number(hoursStr) || 0;
    const total = lineAmount(row);
    update(row._k, { quantity: hoursStr, unit_price: String(hours > 0 ? total / hours : total) });
  };
  // Changing TOTAL COST keeps the hours fixed and re-solves the hidden rate the same way.
  const setLaborTotal = (row, totalStr) => {
    const total = Number(totalStr) || 0;
    const hours = Number(row.quantity) || 0;
    update(row._k, { unit_price: String(hours > 0 ? total / hours : total) });
  };

  // One "Link to symptom" dropdown — required on every line. Reds out until a finding is chosen so
  // the Diagnosis-First gate is obvious at a glance.
  const FindingLink = ({ row }) => (
    <div>
      <label className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.lineItem.linkToSymptom')}<span className="text-red-500"> *</span></label>
      <select
        value={row.finding_text || ''}
        onChange={(e) => (row.kind === 'part' ? linkFinding(row._k, e.target.value) : update(row._k, { finding_text: e.target.value }))}
        className={`w-full rounded-lg border px-2.5 py-2 text-sm focus:outline-none focus:ring-1 ${row.finding_text ? 'border-slate-300 text-slate-700 focus:border-indigo-400 focus:ring-indigo-400' : 'border-red-300 text-red-700 focus:border-red-400 focus:ring-red-400'}`}
      >
        <option value="">{t('workflow.lineItem.selectSymptom')}</option>
        {findingOptions.map((f) => (
          <option key={f.value} value={f.value}>{f.label}{f.source ? ` · ${f.source}` : ''}</option>
        ))}
      </select>
    </div>
  );

  return (
    <div className="space-y-5">
      <p className="text-xs text-slate-400">{t('workflow.lineItem.hint')}</p>

      {/* Diagnosis-First gate — no findings means nothing to attribute spend to. */}
      {!hasFindings && (
        <div className="flex items-start gap-2 rounded-lg bg-amber-50/70 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/10">
          <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>{t('workflow.lineItem.noFindings')}</span>
        </div>
      )}

      {/* ── PARTS ─────────────────────────────────────────────────────────── */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-indigo-100 bg-indigo-50/60 px-3 py-2.5">
          <span className="flex items-center gap-2 text-sm font-semibold text-slate-700">
            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
              <Icon.Wrench className="h-3.5 w-3.5" />
            </span>
            {t('workflow.lineItem.parts')}
            {parts.length > 0 && (
              <span className="rounded-full bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-indigo-600">{parts.length}</span>
            )}
          </span>
          <button type="button" onClick={addPart} disabled={!hasFindings} className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent">
            {t('workflow.lineItem.addPart')}
          </button>
        </div>

        <div className="p-3">
        {parts.length === 0 ? (
          <p className="px-1 py-2 text-xs text-slate-400">{t('workflow.lineItem.empty')}</p>
        ) : (
          <ul className="space-y-3">
            {parts.map((r) => (
              <li key={r._k} className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                {/* Header row — diagnosis link (left, the required bit) + remove (always reachable, top-right). */}
                <div className="mb-3 flex items-start gap-2">
                  <div className="flex-1"><FindingLink row={r} /></div>
                  <button type="button" onClick={() => remove(r._k)} className="mt-6 shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-red-500 hover:bg-red-50">{t('common.remove')}</button>
                </div>

                {/* Identity — what the part is. */}
                <div className="grid grid-cols-12 gap-2">
                  <div className="col-span-12 sm:col-span-8">
                    <Input label={t('workflow.lineItem.partName')} value={r.description} onChange={(e) => update(r._k, { description: e.target.value })} placeholder="e.g. Front brake pads" />
                  </div>
                  <div className="col-span-12 sm:col-span-4">
                    <Input label={t('workflow.lineItem.partNumber')} value={r.part_number} onChange={(e) => update(r._k, { part_number: e.target.value })} placeholder="OEM / SKU" />
                  </div>
                </div>

                {/* Pricing — qty × unit price = line total, grouped so the math reads left to right. */}
                <div className="mt-2.5 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-2">
                  <div className="w-16">
                    <Input label={t('workflow.lineItem.qty')} type="number" min="0" step="1" value={r.quantity} onChange={(e) => update(r._k, { quantity: e.target.value })} />
                  </div>
                  <span className="pb-2 text-sm text-slate-300">×</span>
                  <div className="w-28">
                    <Input label={t('workflow.lineItem.unitPrice')} type="number" min="0" step="0.01" value={r.unit_price} onChange={(e) => update(r._k, { unit_price: e.target.value })} placeholder="0.00" />
                  </div>
                  <span className="pb-2 text-sm text-slate-300">=</span>
                  <span className="pb-2 text-sm font-semibold tabular-nums text-slate-700">{money(lineAmount(r))}</span>
                </div>

                {/* Details — durability + Odoo grouping, boxed to match the pricing strip above. */}
                <div className="mt-2.5 rounded-lg border border-slate-200 bg-white p-2.5">
                  <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <Icon.Shield className="h-3 w-3" />{t('workflow.lineItem.details')}
                  </p>
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                      <Input label={t('workflow.lineItem.installedOn')} type="date" value={r.installed_on} onChange={(e) => update(r._k, { installed_on: e.target.value })} />
                    </div>
                    <div>
                      <Input label={t('workflow.lineItem.warrantyMonths')} type="number" min="0" step="1" value={r.warranty_months} onChange={(e) => update(r._k, { warranty_months: e.target.value })} placeholder="0" />
                    </div>
                    <div className="col-span-2">
                      <label className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.lineItem.category')}</label>
                      {deriveCategory(r.finding_text) ? (
                        // Auto-filled from the linked symptom — read-only so it can't drift from the diagnosis.
                        <div className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-100/70 px-2.5 py-2 text-sm text-slate-600">
                          <Icon.Check className="h-3.5 w-3.5 text-emerald-500" />
                          <span>{categoryLabel(r.category_key)}</span>
                          <span className="ml-auto text-[10px] uppercase tracking-wide text-slate-400">{t('workflow.lineItem.categoryAuto')}</span>
                        </div>
                      ) : (
                        <select
                          value={r.category_key || ''}
                          onChange={(e) => update(r._k, { category_key: e.target.value })}
                          className="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm text-slate-700 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                        >
                          <option value="">{t('workflow.lineItem.none')}</option>
                          {categoryOptions.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </select>
                      )}
                    </div>
                  </div>
                </div>

                {/* Tire Details — revealed only when this part is categorised as tyres. Captures the
                    brand, DOT batch code and tread-at-install so a fitted tyre has an audit trail. */}
                {r.category_key === TIRE_CATEGORY && (
                  <div className="mt-2 rounded-lg border border-indigo-100 bg-indigo-50/40 p-2.5">
                    <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-indigo-700">
                      <span aria-hidden>🛞</span>{t('workflow.lineItem.tireDetails')}
                    </div>
                    <div className="grid grid-cols-12 gap-2">
                      <div className="col-span-12 sm:col-span-5">
                        <Input label={t('workflow.lineItem.tireBrand')} value={r.tire_brand || ''} onChange={(e) => update(r._k, { tire_brand: e.target.value })} placeholder="e.g. Michelin" />
                      </div>
                      <div className="col-span-6 sm:col-span-4">
                        <Input label={t('workflow.lineItem.tireDot')} value={r.tire_dot || ''} onChange={(e) => update(r._k, { tire_dot: e.target.value })} placeholder="e.g. DOT 3223" />
                      </div>
                      <div className="col-span-6 sm:col-span-3">
                        <Input label={t('workflow.lineItem.tireTread')} type="number" min="0" step="0.1" value={r.tire_tread_mm ?? ''} onChange={(e) => update(r._k, { tire_tread_mm: e.target.value })} placeholder="mm" />
                      </div>
                    </div>
                    <p className="mt-1.5 text-[11px] text-indigo-400">{t('workflow.lineItem.tireHint')}</p>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 px-1 text-[11px] text-slate-400">{t('workflow.lineItem.durabilityNote')}</p>
        </div>
      </div>

      {/* ── LABOR ─────────────────────────────────────────────────────────── */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-sky-100 bg-sky-50/60 px-3 py-2.5">
          <span className="flex items-center gap-2 text-sm font-semibold text-slate-700">
            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
              <Icon.Clock className="h-3.5 w-3.5" />
            </span>
            {t('workflow.lineItem.labor')}
            {labor.length > 0 && (
              <span className="rounded-full bg-sky-100 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-sky-600">{labor.length}</span>
            )}
          </span>
          <button type="button" onClick={addLabor} disabled={!hasFindings} className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent">
            {t('workflow.lineItem.addLabor')}
          </button>
        </div>

        <div className="p-3">
        {labor.length === 0 ? (
          <p className="px-1 py-2 text-xs text-slate-400">{t('workflow.lineItem.empty')}</p>
        ) : (
          <ul className="space-y-3">
            {labor.map((r) => (
              <li key={r._k} className="rounded-lg border border-slate-100 bg-slate-50/60 p-2.5">
                {/* Diagnosis-First — which symptom this labor addresses (required). */}
                <div className="mb-2"><FindingLink row={r} /></div>
                <div className="grid grid-cols-12 items-end gap-2">
                <div className="col-span-12 sm:col-span-6">
                  <Input label={t('workflow.lineItem.laborDesc')} value={r.description} onChange={(e) => update(r._k, { description: e.target.value })} placeholder="e.g. Front brake job" />
                </div>
                <div className="col-span-6 sm:col-span-2">
                  <Input label={t('workflow.lineItem.hours')} type="number" min="0" step="0.5" value={r.quantity} onChange={(e) => setLaborHours(r, e.target.value)} placeholder="0" />
                </div>
                <div className="col-span-6 sm:col-span-2">
                  <Input label={t('workflow.lineItem.totalCost')} type="number" min="0" step="0.01" value={laborTotalDisplay(r)} onChange={(e) => setLaborTotal(r, e.target.value)} placeholder="0.00" />
                </div>
                <div className="col-span-12 flex items-center justify-end sm:col-span-2">
                  <button type="button" onClick={() => remove(r._k)} className="rounded-lg px-2 py-1 text-xs font-medium text-red-500 hover:bg-red-50">{t('common.remove')}</button>
                </div>
                </div>
              </li>
            ))}
          </ul>
        )}
        </div>
      </div>

      {/* ── TOTALS (the requested auto-sum) ───────────────────────────────── */}
      <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-xl bg-slate-900 px-5 py-4 shadow-sm">
        <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-slate-400">
          <span>{t('workflow.lineItem.partsTotal')}: <span className="font-semibold tabular-nums text-slate-200">{money(partsTotal)}</span></span>
          <span>{t('workflow.lineItem.laborTotal')}: <span className="font-semibold tabular-nums text-slate-200">{money(laborTotal)}</span></span>
        </div>
        <div className="flex items-baseline gap-2 border-l border-white/10 pl-5">
          <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.lineItem.grandTotal')}</span>
          <span className="text-2xl font-bold tabular-nums text-white">{money(grandTotal)}</span>
        </div>
      </div>

      {/* ── RECEIPT VALIDATION (invoice-form mode) ────────────────────────────
          The itemised sum above is reconciled against the garage's printed receipt total. Any gap must
          be explained before the invoice can be saved — the mandatory variance note the backend enforces. */}
      {requireReceipt && (
        <div className="rounded-xl border border-slate-200 bg-white p-3">
          <div className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
            <Icon.Invoice className="h-4 w-4 text-slate-400" />{t('workflow.lineItem.receiptHeading')}
          </div>
          <div className="grid grid-cols-12 items-end gap-3">
            <div className="col-span-12 sm:col-span-5">
              <Input
                label={t('workflow.lineItem.receiptTotal')}
                type="number"
                min="0"
                step="0.01"
                value={receiptTotal}
                onChange={(e) => onReceiptTotalChange?.(e.target.value)}
                placeholder="0.00"
              />
            </div>
            <div className="col-span-12 sm:col-span-7">
              {/* Live reconciliation verdict — matched / off by AED N. */}
              {!receiptEntered ? (
                <p className="text-xs text-slate-400">{t('workflow.lineItem.receiptHint')}</p>
              ) : varianceAmount === 0 ? (
                <p className="flex items-center gap-1.5 text-xs font-medium text-emerald-600">
                  <Icon.Check className="h-3.5 w-3.5" />{t('workflow.lineItem.receiptMatches')}
                </p>
              ) : (
                <p className="flex items-center gap-1.5 text-xs font-semibold text-amber-600">
                  <Icon.Alert className="h-3.5 w-3.5" />
                  {t('workflow.lineItem.receiptVariance', { amount: money(Math.abs(varianceAmount)), dir: varianceAmount > 0 ? t('workflow.lineItem.over') : t('workflow.lineItem.under') })}
                </p>
              )}
            </div>
          </div>

          {/* Mandatory only when the numbers actually disagree — mirror of the server gate. */}
          {receiptEntered && varianceAmount !== 0 && (
            <div className="mt-3">
              <label className="mb-1 block text-sm font-medium text-slate-700">
                {t('workflow.lineItem.varianceExplanation')}<span className="text-red-500"> *</span>
              </label>
              <textarea
                value={variance}
                onChange={(e) => onVarianceChange?.(e.target.value)}
                rows={2}
                placeholder={t('workflow.lineItem.variancePlaceholder')}
                className={`w-full rounded-lg border px-2.5 py-2 text-sm focus:outline-none focus:ring-1 ${variance.trim() ? 'border-slate-300 text-slate-700 focus:border-indigo-400 focus:ring-indigo-400' : 'border-red-300 text-red-700 focus:border-red-400 focus:ring-red-400'}`}
              />
              {!variance.trim() && <p className="mt-1 text-[11px] text-red-500">{t('workflow.lineItem.varianceRequired')}</p>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// Strip client-only keys + empty rows down to the API contract. Exported so both the garage step
// and the deferred-edit action serialise the editor identically.
export function serializeLineItems(rows = []) {
  return rows
    .filter((r) => (r.description || '').trim() !== '')
    .map((r) => {
      const base = {
        kind: r.kind === 'labor' ? 'labor' : 'part',
        // Diagnosis-First: the finding this line is attributed to (required, validated server-side).
        finding_text: (r.finding_text || '').trim(),
        description: r.description.trim(),
        quantity: r.quantity === '' || r.quantity == null ? 1 : Number(r.quantity),
        unit_price: r.unit_price === '' || r.unit_price == null ? 0 : Number(r.unit_price),
      };
      if (base.kind === 'part') {
        if (r.part_number) base.part_number = r.part_number.trim();
        if (r.category_key) base.category_key = r.category_key;
        if (r.installed_on) base.installed_on = r.installed_on;
        if (r.warranty_months !== '' && r.warranty_months != null) base.warranty_months = Number(r.warranty_months);
        // Tire audit trail — only meaningful (and only captured) on a tyres-category line.
        if ((r.tire_brand || '').trim()) base.tire_brand = r.tire_brand.trim();
        if ((r.tire_dot || '').trim()) base.tire_dot = r.tire_dot.trim();
        if (r.tire_tread_mm !== '' && r.tire_tread_mm != null) base.tire_tread_mm = Number(r.tire_tread_mm);
      }
      return base;
    });
}

// Diagnosis-First submit gate: true when any line carrying a description is not yet linked to a
// finding. The modal blocks the save until every cost is attributed to a symptom.
export function lineItemsUnlinked(rows = []) {
  return rows.some((r) => (r.description || '').trim() && !(r.finding_text || '').trim());
}

// The live itemised grand total (Σ quantity × unit price) — the auto-sum the receipt is checked against.
export function lineItemsGrandTotal(rows = []) {
  return rows.reduce((a, r) => a + lineAmount(r), 0);
}

// Cost-integrity gate (mirrors the server's gt:0 rule): true when any described line has a zero/blank
// quantity or unit price — i.e. a line with no real charge. The modal blocks the save so the user
// fixes it here rather than bouncing off a 422.
export function lineItemsHaveZeroCost(rows = []) {
  return rows.some((r) => (r.description || '').trim() && lineAmount(r) <= 0);
}

// Invoice-validation submit gate (mirrors the server): once there's an itemised line, a positive receipt
// total is REQUIRED, and any mismatch beyond a cent REQUIRES a variance explanation. Returns true = block.
export function invoiceVarianceBlocked({ rows = [], receiptTotal = '', variance = '' } = {}) {
  const hasLines = rows.some((r) => (r.description || '').trim());
  if (!hasLines) return false; // nothing itemised yet — nothing to reconcile

  const receipt = Number(receiptTotal);
  if (receiptTotal === '' || receiptTotal == null || !Number.isFinite(receipt) || receipt <= 0) return true;

  const mismatch = Math.abs(lineItemsGrandTotal(rows) - receipt) > 0.01;
  return mismatch && !(variance || '').trim();
}
