import { useCallback, useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import { RadialGauge, useCountUp } from '../components/ui/Gauge';
import FleetStatusCard from '../components/ui/FleetStatusCard';
import { TimelineBar } from '../components/ui/Progress';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import Sparkline from '../components/ui/Sparkline';
import FleetPulseGrid from '../components/FleetPulseGrid';
import { usePageStat } from '../components/PageStat';
import { MaintenanceBoardPanel } from './MaintenanceBoard';
import { aed, aed2, fmtDate } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';
import { useAuth } from '../auth/AuthContext';

// Time-of-day greeting for the dashboard header ("Good morning, Rami!").
function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

// Compact currency for chart axes (AED 4,180 → "4.2k") so y-labels never overflow.
const aedK = (n) => {
  const v = Number(n) || 0;
  if (Math.abs(v) >= 1000) return `${(v / 1000).toFixed(Math.abs(v) % 1000 ? 1 : 0)}k`;
  return Math.round(v).toString();
};

// A small "plate" chip that reads like a real number plate.
function PlateChip({ plate }) {
  if (!plate) return <span className="text-slate-300">—</span>;
  return (
    <span className="inline-flex items-center rounded-md border border-slate-300 bg-slate-50 px-2 py-0.5 font-mono text-xs font-semibold tracking-wider text-slate-700">
      {plate}
    </span>
  );
}

// Map a "days until expiry" number to an urgency colour. Red = expired,
// amber = this week, blue = this month, emerald = comfortably ahead.
function urgency(days) {
  if (days === null || days === undefined) return { ring: '#94a3b8', text: 'text-slate-400' };
  if (days < 0)  return { ring: '#ef4444', text: 'text-red-600' };
  if (days <= 7) return { ring: '#f59e0b', text: 'text-amber-600' };
  if (days <= 30) return { ring: '#3b82f6', text: 'text-blue-600' };
  return { ring: '#10b981', text: 'text-emerald-600' };
}

// A small animated countdown ring — the "days left" rendered as a depleting arc
// over a 30-day window, with the number (or "!" when expired) in the centre.
// This replaces a flat expiry cell with a glanceable, alive indicator.
function MiniCountRing({ days, label }) {
  const size = 60, stroke = 5;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const u = urgency(days);
  const ratio = days == null ? 0 : Math.max(0, Math.min(1, days / 30));
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(ratio));
    return () => cancelAnimationFrame(id);
  }, [ratio]);
  const center = days == null ? '—' : days < 0 ? '!' : days;
  const expired = days != null && days < 0;
  return (
    <div className="flex flex-col items-center gap-1">
      <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(var(--line))" strokeWidth={stroke} />
          <circle
            cx={size / 2} cy={size / 2} r={r} fill="none" stroke={u.ring} strokeWidth={stroke}
            strokeLinecap="round" strokeDasharray={c}
            strokeDashoffset={expired ? 0 : c * (1 - grow)}
            style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
          />
        </svg>
        <span className={`absolute font-display text-lg font-bold tabular-nums ${u.text}`}>{center}</span>
      </div>
      <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
    </div>
  );
}

// A number that counts up from 0 on mount — used in the greeting header.
function CountUp({ value, format }) {
  const v = useCountUp(value);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

// Percent change vs. a prior value. Returns null when there's no basis to compare
// against (a zero previous month), so we never print a misleading "+100%".
function pctChange(cur, prev) {
  if (!prev) return null;
  return ((cur - prev) / Math.abs(prev)) * 100;
}

// Delta-pill tones. Arrow always shows the real DIRECTION of change; colour shows
// the SENTIMENT (is this movement good or bad for the business?).
const DELTA = {
  good: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  bad:  'bg-red-50 text-red-600 ring-red-600/20',
  flat: 'bg-slate-100 text-slate-500 ring-slate-500/20',
};
const TILE_TONE = {
  indigo:  'bg-indigo-100 text-indigo-600',
  emerald: 'bg-emerald-100 text-emerald-600',
  amber:   'bg-amber-100 text-amber-600',
  violet:  'bg-violet-100 text-violet-600',
};
const TILE_TONE_SOFT = {
  amber: 'bg-amber-100 text-amber-600',
  red:   'bg-red-100 text-red-600',
  blue:  'bg-blue-100 text-blue-600',
};

// A compact statistics row for the Gatra-style "Statistics" column: an icon,
// label + value + month-over-month delta pill on the left, and a real trailing
// sparkline on the right. `goodWhen` ('down'|'up'|null) decides whether a rise is
// celebrated (green) or flagged (red). The whole row can deep-link via `to`.
function StatRow({ icon, tone = 'indigo', label, value, cur, prev, series, goodWhen = null, to }) {
  const pct = pctChange(cur, prev);
  const dir = cur > prev ? 'up' : cur < prev ? 'down' : 'flat';
  const sentiment = goodWhen == null || dir === 'flat' ? 'flat' : dir === goodWhen ? 'good' : 'bad';
  const arrow = dir === 'up' ? 'M5 15l7-7 7 7' : dir === 'down' ? 'M19 9l-7 7-7-7' : 'M5 12h14';
  const sparkColor = sentiment === 'good' ? 'emerald' : sentiment === 'bad' ? 'red' : tone;
  const Wrap = to ? Link : 'div';
  const wrapProps = to ? { to } : {};

  return (
    <Wrap
      {...wrapProps}
      className={`flex items-center gap-3 py-3 ${to ? '-mx-2 rounded-xl px-2 transition hover:bg-slate-50' : ''}`}
    >
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${TILE_TONE[tone] || TILE_TONE.indigo}`}>
        {icon}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-xs font-medium text-slate-500">{label}</p>
        <div className="mt-0.5 flex items-center gap-1.5">
          <p className="font-display text-base font-bold leading-none tabular-nums text-slate-900">{value}</p>
          {pct != null && (
            <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${DELTA[sentiment]}`}>
              <svg className="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                <path d={arrow} />
              </svg>
              {Math.abs(pct) < 0.5 ? '0%' : `${pct > 0 ? '+' : ''}${pct.toFixed(0)}%`}
            </span>
          )}
        </div>
      </div>
      {series && series.length > 1 && (
        <div className="w-20 shrink-0"><Sparkline data={series} color={sparkColor} height={32} /></div>
      )}
    </Wrap>
  );
}

