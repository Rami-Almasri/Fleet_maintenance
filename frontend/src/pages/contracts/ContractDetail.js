import { useCallback, useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge, { ContractTypeBadge, ContractStateBadge } from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { Card, Spinner, ErrorState } from '../../components/ui/Misc';
import { CommandPanel } from '../../components/ops';
import ExchangeChainPanel from '../../components/ExchangeChainPanel';
import VisitJourneyPanel from '../../components/contracts/VisitJourneyPanel';
import WorkshopEvents from '../../components/WorkshopEvents';
import ContractInvoices from '../../components/ContractInvoices';
import ContractPayments from '../../components/ContractPayments';
import BillingReconciliation from '../../components/BillingReconciliation';
import ReadinessPanel from '../../components/readiness/ReadinessPanel';
import { aed2, fmtDate, fmtTime, combineDateTime, fmtDuration, num } from '../../lib/format';
import { SHOW_FINANCIALS } from '../../config/features';
import { useI18n } from '../../i18n/I18nContext';

// Renders a card with a label/value grid. Pairs = [[label, value], ...]
function Section({ title, pairs }) {
  const visible = pairs.filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '—');
  if (visible.length === 0) return null;
  return (
    <CommandPanel title={title} dotColor="#22d3ee">
      <div className="grid grid-cols-1 gap-x-8 gap-y-1 sm:grid-cols-2">
        {visible.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4 py-1.5 text-sm" style={{ borderBottom: '1px solid var(--line)' }}>
            <span style={{ color: 'var(--ink-3)' }}>{label}</span>
            <span className="text-end font-medium" style={{ color: 'var(--ink)' }}>{value}</span>
          </div>
        ))}
      </div>
    </CommandPanel>
  );
}

const money = (v) => (v === null || v === undefined || v === '' ? null : aed2(v));

// Verdict styling for the contract-level Net Profit / reconciliation badge.
const RECON_STATUS = {
  reconciled: { label: 'Reconciled', tone: 'green', ring: 'ring-emerald-200', sub: 'Net cash collected matches the amount billed, within tolerance.' },
  review: { label: 'Needs review', tone: 'amber', ring: 'ring-amber-200', sub: 'Gap is beyond the 2% fee/rounding tolerance — worth a look.' },
  exception: { label: 'Exception', tone: 'red', ring: 'ring-red-200', sub: 'A reconciliation problem was found on this contract.' },
  error: { label: 'Unavailable', tone: 'gray', ring: 'ring-slate-200', sub: 'Could not read the accounting feed (the server may be slow). Reload to retry.' },
};

/**
 * Daily-glance Net Profit for one contract = Net Collected − Billed, reconciled live against the
 * accounting system. Shows the headline figure + a Reconciled / Needs Review / Exception badge. The
 * live call is async so the rest of the page stays instantly usable; a slow/again-fragile
 * OfficeManager server degrades to "Unavailable".
 */
function NetProfitCard({ contractId }) {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: true, error: '', data: null });

  useEffect(() => {
    let active = true;
    setState({ loading: true, error: '', data: null });
    api.get('/Reconciliation', { params: { contract_id: contractId } })
      .then((r) => { if (active) setState({ loading: false, error: '', data: r.data.data }); })
      .catch((e) => { if (active) setState({ loading: false, error: e.response?.data?.message || e.message || 'Lookup failed', data: null }); });
    return () => { active = false; };
  }, [contractId]);

  const d = state.data;
  const r = d?.reconciliation;
  const netProfit = r ? Number(r.cash_gap) : null;   // net collected − billed
  const st = r ? (RECON_STATUS[r.status] || RECON_STATUS.review) : RECON_STATUS.error;
  const positive = Number(netProfit) >= 0;

  return (
    <Card className={`ring-1 ${r ? st.ring : 'ring-slate-200/60'}`}>
      <div className="flex flex-wrap items-center justify-between gap-4 px-6 py-5">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('contractDetail.netProfit')}</p>
            {!state.loading && <Badge tone={st.tone}>{st.label}</Badge>}
          </div>
          {state.loading ? (
            <div className="mt-2 flex items-center gap-2 text-slate-400"><Spinner className="h-5 w-5" /><span className="text-sm">{t('contractDetail.reconciling')}</span></div>
          ) : state.error ? (
            <p className="mt-2 text-sm text-red-600">{t('contractDetail.reconcileFailed')} {state.error}</p>
          ) : (
            <>
              <p className={`mt-1 text-3xl font-bold tracking-tight ${positive ? 'text-emerald-600' : 'text-red-600'}`}>
                {netProfit > 0 ? '+' : ''}{aed2(netProfit)}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Net collected {aed2(d.cash.net)} − billed {aed2(d.fleet.billed)}. {st.sub}
              </p>
            </>
          )}
        </div>
      </div>
    </Card>
  );
}

