// Procurement — what we owe, to whom, for how long, and which suppliers are worth buying from.
//
// Every figure here rests on the structured origin: spend is grouped by the document that proves it, so a
// number can be opened and checked rather than believed. Where a figure cannot be proved, the page says
// so beside it — a spend report that hides its own uncertainty is worse than no report, because it gets
// quoted in decisions.
//
// Three views, in the order finance actually uses them:
//   Payables    — what is owed, aged. The first question every week.
//   Suppliers   — who we buy from, how well they document, how fast they deliver, how often it comes back.
//   Payments    — what has left the account, for reconciliation against the bank.

import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Modal from '../components/ui/Modal';
import Segmented from '../components/ui/Segmented';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import { useI18n } from '../i18n/I18nContext';
import { aed } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const METHODS = [
  ['bank_transfer', 'Bank transfer'],
  ['cash', 'Cash'],
  ['cheque', 'Cheque'],
  ['card', 'Card'],
  ['credit_offset', 'Settled against a credit note'],
];

// Aging bands, oldest last — read left to right as the debt gets worse.
const BUCKET_TONE = {
  '0-30': 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  '0-60': 'bg-amber-50 text-amber-700 ring-amber-200',
  '0-90': 'bg-orange-50 text-orange-700 ring-orange-200',
  '90+':  'bg-red-50 text-red-700 ring-red-200',
};
// Bands count days LATE, not days old — a bill inside its agreed terms is not in any of them.
const BUCKET_LABEL = {
  '0-30': '1–30 days late', '0-60': '31–60 days late', '0-90': '61–90 days late', '90+': 'Over 90 days late',
};

