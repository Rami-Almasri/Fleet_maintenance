// Orders board — a three-lane Kanban of live trips (Scheduled → Completed →
// Cancelled), fed by the Main Trip Dashboard sheet (GET /TripDashboard). Each
// card is a real pickup/drop-off trip: vehicle, trip no., status, assignee
// (driver/sales), destination and date. Light surface; single ACCENT constant.

import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Skeleton } from '../../components/ui/Skeleton';

const ACCENT = '#22d3ee'; // cyan-400 — matches Delivery Command

const LANES = [
  { key: 'scheduled', title: 'Scheduled', dot: '#f59e0b' },
  { key: 'completed', title: 'Completed', dot: '#06b6d4' },
  { key: 'cancelled', title: 'Cancelled', dot: '#f43f5e' },
];

const LANE_CHIP = {
  scheduled: { label: 'Scheduled', dot: '#f59e0b', text: 'text-amber-600' },
  completed: { label: 'Completed', dot: '#06b6d4', text: 'text-cyan-600' },
  cancelled: { label: 'Cancelled', dot: '#f43f5e', text: 'text-rose-600' },
};

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return Number.isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function ParcelGlyph() {
  return (
    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
        <path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12" />
      </svg>
    </span>
  );
}

function TripCard({ card, laneKey }) {
  const chip = LANE_CHIP[laneKey];
  const assignee = card.driver || card.sales || '—';
  const dest = card.location && card.location !== 'WILL UPDATE' ? card.location : (card.action || '—');
  return (
    <div className="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-sm transition hover:shadow-md">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <ParcelGlyph />
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-900">{card.model || 'Vehicle'}</p>
            <p className="font-mono text-xs text-slate-400">{card.trip_no}{card.plate ? ` · ${card.plate}` : ''}</p>
          </div>
        </div>
        <span className={`inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-xs font-medium ${chip.text}`}>
          <span className="h-1.5 w-1.5 rounded-full" style={{ background: chip.dot }} />
          {card.status || chip.label}
        </span>
      </div>
      <div className="mt-3 truncate text-xs text-slate-500">📍 {dest}</div>
      <div className="mt-3 grid grid-cols-3 items-end gap-2 border-t border-slate-100 pt-3">
        <div className="col-span-2 min-w-0">
          <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">Assigned to</p>
          <p className="truncate text-sm font-medium text-slate-700">{assignee}</p>
        </div>
        <div className="text-right">
          <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">When</p>
          <p className="text-sm font-medium text-slate-700">{fmtDate(card.date)}</p>
        </div>
      </div>
    </div>
  );
}

function PromoCard() {
  return (
    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-900 to-slate-700 p-5 text-white shadow-md">
      <h3 className="max-w-[75%] text-lg font-bold leading-snug">Unlock powerful advanced route analytics</h3>
      <Link to="/ops-dashboard" className="mt-4 inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold text-black transition hover:brightness-95" style={{ background: ACCENT }}>
        Open dashboard
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
      </Link>
      <svg className="pointer-events-none absolute -bottom-2 right-2 h-24 w-40 opacity-90" viewBox="0 0 160 90" fill="none">
        <rect x="8" y="22" width="78" height="46" rx="6" fill={ACCENT} opacity="0.85" />
        <path d="M86 38h20l14 12v18H86z" fill="#94a3b8" />
        <rect x="86" y="38" width="34" height="30" rx="4" fill="#cbd5e1" />
        <circle cx="40" cy="72" r="9" fill="#1e293b" /><circle cx="40" cy="72" r="4" fill="#475569" />
        <circle cx="104" cy="72" r="9" fill="#1e293b" /><circle cx="104" cy="72" r="4" fill="#475569" />
      </svg>
    </div>
  );
}

export default function OrdersBoard() {
  const fetcher = useCallback(async () => {
    const res = await api.get('/TripDashboard');
    return res.data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const board = data?.board || { scheduled: [], completed: [], cancelled: [] };
  const counts = data?.counts || {};

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-6 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-[1400px]">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3">
            <Link to="/ops-dashboard" className="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:text-slate-800" title="Back to dashboard">
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
            </Link>
            <div>
              <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">Orders</h1>
              <p className="text-xs text-slate-500">Live trips from the Main Trip Dashboard · newest first</p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {LANES.map((l) => (
              <span key={l.key} className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm font-medium text-slate-600">
                <span className="h-1.5 w-1.5 rounded-full" style={{ background: l.dot }} />
                {l.title}
                <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-slate-900 px-1 text-[11px] font-semibold tabular-nums text-white">
                  {counts[l.key] ?? 0}
                </span>
              </span>
            ))}
          </div>
        </div>

        {error && <div className="mt-6 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">{error}</div>}

        <div className="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {LANES.map((lane) => {
            const cards = board[lane.key] || [];
            return (
              <div key={lane.key} className="space-y-4">
                <div className="flex items-center gap-2 px-1">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: lane.dot }} />
                  <h2 className="text-base font-semibold text-slate-800">{lane.title}</h2>
                  <span className="text-sm font-medium text-slate-400">{loading ? '' : cards.length}</span>
                </div>
                {loading ? (
                  Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-[150px] rounded-2xl" />)
                ) : cards.length === 0 ? (
                  <p className="rounded-2xl border border-dashed border-slate-200 py-10 text-center text-sm text-slate-400">No trips</p>
                ) : (
                  cards.map((card, i) => (
                    <div key={`${card.trip_no}-${i}`} className="space-y-4">
                      <TripCard card={card} laneKey={lane.key} />
                      {lane.key === 'completed' && i === 0 && <PromoCard />}
                    </div>
                  ))
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
