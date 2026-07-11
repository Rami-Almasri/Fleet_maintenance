// ShopTimer — shows a maintenance ticket's REAL downtime as pause/resume sessions, so an employee sees
//   "Total Days in Shop: 7  (2 days initial + 5 days after rental)"
// with a small timeline: each in-shop session (red) separated by the rental pause (green) that paused
// the clock. `sessions` come from buildShopSessions() in lib/maintenanceSessions.
import { describeShopSessions } from '../../lib/maintenanceSessions';
import { fmtDate } from '../../lib/format';

export default function ShopTimer({ sessions = [], compact = false }) {
  const total = sessions.reduce((t, s) => t + (s.days || 0), 0);

  if (compact) {
    return (
      <span className="inline-flex flex-col">
        <span className="font-semibold tabular-nums text-red-600">{total}d</span>
        {sessions.length > 0 && (
          <span className="text-[11px] text-slate-400">{describeShopSessions(sessions)}</span>
        )}
      </span>
    );
  }

  return (
    <div>
      <p className="text-sm text-slate-700">
        Total Days in Shop:{' '}
        <span className="font-bold tabular-nums text-red-600">{total}</span>
        {sessions.length > 0 && <span className="text-slate-400"> ({describeShopSessions(sessions)})</span>}
      </p>

      {/* Timeline: in-shop sessions (red) with the rental pause shown as the gap between them. */}
      {sessions.length > 0 && (
        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          {sessions.map((s, i) => (
            <span key={`${s.start}-${i}`} className="inline-flex items-center gap-1.5">
              {i > 0 && (
                <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-600">
                  <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5" /></svg>
                  rental
                </span>
              )}
              <span
                className="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700 ring-1 ring-inset ring-red-200"
                title={`${s.label}: ${fmtDate(s.start)} → ${fmtDate(s.end)}`}
              >
                <span className="font-semibold tabular-nums">{s.days}d</span>
                <span className="capitalize text-red-400">{s.label}</span>
              </span>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
