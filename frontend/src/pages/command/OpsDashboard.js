// Delivery Command — a dark "control room" dashboard for the pickup/drop-off trip
// operation, fed by the live Main Trip Dashboard sheet (GET /TripDashboard).
// KPIs (on-time %, total trips, cars, completion), a weekly activity chart, a
// live trips table with lane tabs, and a top-drivers strip. Self-contained dark
// styling so it renders identically regardless of the app theme toggle.
//
// Accent is a single constant — change ACCENT to re-skin the page.

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Skeleton } from '../../components/ui/Skeleton';

const ACCENT = '#22d3ee'; // cyan-400 — the page's signature colour

const BAR_TONE = { low: '#3a4658', mid: '#7c8aa3', high: ACCENT };

// ---- Small primitives ------------------------------------------------------
function ArrowBtn({ dark = false }) {
  return (
    <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${dark ? 'bg-black/85 text-white' : 'bg-white/10 text-white'}`}>
      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M7 17L17 7M9 7h8v8" /></svg>
    </span>
  );
}

function KpiCard({ label, value, hint, hero = false, loading }) {
  if (loading) return <Skeleton className="h-[132px] rounded-3xl bg-white/5" />;
  if (hero) {
    return (
      <div className="relative overflow-hidden rounded-3xl p-5 text-black shadow-lg" style={{ background: ACCENT }}>
        <div className="flex items-start justify-between">
          <p className="text-sm font-medium text-black/80">{label}</p>
          <ArrowBtn dark />
        </div>
        <div className="mt-8 flex items-end gap-2">
          <span className="font-display text-4xl font-bold leading-none tracking-tight">{value}</span>
          {hint && <span className="mb-1 text-[11px] font-medium text-black/60">{hint}</span>}
        </div>
      </div>
    );
  }
  return (
    <div className="rounded-3xl bg-[#17181c] p-5 ring-1 ring-white/[0.06]">
      <div className="flex items-start justify-between">
        <p className="text-sm font-medium text-slate-400">{label}</p>
        <ArrowBtn />
      </div>
      <div className="mt-8 flex items-end gap-2">
        <span className="font-display text-4xl font-bold leading-none tracking-tight text-white">{value}</span>
        {hint && <span className="mb-1 text-[11px] font-medium text-slate-500">{hint}</span>}
      </div>
    </div>
  );
}

function ActivityChart({ data }) {
  if (!data.length) return <p className="py-16 text-center text-sm text-slate-500">No recent trip activity</p>;
  const max = Math.max(...data.map((d) => d.orders), 1);
  const peakIdx = data.reduce((best, d, i) => (d.orders > data[best].orders ? i : best), 0);
  const top = Math.max(50, Math.ceil(max / 10) * 10);
  const yTicks = [top, Math.round(top * 0.8), Math.round(top * 0.6), Math.round(top * 0.4), Math.round(top * 0.2), 0];
  return (
    <div className="flex gap-3">
      <div className="flex flex-col justify-between py-1 text-[10px] font-medium text-slate-600" style={{ height: 210 }}>
        {yTicks.map((t, i) => <span key={i}>{t}</span>)}
      </div>
      <div className="relative flex-1">
        <div className="flex items-end justify-between gap-2 sm:gap-3" style={{ height: 210 }}>
          {data.map((d, i) => (
            <div key={i} className="group relative flex flex-1 flex-col items-center justify-end">
              {i === peakIdx && (
                <div className="absolute -top-1 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-xl bg-white px-3 py-1.5 text-center shadow-lg">
                  <p className="text-[11px] font-semibold leading-tight text-slate-900">{d.day}</p>
                  <p className="text-[11px] font-medium leading-tight text-slate-500">{d.orders} trip{d.orders === 1 ? '' : 's'}</p>
                  <span className="absolute left-1/2 top-full h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45 bg-white" />
                </div>
              )}
              <div className="w-full max-w-[38px] rounded-md transition-all" style={{ height: `${Math.max(4, (d.orders / top) * 100)}%`, background: BAR_TONE[d.tier] }} />
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

// Status → chip styling. Trips carry Done / Cancel / Pending (+ free-text variants).
function statusChip(status) {
  const s = (status || '').toLowerCase();
  if (s.includes('done')) return { label: status, className: 'text-black', style: { background: ACCENT } };
  if (s.includes('cancel')) return { label: status, className: 'bg-rose-500/15 text-rose-300' };
  if (s) return { label: status, className: 'bg-amber-500/15 text-amber-300' };
  return { label: '—', className: 'bg-white/10 text-slate-400' };
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
    <div className="flex h-full flex-col rounded-3xl bg-[#17181c] p-5 ring-1 ring-white/[0.06]">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold text-white">Trips</h2>
        <span className="text-xs text-slate-500">latest {recent.length}</span>
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        {LANES.map((t) => {
          const on = active === t.key;
          return (
            <button key={t.key} type="button" onClick={() => setActive(t.key)}
              className={`inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-medium ring-1 transition ${on ? 'text-black ring-transparent' : 'text-slate-300 ring-white/10 hover:bg-white/[0.06]'}`}
              style={on ? { background: ACCENT } : undefined}>
              {t.label}
              <span className={`inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-semibold tabular-nums ${on ? 'bg-black/85 text-white' : 'bg-white/10 text-slate-300'}`}>
                {laneCount(t.key).toLocaleString()}
              </span>
            </button>
          );
        })}
      </div>

      <div className="mt-4 flex-1 overflow-hidden">
        <div className="grid grid-cols-[auto_1fr_auto_auto] gap-x-4 border-b border-white/5 pb-2 text-[11px] font-medium uppercase tracking-wide text-slate-500">
          <span>Trip</span><span>Vehicle · destination</span><span>When</span><span className="text-right">Status</span>
        </div>
        {loading ? (
          <div className="space-y-2 pt-3">{Array.from({ length: 7 }).map((_, i) => <Skeleton key={i} className="h-9 rounded-lg bg-white/5" />)}</div>
        ) : rows.length === 0 ? (
          <p className="py-10 text-center text-sm text-slate-500">No trips in this lane</p>
        ) : (
          <div className="max-h-[440px] divide-y divide-white/5 overflow-y-auto">
            {rows.map((r, i) => {
              const s = statusChip(r.status);
              return (
                <div key={`${r.trip_no}-${i}`} className="grid grid-cols-[auto_1fr_auto_auto] items-center gap-x-4 py-3">
                  <span className="font-mono text-sm font-medium text-slate-300">{r.trip_no}</span>
                  <div className="min-w-0">
                    <p className="truncate text-sm text-slate-200">{r.model} {r.plate && <span className="text-slate-500">· {r.plate}</span>}</p>
                    <p className="truncate text-xs text-slate-500">{r.location && r.location !== 'WILL UPDATE' ? r.location : (r.action || '—')}</p>
                  </div>
                  <span className="text-sm tabular-nums text-slate-400">{fmtDate(r.date)}</span>
                  <span className="justify-self-end">
                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${s.className}`} style={s.style}>{s.label}</span>
                  </span>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}