const ICON_CAL = 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z';
const ICON_CLOCK = 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z';
const ICON_CHECK = 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z';

const MILE_TONE = {
  slate: 'bg-slate-100 text-slate-500',
  indigo: 'bg-indigo-100 text-indigo-600',
  amber: 'bg-amber-100 text-amber-600',
  emerald: 'bg-emerald-100 text-emerald-600',
  red: 'bg-red-100 text-red-600',
};

function Milestone({ label, date, icon, tone = 'slate' }) {
  return (
    <div className="relative z-10 flex flex-1 flex-col items-center text-center">
      <span className={`flex h-10 w-10 items-center justify-center rounded-full ring-4 ring-white ${MILE_TONE[tone] || MILE_TONE.slate}`}>
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
      </span>
      <p className="mt-2 text-xs font-semibold text-slate-700">{label}</p>
      <p className="text-xs text-slate-400">{date}</p>
    </div>
  );
}

// Out → Expected → Returned lifecycle of a contract, as a 3-step timeline.
function Lifecycle({ out, expected, actual, open, late }) {
  const { t } = useI18n();
  if (!out) return null;
  const third = actual
    ? { label: t('contractDetail.lifecycle.returned'), date: fmtDate(actual), tone: late ? 'red' : 'emerald', icon: ICON_CHECK }
    : open
      ? { label: t('contractDetail.lifecycle.inProgress'), date: t('contractDetail.lifecycle.notReturned'), tone: 'amber', icon: ICON_CLOCK }
      : { label: t('contractDetail.lifecycle.closed'), date: '—', tone: 'slate', icon: ICON_CHECK };
  const nodes = [
    { label: t('contractDetail.lifecycle.out'), date: fmtDate(out), tone: 'indigo', icon: ICON_CAL },
    { label: t('contractDetail.lifecycle.expected'), date: expected ? fmtDate(expected) : '—', tone: expected ? 'amber' : 'slate', icon: ICON_CLOCK },
    third,
  ];
  return (
    <Card className="p-6">
      <h3 className="mb-5 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('contractDetail.lifecycle.title')}</h3>
      <div className="relative flex items-start justify-between">
        <span aria-hidden className="pointer-events-none absolute start-8 end-8 top-5 h-0.5 bg-slate-200" />
        {nodes.map((n, i) => <Milestone key={i} {...n} />)}
      </div>
    </Card>
  );
}