function Stat({ label, value, sub, tone = 'slate' }) {
  const toneClass = {
    slate: 'text-slate-900', red: 'text-red-700', emerald: 'text-emerald-700', amber: 'text-amber-700',
  }[tone] || 'text-slate-900';

  return (
    <Card className="px-5 py-4">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-semibold tabular-nums ${toneClass}`}>{value}</p>
      {sub && <p className="mt-1 text-[11px] text-slate-500">{sub}</p>}
    </Card>
  );
}

// ── Record a payment ──────────────────────────────────────────────────────────────────────────────
function PaymentModal({ open, payables, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const [form, setForm] = useState({});
  const [picked, setPicked] = useState({});   // documentKey → amount
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setForm({ payee_name: '', vendor_id: '', amount: '', method: 'bank_transfer', reference: '', notes: '' });
    setPicked({});
  }, [open]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e?.target ? e.target.value : e }));
  const keyOf = (inv) => `${inv.document_type}:${inv.document_id}`;

  const toggle = (inv) => {
    const k = keyOf(inv);
    setPicked((p) => {
      const next = { ...p };
      if (k in next) delete next[k];
      else next[k] = String(inv.outstanding);
      return next;
    });
  };

  // The payment defaults to exactly what was selected — the overwhelmingly common case.
  const allocatedTotal = useMemo(
    () => Object.values(picked).reduce((s, v) => s + (Number(v) || 0), 0),
    [picked],
  );
  const amount = form.amount === '' ? allocatedTotal : Number(form.amount || 0);
  const overAllocated = allocatedTotal > amount + 0.01;

  const submit = async () => {
    setSaving(true);
    try {
      await api.post('/supplier-payments', {
        vendor_id: form.vendor_id || null,
        payee_name: form.payee_name || null,
        amount,
        method: form.method,
        reference: form.reference || null,
        notes: form.notes || null,
        allocations: Object.entries(picked).map(([k, v]) => {
          const [document_type, document_id] = k.split(':');
          return { document_type, document_id: Number(document_id), amount: Number(v) };
        }),
      });
      toast.success(t('Payment recorded'));
      onDone?.();
      onClose?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not record the payment.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={t('Record a payment')} size="lg">
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Input label={t('Paid to')} value={form.payee_name || ''} onChange={set('payee_name')} placeholder={t('ABC Auto Parts')} />
          <Select label={t('Method')} value={form.method || ''} onChange={set('method')}>
            {METHODS.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
          </Select>
          <Input label={t('Reference')} value={form.reference || ''} onChange={set('reference')} placeholder="TRF-9931" />
          <Input
            label={t('Amount')}
            type="number" min="0" step="0.01"
            value={form.amount || ''}
            onChange={set('amount')}
            placeholder={String(allocatedTotal.toFixed(2))}
          />
        </div>

        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('Bills this payment settles')}
          </p>
          <div className="max-h-56 overflow-y-auto rounded-lg border border-slate-200">
            {payables.length === 0 && (
              <p className="px-3 py-4 text-center text-sm text-slate-400">{t('Nothing is outstanding.')}</p>
            )}
            {payables.map((inv) => {
              const k = keyOf(inv);
              return (
                <div key={k} className="flex items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0">
                  <input type="checkbox" checked={k in picked} onChange={() => toggle(inv)} className="h-4 w-4" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-slate-800">
                      {inv.payee} · {inv.invoice_no || `#${inv.document_id}`}
                    </span>
                    <span className="block text-[11px] text-slate-500">
                      {Number(inv.age_days) === 1
                        ? t('{date} · 1 day old · owes {amount}', { date: inv.date, amount: aed(inv.outstanding) })
                        : t('{date} · {days} days old · owes {amount}', { date: inv.date, days: inv.age_days, amount: aed(inv.outstanding) })}
                    </span>
                  </span>
                  {k in picked && (
                    <input
                      type="number" min="0" step="0.01"
                      value={picked[k]}
                      onChange={(e) => setPicked((p) => ({ ...p, [k]: e.target.value }))}
                      className="w-28 rounded border border-slate-300 px-2 py-1 text-sm tabular-nums"
                    />
                  )}
                </div>
              );
            })}
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
          <div className="flex justify-between py-0.5">
            <span className="text-slate-600">{t('Allocated to bills')}</span>
            <span className="tabular-nums">{aed(allocatedTotal)}</span>
          </div>
          <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold">
            <span>{t('Payment amount')}</span><span className="tabular-nums">{aed(amount)}</span>
          </div>
          {amount > allocatedTotal + 0.01 && (
            <p className="mt-1 text-[11px] text-slate-500">
              {t('{amount} will sit as unallocated credit with this payee — you can point it at a bill later.', { amount: aed(amount - allocatedTotal) })}
            </p>
          )}
          {overAllocated && (
            <p className="mt-1 text-[12px] font-medium text-red-600">
              {t('The bills selected come to more than the payment.')}
            </p>
          )}
        </div>

        <Textarea label={t('Notes')} rows={2} value={form.notes || ''} onChange={set('notes')} />

        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('Cancel')}</Button>
          <Button loading={saving} disabled={overAllocated || amount <= 0} onClick={submit}>{t('Record payment')}</Button>
        </div>
      </div>
    </Modal>
  );
}

