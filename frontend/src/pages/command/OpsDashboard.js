// Delivery Command — the command dashboard for the pickup/drop-off trip
// operation, fed by the live Main Trip Dashboard sheet (GET /TripDashboard).
// KPIs (on-time %, total trips, cars, completion), a weekly activity chart, a
// live trips table with lane tabs, and a top-drivers strip. Built on the shared
// Aurora ui/ primitives so it follows the app theme (Platinum/Cockpit).

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Skeleton } from '../../components/ui/Skeleton';
import { PageHeader, EmptyState } from '../../components/ui/Misc';
import { SectionCard } from '../../components/ui/Table';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import Badge from '../../components/ui/Badge';

// Bar tint per activity tier — slate ramp for quiet days, cyan accent for peaks.
const BAR_TONE = { low: 'bg-slate-200', mid: 'bg-slate-400', high: 'bg-cyan-500' };

function ActivityChart({ data }) {
  if (!data.length) return <p className="py-16 text-center text-sm text-slate-400">No recent trip activity</p>;
  const max = Math.max(...data.map((d) => d.orders), 1);
  const peakIdx = data.reduce((best, d, i) => (d.orders > data[best].orders ? i : best), 0);
  const top = Math.max(50, Math.ceil(max / 10) * 10);
  const yTicks = [top, Math.round(top * 0.8), Math.round(top * 0.6), Math.round(top * 0.4), Math.round(top * 0.2), 0];
  return (
    <div className="flex gap-3">
      <div className="flex flex-col justify-between py-1 text-[10px] font-medium tabular-nums text-slate-400" style={{ height: 210 }}>
        {yTicks.map((t, i) => <span key={i}>{t}</span>)}
      </div>
      <div className="relative flex-1">
        <div className="flex items-end justify-between gap-2 sm:gap-3" style={{ height: 210 }}>
          {data.map((d, i) => (
            <div key={i} className="group relative flex flex-1 flex-col items-center justify-end">
              {i === peakIdx && (
                <div className="absolute -top-1 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-xl border border-slate-200/60 bg-white px-3 py-1.5 text-center shadow-card">
                  <p className="text-[11px] font-semibold leading-tight text-slate-900">{d.day}</p>
                  <p className="text-[11px] font-medium leading-tight text-slate-500">{d.orders} trip{d.orders === 1 ? '' : 's'}</p>
                  <span className="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45 border-b border-r border-slate-200/60 bg-white" />
                </div>
              )}
              <div className={`w-full max-w-[38px] rounded-lg transition-all ${BAR_TONE[d.tier] || BAR_TONE.low}`} style={{ height: `${Math.max(4, (d.orders / top) * 100)}%` }} />
            </div>
          ))}
        </div>
        <div className="mt-2 flex justify-between gap-2 text-[10px] font-medium text-slate-500 sm:gap-3">
          {data.map((d, i) => <span key={i} className="flex-1 text-center">{d.day}</span>)}
        </div>
      </div>
    </div>
  );
}

// Status → Badge tone. Trips carry Done / Cancel / Pending (+ free-text variants).
function statusChip(status) {
  const s = (status || '').toLowerCase();
  if (s.includes('done')) return { label: status, tone: 'cyan' };
  if (s.includes('cancel')) return { label: status, tone: 'red' };
  if (s) return { label: status, tone: 'amber' };
  return { label: '—', tone: 'slate' };
}

