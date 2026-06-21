import { useCallback } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge, { ContractTypeBadge, ContractStateBadge } from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { Card, Spinner } from '../../components/ui/Misc';
import ExchangeChainPanel from '../../components/ExchangeChainPanel';
import WorkshopEvents from '../../components/WorkshopEvents';
import { aed2, fmtDate, fmtTime, combineDateTime, fmtDuration, num } from '../../lib/format';

// Renders a card with a label/value grid. Pairs = [[label, value], ...]
function Section({ title, pairs }) {
  const visible = pairs.filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '—');
  if (visible.length === 0) return null;
  return (
    <Card className="p-6">
      <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</h3>
      <div className="grid grid-cols-1 gap-x-8 gap-y-1 sm:grid-cols-2">
        {visible.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4 py-1.5 text-sm">
            <span className="text-gray-500">{label}</span>
            <span className="text-right font-medium text-gray-900">{value}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

const money = (v) => (v === null || v === undefined || v === '' ? null : aed2(v));

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
  if (!out) return null;
  const third = actual
    ? { label: 'Returned', date: fmtDate(actual), tone: late ? 'red' : 'emerald', icon: ICON_CHECK }
    : open
      ? { label: 'In progress', date: 'Not returned yet', tone: 'amber', icon: ICON_CLOCK }
      : { label: 'Closed', date: '—', tone: 'slate', icon: ICON_CHECK };
  const nodes = [
    { label: 'Out', date: fmtDate(out), tone: 'indigo', icon: ICON_CAL },
    { label: 'Expected return', date: expected ? fmtDate(expected) : '—', tone: expected ? 'amber' : 'slate', icon: ICON_CLOCK },
    third,
  ];
  return (
    <Card className="p-6">
      <h3 className="mb-5 text-xs font-semibold uppercase tracking-wide text-slate-400">Lifecycle</h3>
      <div className="relative flex items-start justify-between">
        <span aria-hidden className="pointer-events-none absolute left-8 right-8 top-5 h-0.5 bg-slate-200" />
        {nodes.map((n, i) => <Milestone key={i} {...n} />)}
      </div>
    </Card>
  );
}

export default function ContractDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Contract/${id}`);
    return data.data;
  }, [id]);
  const { data: c, loading, error } = useFetch(fetcher, [id]);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;
  if (error || !c) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error || 'Not found'}</div>
        <Link to="/contracts" className="mt-4 inline-block text-sm font-medium text-indigo-600">← Back to contracts</Link>
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
      mStatus = { label: 'Returned', tone: 'text-gray-600', dot: 'bg-gray-400', badge: 'gray' };
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

  const stats = isMaintenance
    ? [
        { label: 'Total Cost', value: aed2(c.maintenance_total ?? c.contract_debit), accent: 'amber', icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' },
        { label: garageLabel || 'Garage', value: garageName || '—', small: true, accent: 'indigo', icon: 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z' },
        { label: 'Days in Garage', value: daysInGarage != null ? `${daysInGarage}d` : '—', accent: 'indigo', icon: ICON_CLOCK },
        { label: 'Status', value: mStatus?.label || '—', tone: mStatus?.tone, small: true, icon: ICON_CHECK, accent: mStatus?.badge === 'green' ? 'emerald' : mStatus?.badge === 'red' ? 'red' : mStatus?.badge === 'amber' ? 'amber' : 'slate' },
      ]
    : [
        { label: 'Total Debit', value: aed2(c.contract_debit), accent: 'indigo', icon: 'M7 11l5-5 5 5M12 6v12' },
        { label: 'Total Credit', value: aed2(c.contract_credit), accent: 'emerald', icon: 'M17 13l-5 5-5-5M12 18V6' },
        { label: 'Balance', value: aed2(c.contract_balance), tone: Number(c.contract_balance) > 0 ? 'text-red-600' : Number(c.contract_balance) < 0 ? 'text-emerald-600' : 'text-slate-900', accent: Number(c.contract_balance) > 0 ? 'red' : Number(c.contract_balance) < 0 ? 'emerald' : 'slate', icon: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' },
        { label: 'Deposit', value: aed2(c.contract_deposit), accent: 'indigo', icon: 'M3 10l9-6 9 6M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9' },
      ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/contracts" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          Contracts
        </Link>

        {/* Hero */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 shadow-card sm:p-8">
          <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-24 left-1/4 h-64 w-64 rounded-full bg-violet-500/10 blur-3xl" />
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
                  {rentalDue?.inProgress && <Badge tone={rentalDue.overdue ? 'red' : 'blue'}>{rentalDue.overdue ? `Overdue · due ${fmtDate(rentalDue.due)}` : `Due back ${fmtDate(rentalDue.due)}`}</Badge>}
                  {c.parent_contract_id && <Badge tone="indigo">🔁 Exchange</Badge>}
                  {Number(c.carried_balance) > 0 && <Badge tone="green" className="font-semibold">Carried {aed2(c.carried_balance)}</Badge>}
                  {/* Customer-wide standing (across ALL their contracts) — only one ever shows */}
                  {Number(c.customer?.available_wallet) > 0 && <Badge tone="cyan" className="font-semibold">💰 Customer wallet {aed2(c.customer.available_wallet)}</Badge>}
                  {Number(c.customer?.balance) > 0 && <Badge tone="red" className="font-semibold">⚠️ Customer owes {aed2(c.customer.balance)}</Badge>}
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
              <p className="text-xs font-medium text-white/55">{isMaintenance ? 'Total Cost' : 'Balance'}</p>
              <p className={`mt-1 text-3xl font-bold tracking-tight ${!isMaintenance && Number(c.contract_balance) > 0 ? 'text-red-300' : !isMaintenance && Number(c.contract_balance) < 0 ? 'text-emerald-300' : 'text-white'}`}>
                {isMaintenance ? aed2(c.maintenance_total ?? c.contract_debit) : aed2(c.contract_balance)}
              </p>
              <Button variant="secondary" className="mt-4 w-full justify-center" onClick={() => navigate(`/contracts/${id}/edit`)}>Edit Contract</Button>
            </div>
          </div>
        </div>

        {/* Stat tiles */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {stats.map((s) => (
            <div key={s.label} className="hover-lift rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-medium text-slate-500">{s.label}</p>
                {s.icon && (
                  <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${MILE_TONE[s.accent] || MILE_TONE.slate}`}>
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={s.icon} /></svg>
                  </span>
                )}
              </div>
              <p className={`mt-1.5 ${s.small ? 'text-base' : 'text-2xl'} truncate font-bold tracking-tight ${s.tone || 'text-slate-900'}`} title={typeof s.value === 'string' ? s.value : undefined}>{s.value}</p>
            </div>
          ))}
        </div>

        {/* Lifecycle timeline */}
        <Lifecycle
          out={c.out_date}
          expected={c.expected_return_date || rentalDue?.due}
          actual={c.in_date}
          open={c.state === 'open' && !c.in_date}
          late={isMaintenance ? !!(c.in_date && c.expected_return_date && dayDiff(c.in_date, c.expected_return_date) > 0) : !!rentalDue?.overdue}
        />

        {/* Overdue rental — what the extra days would cost if the lease is extended */}
        {overdueBilling && (
          <Card className="p-6 ring-1 ring-red-200">
            <div className="mb-3 flex items-center justify-between gap-3">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-red-500">Overdue — extension estimate</h3>
              <Badge tone="red">{overdueBilling.lateDays}d past due</Badge>
            </div>
            <p className="mb-5 text-sm text-gray-600">
              Expected return was <span className="font-medium text-gray-900">{fmtDate(rentalDue.due)}</span> and the car is still out.
              If the lease is extended, here’s the estimated charge for the extra {overdueBilling.lateDays} day{overdueBilling.lateDays === 1 ? '' : 's'}.
            </p>

            {overdueBilling.daily != null ? (
              <>
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">Days late</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">{overdueBilling.lateDays}d</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">Daily rate</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900">{aed2(overdueBilling.daily)}</p>
                    <p className="mt-0.5 text-xs text-slate-400">{overdueBilling.rateSource}</p>
                  </div>
                  <div className="rounded-2xl border border-red-200 bg-red-50/50 px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-red-500">Estimated extra</p>
                    <p className="mt-1.5 text-2xl font-bold tracking-tight text-red-600">{aed2(overdueBilling.estimate)}</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
                    <p className="text-xs font-medium text-slate-500">Projected balance</p>
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

        {(c.contract_type === 'U' || (c.items && c.items.length > 0)) && (
          <Card className="p-6">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Maintenance</h3>
              {garageName && (
                <span className="text-sm text-gray-600">
                  {garageLabel}: <span className="font-medium text-gray-900">{garageName}</span>
                  {garage?.as_of && <span className="text-gray-400"> · {fmtDate(garage.as_of)}</span>}
                </span>
              )}
            </div>

            <div className="mb-4 grid grid-cols-1 gap-x-8 gap-y-1 sm:grid-cols-3">
              {[
                ['Vehicle', c.vehicle?.plate_no ? <Link to={`/vehicles/${c.vehicle_id}`} className="text-indigo-600 hover:text-indigo-700">{c.vehicle.plate_no}</Link> : null],
                ['Responsible', c.responsible],
                ['Approved By', c.approved_by],
                ['Sent to Garage', c.out_date ? fmtDate(c.out_date) : null],
                ['Expected Return', c.expected_return_date ? fmtDate(c.expected_return_date) : null],
                ['Returned', c.in_date ? fmtDate(c.in_date) : null],
                ['Late by', (c.in_date && c.expected_return_date && dayDiff(c.in_date, c.expected_return_date) > 0) ? <span className="text-red-600">{dayDiff(c.in_date, c.expected_return_date)} days</span> : null],
                ['Mileage Out', c.out_milage != null ? `${num(c.out_milage)} km` : null],
                ['Mileage In', c.in_milage != null ? `${num(c.in_milage)} km` : null],
                ['Days', c.days != null ? c.days : null],
              ]
                .filter(([, v]) => v !== null && v !== undefined && v !== '')
                .map(([l, v]) => (
                  <div key={l} className="flex justify-between gap-4 py-1.5 text-sm">
                    <span className="text-gray-500">{l}</span>
                    <span className="text-right font-medium text-gray-900">{v}</span>
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
                <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
                  <thead className="bg-gray-50/60">
                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                      <th className="px-4 py-2">Service</th>
                      <th className="px-4 py-2 text-right">Cost</th>
                      <th className="px-4 py-2">Notes</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {c.items.map((it) => (
                      <tr key={it.id}>
                        <td className="px-4 py-2 font-medium text-gray-900">{it.service_name}</td>
                        <td className="px-4 py-2 text-right text-gray-700">{aed2(it.cost)}</td>
                        <td className="px-4 py-2 text-gray-500">{it.notes || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t border-gray-200">
                      <td className="px-4 py-2 text-right font-semibold text-gray-700">Total</td>
                      <td className="px-4 py-2 text-right font-bold text-gray-900">{aed2(c.maintenance_total ?? c.items.reduce((s, i) => s + Number(i.cost || 0), 0))}</td>
                      <td />
                    </tr>
                  </tfoot>
                </table>
              </div>
            ) : (
              <p className="text-sm text-gray-400">No items recorded.</p>
            )}

            {c.maintenance_notes && <p className="mt-4 whitespace-pre-line text-sm text-gray-700">{c.maintenance_notes}</p>}
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

        {c.invoices?.length > 0 && (
          <Card className="p-6">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Invoices</h3>
              <span className="text-sm text-gray-600">{c.invoices.length} invoice{c.invoices.length === 1 ? '' : 's'}</span>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-100 text-sm">
                <thead className="bg-gray-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th className="px-4 py-2">Invoice #</th>
                    <th className="px-4 py-2">Date</th>
                    <th className="px-4 py-2 text-right">Value</th>
                    <th className="px-4 py-2 text-right">VAT</th>
                    <th className="px-4 py-2 text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {c.invoices.map((inv) => (
                    <tr key={inv.invoice_no}>
                      <td className="px-4 py-2 font-medium text-gray-900">#{inv.invoice_no}</td>
                      <td className="px-4 py-2 text-gray-500">{fmtDate(inv.date)}</td>
                      <td className="px-4 py-2 text-right text-gray-600">{aed2(inv.total_value)}</td>
                      <td className="px-4 py-2 text-right text-gray-600">{aed2(inv.vat_value)}</td>
                      <td className="px-4 py-2 text-right font-medium text-gray-900">{aed2(inv.total_after_vat)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t border-gray-200">
                    <td className="px-4 py-2 font-semibold text-gray-700" colSpan="4">Total billed</td>
                    <td className="px-4 py-2 text-right font-bold text-gray-900">{aed2(c.invoices_total ?? c.invoices.reduce((s, i) => s + Number(i.total_after_vat || 0), 0))}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </Card>
        )}

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Section title="Parties" pairs={[
            ['Customer', c.customer ? <Link to={`/customers/${c.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{c.customer.name_en || `#${c.customer.customer_no}`}{c.customer.name_en ? <span className="ml-1.5 text-xs text-gray-400">#{c.customer.customer_no}</span> : null}</Link> : '—'],
            ['Vehicle', c.vehicle?.plate_no ? <Link to={`/vehicles/${c.vehicle_id}`} className="text-indigo-600 hover:text-indigo-700">{c.vehicle.plate_no} · {[c.vehicle.make, c.vehicle.model].filter(Boolean).join(' ')}</Link> : (c.vehicle_id || '—')],
            ['Reference', c.reference],
            ['Source', c.source],
            ['Salesman', c.sales_man1],
          ]} />

          <Section title="Period" pairs={[
            ['Out Date', fmtDate(c.out_date)], ['Out Time', c.out_time ? fmtTime(c.out_time) : null], ['Out Mileage', c.out_milage != null ? num(c.out_milage) : null], ['Out Fuel', c.out_fuel], ['Opened By', c.opened_by],
            ['In Date', fmtDate(c.in_date)], ['In Time', c.in_time ? fmtTime(c.in_time) : null], ['In Mileage', c.in_milage != null ? num(c.in_milage) : null], ['In Fuel', c.in_fuel], ['Closed By', c.closed_by],
            ['Duration', heldDuration], ['Days', c.days], ['KM', c.km != null ? num(c.km) : null],
            ['Expected Return', rentalDue ? `${fmtDate(rentalDue.due)}${rentalDue.overdue ? ' (overdue)' : ''}` : null],
          ]} />

          {/* rental-only financial sections — hidden for maintenance contracts */}
          {!isMaintenance && (
            <>
              <Section title="Pricing" pairs={[
                ['Day Price', money(c.day_price)], ['Week Price', money(c.week_price)], ['Month Price', money(c.month_price)],
                ['Hour Price', money(c.hour_price)], ['Year Price', money(c.year_price)],
                ['Miles / day', c.miles_allowed_pd], ['Miles / month', c.miles_allowed_pm], ['Extra mile', money(c.extra_mile_charge)],
                ['CDW rate', money(c.cdw_rate)], ['Insurance type', c.insurance_type],
              ]} />

              <Section title="Debit Breakdown" pairs={[
                ['Rents', money(c.rents_debit)], ['Salik', money(c.salik_debit)], ['Damages', money(c.damages_debit)],
                ['Breaches', money(c.breachs_debit)], ['Extra charges', money(c.extra_charges_debit)], ['KM', money(c.km_debit)],
                ['Fuel', money(c.fuel_debit)], ['GPS', money(c.gps_debit)], ['CDW', money(c.cdw_debit)],
                ['Extra driver', money(c.extra_driver_debit)], ['VAT', money(c.vat_debit)], ['Deposit', money(c.deposit_debit)],
              ]} />

              <Section title="Credit Breakdown" pairs={[
                ['Rents', money(c.rents_credit)], ['Salik', money(c.salik_credit)], ['Damages', money(c.damages_credit)],
                ['Breaches', money(c.breachs_credit)], ['Extra charges', money(c.extra_charges_credit)], ['KM', money(c.km_credit)],
                ['Fuel', money(c.fuel_credit)], ['GPS', money(c.gps_credit)], ['CDW', money(c.cdw_credit)],
                ['Extra driver', money(c.extra_driver_credit)], ['VAT', money(c.vat_credit)], ['Deposit', money(c.deposit_credit)],
              ]} />

              <Section title="Totals & Adjustments" pairs={[
                ['Contract Debit', money(c.contract_debit)], ['Contract Credit', money(c.contract_credit)], ['Balance', money(c.contract_balance)],
                ['Refunds', money(c.contract_refunds)], ['Discount', money(c.contract_discount)], ['Bad Debts', money(c.contract_bad_debts)],
                ['Deposit', money(c.contract_deposit)], ['Commissions', money(c.contract_commissions)], ['Income', money(c.contract_income)],
              ]} />
            </>
          )}
        </div>

        {c.remarks && (
          <Card className="p-6">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Remarks</h3>
            <p className="text-sm text-gray-700">{c.remarks}</p>
          </Card>
        )}
      </div>
    </div>
  );
}