// Proactive Flags — three forward-looking groups (rentals expiring within 7 days, returned
// rentals with an unpaid balance, inspections due/overdue). Reads /Dashboard/proactive-flags,
// the SAME source lists the notification bell raises rental_expiring / invoice_overdue /
// inspection_due alerts from — so a card here and its bell alert can never disagree. Every row
// deep-links to its source record (traceability). The money group is gated by SHOW_FINANCIALS.
function ProactiveFlags({ data, loading }) {
  const expiry = data?.contract_expiry || { count: 0, items: [] };
  const invoices = data?.invoice_overdue || { count: 0, items: [] };
  const inspections = data?.inspection_due || { count: 0, items: [] };

  const groups = [
    {
      key: 'expiry', title: 'Rentals Expiring', icon: <Icon.Clock className="h-4 w-4" />, tone: 'amber',
      count: expiry.count, viewAll: '/contracts', empty: 'No rentals due in the next 7 days',
      rows: (expiry.items || []).map((r) => ({
        to: `/contracts/${r.id}`,
        primary: r.plate || r.car || `#${r.contract_no || r.id}`,
        secondary: [r.customer, `due ${fmtDate(r.due)}`].filter(Boolean).join(' · '),
        right: r.days_left <= 0 ? 'today' : `${r.days_left}d`,
        rightTone: r.days_left <= 2 ? 'text-amber-600' : 'text-slate-500',
      })),
    },
    ...(SHOW_FINANCIALS ? [{
      key: 'invoices', title: 'Payments Overdue', icon: <Icon.Coins className="h-4 w-4" />, tone: 'red',
      count: invoices.count, note: invoices.total ? aed(invoices.total) : null,
      viewAll: '/contracts', empty: 'No unpaid balances on returned rentals',
      rows: (invoices.items || []).map((r) => ({
        to: `/contracts/${r.id}`,
        primary: r.customer || `#${r.contract_no || r.id}`,
        secondary: [r.plate, `returned ${fmtDate(r.returned_on)}`].filter(Boolean).join(' · '),
        right: aed2(r.balance),
        rightTone: 'text-red-600',
      })),
    }] : []),
    {
      key: 'inspections', title: 'Inspections Due', icon: <Icon.Alert className="h-4 w-4" />, tone: 'blue',
      count: inspections.count, viewAll: '/inspections/schedules', empty: 'No inspections due or overdue',
      rows: (inspections.items || []).map((r) => ({
        to: r.vehicle_id ? `/vehicles/${r.vehicle_id}` : '/inspections/schedules',
        primary: r.plate || r.car || r.name || 'Vehicle',
        secondary: [r.name, r.car].filter(Boolean).join(' · '),
        right: r.status === 'overdue' ? 'overdue' : 'due soon',
        rightTone: r.status === 'overdue' ? 'text-red-600' : 'text-amber-600',
      })),
    },
  ];

  const totalCount = groups.reduce((s, g) => s + (g.count || 0), 0);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Proactive Flags
          <InfoTip content="Forward-looking conditions to act on before they become problems. Sources — Rentals Expiring: open rental contracts (out date + planned days) due within 7 days; Payments Overdue: returned rentals with an outstanding contract balance; Inspections Due: active inspection schedules past or near their due point. Same sources as the notification bell — click any row to open its record." />
        </span>
      }
      subtitle="Coming due in the next 7 days — act before it slips"
      actions={<Badge tone={totalCount ? 'amber' : 'gray'}>{totalCount}</Badge>}
    >
      {loading ? (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-40 rounded-2xl" />)}
        </div>
      ) : (
        <div className={`grid grid-cols-1 gap-4 ${SHOW_FINANCIALS ? 'lg:grid-cols-3' : 'sm:grid-cols-2'}`}>
          {groups.map((g) => (
            <div key={g.key} className="rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${TILE_TONE_SOFT[g.tone]}`}>{g.icon}</span>
                  <h3 className="truncate text-sm font-semibold text-slate-800">{g.title}</h3>
                  <Badge tone={g.count ? g.tone : 'gray'}>{g.count}</Badge>
                  {g.note && <span className="truncate text-xs font-medium text-slate-400">{g.note}</span>}
                </div>
                <Link to={g.viewAll} className="shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-700">All →</Link>
              </div>
              {g.rows.length === 0 ? (
                <p className="py-6 text-center text-xs text-slate-400">{g.empty}</p>
              ) : (
                <ul className="space-y-1">
                  {g.rows.map((row, i) => (
                    <li key={i}>
                      <Link to={row.to} className="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2 hover:bg-slate-50">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-800">{row.primary}</p>
                          <p className="truncate text-xs text-slate-400">{row.secondary || '—'}</p>
                        </div>
                        <span className={`shrink-0 text-sm font-bold tabular-nums ${row.rightTone}`}>{row.right}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}
    </SectionCard>
  );
}

// Rental Billing — live invoice settlement (Track A). Paid / Partial / Not Paid counts for the
// OM-synced rental invoices, derived from each invoice's balance on sync (no manual sheet).
// Deep-links to the full Financial Reconciliation page. Money widget → gated by SHOW_FINANCIALS.
const BILL_TILES = [
  { key: 'paid',     label: 'Paid',     ring: 'border-emerald-200 bg-emerald-50', num: 'text-emerald-700', dot: 'bg-emerald-500' },
  { key: 'partial',  label: 'Partial',  ring: 'border-amber-200 bg-amber-50',     num: 'text-amber-700',   dot: 'bg-amber-500' },
  { key: 'not_paid', label: 'Not Paid', ring: 'border-red-200 bg-red-50',         num: 'text-red-600',     dot: 'bg-red-500' },
];
function RentalBillingSummary({ billing, loading }) {
  const b = billing || {};
  const total = b.total || 0;
  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Rental Billing
          <InfoTip content="Live settlement status of rental invoices synced from OfficeManager — Paid, Partial, or Not Paid is derived from each invoice's outstanding balance on every sync. Replaces the manual bills sheet." />
        </span>
      }
      subtitle="Invoice settlement · synced from OfficeManager"
      actions={<Link to="/financial-reconciliation" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Financial Reconciliation →</Link>}
      bodyClass="px-5 py-4 sm:px-6"
    >
      {loading ? (
        <MetricGridSkeleton count={3} />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-3">
            {BILL_TILES.map((t) => (
              <Link
                key={t.key}
                to={`/financial-reconciliation?payment_status=${t.key}`}
                className={`rounded-2xl border ${t.ring} px-4 py-3 transition hover:shadow-sm`}
              >
                <div className="flex items-center gap-1.5">
                  <span className={`h-2 w-2 rounded-full ${t.dot}`} />
                  <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t.label}</span>
                </div>
                <div className={`mt-1 font-display text-3xl font-bold tabular-nums ${t.num}`}>
                  {Number(b[t.key] || 0).toLocaleString()}
                </div>
              </Link>
            ))}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
            <span className="text-slate-500">
              {Number(total).toLocaleString()} rental invoice{total === 1 ? '' : 's'} with live status
              {b.unsynced ? ` · ${Number(b.unsynced).toLocaleString()} awaiting status sync` : ''}
            </span>
            <span className="font-medium text-slate-700">
              {Number(b.pending || 0).toLocaleString()} pending ·{' '}
              <span className="tabular-nums">{aed(b.outstanding_balance || 0)}</span> outstanding
            </span>
          </div>
        </div>
      )}
    </SectionCard>
  );
}

export default function Dashboard() {
  const { user } = useAuth();
  const firstName = user?.name ? String(user.name).trim().split(/\s+/)[0] : '';
  const [view, setView] = useState('metrics'); // 'metrics' | 'pulse'

  const fetcher = useCallback(async () => {
    // The KPI summary is the one critical call (drives the headline counts + fleet
    // composition). The auxiliary feeds degrade to empty on failure, so a flaky
    // trends/overdue/expiring endpoint can never blank the whole dashboard.
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const emptyFlags = { contract_expiry: { count: 0, items: [] }, invoice_overdue: { count: 0, items: [] }, inspection_due: { count: 0, items: [] } };
    const emptyBilling = { paid: 0, partial: 0, not_paid: 0, unsynced: 0, pending: 0, total: 0, outstanding_balance: 0 };
    const [kpiRes, expRes, overRentRes, overMaintRes, trendsRes, flagsRes, billingRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Fleet/expiring', { params: { days: 30 } }).catch(safe([])),
      api.get('/Dashboard/overdue-rentals').catch(safe([])),
      api.get('/Dashboard/overdue-maintenance').catch(safe([])),
      api.get('/Dashboard/trends', { params: { months: 12 } }).catch(safe({ cost: [], downtime: [] })),
      api.get('/Dashboard/proactive-flags', { params: { days: 7 } }).catch(safe(emptyFlags)),
      api.get('/Invoice/status-summary').catch(safe(emptyBilling)),
    ]);
    // Merge overdue rentals + maintenance into one "attention needed" list,
    // most-overdue first. Monitoring only — nothing here auto-closes a contract.
    const rentals = (overRentRes.data.data || []).map((r) => ({ ...r, kind: 'Rental' }));
    const maint = (overMaintRes.data.data || []).map((r) => ({ ...r, kind: 'Maintenance' }));
    return {
      kpis: kpiRes.data.data || {},
      expiring: (expRes.data.data || []).slice(0, 8),
      overdue: [...rentals, ...maint].sort((a, b) => (b.days_overdue || 0) - (a.days_overdue || 0)),
      trends: trendsRes.data.data || { cost: [], downtime: [] },
      proactive: flagsRes.data.data || emptyFlags,
      billing: billingRes.data.data || emptyBilling,
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const expiring = data?.expiring || [];
  const overdue = data?.overdue || [];
  const trends = data?.trends || { cost: [], downtime: [] };
  const proactive = data?.proactive || {};
  const billing = data?.billing || {};

  const fleet = kpis.fleet_status || {};
  const fleetTotal = fleet.total || 0;
  const available = fleet.available || 0;
  const rented = fleet.rented || 0;
  const maint = fleet.maintenance || 0;
  const outOfOrder = fleet.out_of_order || 0;
  const utilization = fleetTotal ? Math.round((rented / fleetTotal) * 100) : 0;
  // Dead capacity = fleet not earning because it's in the shop / out of order.
  const deadCount = maint + outOfOrder;
  const downtimePct = fleetTotal ? Math.round((deadCount / fleetTotal) * 100) : 0;

  // Headline performance band — derived entirely from the real 12-month trend
  // series already fetched, so the numbers always agree with the charts below.
  const costSeries = trends.cost || [];
  const downSeries = trends.downtime || [];
  const last = (arr, k) => Number(arr[arr.length - 1]?.[k]) || 0;
  const prev = (arr, k) => Number(arr[arr.length - 2]?.[k]) || 0;
  const spend12mo = costSeries.reduce((s, m) => s + (Number(m.value) || 0), 0);
  const totalVisits = costSeries.reduce((s, m) => s + (Number(m.visits) || 0), 0);

  // Headline percent for the floating page gauge: fleet utilization.
  usePageStat({
    percent: loading || !fleetTotal ? null : utilization,
    label: 'Utilization',
    color: 'indigo',
    hint: `${rented} of ${fleetTotal} cars currently rented out`,
  });

  // Fleet Status — a four-bucket live snapshot for the headline donut. The three
  // operational states plus a single "Unavailable" catch-all (out-of-order,
  // suspended, office-use, returned, sold, disposed, other) so the slices always
  // sum to the true fleet total with nothing dropped.
  const unavailable = Math.max(0, fleetTotal - available - rented - maint);
  const fleetStatusSegments = [
    { label: 'Available',   value: available,   color: 'green'  },
    { label: 'On Rent',     value: rented,      color: 'blue'   },
    { label: 'Maintenance', value: maint,       color: 'yellow' },
    { label: 'Unavailable', value: unavailable, color: 'red'    },
  ];

  // The Gatra-style "Statistics" column — real month-over-month KPIs, each backed by
  // a trailing sparkline where a real series exists. Money rows are gated behind
  // SHOW_FINANCIALS; when hidden we swap in two operational rows so the panel stays full.
  const statRows = [
    ...(SHOW_FINANCIALS ? [{
      icon: <Icon.Coins className="h-4 w-4" />, tone: 'indigo', label: 'Maintenance Spend · this month',
      value: aed(last(costSeries, 'value')), cur: last(costSeries, 'value'), prev: prev(costSeries, 'value'),
      series: costSeries, goodWhen: 'down',
    }] : []),
    {
      icon: <Icon.Wrench className="h-4 w-4" />, tone: 'amber', label: 'Workshop Visits · this month',
      value: last(costSeries, 'visits').toLocaleString(), cur: last(costSeries, 'visits'), prev: prev(costSeries, 'visits'),
      series: costSeries.map((m) => m.visits), goodWhen: 'down',
    },
    {
      icon: <Icon.Activity className="h-4 w-4" />, tone: 'emerald', label: 'Avg Downtime · days per visit',
      value: `${last(downSeries, 'value')}d`, cur: last(downSeries, 'value'), prev: prev(downSeries, 'value'),
      series: downSeries, goodWhen: 'down',
    },
    ...(SHOW_FINANCIALS ? [{
      icon: <Icon.Chart className="h-4 w-4" />, tone: 'violet', label: 'Spend · last 12 months',
      value: aed(spend12mo), series: costSeries,
    }] : [
      {
        icon: <Icon.Invoice className="h-4 w-4" />, tone: 'indigo', label: 'Active Contracts',
        value: Number(kpis.active_contracts || 0).toLocaleString(), to: '/contracts',
      },
      {
        icon: <Icon.Car className="h-4 w-4" />, tone: 'violet', label: 'Cars in Maintenance',
        value: Number(kpis.cars_in_maintenance || 0).toLocaleString(), to: '/maintenance',
      },
    ]),
  ];

  // The three sub-tiles under the Rent Status gauge (Gatra's Hired / Pending / Cancelled,
  // re-cast as our real operational states: rented / available / in the shop).
  const rentTiles = [
    { label: 'Rented',    val: rented,    icon: <Icon.Car className="h-4 w-4" />,    cls: 'bg-blue-50 text-blue-600' },
    { label: 'Available', val: available, icon: <Icon.Check className="h-4 w-4" />,  cls: 'bg-emerald-50 text-emerald-600' },
    { label: 'In Shop',   val: maint,     icon: <Icon.Wrench className="h-4 w-4" />, cls: 'bg-amber-50 text-amber-600' },
  ];

  // KPI tiles — each maps to a semantic tone, a design-system icon, a short hint
  // and a tooltip that defines the metric. The whole tile links via MetricCard's `to`.
  const cards = [
    {
      label: 'Pending Approvals', key: 'pending_approvals', tone: 'amber', icon: <Icon.Check className="h-5 w-5" />,
      to: '/maintenance-approvals', hint: 'Awaiting sign-off',
      tooltip: 'Maintenance bills submitted by the garage that still need a manager to approve before they are booked as cost.',
    },
    {
      label: 'Overdue Rentals', key: 'overdue_rentals', tone: 'red', icon: <Icon.Clock className="h-5 w-5" />,
      to: '/overdue-rentals', hint: 'Past estimated return date',
      tooltip: 'Open rental contracts whose expected return date has already passed — contact the customer for an extension.',
    },
    {
      label: 'Cars in Maintenance', key: 'cars_in_maintenance', tone: 'amber', icon: <Icon.Wrench className="h-5 w-5" />,
      to: '/maintenance', hint: 'Currently in the garage',
      tooltip: 'Vehicles whose live operational status is "in maintenance" — not earning while under repair.',
    },
    {
      label: 'Active Contracts', key: 'active_contracts', tone: 'blue', icon: <Icon.Invoice className="h-5 w-5" />,
      to: '/contracts', hint: 'Cars currently out',
      tooltip: 'Open rental contracts — cars that are currently checked out to a customer.',
    },
    {
      label: 'Expiring Documents', key: 'expiring_registrations', tone: 'amber', icon: <Icon.Alert className="h-5 w-5" />,
      to: '/registrations', hint: 'Registration / insurance ≤ 7 days',
      tooltip: 'Vehicles whose registration (Mulkiya) or insurance expires within the next 7 days.',
    },
    {
      label: 'Vehicles For Sale', key: 'vehicles_for_sale', tone: 'violet', icon: <Icon.Flag className="h-5 w-5" />,
      to: '/vehicles', hint: 'Flagged for disposal',
      tooltip: 'Cars flagged to be sold or disposed and removed from the active rental fleet.',
    },
    {
      label: 'Negative Yield', key: 'negative_yield', value: (k) => k.negative_yield?.count, tone: 'red', icon: <Icon.TrendDown className="h-5 w-5" />,
      to: '/maintenance-foresight', hint: 'Real net profit below repair cost (12mo)',
      tooltip: 'Cars whose real net profit over the last 12 months is below what was spent repairing them — money-losers to review.',
    },
    {
      label: 'Repairs Without Cost', key: 'uncosted_repairs', tone: 'indigo', icon: <Icon.Coins className="h-5 w-5" />,
      to: '/cost-capture', hint: 'Tap to enter repair amounts (12mo)',
      tooltip: 'Closed repairs in the last 12 months that have no cost recorded yet — fill these in for accurate per-car profit.',
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Greeting header — a light, personable "Good morning, {name}!" band with a live
            pulse, the last-updated stamp, the fleet-size counter, and the view switcher. */}
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-indigo-500">
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-70" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
              </span>
              Fleet Command Center · Live
            </p>
            <h1 className="mt-1.5 font-display text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
              {greeting()}{firstName ? `, ${firstName}` : ''}!
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Live snapshot of your fleet's {SHOW_FINANCIALS ? 'finances and operations' : 'status and operations'}.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-2 rounded-xl border border-slate-200/70 bg-white px-4 py-2 shadow-soft">
              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600"><Icon.Car className="h-5 w-5" /></span>
              <div className="text-right">
                <p className="text-[11px] font-medium text-slate-400">Fleet size</p>
                <p className="font-display text-lg font-bold leading-none tabular-nums text-slate-900">
                  {loading ? '—' : <CountUp value={fleetTotal} />}
                </p>
              </div>
            </div>
            <span className="hidden text-xs font-medium text-slate-400 sm:inline">Updated {fmtDate(new Date())}</span>
            {/* View toggle — flip between the analytical "Metrics" view and the live "Fleet Pulse" wall. */}
            <div className="inline-flex rounded-xl bg-slate-100 p-1">
              {[
                { key: 'metrics', label: 'Metrics', icon: <Icon.Chart className="h-4 w-4" /> },
                { key: 'pulse', label: 'Fleet Pulse', icon: <Icon.Activity className="h-4 w-4" /> },
              ].map((t) => (
                <button
                  key={t.key}
                  type="button"
                  onClick={() => setView(t.key)}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-semibold transition ${
                    view === t.key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {t.icon}
                  {t.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {view === 'pulse' && <FleetPulseGrid />}

        {view === 'metrics' && (
          <>
        {/* ── Command band — the Gatra-style hero row: Available Cars + Workshop Activity
            on the left, the Rent Status gauge in the middle, and the Statistics column
            on the right. Everything below maps to a real endpoint (no fake widgets). ── */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {/* Left column — Available Cars (condition bar) + Workshop Activity (line) */}
          <div className="space-y-6">
            {/* Available Cars — the count free to rent, with a live condition split. */}
            <Card className="p-5">
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-3">
                  <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600"><Icon.Car className="h-5 w-5" /></span>
                  <div>
                    <p className="flex items-center gap-1 text-xs font-medium text-slate-500">
                      Available Cars
                      <InfoTip content="Cars free to rent right now (OfficeManager 'available'). The bar below splits the active fleet by condition — Good (ready), Needs Service (in the shop) and Damaged (out of order)." />
                    </p>
                    <p className="font-display text-2xl font-bold leading-none tabular-nums text-slate-900">
                      {loading ? '—' : available.toLocaleString()}
                    </p>
                  </div>
                </div>
                <Link to="/vehicles" className="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">See All</Link>
              </div>
              <div className="mt-4">
                {loading ? (
                  <Skeleton className="h-3 w-full rounded-full" />
                ) : (
                  <TimelineBar
                    segments={[
                      { label: 'Good Condition', value: available, tone: 'success' },
                      { label: 'Needs Service', value: maint, tone: 'amber' },
                      { label: 'Damaged', value: outOfOrder, tone: 'red' },
                    ]}
                  />
                )}
              </div>
            </Card>

            {/* Workshop Activity — real monthly repair-visit trend (replaces the map/bookings widget). */}
            <Card className="p-5">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-600"><Icon.Wrench className="h-4 w-4" /></span>
                  <div>
                    <p className="text-xs font-medium text-slate-500">Workshop Activity</p>
                    <p className="font-display text-xl font-bold leading-none tabular-nums text-slate-900">
                      {loading ? '—' : `${totalVisits.toLocaleString()} visits`}
                    </p>
                  </div>
                </div>
                <span className="rounded-lg bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-500">12 mo</span>
              </div>
              <div className="mt-2">
                {loading ? (
                  <Skeleton className="h-[120px] w-full rounded-xl" />
                ) : (
                  <LineChart
                    data={costSeries.map((m) => ({ label: m.label, value: m.visits }))}
                    color="amber"
                    height={120}
                    yTicks={3}
                    format={(v) => `${Math.round(v)}`}
                    tickFormat={(v) => `${Math.round(v)}`}
                    valueLabel="Visits"
                  />
                )}
              </div>
            </Card>
          </div>

          {/* Center — Rent Status radial gauge with the operational sub-tiles. */}
          <Card className="flex flex-col p-5">
            <div className="flex items-center gap-1.5">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-100 text-blue-600"><Icon.Gauge className="h-4 w-4" /></span>
              <h2 className="text-sm font-semibold text-slate-800">Rent Status</h2>
              <InfoTip content="How much of the fleet is earning right now. The ring fills to utilization — rented ÷ total. The tiles below split every car into rented, available and in the shop." />
            </div>

            <div className="flex flex-1 flex-col items-center justify-center pt-6 pb-2">
              {loading ? (
                <Skeleton className="h-[168px] w-[168px] rounded-full" />
              ) : (
                <RadialGauge value={rented} max={fleetTotal} color="indigo" size={168} stroke={14} format={(v) => Math.round(v).toLocaleString()} />
              )}
            </div>

            {!loading && (
              <p className="mt-4 text-center">
                <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600">
                  {utilization}% fleet utilization
                </span>
              </p>
            )}

            <div className="mt-4 grid grid-cols-3 gap-2">
              {rentTiles.map((t) => (
                <div key={t.label} className="rounded-2xl border border-slate-200/60 p-3 text-center">
                  <span className={`mx-auto flex h-8 w-8 items-center justify-center rounded-lg ${t.cls}`}>{t.icon}</span>
                  <p className="mt-1.5 font-display text-lg font-bold tabular-nums text-slate-900">{loading ? '—' : t.val.toLocaleString()}</p>
                  <p className="text-[11px] font-medium text-slate-400">{t.label}</p>
                </div>
              ))}
            </div>
            <p className="mt-3 text-center text-xs text-slate-400">
              {loading ? '' : `${rented} of ${fleetTotal} cars currently rented out`}
            </p>
          </Card>

          {/* Right — Statistics: real MoM KPIs with trailing sparklines. */}
          <Card className="p-5">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 text-violet-600"><Icon.Chart className="h-4 w-4" /></span>
                <h2 className="text-sm font-semibold text-slate-800">Statistics</h2>
              </div>
              <span className="text-[11px] font-medium text-slate-400">Last 12 months</span>
            </div>
            <div className="mt-1 divide-y divide-slate-100">
              {loading
                ? Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="py-3"><Skeleton className="h-9 w-full rounded-xl" /></div>
                  ))
                : statRows.map((r, i) => <StatRow key={i} {...r} />)}
            </div>
          </Card>
        </div>

        {/* Fleet composition — the big glowing status donut beside a live capacity gauge. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {loading ? (
            <Card className="lg:col-span-2">
              <div className="flex justify-center py-16"><Skeleton className="h-56 w-56 rounded-full" /></div>
            </Card>
          ) : (
            <FleetStatusCard
              className="lg:col-span-2"
              title="Fleet Status"
              centerLabel="Total Fleet"
              unit="cars"
              total={fleetTotal}
              segments={fleetStatusSegments}
              headerRight={
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                  <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                  </span>
                  Live
                </span>
              }
            />
          )}

          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Capacity
                <InfoTip content="Dead Capacity = the share of the fleet not earning because it's in the shop or out of order. The bar splits every car into rented, available and in-shop right now." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Idle vs. earning, right now</p>
            </div>
            <div className="space-y-7 px-6 py-7">
              {loading ? (
                <div className="flex justify-center"><Skeleton className="h-28 w-28 rounded-full" /></div>
              ) : (
                <div className="flex justify-center pb-7">
                  <RadialGauge value={downtimePct} max={100} color="orange" label="Dead capacity" format={(v) => `${Math.round(v)}%`} size={128} stroke={11} />
                </div>
              )}
              {!loading && (
                <TimelineBar
                  className="w-full"
                  segments={[
                    { label: 'Rented', value: rented, tone: 'brand' },
                    { label: 'Available', value: available, tone: 'success' },
                    { label: 'In shop', value: maint, tone: 'alert' },
                    { label: 'Other', value: unavailable, tone: 'slate' },
                  ]}
                />
              )}
            </div>
          </Card>
        </div>

        {/* KPI cards */}
        {loading ? (
          <MetricGridSkeleton count={8} />
        ) : (
          <MetricGrid cols={4}>
            {cards.filter((card) => SHOW_FINANCIALS || card.key !== 'negative_yield').map((card) => (
              <MetricCard
                key={card.label}
                label={card.label}
                value={Number((card.value ? card.value(kpis) : kpis[card.key]) || 0).toLocaleString()}
                tone={card.tone}
                icon={card.icon}
                hint={card.hint}
                tooltip={card.tooltip}
                to={card.to}
              />
            ))}
          </MetricGrid>
        )}

        {/* Rental Billing — live invoice settlement (Track A): Paid / Partial / Not Paid counts
            for OM-synced rental invoices, derived on sync (replaces the manual bills sheet). */}
        {SHOW_FINANCIALS && <RentalBillingSummary billing={billing} loading={loading} />}

        {/* Proactive Flags — forward-looking conditions (rentals expiring, payments overdue,
            inspections due) surfaced before they become problems. Same source lists as the
            notification bell; every row deep-links to its record. */}
        <ProactiveFlags data={proactive} loading={loading} />

        {/* Data visualization — maintenance spend per month (bar) and the downtime
            trend (line). Both are bespoke SVG, so they match the gauges and donut. */}
        <div className={`grid grid-cols-1 gap-6 ${SHOW_FINANCIALS ? 'lg:grid-cols-2' : ''}`}>
          {SHOW_FINANCIALS && (
          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Maintenance Cost
                <InfoTip content="Total workshop spend per month over the last 12 months, from the live garage log (imported + hand-entered events). Hover a bar for the visit count." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Monthly repair spend · last 12 months</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <BarChart
                  data={trends.cost}
                  color="indigo"
                  height={260}
                  format={aed}
                  tickFormat={aedK}
                  valueLabel="Spend"
                  tooltip={(d) => `${d.visits} visit${d.visits === 1 ? '' : 's'}`}
                />
              )}
            </div>
          </Card>
          )}

          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Downtime Trend
                <InfoTip content="Average number of days a car spent in the shop per repair visit, by month. A falling line means cars are being turned around faster — downtime is improving." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Avg days in shop per visit · lower is better</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <LineChart
                  data={trends.downtime}
                  color="emerald"
                  height={260}
                  format={(v) => `${v} day${v === 1 ? '' : 's'}`}
                  tickFormat={(v) => `${Math.round(v)}d`}
                  valueLabel="Avg in shop"
                  tooltip={(d) => `${d.visits} visit${d.visits === 1 ? '' : 's'}`}
                />
              )}
            </div>
          </Card>
        </div>

        {/* Maintenance Board — the operational core of the product, front and center.
            Reuses the live board panel so the Dashboard never drifts from /maintenance. */}
        <section className="space-y-4">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 className="text-xl font-bold tracking-tight text-slate-900">Maintenance Board</h2>
              <p className="mt-1 text-sm text-slate-500">
                Cars currently in the garage — due dates, issues{SHOW_FINANCIALS ? ', cost' : ''} and live status.
              </p>
            </div>
            <div className="flex items-center gap-4">
              {SHOW_FINANCIALS && (
                <Link to="/maintenance-analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Cost Analytics →</Link>
              )}
              <Link to="/maintenance" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Open full board →</Link>
            </div>
          </div>
          <MaintenanceBoardPanel />
        </section>

        {/* Expiring soon — registration / insurance due in the next 30 days,
            shown as glanceable countdown-ring cards instead of a flat table. */}
        <SectionCard
          title="Expiring Soon"
          subtitle="Registration & insurance due in the next 30 days"
          actions={
            <>
              <Badge tone="gray">next 30 days</Badge>
              <Link to="/registrations" className="hidden text-xs font-medium text-indigo-600 hover:text-indigo-700 sm:inline">View all →</Link>
            </>
          }
        >
          {loading ? (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-[92px] rounded-2xl" />)}
            </div>
          ) : expiring.length === 0 ? (
            <p className="py-8 text-center text-sm text-slate-400">Nothing expiring soon 🎉</p>
          ) : (
            <div className="stagger grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {expiring.map((r, i) => {
                const overdue = (r.registration_days_left != null && r.registration_days_left < 0) || (r.insurance_days_left != null && r.insurance_days_left < 0);
                return (
                  <Link
                    key={`${r.plate_no || r.vehicle || 'exp'}-${i}`}
                    to="/registrations"
                    className={`hover-lift flex items-center justify-between gap-3 rounded-2xl border bg-white p-4 shadow-soft ${overdue ? 'border-red-200 ring-1 ring-red-100' : 'border-slate-200/60'}`}
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                        <Icon.Car className="h-4 w-4" />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{r.vehicle || '—'}</p>
                        <span className="mt-1 inline-block"><PlateChip plate={r.plate_no} /></span>
                      </div>
                    </div>
                    <div className="flex shrink-0 gap-2">
                      <MiniCountRing days={r.registration_days_left} label="Mulkiya" />
                      <MiniCountRing days={r.insurance_days_left} label="Insurance" />
                    </div>
                  </Link>
                );
              })}
            </div>
          )}
        </SectionCard>

        {/* Overdue / Attention Needed — open rentals AND maintenance whose expected
            return date has passed. Monitoring only: nothing here closes a contract,
            so extensions and garage delays just surface for the team to action. */}
        {!loading && overdue.length > 0 && (
          <SectionCard
            className="ring-1 ring-red-200"
            title={
              <span className="flex items-center gap-2 text-red-700">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-100 text-red-600">!</span>
                Overdue Contracts / Attention Needed
              </span>
            }
            subtitle="Expected return date has passed — contact the customer for an extension or check with the garage."
            actions={<Badge tone="red">{overdue.length}</Badge>}
          >
            <div className="stagger space-y-2.5">
              {overdue.slice(0, 12).map((r, i) => {
                const maxLate = overdue[0]?.days_overdue || 1;
                const latePct = Math.max(6, Math.min(100, Math.round(((r.days_overdue || 0) / maxLate) * 100)));
                const isMaint = r.kind === 'Maintenance';
                const rail = isMaint ? '#f59e0b' : '#3b82f6';
                const hot = (r.days_overdue || 0) >= 7;
                return (
                  <div
                    key={`${r.kind}-${r.id}`}
                    className="hover-lift relative overflow-hidden rounded-2xl border border-slate-200/60 bg-white pl-4 pr-4 py-3 shadow-soft"
                  >
                    {/* coloured urgency rail down the left edge */}
                    <span className="absolute inset-y-0 left-0 w-1.5" style={{ background: rail }} />
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                      {/* icon + car */}
                      <div className="flex min-w-0 flex-1 items-center gap-3">
                        <span
                          className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                          style={{ background: `${rail}1f`, color: rail }}
                        >
                          {isMaint ? <Icon.Wrench className="h-5 w-5" /> : <Icon.Clock className="h-5 w-5" />}
                        </span>
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <Link to={`/contracts/${r.id}`} className="font-semibold text-slate-900 hover:text-indigo-600">{r.plate || `#${r.contract_no || r.id}`}</Link>
                            <Badge tone={isMaint ? 'amber' : 'blue'}>{r.kind}</Badge>
                          </div>
                          <p className="truncate text-xs text-slate-400">{r.car || '—'}</p>
                        </div>
                      </div>
                      {/* customer / garage */}
                      <div className="min-w-0 sm:w-44">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{isMaint ? 'Garage' : 'Customer'}</p>
                        <p className="truncate text-sm text-slate-700">
                          {r.customer_id
                            ? <Link to={`/customers/${r.customer_id}`} className="hover:text-indigo-600">{r.customer || '—'}</Link>
                            : (r.customer || r.garage || '—')}
                        </p>
                      </div>
                      {/* out → due window */}
                      <div className="hidden sm:block sm:w-40">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Out → Est. return</p>
                        <p className="text-sm tabular-nums text-slate-600">{fmtDate(r.out_date || r.since)} → {fmtDate(r.due)}</p>
                      </div>
                      {/* balance */}
                      {SHOW_FINANCIALS && (
                        <div className="sm:w-24 sm:text-right">
                          <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Balance</p>
                          <p className="text-sm font-semibold tabular-nums text-slate-900">{r.balance ? aed2(r.balance) : '—'}</p>
                        </div>
                      )}
                      {/* days late — bold, with a ping on the worst offenders */}
                      <div className="flex shrink-0 items-center gap-2 sm:w-24 sm:justify-end">
                        {hot && (
                          <span className="relative flex h-2 w-2">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-70" />
                            <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" />
                          </span>
                        )}
                        <span className="font-display text-lg font-bold tabular-nums text-red-600">{r.days_overdue}<span className="ml-0.5 text-xs font-semibold text-red-400">d</span></span>
                      </div>
                    </div>
                    {/* relative-lateness bar */}
                    <div className="mt-2.5 h-1 overflow-hidden rounded-full bg-slate-100">
                      <div className="h-full rounded-full bg-gradient-to-r from-red-400 to-red-600" style={{ width: `${latePct}%` }} />
                    </div>
                  </div>
                );
              })}
            </div>
          </SectionCard>
        )}
          </>
        )}

      </div>
    </div>
  );
}