const AVATAR_TONE = ['from-cyan-400 to-sky-500', 'from-violet-400 to-fuchsia-500', 'from-amber-400 to-orange-500', 'from-emerald-400 to-teal-500'];
function Avatar({ name, i = 0 }) {
  const initials = (name || '?').replace(/ /g, ' ').split(' ').filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  return <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br ${AVATAR_TONE[i % AVATAR_TONE.length]} text-sm font-semibold text-white`}>{initials}</span>;
}

function TopDrivers({ drivers, loading }) {
  return (
    <div className="rounded-3xl bg-[#17181c] p-5 ring-1 ring-white/[0.06]">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold text-white">Top Drivers</h2>
        <span className="text-xs text-slate-500">by trips</span>
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        {loading
          ? Array.from({ length: 2 }).map((_, i) => <Skeleton key={i} className="h-[116px] rounded-2xl bg-white/5" />)
          : drivers.map((c, i) => (
            <div key={c.name} className="rounded-2xl bg-white/[0.03] p-4 ring-1 ring-white/[0.06]">
              <div className="flex items-center gap-3">
                <Avatar name={c.name} i={i} />
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-white">{(c.name || '').replace(/ /g, ' ')}</p>
                  <p className="truncate text-xs text-slate-500">Driver</p>
                </div>
              </div>
              <div className="mt-4 flex items-end justify-between">
                <p className="flex items-baseline gap-1.5">
                  <span className="font-display text-3xl font-bold text-white">{c.trips.toLocaleString()}</span>
                  <span className="text-xs text-slate-500">Trips</span>
                </p>
                <ArrowBtn />
              </div>
            </div>
          ))}
      </div>
    </div>
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
    { label: 'On-Time Rate', value: k.on_time_pct != null ? `${k.on_time_pct}%` : '—', hint: 'of completed trips', hero: true },
    { label: 'Total Trips', value: (k.total_trips || 0).toLocaleString(), hint: 'all-time logged' },
    { label: 'Vehicles', value: (k.total_cars || 0).toLocaleString(), hint: 'cars in rotation' },
    { label: 'Completion Rate', value: k.completion_pct != null ? `${k.completion_pct}%` : '—', hint: 'done vs cancelled' },
  ];

  return (
    <div className="min-h-screen bg-[#0d0e11] px-4 py-6 text-white sm:px-6 lg:px-8">
      <div className="mx-auto max-w-[1400px] space-y-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="font-display text-2xl font-bold tracking-tight">Delivery Command</h1>
            <p className="mt-0.5 text-sm text-slate-500">Live pickup & drop-off trip operations · Main Trip Dashboard</p>
          </div>
          <Link to="/orders-board" className="inline-flex items-center gap-2 self-start rounded-full px-4 py-2 text-sm font-semibold text-black transition hover:brightness-95" style={{ background: ACCENT }}>
            Orders board
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
          </Link>
        </div>

        {error && <div className="rounded-2xl bg-rose-500/10 px-4 py-3 text-sm text-rose-300 ring-1 ring-rose-500/20">{error}</div>}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {kpis.map((kpi) => <KpiCard key={kpi.label} {...kpi} loading={loading} />)}
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="space-y-4">
            <div className="rounded-3xl bg-[#17181c] p-5 ring-1 ring-white/[0.06]">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold text-white">Activity</h2>
                <span className="text-xs text-slate-500">recent days</span>
              </div>
              <div className="mt-5">
                {loading ? <Skeleton className="h-[240px] rounded-2xl bg-white/5" /> : <ActivityChart data={data?.activity || []} />}
              </div>
              <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-400">
                <span className="inline-flex items-center gap-2"><span className="h-3 w-3 rounded-full" style={{ background: BAR_TONE.low }} /> less than 10 trips</span>
                <span className="inline-flex items-center gap-2"><span className="h-3 w-3 rounded-full" style={{ background: BAR_TONE.mid }} /> 10–19 trips</span>
                <span className="inline-flex items-center gap-2"><span className="h-3 w-3 rounded-full" style={{ background: BAR_TONE.high }} /> 20+ trips</span>
              </div>
            </div>
            <TopDrivers drivers={data?.top_drivers || []} loading={loading} />
          </div>

          <TripsPanel recent={data?.recent || []} counts={data?.counts} loading={loading} />
        </div>
      </div>
    </div>
  );
}