export default function ContractDetail() {
  const { t, tp } = useI18n();
  const { id } = useParams();
  const navigate = useNavigate();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Contract/${id}`);
    return data.data;
  }, [id]);
  const { data: c, loading, error, reload } = useFetch(fetcher, [id]);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;
  if (error || !c) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <Card>
          <ErrorState title={t('contractDetail.loadFailed')} message={error || t('contractDetail.notFound')} onRetry={reload} />
        </Card>
        <Link to="/contracts" className="mt-4 inline-block text-sm font-medium text-indigo-600 transition-colors hover:text-indigo-700">{t('contractDetail.back')}</Link>
      </div>
    );
  }

  const isMaintenance = c.contract_type === 'U';
  const today = new Date().toISOString().slice(0, 10);
  const dayDiff = (a, b) => Math.round((new Date(a) - new Date(b)) / 86400000); // a − b in days

  // "Was the garage late?" — compare actual/now return against the expected return.
  let mStatus = null;
  if (isMaintenance) {
    const due = c.expected_return_date, ret = c.in_date;
    if (ret && due) {
      const late = dayDiff(ret, due);
      mStatus = late > 0
        ? { label: `Returned late · ${late}d`, tone: 'text-red-600', dot: 'bg-red-500', badge: 'red' }
        : { label: 'Returned on time', tone: 'text-emerald-600', dot: 'bg-emerald-500', badge: 'green' };
    } else if (c.state === 'open' && due) {
      const over = dayDiff(today, due);
      mStatus = over > 0
        ? { label: `Overdue · ${over}d`, tone: 'text-red-600', dot: 'bg-red-500', badge: 'red' }
        : { label: 'In garage · on track', tone: 'text-emerald-600', dot: 'bg-emerald-500', badge: 'green' };
    } else if (c.state === 'open') {
      mStatus = { label: 'In garage', tone: 'text-amber-600', dot: 'bg-amber-500', badge: 'amber' };
    } else if (ret) {
      mStatus = { label: 'Returned', tone: 'text-slate-600', dot: 'bg-slate-400', badge: 'gray' };
    }
  }
  // Garage for this maintenance visit. The contract header's vendor is usually empty, so
  // the backend resolves the live garage from the workshop log: where the car is parked now
  // while the visit is open, or the last garage it was in once it's closed/returned.
  // Label follows the contract: while it's open the car is parked there now ("Currently in"),
  // once it's closed that's the last garage it was in ("Last garage").
  const garage = c.current_garage || (c.vendor?.name ? { name: c.vendor.name } : null);
  const garageName = garage?.name || null;
  const garageLabel = garage ? (c.state === 'open' ? 'Currently in' : 'Last garage') : null;

  // Days in the garage for THIS maintenance contract only:
  //  - returned (has in_date) -> in_date − out_date (the real duration)
  //  - still open             -> today − out_date (how long it's been in so far)
  //  - closed with no in_date -> use stored `days`, else unknown (don't count to today)
  let daysInGarage = null;
  if (c.out_date && c.in_date) daysInGarage = dayDiff(c.in_date, c.out_date);
  else if (c.out_date && c.state === 'open') daysInGarage = dayDiff(today, c.out_date);
  else if (c.days != null) daysInGarage = Number(c.days);

  // Expected return = out date + contracted `days`. Computed for EVERY rental with a term
  // (OfficeManager has no due-date field), so the date also shows on returned contracts —
  // not just open ones. "Overdue" only applies while the car is still out.
  let rentalDue = null;
  if (c.contract_type === 'C' && c.out_date && Number(c.days) > 0) {
    const d = new Date(c.out_date);
    d.setDate(d.getDate() + Number(c.days));
    const dueStr = d.toISOString().slice(0, 10);
    const inProgress = c.state === 'open' && !c.in_date;
    rentalDue = { due: dueStr, days: Number(c.days), inProgress, overdue: inProgress && dayDiff(today, dueStr) > 0 };
  }

  // For an open rental still out past its expected return, estimate the cost of the
  // extra days if the lease is extended: days late × the contract's daily rate.
  // OfficeManager has no "extension" field, so this is a guide for negotiating — we
  // prefer the explicit day price, else derive it from the weekly / monthly rate.
  let overdueBilling = null;
  if (rentalDue?.overdue) {
    const lateDays = dayDiff(today, rentalDue.due);
    let daily = null, rateSource = null;
    if (Number(c.day_price) > 0) { daily = Number(c.day_price); rateSource = 'day price'; }
    else if (Number(c.week_price) > 0) { daily = Number(c.week_price) / 7; rateSource = 'weekly rate ÷ 7'; }
    else if (Number(c.month_price) > 0) { daily = Number(c.month_price) / 30; rateSource = 'monthly rate ÷ 30'; }
    const estimate = daily != null ? daily * lateDays : null;
    overdueBilling = {
      lateDays,
      daily,
      rateSource,
      estimate,
      projectedBalance: estimate != null ? Number(c.contract_balance || 0) + estimate : null,
    };
  }

  // Actual time the car was held — out datetime → in datetime (combines date + clock time,
  // since `days` alone hides part-day rentals like 22:48 → 12:24 next day = 13h 36m).
  const outAt = combineDateTime(c.out_date, c.out_time);
  const inAt = combineDateTime(c.in_date, c.in_time);
  let heldDuration = null;
  if (outAt && inAt) heldDuration = fmtDuration(outAt, inAt);
  else if (outAt && c.state === 'open' && !c.in_date) {
    const so = fmtDuration(outAt, new Date());
    if (so) heldDuration = `${so} so far`;
  }

  // Money tiles (Total Cost / Debit / Credit / Balance / Deposit) only render while financials are on.
  const stats = isMaintenance
    ? [
        ...(SHOW_FINANCIALS ? [{ label: 'Total Cost', value: aed2(c.maintenance_total ?? c.contract_debit), accent: 'amber', icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' }] : []),
        { label: garageLabel || 'Garage', value: garageName || '—', small: true, accent: 'indigo', icon: 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z' },
        { label: 'Days in Garage', value: daysInGarage != null ? `${daysInGarage}d` : '—', accent: 'indigo', icon: ICON_CLOCK },
        { label: 'Status', value: mStatus?.label || '—', tone: mStatus?.tone, small: true, icon: ICON_CHECK, accent: mStatus?.badge === 'green' ? 'emerald' : mStatus?.badge === 'red' ? 'red' : mStatus?.badge === 'amber' ? 'amber' : 'slate' },
      ]
    : (SHOW_FINANCIALS ? [
        { label: 'Total Debit', value: aed2(c.contract_debit), accent: 'indigo', icon: 'M7 11l5-5 5 5M12 6v12' },
        { label: 'Total Credit', value: aed2(c.contract_credit), accent: 'emerald', icon: 'M17 13l-5 5-5-5M12 18V6' },
        { label: 'Balance', value: aed2(c.contract_balance), tone: Number(c.contract_balance) > 0 ? 'text-red-600' : Number(c.contract_balance) < 0 ? 'text-emerald-600' : 'text-slate-900', accent: Number(c.contract_balance) > 0 ? 'red' : Number(c.contract_balance) < 0 ? 'emerald' : 'slate', icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' },
        { label: 'Deposit', value: aed2(c.contract_deposit), accent: 'indigo', icon: 'M3 10l9-6 9 6M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9' },
      ] : []);

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/contracts" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          Contracts
        </Link>

        {/* Hero */}
        <div className="relative overflow-hidden rounded-2xl bg-navy-950 p-6 shadow-card sm:p-8">
          <div className="pointer-events-none absolute -end-16 -top-20 h-64 w-64 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-24 start-1/4 h-64 w-64 rounded-full bg-violet-500/10 blur-3xl" />
          <div className="relative flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex items-start gap-4">
              <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-inset ring-white/15 backdrop-blur">
                <svg className="h-8 w-8 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                  <path d={isMaintenance ? 'M11 4a4 4 0 0 0-1 7.9V20a2 2 0 1 0 4 0v-8.1A4 4 0 0 0 11 4zM14.5 4.5l-2 2 3 3 2-2' : 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z'} />
                </svg>
              </div>
              <div className="min-w-0">
                <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">{isMaintenance ? 'Maintenance' : 'Contract'} #{c.contract_no || c.id}</h1>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <ContractTypeBadge type={c.contract_type} />
                  <ContractStateBadge state={c.state} />
                  {mStatus && <Badge tone={mStatus.badge}>{mStatus.label}</Badge>}
                  {rentalDue?.inProgress && <Badge tone={rentalDue.overdue ? 'red' : 'blue'}>{t(rentalDue.overdue ? 'contractDetail.overdueBadge' : 'contractDetail.dueBackBadge', { date: fmtDate(rentalDue.due) })}</Badge>}
                  {c.parent_contract_id && <Badge tone="indigo">🔁 {t('contractDetail.exchange')}</Badge>}
                  {/* Customer-wide money badges (carried balance / wallet / owes) — financials only */}
                  {SHOW_FINANCIALS && Number(c.carried_balance) > 0 && <Badge tone="green" className="font-semibold">Carried {aed2(c.carried_balance)}</Badge>}
                  {SHOW_FINANCIALS && Number(c.customer?.available_wallet) > 0 && <Badge tone="cyan" className="font-semibold">💰 Customer wallet {aed2(c.customer.available_wallet)}</Badge>}
                  {SHOW_FINANCIALS && Number(c.customer?.balance) > 0 && <Badge tone="red" className="font-semibold">⚠️ Customer owes {aed2(c.customer.balance)}</Badge>}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  {c.customer && (
                    <Link to={`/customers/${c.customer_id}`} className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-white ring-1 ring-inset ring-white/15 backdrop-blur transition hover:bg-white/15">
                      <svg className="h-3.5 w-3.5 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1" /></svg>
                      {c.customer.name_en || `#${c.customer.customer_no}`}
                    </Link>
                  )}
                  {c.vehicle && (
                    <Link to={`/vehicles/${c.vehicle_id}`} className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-white ring-1 ring-inset ring-white/15 backdrop-blur transition hover:bg-white/15">
                      <svg className="h-3.5 w-3.5 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14M7.5 16h.01M16.5 16h.01" /></svg>
                      {c.vehicle.plate_no || [c.vehicle.make, c.vehicle.model].filter(Boolean).join(' ')}
                    </Link>
                  )}
                </div>
              </div>
            </div>

            <div className="w-full shrink-0 rounded-2xl bg-white/5 p-4 ring-1 ring-inset ring-white/10 backdrop-blur lg:w-64">
              {SHOW_FINANCIALS && (
                <>
                  <p className="text-xs font-medium text-white/55">{isMaintenance ? 'Total Cost' : 'Balance'}</p>
                  <p className={`mt-1 text-3xl font-bold tracking-tight ${!isMaintenance && Number(c.contract_balance) > 0 ? 'text-red-300' : !isMaintenance && Number(c.contract_balance) < 0 ? 'text-emerald-300' : 'text-white'}`}>
                    {isMaintenance ? aed2(c.maintenance_total ?? c.contract_debit) : aed2(c.contract_balance)}
                  </p>
                </>
              )}
              <Button variant="secondary" className={`${SHOW_FINANCIALS ? 'mt-4' : ''} w-full justify-center`} onClick={() => navigate(`/contracts/${id}/edit`)}>{t('contractDetail.edit')}</Button>
            </div>
          </div>
        </div>

        {/* Net Profit — the daily-glance figure (Net Collected − Billed). Financials only. */}
        {SHOW_FINANCIALS && c.contract_no && !isMaintenance && <NetProfitCard contractId={id} />}

        {/* Stat tiles (money tiles hidden while financials are off; non-money maintenance tiles remain) */}
        {stats.length > 0 && (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {stats.map((s) => (
            <div key={s.label} className="opx-kpi">
              <div className="flex items-center justify-between gap-2">
                <p className="lbl" style={{ marginBottom: 0 }}>{s.label}</p>
                {s.icon && (
                  <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${MILE_TONE[s.accent] || MILE_TONE.slate}`}>
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={s.icon} /></svg>
                  </span>
                )}
              </div>
              <p className={`v tnum truncate ${s.small ? '' : ''}`} style={{ fontSize: s.small ? 16 : 26, marginTop: 8 }} title={typeof s.value === 'string' ? s.value : undefined}>{s.value}</p>
            </div>
          ))}
        </div>
        )}

        {/* 9-point Pre-Delivery Readiness — the Rental Manager's check-in/out decision surface.
            Rentals & bookings only; maintenance visits have their own workshop log below. */}
        {!isMaintenance && c.vehicle_id && (
          <ReadinessPanel vehicleId={c.vehicle_id} plate={c.vehicle?.plate_no} />
        )}

        {/* Billing reconciliation — per-category Charged/Settled/Outstanding. Financials only. */}
        {SHOW_FINANCIALS && !isMaintenance && <BillingReconciliation contract={c} />}

        {/* Lifecycle timeline */}
        <Lifecycle
          out={c.out_date}
          expected={c.expected_return_date || rentalDue?.due}
          actual={c.in_date}
          open={c.state === 'open' && !c.in_date}
          late={isMaintenance ? !!(c.in_date && c.expected_return_date && dayDiff(c.in_date, c.expected_return_date) > 0) : !!rentalDue?.overdue}
        />

        {/* Overdue rental — what the extra days would cost if the lease is extended. Financials only. */}
        {SHOW_FINANCIALS && overdueBilling && (
          <Card className="p-6 ring-1 ring-red-200">
            <div className="mb-3 flex items-center justify-between gap-3">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-red-500">{t('contractDetail.overdue.title')}</h3>
              <Badge tone="red">{t('contractDetail.overdue.pastDue', { n: overdueBilling.lateDays })}</Badge>
            </div>
            <p className="mb-5 text-sm text-slate-600">
              {t('contractDetail.overdue.expectedWas')} <span className="font-medium text-slate-900">{fmtDate(rentalDue.due)}</span> {t('contractDetail.overdue.stillOut')}
              {' '}{tp('contractDetail.overdue.estimateFor', overdueBilling.lateDays, { n: overdueBilling.lateDays })}
            </p>

            {overdueBilling.daily != null ? (
              <>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">{t('contractDetail.overdue.daysLate')}</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">{overdueBilling.lateDays}d</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">{t('contractDetail.overdue.dailyRate')}</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">{aed2(overdueBilling.daily)}</p>
                    <p className="mt-0.5 text-xs text-slate-400">{overdueBilling.rateSource}</p>
                  </div>
                  <div className="rounded-2xl border border-red-200 bg-red-50/50 px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-red-500">{t('contractDetail.overdue.estimatedExtra')}</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-red-600">{aed2(overdueBilling.estimate)}</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">{t('contractDetail.overdue.projectedBalance')}</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">{aed2(overdueBilling.projectedBalance)}</p>
                    <p className="mt-0.5 text-xs text-slate-400">current {aed2(c.contract_balance)} + extra</p>
                  </div>
                </div>
                <p className="mt-4 text-xs text-slate-500">
                  {overdueBilling.lateDays} day{overdueBilling.lateDays === 1 ? '' : 's'} × {aed2(overdueBilling.daily)} = <span className="font-semibold text-slate-700">{aed2(overdueBilling.estimate)}</span>.
                  Estimate only — based on the {overdueBilling.rateSource}, before any deposit, discount or fees.
                </p>
              </>
            ) : (
              <p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/20">
                This rental is {overdueBilling.lateDays} day{overdueBilling.lateDays === 1 ? '' : 's'} overdue, but no day / week / month price is set on the contract, so the extra cost can’t be calculated. Add a rate to the contract to see the estimate.
              </p>
            )}
          </Card>
        )}

        {/* Exchange chain — suggested swaps to link, or the linked Parent → Child chain (rentals only) */}
        {c.contract_type === 'C' && <ExchangeChainPanel contract={c} />}

        {/* THE VISIT JOURNEY — every fault found on this visit (inspector's and the garage's), which
            garage fixed each one and how long it took, plus the stage spine, odometer chain and event
            trail. Maintenance contracts only; the panel hides itself when there is no visit behind it. */}
        {isMaintenance && <VisitJourneyPanel contractId={id} />}

        {(c.contract_type === 'U' || (c.items && c.items.length > 0)) && (
          <Card className="p-6">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('contractDetail.maintenance.title')}</h3>
              {garageName && (
                <span className="text-sm text-slate-600">
                  {garageLabel}: <span className="font-medium text-slate-900">{garageName}</span>
                  {garage?.as_of && <span className="text-slate-400"> · {fmtDate(garage.as_of)}</span>}
                </span>
              )}
            </div>

            <div className="mb-4 grid grid-cols-1 gap-x-8 gap-y-1 sm:grid-cols-3">
              {[
                [t('contractDetail.fields.vehicle'), c.vehicle?.plate_no ? <Link to={`/vehicles/${c.vehicle_id}`} className="text-indigo-600 hover:text-indigo-700">{c.vehicle.plate_no}</Link> : null],
                ['Responsible', c.responsible],
                ['Approved By', c.approved_by],
                ['Sent to Garage', c.out_date ? fmtDate(c.out_date) : null],
                [t('contractDetail.fields.expectedReturn'), c.expected_return_date ? fmtDate(c.expected_return_date) : null],
                ['Returned', c.in_date ? fmtDate(c.in_date) : null],
                ['Late by', (c.in_date && c.expected_return_date && dayDiff(c.in_date, c.expected_return_date) > 0) ? <span className="text-red-600">{dayDiff(c.in_date, c.expected_return_date)} days</span> : null],
                ['Mileage Out', c.out_milage != null ? `${num(c.out_milage)} km` : null],
                ['Mileage In', c.in_milage != null ? `${num(c.in_milage)} km` : null],
                [t('contractDetail.fields.days'), c.days != null ? c.days : null],
              ]
                .filter(([, v]) => v !== null && v !== undefined && v !== '')
                .map(([l, v]) => (
                  <div key={l} className="flex justify-between gap-4 py-1.5 text-sm">
                    <span className="text-slate-500">{l}</span>
                    <span className="text-end font-medium text-slate-900">{v}</span>
                  </div>
                ))}
            </div>

            {c.maintenance_tags?.length > 0 && (
              <div className="mb-4 flex flex-wrap gap-2">
                {c.maintenance_tags.map((t) => (
                  <span key={t} className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200">{t}</span>
                ))}
              </div>
            )}

            {c.items?.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm stagger-rows">
                  <thead className="bg-slate-50/90">
                    <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('contractDetail.maintenance.service')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('contractDetail.maintenance.cost')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('contractDetail.maintenance.notes')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {c.items.map((it) => (
                      <tr key={it.id} className="transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                        <td className="border-b border-slate-100 px-5 py-3.5 font-medium text-slate-900">{it.service_name}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-end tabular-nums text-slate-700">{aed2(it.cost)}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{it.notes || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t border-slate-200">
                      <td className="px-5 py-3 text-end font-semibold text-slate-700">{t('contractDetail.maintenance.total')}</td>
                      <td className="px-5 py-3 text-end font-bold tabular-nums text-slate-900">{aed2(c.maintenance_total ?? c.items.reduce((s, i) => s + Number(i.cost || 0), 0))}</td>
                      <td />
                    </tr>
                  </tfoot>
                </table>
              </div>
            ) : (
              <p className="text-sm text-slate-400">{t('contractDetail.maintenance.empty')}</p>
            )}

            {c.maintenance_notes && <p className="mt-4 whitespace-pre-line text-sm text-slate-700">{c.maintenance_notes}</p>}
          </Card>
        )}

        {/* Editable workshop log for this car/visit — the dashboard owning the garage
            timeline (manual events survive the sheet sync). Maintenance visits only. */}
        {isMaintenance && c.vehicle_id && (
          <WorkshopEvents
            vehicleId={c.vehicle_id}
            contractId={c.id}
            defaultDate={c.out_date}
            expectedReturn={c.expected_return_date}
          />
        )}

        {/* Service Records (always — the money-free technical log) + payments (financials only). */}
        <div className={`grid grid-cols-1 gap-6 ${SHOW_FINANCIALS ? 'xl:grid-cols-2' : ''}`}>
          <ContractInvoices contract={c} onChanged={reload} />
          {SHOW_FINANCIALS && <ContractPayments contract={c} onChanged={reload} />}
        </div>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Section title={t('contractDetail.sections.parties')} pairs={[
            [t('contractDetail.fields.customer'), c.customer ? <Link to={`/customers/${c.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{c.customer.name_en || `#${c.customer.customer_no}`}{c.customer.name_en ? <span className="ms-1.5 text-xs text-slate-400">#{c.customer.customer_no}</span> : null}</Link> : '—'],
            [t('contractDetail.fields.vehicle'), c.vehicle?.plate_no ? <Link to={`/vehicles/${c.vehicle_id}`} className="text-indigo-600 hover:text-indigo-700">{c.vehicle.plate_no} · {[c.vehicle.make, c.vehicle.model].filter(Boolean).join(' ')}</Link> : (c.vehicle_id || '—')],
            [t('contractDetail.fields.reference'), c.reference],
            [t('contractDetail.fields.source'), c.source],
            [t('contractDetail.fields.salesman'), c.sales_man1],
          ]} />

          <Section title={t('contractDetail.sections.period')} pairs={[
            [t('contractDetail.fields.outDate'), fmtDate(c.out_date)], [t('contractDetail.fields.outTime'), c.out_time ? fmtTime(c.out_time) : null], [t('contractDetail.fields.outMileage'), c.out_milage != null ? num(c.out_milage) : null], [t('contractDetail.fields.outFuel'), c.out_fuel], [t('contractDetail.fields.openedBy'), c.opened_by],
            [t('contractDetail.fields.inDate'), fmtDate(c.in_date)], [t('contractDetail.fields.inTime'), c.in_time ? fmtTime(c.in_time) : null], [t('contractDetail.fields.inMileage'), c.in_milage != null ? num(c.in_milage) : null], [t('contractDetail.fields.inFuel'), c.in_fuel], [t('contractDetail.fields.closedBy'), c.closed_by],
            [t('contractDetail.fields.duration'), heldDuration], [t('contractDetail.fields.days'), c.days], [t('contractDetail.fields.kM'), c.km != null ? num(c.km) : null],
            [t('contractDetail.fields.expectedReturn'), rentalDue ? `${fmtDate(rentalDue.due)}${rentalDue.overdue ? ' (overdue)' : ''}` : null],
          ]} />

          {/* rental-only financial sections — hidden for maintenance, and while financials are off */}
          {!isMaintenance && SHOW_FINANCIALS && (
            <>
              <Section title={t('contractDetail.sections.pricing')} pairs={[
                [t('contractDetail.fields.dayPrice'), money(c.day_price)], [t('contractDetail.fields.weekPrice'), money(c.week_price)], [t('contractDetail.fields.monthPrice'), money(c.month_price)],
                [t('contractDetail.fields.hourPrice'), money(c.hour_price)], [t('contractDetail.fields.yearPrice'), money(c.year_price)],
                [t('contractDetail.fields.milesDay'), c.miles_allowed_pd], [t('contractDetail.fields.milesMonth'), c.miles_allowed_pm], [t('contractDetail.fields.extraMile'), money(c.extra_mile_charge)],
                [t('contractDetail.fields.cDWRate'), money(c.cdw_rate)], [t('contractDetail.fields.insuranceType'), c.insurance_type],
              ]} />

              <Section title={t('contractDetail.sections.debit')} pairs={[
                [t('contractDetail.fields.rents'), money(c.rents_debit)], [t('contractDetail.fields.salik'), money(c.salik_debit)], [t('contractDetail.fields.damages'), money(c.damages_debit)],
                [t('contractDetail.fields.breaches'), money(c.breachs_debit)], [t('contractDetail.fields.extraCharges'), money(c.extra_charges_debit)], [t('contractDetail.fields.kM'), money(c.km_debit)],
                [t('contractDetail.fields.fuel'), money(c.fuel_debit)], [t('contractDetail.fields.gPS'), money(c.gps_debit)], [t('contractDetail.fields.cDW'), money(c.cdw_debit)],
                [t('contractDetail.fields.extraDriver'), money(c.extra_driver_debit)], [t('contractDetail.fields.vAT'), money(c.vat_debit)], [t('contractDetail.fields.deposit'), money(c.deposit_debit)],
                [t('contractDetail.fields.cardoo'), money(c.cardoo_debit)],
              ]} />

              <Section title={t('contractDetail.sections.credit')} pairs={[
                [t('contractDetail.fields.rents'), money(c.rents_credit)], [t('contractDetail.fields.salik'), money(c.salik_credit)], [t('contractDetail.fields.damages'), money(c.damages_credit)],
                [t('contractDetail.fields.breaches'), money(c.breachs_credit)], [t('contractDetail.fields.extraCharges'), money(c.extra_charges_credit)], [t('contractDetail.fields.kM'), money(c.km_credit)],
                [t('contractDetail.fields.fuel'), money(c.fuel_credit)], [t('contractDetail.fields.gPS'), money(c.gps_credit)], [t('contractDetail.fields.cDW'), money(c.cdw_credit)],
                [t('contractDetail.fields.extraDriver'), money(c.extra_driver_credit)], [t('contractDetail.fields.vAT'), money(c.vat_credit)], [t('contractDetail.fields.deposit'), money(c.deposit_credit)],
                [t('contractDetail.fields.cardoo'), money(c.cardoo_credit)],
              ]} />

              <Section title={t('contractDetail.sections.totals')} pairs={[
                [t('contractDetail.fields.contractDebit'), money(c.contract_debit)], [t('contractDetail.fields.contractCredit'), money(c.contract_credit)], [t('contractDetail.fields.balance'), money(c.contract_balance)],
                [t('contractDetail.fields.refunds'), money(c.contract_refunds)], [t('contractDetail.fields.discount'), money(c.contract_discount)], [t('contractDetail.fields.badDebts'), money(c.contract_bad_debts)],
                [t('contractDetail.fields.deposit'), money(c.contract_deposit)], [t('contractDetail.fields.commissions'), money(c.contract_commissions)], [t('contractDetail.fields.income'), money(c.contract_income)],
                [t('contractDetail.fields.cardooDeposit'), money(c.cardoo_deposit)],
              ]} />
            </>
          )}
        </div>

        {c.remarks && (
          <Card className="p-6">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('contractDetail.remarks')}</h3>
            <p className="text-sm text-slate-700">{c.remarks}</p>
          </Card>
        )}
      </div>
    </div>
  );
}
