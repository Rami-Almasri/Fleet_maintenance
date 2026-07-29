// Recently Fixed — the Dashboard's "these cars came back working" card: the newest repairs that were
// signed off and closed, each showing WHAT was wrong, what was repaired, where it was fixed and how
// long it took. The positive counterpart to the pipeline cards (which only show what's still broken).
//
// Self-fetching from GET /maintenance-tickets/completed?limit=N — the same endpoint that backs the
// full /completed-repairs ledger, so the two can never disagree.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import Icon from './ui/Icon';
import { Skeleton } from './ui/Skeleton';
import { SectionCard } from './ui/Table';
import { InfoTip } from './ui/Tooltip';
import { SHOW_FINANCIALS } from '../config/features';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short' }) : '—');
const fmtAED = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const ago = (iso) => {
  if (!iso) return '';
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  if (days <= 0) return 'today';
  if (days === 1) return 'yesterday';
  if (days < 30) return `${days}d ago`;
  const months = Math.floor(days / 30);
  return `${months}mo ago`;
};

// The reported problem — what someone actually complained about, in priority order.
const problemOf = (tk) =>
  tk.customer_complaint || tk.trigger_reason || tk.test_drive_report || tk.maintenance_type_label || null;

// The faults worked on: first-class task rows when present, legacy findings otherwise.
const faultsOf = (tk) => {
  if (Array.isArray(tk.tasks) && tk.tasks.length) return tk.tasks.map((f) => f.symptom).filter(Boolean);
  return (tk.findings || []).map((f) => f.symptom || f.text || f.keyword).filter(Boolean);
};

// When the repair was finished. The server fills `completed_at` for both origins; the fallbacks cover
// a legacy closed ticket whose wf_closed_at was never stamped, so the row still dates itself.
const completedAt = (tk) => tk.completed_at || tk.handoffs?.closed?.at || tk.actual_in_date || tk.updated_at || null;

// How long the car was off the road, as a label. A same-day job reads in hours, never a bogus "0d".
const repairSpan = (tk) => {
  let secs = tk.stage_timing?.durations?.total_downtime;
  if (secs == null) {
    const end = completedAt(tk);
    if (!tk.created_at || !end) return null;
    secs = Math.max(0, Math.round((new Date(end) - new Date(tk.created_at)) / 1000));
  }
  if (secs < 3600) return 'same day';
  if (secs < 86400) return `${Math.round(secs / 3600)}h in shop`;
  return `${Math.round(secs / 86400)}d in shop`;
};

// Data origin — 'sheet' rows are imported workshop history, everything else ran in this app.
const ORIGINS = [
  ['all', 'All'],
  ['system', 'System'],
  ['sheet', 'Sheet'],
];

export default function RecentlyFixedCard({ limit = 6 }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [origin, setOrigin] = useState('all');

  useEffect(() => {
    let alive = true;
    setLoading(true);
    api.get('/maintenance-tickets/completed', { params: { limit, source: origin } })
      .then((res) => { if (alive) setRows(res.data.data?.tickets || []); })
      .catch(() => { if (alive) setRows([]); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [limit, origin]);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Recently Fixed
          <InfoTip content="The newest repairs that were signed off and closed — the car is back in the fleet. Each row shows the problem that was reported, the faults actually repaired, the garage that did the work and the total time the car spent off the road." />
        </span>
      }
      subtitle="Cars back in service — the problem, the fix, and how long it took"
      actions={
        <div className="flex items-center gap-3">
          {/* Data origin — imported workshop sheet vs repairs this app ran end-to-end. */}
          <div className="inline-flex rounded-lg bg-slate-100 p-0.5" title="Sheet = imported workshop history · System = a repair this app ran end-to-end">
            {ORIGINS.map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setOrigin(key)}
                className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                  origin === key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {label}
              </button>
            ))}
          </div>
          <Link to="/completed-repairs" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">All fixed repairs →</Link>
        </div>
      }
    >
      {loading ? (
        <ul className="space-y-2">
          {Array.from({ length: limit }).map((_, i) => <li key={i}><Skeleton className="h-16 rounded-xl" /></li>)}
        </ul>
      ) : rows.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">No repairs signed off yet.</p>
      ) : (
        <ul className="space-y-1">
          {rows.map((tk) => {
            const problem = problemOf(tk);
            const faults = faultsOf(tk);
            const span = repairSpan(tk);
            const closed = completedAt(tk);
            const isSheet = tk.source === 'sheet';
            return (
              <li key={tk.id} className="group flex gap-3 rounded-xl px-2 py-2.5 transition-colors hover:bg-emerald-50/40">
                <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200">
                  <Icon.Check className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    {/* A sheet row has no ticket to open — it links to the car's own history instead. */}
                    <Link
                      to={isSheet ? (tk.vehicle_id ? `/vehicles/${tk.vehicle_id}` : '/completed-repairs') : `/maintenance-workflow/${tk.id}`}
                      className="flex min-w-0 items-baseline gap-2 font-semibold text-slate-800 hover:text-indigo-600"
                    >
                      <span className="truncate font-mono text-sm">{tk.plate || `#${tk.id}`}</span>
                      {tk.car && <span className="truncate text-[11px] font-normal text-slate-400">{tk.car}</span>}
                    </Link>
                    <span className="shrink-0 text-[11px] tabular-nums text-slate-400" title={closed ? new Date(closed).toLocaleString() : 'No completion date recorded'}>
                      {closed ? `${fmtDate(closed)} · ${ago(closed)}` : '—'}
                    </span>
                  </div>

                  {/* The problem that started it all — skipped when the fault chips below already say it. */}
                  {(problem || faults.length === 0) && (
                    <p className="mt-0.5 truncate text-xs text-slate-600" title={problem || ''}>
                      {problem || <span className="text-slate-300">No description recorded</span>}
                    </p>
                  )}

                  {/* What was actually repaired. */}
                  {faults.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap items-center gap-1">
                      {faults.slice(0, 3).map((f, i) => (
                        <span key={i} className="inline-flex max-w-[180px] items-center truncate rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200">
                          {f}
                        </span>
                      ))}
                      {faults.length > 3 && <span className="text-[11px] text-slate-400">+{faults.length - 3}</span>}
                    </div>
                  )}

                  <p className="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11px] text-slate-500">
                    <span className="inline-flex items-center gap-1 truncate">
                      <Icon.Wrench className="h-3 w-3 text-slate-300" />
                      {tk.garage || 'On-site'}
                    </span>
                    {span && (
                      <span className="inline-flex items-center gap-1 tabular-nums" title="Total time the car was off the road">
                        <Icon.Clock className="h-3 w-3 text-slate-300" />
                        {span}
                      </span>
                    )}
                    {SHOW_FINANCIALS && tk.cost != null && (
                      <span className="font-semibold tabular-nums text-slate-600">{fmtAED(tk.cost)}</span>
                    )}
                    {/* Data origin — never leave the reader guessing where a row came from. */}
                    <span
                      className={`inline-flex items-center gap-1 rounded-full px-1.5 py-px font-semibold ${
                        isSheet ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
                      }`}
                      title={isSheet
                        ? 'Imported from the historical workshop sheet — only what the sheet recorded is shown.'
                        : 'Ran end-to-end in this app — full people chain, faults and odometer readings.'}
                    >
                      {isSheet ? 'Sheet' : 'System'}
                    </span>
                  </p>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </SectionCard>
  );
}
