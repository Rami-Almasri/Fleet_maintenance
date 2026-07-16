// Analytics — a clean, card-grid overview modelled on the reference SaaS dashboard
// (welcome header + 3×2 grid of glanceable cards). Every number is REAL fleet data,
// pulled from the same /Dashboard + /Dashboard/trends feeds the main Dashboard uses,
// so the two can never disagree. Built entirely from the shared ui/ primitives
// (FleetDonut, BarChart, LineChart, Sparkline, ProgressBar) — no chart dependency.
import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useAuth } from '../auth/AuthContext';
import Icon from '../components/ui/Icon';
import { PageHeader } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import { FleetDonut, useCountUp } from '../components/ui/Gauge';
import { ProgressBar } from '../components/ui/Progress';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import Sparkline from '../components/ui/Sparkline';
import { aed } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

// Compact axis currency (4,180 → "4.2k") so y-labels never overflow a small card.
const compactK = (n) => {
  const v = Number(n) || 0;
  if (Math.abs(v) >= 1000) return `${(v / 1000).toFixed(Math.abs(v) % 1000 ? 1 : 0)}k`;
  return Math.round(v).toString();
};

// A number that counts up from 0 on mount — the big figure at the top of a card.
function CountUp({ value, format }) {
  const v = useCountUp(value);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

// The shared card shell: white, rounded, soft-shadowed, with a title row and a
// decorative "⋯" affordance in the corner (matches the reference cards).
function PanelCard({ title, value, valueTone = 'text-slate-900', hint, children, className = '' }) {
  return (
    <div className={`flex flex-col rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft hover-lift ${className}`}>
      <div className="flex items-start justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
          {title}
          {hint && <InfoTip content={hint} />}
        </h2>
        <span className="select-none text-lg leading-none text-slate-300" aria-hidden="true">⋯</span>
      </div>
      {value != null && (
        <p className={`mt-1 font-display text-3xl font-bold tracking-tight tabular-nums ${valueTone}`}>{value}</p>
      )}
      <div className="mt-4 flex-1">{children}</div>
    </div>
  );
}

export default function Analytics() {
  const { user } = useAuth();

  const fetcher = useCallback(async () => {
    // The KPI summary is the one critical call; trends degrades to empty so a flaky
    // trends endpoint can never blank the page.
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const [kpiRes, trendsRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Dashboard/trends', { params: { months: 12 } }).catch(safe({ cost: [], downtime: [] })),
    ]);
    return {
      kpis: kpiRes.data.data || {},
      trends: trendsRes.data.data || { cost: [], downtime: [] },
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const trends = data?.trends || { cost: [], downtime: [] };
  const fleet = kpis.fleet_status || {};

  const total = fleet.total || 0;
  const available = fleet.available || 0;
  const rented = fleet.rented || 0;
  const maintenance = fleet.maintenance || 0;
  const unavailable = Math.max(0, total - available - rented - maintenance);
  const utilization = total ? Math.round((rented / total) * 100) : 0;

  const costSeries = trends.cost || [];
  const downSeries = trends.downtime || [];
  const visitVals = costSeries.map((m) => Number(m.visits) || 0);
  const totalVisits = visitVals.reduce((a, b) => a + b, 0);
  const spend12mo = costSeries.reduce((s, m) => s + (Number(m.value) || 0), 0);
  const latestDowntime = Number(downSeries[downSeries.length - 1]?.value) || 0;

  // Four-bucket fleet composition — always sums to the true total (nothing dropped).
  // Uses valid palette keys so the slice colours actually render.
  const segments = [
    { label: 'Available',   value: available,   color: 'emerald', tone: 'emerald' },
    { label: 'On Rent',     value: rented,      color: 'blue',    tone: 'blue' },
    { label: 'Maintenance', value: maintenance, color: 'amber',   tone: 'amber' },
    { label: 'Unavailable', value: unavailable, color: 'red',     tone: 'red' },
  ];

  const today = new Date().toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'long' });
  const firstName = (user?.name || '').trim().split(/\s+/)[0] || 'there';
  const initials = (user?.name || 'U')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((s) => s[0]?.toUpperCase())
    .join('') || 'U';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Welcome header */}
        <PageHeader title={`Welcome, ${firstName}!`} subtitle="Fleet analytics at a glance — live from the same feeds as the Dashboard">
          <span className="text-sm font-medium text-slate-500">{today}</span>
          <span className="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold text-white shadow-sm ring-2 ring-white">
            {initials}
          </span>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* 3 × 2 card grid */}
        <div className="stagger grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {/* 1 — Fleet Activity (area sparkline) */}
          <PanelCard
            title="Fleet Activity"
            value={loading ? '—' : <CountUp value={totalVisits} />}
            hint="Total workshop visits across the fleet over the last 12 months, from the live garage log."
          >
            <p className="-mt-2 mb-2 text-xs font-medium text-slate-400">Workshop visits · last 12 months</p>
            {loading ? (
              <Skeleton className="h-[110px] w-full rounded-xl" />
            ) : (
              <Sparkline data={visitVals} color="cyan" height={110} strokeWidth={2.5} />
            )}
          </PanelCard>

          {/* 2 — Fleet Status (donut) */}
          <PanelCard
            title="Fleet Status"
            hint="Live composition of the fleet — the four buckets always sum to the true total, contract-derived so it matches the Dashboard."
          >
            {loading ? (
              <div className="flex justify-center"><Skeleton className="h-[180px] w-[180px] rounded-full" /></div>
            ) : (
              <FleetDonut segments={segments} total={total} centerLabel="Vehicles" size={180} stroke={20} />
            )}
          </PanelCard>

          {/* 3 — Utilization (% + legend list) */}
          <PanelCard
            title="Utilization"
            value={loading ? '—' : <CountUp value={utilization} format={(v) => `${Math.round(v)}%`} />}
            valueTone="text-indigo-600"
            hint="Share of the fleet currently out on rent — the headline earning rate."
          >
            <ul className="space-y-2.5">
              {segments.map((s) => (
                <li key={s.label} className="flex items-center gap-2.5 text-sm">
                  <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${DOT_BG[s.tone]}`} />
                  <span className="font-medium text-slate-600">{s.label}</span>
                  <span className="ml-auto font-semibold tabular-nums text-slate-800">
                    {loading ? '—' : s.value}
                  </span>
                </li>
              ))}
            </ul>
          </PanelCard>

          {/* 4 — Maintenance Cost / Repair Visits (bar) */}
          <PanelCard
            title={SHOW_FINANCIALS ? 'Maintenance Cost' : 'Repair Visits'}
            value={
              loading ? '—'
                : SHOW_FINANCIALS
                  ? <CountUp value={spend12mo} format={(v) => aed(v)} />
                  : <CountUp value={totalVisits} />
            }
            hint={SHOW_FINANCIALS
              ? 'Monthly workshop spend over the last 12 months.'
              : 'Number of workshop visits per month over the last 12 months.'}
          >
            <p className="-mt-2 mb-2 text-xs font-medium text-slate-400">
              {SHOW_FINANCIALS ? 'Monthly repair spend' : 'Visits per month'} · last 12 months
            </p>
            {loading ? (
              <Skeleton className="h-[150px] w-full rounded-xl" />
            ) : (
              <BarChart
                data={SHOW_FINANCIALS ? costSeries : costSeries.map((m) => ({ label: m.label, value: Number(m.visits) || 0 }))}
                color="indigo"
                height={150}
                format={SHOW_FINANCIALS ? aed : (v) => `${v} visit${v === 1 ? '' : 's'}`}
                tickFormat={SHOW_FINANCIALS ? compactK : (v) => Math.round(v).toString()}
                valueLabel={SHOW_FINANCIALS ? 'Spend' : 'Visits'}
                yTicks={3}
              />
            )}
          </PanelCard>

          {/* 5 — Downtime Trend (area line) */}
          <PanelCard
            title="Downtime Trend"
            value={loading ? '—' : <CountUp value={latestDowntime} format={(v) => `${v.toFixed(1)}d`} />}
            hint="Average days a car spends in the shop per repair visit, by month. Lower is better."
          >
            <p className="-mt-2 mb-2 text-xs font-medium text-slate-400">Avg days in shop per visit · lower is better</p>
            {loading ? (
              <Skeleton className="h-[150px] w-full rounded-xl" />
            ) : (
              <LineChart
                data={downSeries}
                color="orange"
                height={150}
                format={(v) => `${v} day${v === 1 ? '' : 's'}`}
                tickFormat={(v) => `${Math.round(v)}d`}
                valueLabel="Avg in shop"
                tooltip={(d) => `${d.visits} visit${d.visits === 1 ? '' : 's'}`}
                yTicks={3}
              />
            )}
          </PanelCard>

          {/* 6 — Fleet Readiness (progress-bar list) */}
          <PanelCard
            title="Fleet Readiness"
            hint="How the fleet is split across states right now, as a share of the whole fleet."
          >
            <div className="space-y-4">
              {READINESS.map((row) => {
                const val = { available, rented, maintenance, unavailable }[row.key];
                const pct = total ? Math.round((val / total) * 100) : 0;
                return (
                  <div key={row.key} className="flex items-center gap-3">
                    <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${CHIP_TONE[row.tone]}`}>
                      {row.icon}
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                        <span className="font-medium text-slate-600">{row.label}</span>
                        <span className="font-semibold tabular-nums text-slate-700">{loading ? '—' : `${pct}%`}</span>
                      </div>
                      <ProgressBar value={loading ? 0 : val} max={total || 1} tone={row.tone} height={7} />
                    </div>
                  </div>
                );
              })}
            </div>
            <Link to="/" className="mt-5 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
              Open full Dashboard <Icon.ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </PanelCard>
        </div>
      </div>
    </div>
  );
}

// Solid legend-dot / tinted-chip tones (aligned with the ProgressBar / FleetDonut
// palettes) — Tailwind classes so the dark "Cockpit" override layer remaps them.
const DOT_BG = {
  emerald: 'bg-emerald-500',
  blue:    'bg-blue-500',
  amber:   'bg-amber-500',
  red:     'bg-red-500',
};

const CHIP_TONE = {
  emerald: 'bg-emerald-50 text-emerald-600',
  blue:    'bg-blue-50 text-blue-600',
  amber:   'bg-amber-50 text-amber-600',
  red:     'bg-red-50 text-red-600',
};

const READINESS = [
  { key: 'available',   label: 'Available',   tone: 'emerald', icon: <Icon.Check className="h-4 w-4" /> },
  { key: 'rented',      label: 'On Rent',     tone: 'blue',    icon: <Icon.Car className="h-4 w-4" /> },
  { key: 'maintenance', label: 'Maintenance', tone: 'amber',   icon: <Icon.Wrench className="h-4 w-4" /> },
  { key: 'unavailable', label: 'Unavailable', tone: 'red',     icon: <Icon.Alert className="h-4 w-4" /> },
];