const LANES = [
  { key: 'all', label: 'All' },
  { key: 'scheduled', label: 'Scheduled', match: (t) => !/done|cancel/i.test(t.status) },
  { key: 'completed', label: 'Completed', match: (t) => /done/i.test(t.status) },
  { key: 'cancelled', label: 'Cancelled', match: (t) => /cancel/i.test(t.status) },
];

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return Number.isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function TripsPanel({ recent, counts, loading }) {
  const [active, setActive] = useState('all');
  const laneCount = (k) => (k === 'all' ? counts?.total ?? recent.length : counts?.[k] ?? 0);
  const rows = active === 'all' ? recent : recent.filter(LANES.find((l) => l.key === active)?.match || (() => true));

  return (
    <SectionCard
      title="Trips"
      actions={<span className="text-xs text-slate-400">latest {recent.length}</span>}
      className="flex h-full flex-col"
      bodyClass="flex flex-1 flex-col px-5 py-4"
    >
      <div className="flex flex-wrap gap-2" role="tablist" aria-label="Trip lanes">
        {LANES.map((t) => {
          const on = active === t.key;
          return (
            <button
              key={t.key}
              type="button"
              role="tab"
              aria-selected={on}
              onClick={() => setActive(t.key)}
              className={`focus-ring-self inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 ${
                on
                  ? 'bg-indigo-600 text-white shadow-sm'
                  : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50'
              }`}
            >
              {t.label}
              <span className={`inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-semibold tabular-nums ${on ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500'}`}>
                {laneCount(t.key).toLocaleString()}
              </span>
            </button>
          );
        })}
      </div>

      <div className="mt-4 flex-1 overflow-hidden">
        <div className="grid grid-cols-[auto_1fr_auto_auto] gap-x-4 border-b border-slate-200 pb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          <span>Trip</span><span>Vehicle · destination</span><span>When</span><span className="text-right">Status</span>
        </div>
        {loading ? (
          <div className="space-y-2 pt-3">{Array.from({ length: 7 }).map((_, i) => <Skeleton key={i} className="h-9 rounded-lg" />)}</div>
        ) : rows.length === 0 ? (
          <EmptyState title="No trips in this lane" message="Trips land here as they're logged on the Main Trip Dashboard sheet." />
        ) : (
          <div className="max-h-[440px] divide-y divide-slate-100 overflow-y-auto">
            {rows.map((r, i) => {
              const s = statusChip(r.status);
              return (
                <div key={`${r.trip_no}-${i}`} className="grid grid-cols-[auto_1fr_auto_auto] items-center gap-x-4 py-3 transition-colors hover:bg-indigo-50/40">
                  <span className="font-mono text-sm font-medium text-slate-700">{r.trip_no}</span>
                  <div className="min-w-0">
                    <p className="truncate text-sm text-slate-700">{r.model} {r.plate && <span className="text-slate-400">· {r.plate}</span>}</p>
                    <p className="truncate text-xs text-slate-400">{r.location && r.location !== 'WILL UPDATE' ? r.location : (r.action || '—')}</p>
                  </div>
                  <span className="text-sm tabular-nums text-slate-500">{fmtDate(r.date)}</span>
                  <span className="justify-self-end">
                    <Badge tone={s.tone} dot className="whitespace-nowrap">{s.label}</Badge>
                  </span>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </SectionCard>
  );
}

function Avatar({ name }) {
  const initials = (name || '?').replace(/ /g, ' ').split(' ').filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  return <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">{initials}</span>;
}

function TopDrivers({ drivers, loading }) {
  return (
    <SectionCard
      title="Top Drivers"
      actions={<span className="text-xs text-slate-400">by trips</span>}
      bodyClass="px-5 py-4"
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {loading
          ? Array.from({ length: 2 }).map((_, i) => <Skeleton key={i} className="h-[116px] rounded-xl" />)
          : drivers.map((c, i) => (
            <div key={c.name} className="rounded-xl border border-slate-200/60 bg-slate-50/60 p-4">
              <div className="flex items-center gap-3">
                <Avatar name={c.name} i={i} />
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900">{(c.name || '').replace(/ /g, ' ')}</p>
                  <p className="truncate text-xs text-slate-400">Driver</p>
                </div>
              </div>
              <div className="mt-4">
                <p className="flex items-baseline gap-1.5">
                  <span className="font-display text-3xl font-bold tabular-nums text-slate-900">{c.trips.toLocaleString()}</span>
                  <span className="text-xs text-slate-400">Trips</span>
                </p>
              </div>
            </div>
          ))}
      </div>
    </SectionCard>
  );
}

export default function OpsDashboard() {
  const fetcher = useCallback(async () => {
    const res = await api.get('/TripDashboard');
    return res.data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const k = data?.kpis || {};
  const kpis = [
    { label: 'On-Time Rate', value: k.on_time_pct != null ? `${k.on_time_pct}%` : '—', hint: 'of completed trips', tone: 'cyan', big: true },
    { label: 'Total Trips', value: (k.total_trips || 0).toLocaleString(), hint: 'all-time logged' },
    { label: 'Vehicles', value: (k.total_cars || 0).toLocaleString(), hint: 'cars in rotation' },
    { label: 'Completion Rate', value: k.completion_pct != null ? `${k.completion_pct}%` : '—', hint: 'done vs cancelled' },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Delivery Command" subtitle="Live pickup & drop-off trip operations · Main Trip Dashboard">
          <Link
            to="/orders-board"
            className="focus-ring-self inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
          >
            Orders board
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
          </Link>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        <MetricGrid cols={4}>
          {kpis.map((kpi) => <MetricCard key={kpi.label} {...kpi} loading={loading} />)}
        </MetricGrid>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="space-y-4">
            <SectionCard
              title="Activity"
              actions={<span className="text-xs text-slate-400">recent days</span>}
              bodyClass="px-5 py-4"
            >
              {loading ? <Skeleton className="h-[240px] rounded-xl" /> : <ActivityChart data={data?.activity || []} />}
              <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                <span className="inline-flex items-center gap-2"><span className={`h-3 w-3 rounded-full ${BAR_TONE.low}`} /> less than 10 trips</span>
                <span className="inline-flex items-center gap-2"><span className={`h-3 w-3 rounded-full ${BAR_TONE.mid}`} /> 10–19 trips</span>
                <span className="inline-flex items-center gap-2"><span className={`h-3 w-3 rounded-full ${BAR_TONE.high}`} /> 20+ trips</span>
              </div>
            </SectionCard>
            <TopDrivers drivers={data?.top_drivers || []} loading={loading} />
          </div>

          <TripsPanel recent={data?.recent || []} counts={data?.counts} loading={loading} />
        </div>
      </div>
    </div>
  );
}
