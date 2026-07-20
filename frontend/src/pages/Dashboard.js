import { useCallback, useEffect, useState } from 'react';
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
import FleetStatusCard from '../components/ui/FleetStatusCard';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import Sparkline from '../components/ui/Sparkline';
import FleetPulseGrid from '../components/FleetPulseGrid';
import { usePageStat } from '../components/PageStat';
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
    <span className="inline-flex items-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-0.5 font-mono text-xs font-semibold tracking-wider text-slate-700">
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

// A calm, static expiry readout — "days left" as a plain coloured number (or
// "expired"), replacing the animated countdown ring. The colour still encodes
// urgency (red = expired, amber = this week, blue = this month, green = ahead).
function ExpiryStat({ days, label }) {
  const u = urgency(days);
  const text = days == null ? '—' : days < 0 ? 'expired' : `${days}d`;
  return (
    <div className="text-center">
      <p className={`font-display text-base font-bold tabular-nums ${u.text}`}>{text}</p>
      <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
    </div>
  );
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
// Date windows offered by the "Most in Maintenance" filter.
const MM_WINDOWS = [
  { days: 30, label: '30 days' },
  { days: 90, label: '90 days' },
  { days: 180, label: '6 months' },
  { days: 365, label: '1 year' },
];

function ProactiveFlags({ data, loading }) {
  const inShop = data?.in_maintenance || { count: 0, items: [] };
  const invoices = data?.invoice_overdue || { count: 0, items: [] };

  // "Most in Maintenance" has its own date filter, so it fetches independently of the main
  // proactive-flags payload — changing the window refreshes only this column.
  const [mmDays, setMmDays] = useState(90);
  const [mm, setMm] = useState({ count: 0, items: [] });
  const [mmLoading, setMmLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    setMmLoading(true);
    api.get('/Dashboard/most-maintained', { params: { days: mmDays } })
      .then((res) => { if (alive) setMm(res.data.data || { count: 0, items: [] }); })
      .catch(() => { if (alive) setMm({ count: 0, items: [] }); })
      .finally(() => { if (alive) setMmLoading(false); });
    return () => { alive = false; };
  }, [mmDays]);

  // Colour the stage label by urgency: an active repair / failed QA is hot, a move is in-flight,
  // everything else is a calm "waiting" amber.
  const stageTone = (s = '') => (/repair|failed/i.test(s) ? 'text-red-600'
    : /transit|pickup/i.test(s) ? 'text-blue-600' : 'text-amber-600');

  const groups = [
    {
      key: 'maintenance', title: 'In Maintenance', icon: <Icon.Wrench className="h-4 w-4" />, tone: 'blue',
      count: inShop.count, viewAll: '/maintenance-workflow', empty: 'No cars in the workshop right now',
      rows: (inShop.items || []).map((r) => ({
        to: r.id ? `/vehicles/${r.id}` : '/maintenance-workflow',
        primary: r.plate || r.car || 'Vehicle',
        secondary: [r.car, r.garage].filter(Boolean).join(' · '),
        right: r.stage,
        rightTone: stageTone(r.stage),
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
      key: 'mostMaintained', title: 'Most in Maintenance', icon: <Icon.Activity className="h-4 w-4" />, tone: 'amber',
      count: mm.count, viewAll: '/maintenance-history', empty: 'No workshop visits in this period',
      loading: mmLoading,
      // A compact date-window picker lives in this column's header (see renderer).
      control: (
        <select
          value={mmDays}
          onChange={(e) => setMmDays(Number(e.target.value))}
          className="shrink-0 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-200"
          aria-label="Maintenance window"
        >
          {MM_WINDOWS.map((w) => <option key={w.days} value={w.days}>{w.label}</option>)}
        </select>
      ),
      rows: (mm.items || []).map((r) => ({
        to: r.id ? `/vehicles/${r.id}` : '/maintenance-history',
        primary: r.plate || r.car || 'Vehicle',
        secondary: [r.car, r.last_visit ? `last ${fmtDate(r.last_visit)}` : null].filter(Boolean).join(' · '),
        right: `${r.visits} visit${r.visits === 1 ? '' : 's'}`,
        rightTone: r.visits >= 3 ? 'text-red-600' : 'text-slate-600',
      })),
    },
  ];

  // Header badge counts only the "needs action" groups — the most-maintained list is a ranking, not a queue.
  const totalCount = groups.reduce((s, g) => s + (g.key === 'mostMaintained' ? 0 : (g.count || 0)), 0);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Proactive Flags
          <InfoTip content="Conditions to act on. Sources — In Maintenance: cars currently in the workshop, tagged with the lifecycle stage they sit at (pending dispatch → under repair → final QA → ready for pickup); Payments Overdue: returned rentals with an outstanding contract balance; Most in Maintenance: the cars with the most workshop visits over the window you pick (30 days → 1 year). Click any row to open its record." />
        </span>
      }
      subtitle="What needs attention now — cars in the shop, unpaid returns, and your repeat-visit workshop cars"
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
                <div className="flex shrink-0 items-center gap-2">
                  {g.control}
                  <Link to={g.viewAll} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">All →</Link>
                </div>
              </div>
              {g.loading ? (
                <ul className="space-y-1">
                  {Array.from({ length: 3 }).map((_, i) => <li key={i}><Skeleton className="h-10 rounded-xl" /></li>)}
                </ul>
              ) : g.rows.length === 0 ? (
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
  // Workshop Activity date filter — how many trailing months of the 12-month
  // series to show. Preset ranges keep the control to a single clean tap.
  const [wsMonths, setWsMonths] = useState(12);

  const fetcher = useCallback(async () => {
    // The KPI summary is the one critical call (drives the headline counts + fleet
    // composition). The auxiliary feeds degrade to empty on failure, so a flaky
    // trends/overdue/expiring endpoint can never blank the whole dashboard.
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const emptyFlags = { contract_expiry: { count: 0, items: [] }, in_maintenance: { count: 0, items: [] }, invoice_overdue: { count: 0, items: [] }, inspection_due: { count: 0, items: [] } };
    const emptyBilling = { paid: 0, partial: 0, not_paid: 0, unsynced: 0, pending: 0, total: 0, outstanding_balance: 0 };
    const emptyOversight = { mileage_flags: 0, severity_mismatches: 0, misdiagnoses: 0, awaiting_parts: 0, left_garage: 0, resolved_transfers: 0 };
    const [kpiRes, expRes, trendsRes, flagsRes, billingRes, oversightRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Fleet/expiring', { params: { days: 30 } }).catch(safe([])),
      api.get('/Dashboard/trends', { params: { months: 12 } }).catch(safe({ cost: [], downtime: [] })),
      api.get('/Dashboard/proactive-flags', { params: { days: 7 } }).catch(safe(emptyFlags)),
      api.get('/Invoice/status-summary').catch(safe(emptyBilling)),
      api.get('/Oversight/overview').catch(safe(emptyOversight)),
    ]);
    return {
      // Fold the workflow-oversight roll-up (mileage / severity / mis-diagnosis / waiting-for-parts
      // counts) into the KPI object so the oversight KPI tiles read straight from `kpis[card.key]`.
      kpis: { ...(kpiRes.data.data || {}), ...(oversightRes.data.data || emptyOversight) },
      expiring: (expRes.data.data || []).slice(0, 8),
      trends: trendsRes.data.data || { cost: [], downtime: [] },
      proactive: flagsRes.data.data || emptyFlags,
      billing: billingRes.data.data || emptyBilling,
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const expiring = data?.expiring || [];
  const trends = data?.trends || { cost: [], downtime: [] };
  const proactive = data?.proactive || {};
  const billing = data?.billing || {};

  const fleet = kpis.fleet_status || {};
  const available = fleet.available || 0;
  const rented = fleet.rented || 0;
  const maint = fleet.maintenance || 0;
  // Operational fleet = cars the team actually works with (ready + on-rent + in-shop); excludes
  // sold / disposed / office-use, which inflate fleet.total. All readiness ratios divide by THIS.
  const activeFleet = available + rented + maint;
  const availabilityRate = activeFleet ? Math.round((available / activeFleet) * 100) : 0;
  const utilizationRate = activeFleet ? Math.round((rented / activeFleet) * 100) : 0;

  // Headline performance band — derived entirely from the real 12-month trend
  // series already fetched, so the numbers always agree with the charts below.
  const costSeries = trends.cost || [];
  const last = (arr, k) => Number(arr[arr.length - 1]?.[k]) || 0;
  const prev = (arr, k) => Number(arr[arr.length - 2]?.[k]) || 0;
  const spend12mo = costSeries.reduce((s, m) => s + (Number(m.value) || 0), 0);
  // Workshop Activity slice — the last N months of the trend, per the chosen
  // preset. The headline visit count, the range caption and the chart all follow.
  const wsCount = Math.min(wsMonths, costSeries.length);
  const wsSeries = wsCount > 0 ? costSeries.slice(costSeries.length - wsCount) : costSeries;
  const wsVisits = wsSeries.reduce((s, m) => s + (Number(m.visits) || 0), 0);
  const wsRangeLabel = wsSeries.length
    ? wsSeries.length === 1
      ? wsSeries[0].label
      : `${wsSeries[0].label} – ${wsSeries[wsSeries.length - 1].label}`
    : '';

  // Headline percent for the floating page gauge: fleet utilization.
  usePageStat({
    percent: loading || !activeFleet ? null : utilizationRate,
    label: 'Utilization',
    color: 'indigo',
    hint: `${rented} of ${activeFleet} operational cars currently rented out`,
  });

  // Fleet Status — a live snapshot for the headline donut, limited to the three
  // operational states the team actually works with. The "Unavailable" catch-all
  // (sold/disposed/office-use/other) was dropped because those cars surface in no
  // list, so the donut totals only the active, accounted-for fleet.
  const fleetStatusSegments = [
    { label: 'Available',   value: available, color: 'green'  },
    { label: 'On Rent',     value: rented,    color: 'blue'   },
    { label: 'Maintenance', value: maint,     color: 'yellow' },
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
    ...(SHOW_FINANCIALS ? [{
      icon: <Icon.Chart className="h-4 w-4" />, tone: 'violet', label: 'Spend · last 12 months',
      value: aed(spend12mo), series: costSeries,
    }] : []),
    {
      // Fleet Availability Rate — share of the OPERATIONAL fleet ready to rent right now.
      icon: <Icon.Check className="h-4 w-4" />, tone: 'emerald',
      label: `Fleet Availability · ${available}/${activeFleet} ready`,
      value: `${availabilityRate}%`, to: '/vehicles',
    },
    {
      // Fleet Utilization — share of the OPERATIONAL fleet currently out on rent.
      icon: <Icon.Gauge className="h-4 w-4" />, tone: 'indigo',
      label: `Fleet Utilization · ${rented}/${activeFleet} on rent`,
      value: `${utilizationRate}%`, to: '/fleet-utilization',
    },
  ];

  // KPI tiles — each maps to a semantic tone, a design-system icon, a short hint
  // and a tooltip that defines the metric. The whole tile links via MetricCard's `to`.
  const cards = [
    {
      label: 'Fixed This Month', key: 'fixed_this_month', tone: 'emerald', icon: <Icon.TrendUp className="h-5 w-5" />,
      to: '/maintenance-workflow', hint: 'Re-inspected & back in service',
      tooltip: 'Maintenance tickets completed this calendar month — re-inspected, signed off and returned to service.',
    },
    {
      label: 'Pending Approvals', key: 'pending_approvals', tone: 'amber', icon: <Icon.Check className="h-5 w-5" />,
      to: '/maintenance-approvals', hint: 'Awaiting sign-off',
      tooltip: 'Maintenance bills submitted by the garage that still need a manager to approve before they are booked as cost.',
    },
    {
      label: 'Overdue Maintenance', key: 'overdue_maintenance', tone: 'red', icon: <Icon.Clock className="h-5 w-5" />,
      to: '/maintenance-workflow', hint: 'In the shop past expected return',
      tooltip: 'Cars whose maintenance is still open past its expected completion date — stalled repairs to chase before they eat more downtime.',
    },
    {
      label: 'Invoice Due (Left Garage)', key: 'left_garage', tone: 'amber', icon: <Icon.Truck className="h-5 w-5" />,
      to: '/oversight/left-garage', hint: 'Car back, bill not collected',
      tooltip: 'Cars that have physically left the garage but whose repair invoice is still outstanding — the garages to chase for a bill so cost is booked accurately.',
    },
    {
      label: 'Resolved Transfers', key: 'resolved_transfers', tone: 'violet', icon: <Icon.ArrowRight className="h-5 w-5" />,
      to: '/oversight/resolved-transfers', hint: 'Moved with all faults fixed',
      tooltip: 'Cars a supervisor transferred out even though every fault was already fixed — logged with a required note for accountability.',
    },
    {
      label: 'Negative Yield', key: 'negative_yield', value: (k) => k.negative_yield?.count, tone: 'red', icon: <Icon.TrendDown className="h-5 w-5" />,
      to: '/maintenance-foresight', hint: 'Real net profit below repair cost (12mo)',
      tooltip: 'Cars whose real net profit over the last 12 months is below what was spent repairing them — money-losers to review.',
    },
    {
      label: 'Waiting for Parts', key: 'awaiting_parts', tone: 'amber', icon: <Icon.Wrench className="h-5 w-5" />,
      to: '/maintenance-recommendations', hint: 'Held until the spare arrives',
      tooltip: 'Cars approved for maintenance but sitting in the recommendation queue until the ordered spare part arrives.',
    },
    {
      label: 'Severity Review', key: 'severity_mismatches', tone: 'red', icon: <Icon.Alert className="h-5 w-5" />,
      to: '/oversight/severity', hint: 'Grade looks too low',
      tooltip: 'Tickets graded Routine or Moderate where a critical-risk keyword, a breakdown or a red-graded car says the fault should be Critical.',
    },
    {
      label: 'Mis-Diagnosis', key: 'misdiagnoses', tone: 'red', icon: <Icon.XCircle className="h-5 w-5" />,
      to: '/oversight/misdiagnoses', hint: 'Inspector calls a supervisor overruled',
      tooltip: 'Faults the inspector diagnosed that a supervisor later overruled as wrong (the "mark fault incorrect" override).',
    },
    {
      label: 'Mileage Discrepancies', key: 'mileage_flags', tone: 'indigo', icon: <Icon.Gauge className="h-5 w-5" />,
      to: '/oversight/mileage', hint: 'Odometer didn\'t match expected',
      tooltip: 'Workflow stages where the odometer entered ran backwards, jumped, or came from a garage test-drive — with the before/after readings.',
    },
  ];

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Greeting header — a light, personable "Good morning, {name}!" band with a live
            pulse, the last-updated stamp, the fleet-size counter, and the view switcher. */}
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 7, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--avail)', boxShadow: '0 0 8px var(--avail)' }} />
              Fleet Command · Live
            </div>
            <div className="flex items-center gap-2.5">
              <span className="h-5 w-1 rounded-full bg-indigo-500" />
              <h1 className="font-display text-2xl font-bold tracking-tight" style={{ color: 'var(--ink)' }}>
                {greeting()}{firstName ? `, ${firstName}` : ''}
              </h1>
            </div>
            <p className="mt-1.5 text-sm sm:ps-3.5" style={{ color: 'var(--ink-3)' }}>
              Live snapshot of your fleet's {SHOW_FINANCIALS ? 'finances and operations' : 'status and operations'}.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
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
        {/* ── Command band — Available Cars + Workshop Activity on the left, the
            Statistics column on the right. Every widget maps to a real endpoint. ── */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          {/* Left column — Workshop Activity (line). Fills the column height so it
              sits level with the Statistics card beside it. */}
          <Card className="flex h-full flex-col p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-600"><Icon.Wrench className="h-4 w-4" /></span>
                <div>
                  <p className="text-xs font-medium text-slate-500">Workshop Activity</p>
                  <p className="font-display text-xl font-bold leading-none tabular-nums text-slate-900">
                    {loading ? '—' : `${wsVisits.toLocaleString()} visits`}
                  </p>
                  <p className="mt-1 text-[11px] font-medium text-slate-400">
                    {loading ? '' : `${wsRangeLabel} · repair visits`}
                  </p>
                </div>
              </div>
              {/* Range presets — one-tap trailing windows over the 12-month series. */}
              {!loading && costSeries.length > 0 && (
                <div className="inline-flex rounded-lg bg-slate-100 p-0.5">
                  {[
                    { m: 3, label: '3M' },
                    { m: 6, label: '6M' },
                    { m: 12, label: '12M' },
                  ].map((opt) => (
                    <button
                      key={opt.m}
                      type="button"
                      onClick={() => setWsMonths(opt.m)}
                      className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition ${
                        wsMonths === opt.m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                      }`}
                    >
                      {opt.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
            <div className="mt-3 flex-1">
              {loading ? (
                <Skeleton className="h-full min-h-[160px] w-full rounded-xl" />
              ) : (
                <LineChart
                  data={wsSeries.map((m) => ({ label: m.label, value: m.visits }))}
                  color="amber"
                  height={200}
                  yTicks={3}
                  format={(v) => `${Math.round(v)}`}
                  tickFormat={(v) => `${Math.round(v)}`}
                  valueLabel="Visits"
                />
              )}
            </div>
          </Card>

          {/* Right — Fleet Readiness: live operational rates (availability / utilization), plus
              12-month spend trends when financials are shown. */}
          <Card className="p-5">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 text-violet-600"><Icon.Chart className="h-4 w-4" /></span>
                <h2 className="text-sm font-semibold text-slate-800">{SHOW_FINANCIALS ? 'Statistics' : 'Fleet Readiness'}</h2>
              </div>
              <span className="text-[11px] font-medium text-slate-400">{SHOW_FINANCIALS ? 'Last 12 months' : 'Live'}</span>
            </div>
            <div className="mt-1 divide-y divide-slate-100">
              {loading
                ? Array.from({ length: SHOW_FINANCIALS ? 4 : 2 }).map((_, i) => (
                    <div key={i} className="py-3"><Skeleton className="h-9 w-full rounded-xl" /></div>
                  ))
                : statRows.map((r, i) => <StatRow key={i} {...r} />)}
            </div>
          </Card>
        </div>

        {/* Fleet composition — the status donut beside a compact utilization summary. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {loading ? (
            <Card className="lg:col-span-2">
              <div className="flex justify-center py-16"><Skeleton className="h-56 w-56 rounded-full" /></div>
            </Card>
          ) : (
            <FleetStatusCard
              className="lg:col-span-2"
              title="Fleet Status"
              centerLabel="Active Fleet"
              unit="cars"
              total={activeFleet}
              segments={fleetStatusSegments}
              headerRight={
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                  <span className="h-2 w-2 rounded-full bg-emerald-500" />
                  Live
                </span>
              }
            />
          )}

          {/* Fleet split — a compact legend of the active-fleet composition
              (Available / On Rent / Maintenance), matching the donut beside it. */}
          <Card className="flex flex-col p-6">
            <p className="flex items-center gap-1 text-sm font-semibold text-slate-800">
              Fleet Split
              <InfoTip content="How the active fleet breaks down right now — cars free to rent, out on rent, and in maintenance." />
            </p>
            <div className="mt-5 flex-1 space-y-3.5 border-t border-slate-100 pt-5">
              {[
                { label: 'Available',   value: available, dot: '#22C55E' },
                { label: 'On Rent',     value: rented,    dot: '#2F7EF6' },
                { label: 'Maintenance', value: maint,     dot: '#F5C518' },
              ].map((s) => (
                <div key={s.label} className="flex items-center gap-3">
                  <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.dot }} />
                  <span className="flex-1 text-sm font-medium text-slate-600">{s.label}</span>
                  <span className="text-sm font-semibold tabular-nums text-slate-900">
                    {loading ? '—' : s.value.toLocaleString()}
                  </span>
                </div>
              ))}
            </div>
          </Card>
        </div>

        {/* KPI cards */}
        {loading ? (
          <MetricGridSkeleton count={9} />
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
            <p className="py-8 text-center text-sm text-slate-400">Nothing expiring soon.</p>
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
                    <div className="flex shrink-0 gap-4">
                      <ExpiryStat days={r.registration_days_left} label="Mulkiya" />
                      <ExpiryStat days={r.insurance_days_left} label="Insurance" />
                    </div>
                  </Link>
                );
              })}
            </div>
          )}
        </SectionCard>

          </>
        )}

      </div>
    </div>
  );
}
