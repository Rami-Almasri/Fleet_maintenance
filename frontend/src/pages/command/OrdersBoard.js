// Orders board — a three-lane Kanban of live trips (Scheduled → Completed →
// Cancelled), fed by the Main Trip Dashboard sheet (GET /TripDashboard). Each
// card is a real pickup/drop-off trip: vehicle, trip no., status, assignee
// (driver/sales), destination and date. Aurora light surface, shared primitives.

import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Skeleton } from '../../components/ui/Skeleton';
import { PageHeader } from '../../components/ui/Misc';

const LANES = [
  { key: 'scheduled', title: 'Scheduled', dot: 'bg-amber-500' },
  { key: 'completed', title: 'Completed', dot: 'bg-cyan-500' },
  { key: 'cancelled', title: 'Cancelled', dot: 'bg-rose-500' },
];

const LANE_CHIP = {
  scheduled: { label: 'Scheduled', dot: 'bg-amber-500', text: 'text-amber-600' },
  completed: { label: 'Completed', dot: 'bg-cyan-500', text: 'text-cyan-600' },
  cancelled: { label: 'Cancelled', dot: 'bg-rose-500', text: 'text-rose-600' },
};

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return Number.isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function ParcelGlyph() {
  return (
    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
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
    <div className="hover-lift rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <ParcelGlyph />
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-900">{card.model || 'Vehicle'}</p>
            <p className="font-mono text-xs text-slate-400">{card.trip_no}{card.plate ? ` · ${card.plate}` : ''}</p>
          </div>
        </div>
        <span className={`inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-xs font-medium ${chip.text}`}>
          <span className={`h-1.5 w-1.5 rounded-full ${chip.dot}`} />
          {card.status || chip.label}
        </span>
      </div>
      <div className="mt-3 flex items-center gap-1.5 truncate text-xs text-slate-500">
        <svg className="h-3.5 w-3.5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 21s-6-5.7-6-10a6 6 0 0 1 12 0c0 4.3-6 10-6 10z" /><circle cx="12" cy="11" r="2" /></svg>
        <span className="truncate">{dest}</span>
      </div>
      <div className="mt-3 grid grid-cols-3 items-end gap-2 border-t border-slate-100 pt-3">
        <div className="col-span-2 min-w-0">
          <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Assigned to</p>
          <p className="truncate text-sm font-medium text-slate-700">{assignee}</p>
        </div>
        <div className="text-right">
          <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">When</p>
          <p className="text-sm font-medium tabular-nums text-slate-700">{fmtDate(card.date)}</p>
        </div>
      </div>
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
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex items-start gap-3">
          <Link
            to="/ops-dashboard"
            aria-label="Back to Delivery Command"
            title="Back to dashboard"
            className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-soft transition-colors duration-150 hover:bg-slate-50 hover:text-slate-800"
          >
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
          </Link>
          <div className="min-w-0 flex-1">
            <PageHeader title="Orders" subtitle="Live trips from the Main Trip Dashboard · newest first">
              <div className="flex flex-wrap gap-2">
                {LANES.map((l) => (
                  <span key={l.key} className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm font-medium text-slate-600 shadow-soft">
                    <span className={`h-1.5 w-1.5 rounded-full ${l.dot}`} />
                    {l.title}
                    <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-slate-900 px-1 text-[11px] font-semibold tabular-nums text-white">
                      {counts[l.key] ?? 0}
                    </span>
                  </span>
                ))}
              </div>
            </PageHeader>
          </div>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        <div className="stagger grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {LANES.map((lane) => {
            const cards = board[lane.key] || [];
            return (
              <div key={lane.key} className="space-y-4">
                <div className="flex items-center gap-2 px-1">
                  <span className={`h-2.5 w-2.5 rounded-full ${lane.dot}`} />
                  <h2 className="text-base font-semibold text-slate-800">{lane.title}</h2>
                  <span className="text-sm font-medium tabular-nums text-slate-400">{loading ? '' : cards.length}</span>
                </div>
                {loading ? (
                  Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-[150px] rounded-2xl" />)
                ) : cards.length === 0 ? (
                  <p className="rounded-2xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-400">No trips in this lane — they appear here as they're logged on the Main Trip Dashboard sheet.</p>
                ) : (
                  cards.map((card, i) => (
                    <TripCard key={`${card.trip_no}-${i}`} card={card} laneKey={lane.key} />
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