// ── The page ──────────────────────────────────────────────────────────────────────────────────────
export default function Procurement() {
  const { t } = useI18n();
  const { can } = usePermissions();
  const canPay = can('maintenance.manage');

  const [tab, setTab] = useState('payables');
  const [overview, setOverview] = useState(null);
  const [payables, setPayables] = useState(null);
  const [suppliers, setSuppliers] = useState([]);
  const [payments, setPayments] = useState(null);
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [o, p, s, pm] = await Promise.all([
        api.get('/procurement/overview').catch(() => null),
        api.get('/procurement/payables').catch(() => null),
        api.get('/procurement/suppliers').catch(() => null),
        api.get('/procurement/payments').catch(() => null),
      ]);
      setOverview(payload(o));
      setPayables(payload(p));
      setSuppliers(payload(s) || []);
      setPayments(payload(pm));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const trace = overview?.traceability;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Procurement')}
          subtitle={t('What we owe, what we have paid, and who we buy from — every figure grouped by the document that proves it.')}
          actions={canPay && <Button onClick={() => setPaying(true)}>{t('Record a payment')}</Button>}
        />

        {SHOW_FINANCIALS && overview && (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label={t('Committed')} value={aed(overview.committed)} sub={t('Approved bills, both kinds')} />
            <Stat label={t('Paid')} value={aed(overview.paid)} tone="emerald" />
            <Stat
              label={t('Outstanding')}
              value={aed(overview.outstanding)}
              tone={overview.outstanding > 0 ? 'red' : 'slate'}
              sub={overview.unallocated_payments > 0 ? t('{amount} paid on account', { amount: aed(overview.unallocated_payments) }) : null}
            />
            {/* The honesty figure travels WITH the money, never on a separate screen. */}
            <Stat
              label={t('Provable spend')}
              value={`${trace?.coverage_pct ?? 0}%`}
              tone={(trace?.coverage_pct ?? 0) >= 90 ? 'emerald' : 'amber'}
              sub={trace ? t('{legacy} legacy · {unverified} unverified', { legacy: aed(trace.legacy_cost), unverified: aed(trace.unverified_cost) }) : null}
            />
          </div>
        )}

        <Segmented
          value={tab}
          onChange={setTab}
          options={[
            { key: 'payables', label: t('Payables') },
            { key: 'suppliers', label: t('Suppliers') },
            { key: 'payments', label: t('Payments') },
          ]}
        />

        {loading && <Card><TableSkeleton cols={5} /></Card>}

        {/* ── Payables ── */}
        {!loading && tab === 'payables' && payables && (
          <>
            {/* What is LATE is a different question from what is owed, and only one of them is a
                problem. Ageing against agreed terms is what stops people chasing the patient supplier. */}
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2">
                <p className="text-[10px] font-medium uppercase tracking-wide text-red-600">{t('Overdue')}</p>
                <p className="text-lg font-semibold tabular-nums text-red-800">
                  {SHOW_FINANCIALS ? aed(payables.overdue_total) : '—'}
                </p>
                <p className="text-[11px] text-red-600">
                  {payables.overdue_count === 1
                    ? t('1 bill past its agreed terms')
                    : t('{n} bills past their agreed terms', { n: payables.overdue_count })}
                </p>
              </div>
              <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                <p className="text-[10px] font-medium uppercase tracking-wide text-slate-500">{t('Not yet due')}</p>
                <p className="text-lg font-semibold tabular-nums text-slate-700">
                  {SHOW_FINANCIALS ? aed(payables.not_yet_due_total) : '—'}
                </p>
                <p className="text-[11px] text-slate-500">{t('Owed, but still inside terms')}</p>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-4">
              {Object.entries(payables.buckets).map(([bucket, amount]) => (
                <div key={bucket} className={`rounded-lg px-3 py-2 ring-1 ring-inset ${BUCKET_TONE[bucket] || BUCKET_TONE['0-30']}`}>
                  <p className="text-[10px] font-medium uppercase tracking-wide">{BUCKET_LABEL[bucket] ? t(BUCKET_LABEL[bucket]) : bucket}</p>
                  <p className="text-lg font-semibold tabular-nums">{SHOW_FINANCIALS ? aed(amount) : '—'}</p>
                </div>
              ))}
            </div>

            <Card>
              <div className="overflow-x-auto">
                <table className="min-w-full border-separate border-spacing-0 text-sm">
                  <thead className="bg-slate-50/90">
                    <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Payee')}</th>
                      <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Invoice')}</th>
                      <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Age')}</th>
                      <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Total')}</th>
                      <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Outstanding')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {payables.invoices.map((i) => (
                      <tr key={`${i.document_type}-${i.document_id}`} className="bg-white even:bg-slate-50/40">
                        <td className="border-b border-slate-100 px-5 py-3">
                          <div className="font-medium text-slate-900">{i.payee}</div>
                          <div className="text-xs text-slate-400">
                            {i.document_type === 'supplier_invoice' ? t('Supplier') : t('Garage')}
                          </div>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3 text-slate-700">
                          {i.invoice_no || `#${i.document_id}`}
                          <div className="text-xs text-slate-400">{i.date}</div>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3">
                          {i.overdue ? (
                            <span className={`rounded px-1.5 py-0.5 text-[11px] ring-1 ring-inset ${BUCKET_TONE[i.bucket]}`}>
                              {t('{n}d late', { n: i.days_overdue })}
                            </span>
                          ) : (
                            <span className="text-[11px] text-slate-400">
                              {i.days_overdue != null ? t('due in {n}d', { n: Math.abs(i.days_overdue) }) : '—'}
                            </span>
                          )}
                          {i.due_date && (
                            <div className="text-[10px] text-slate-400">
                              {i.terms_days != null
                                ? t('due {date} · net {n}', { date: i.due_date, n: i.terms_days })
                                : t('due {date}', { date: i.due_date })}
                            </div>
                          )}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3 text-end tabular-nums text-slate-600">
                          {SHOW_FINANCIALS ? aed(i.total) : '—'}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3 text-end font-semibold tabular-nums text-slate-900">
                          {SHOW_FINANCIALS ? aed(i.outstanding) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {payables.invoices.length === 0 && (
                  <EmptyState title={t('Nothing outstanding')} message={t('Every approved bill has been settled.')} />
                )}
              </div>
            </Card>
          </>
        )}

        {/* ── Suppliers ── */}
        {!loading && tab === 'suppliers' && (
          <Card>
            <div className="overflow-x-auto">
              <table className="min-w-full border-separate border-spacing-0 text-sm">
                <thead className="bg-slate-50/90">
                  <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Supplier')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Net spend')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Documented')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Avg delivery')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Returns')}</th>
                  </tr>
                </thead>
                <tbody>
                  {suppliers.map((s) => (
                    <tr key={s.supplier} className="bg-white even:bg-slate-50/40">
                      <td className="border-b border-slate-100 px-5 py-3">
                        <div className="font-medium text-slate-900">{s.supplier}</div>
                        <div className="text-xs text-slate-400">
                          {s.purchases === 1 ? t('1 purchase') : t('{n} purchases', { n: s.purchases })}
                        </div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end tabular-nums text-slate-900">
                        {SHOW_FINANCIALS ? aed(s.net_spend) : '—'}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end">
                        <Badge tone={s.documentation_pct >= 90 ? 'green' : s.documentation_pct >= 50 ? 'amber' : 'red'}>
                          {s.documentation_pct}%
                        </Badge>
                        {SHOW_FINANCIALS && s.undocumented > 0 && (
                          <div className="text-[11px] text-amber-700">{t('{amount} unproven', { amount: aed(s.undocumented) })}</div>
                        )}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end tabular-nums text-slate-600">
                        {/* Never show an unmeasured lead time as a good one. */}
                        {s.avg_lead_days == null
                          ? <span className="text-slate-300" title={t('No delivery dates recorded')}>{t('not measured')}</span>
                          : `${s.avg_lead_days}d`}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end">
                        <Badge tone={s.return_rate_pct > 15 ? 'red' : s.return_rate_pct > 0 ? 'amber' : 'gray'}>
                          {s.return_rate_pct}%
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {suppliers.length === 0 && (
                <EmptyState title={t('No supplier purchases yet')} message={t('Buy a part from a supplier and it will appear here.')} />
              )}
            </div>
          </Card>
        )}

        {/* ── Payments ── */}
        {!loading && tab === 'payments' && payments && (
          <Card>
            <div className="overflow-x-auto">
              <table className="min-w-full border-separate border-spacing-0 text-sm">
                <thead className="bg-slate-50/90">
                  <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Date')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Payee')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-start">{t('Method')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Amount')}</th>
                    <th className="border-b border-slate-200 px-5 py-3 text-end">{t('Unallocated')}</th>
                  </tr>
                </thead>
                <tbody>
                  {payments.payments.map((p) => (
                    <tr key={p.id} className="bg-white even:bg-slate-50/40">
                      <td className="border-b border-slate-100 px-5 py-3 text-slate-700">{p.date}</td>
                      <td className="border-b border-slate-100 px-5 py-3">
                        <div className="font-medium text-slate-900">{p.payee}</div>
                        <div className="text-xs text-slate-400">{p.recorded_by}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-slate-600">
                        {p.method_label}
                        {p.reference && <div className="text-xs text-slate-400">{p.reference}</div>}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end tabular-nums text-slate-900">
                        {SHOW_FINANCIALS ? aed(p.amount) : '—'}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3 text-end tabular-nums">
                        {p.unallocated > 0
                          ? <span className="text-amber-700">{aed(p.unallocated)}</span>
                          : <span className="text-slate-300">—</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {payments.payments.length === 0 && (
                <EmptyState title={t('No payments recorded')} message={t('Record a payment and it will appear here.')} />
              )}
            </div>
          </Card>
        )}
      </div>

      <PaymentModal
        open={paying}
        payables={payables?.invoices || []}
        onClose={() => setPaying(false)}
        onDone={load}
      />
    </div>
  );
}
