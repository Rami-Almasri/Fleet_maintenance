// Delay Explanation — the "why is this vehicle delayed?" panel. The whole point of Maintenance Progress
// is to understand the CAUSE of a delay and who moved the ETA, not just to flash "Overdue". Given a row's
// last checkpoint, this renders the delay story as a labelled block: Previous ETA → New ETA, the delay in
// days, the structured reason (+ the free-text note), who filed it and when.
//
// If no checkpoint exists — or a checkpoint exists but carries no reason — it says "No delay reason
// recorded" clearly, so an overdue car with no explanation is itself an actionable signal.

import { fmtDate } from '../../lib/format';
import { delayReasonLabel } from '../../lib/maintenanceCheckpoints';

// Whole days between the previously promised ETA and the revised one (positive = the job slipped later).
function delayDays(prev, next) {
  if (!prev || !next) return null;
  const a = new Date(`${prev}T00:00:00`);
  const b = new Date(`${next}T00:00:00`);
  if (isNaN(a) || isNaN(b)) return null;
  return Math.round((b - a) / 86400000);
}

function Field({ label, children }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-700">{children}</dd>
    </div>
  );
}

export default function DelayExplanation({ checkpoint, className = '' }) {
  const c = checkpoint;

  // No update at all — the ETA has never been revised, so there is nothing to explain.
  if (!c) {
    return (
      <div className={`rounded-xl border border-amber-200 bg-amber-50/60 px-3.5 py-2.5 text-sm font-medium text-amber-700 ${className}`}>
        No delay reason recorded
      </div>
    );
  }

  const reason = c.delay_reason === 'other'
    ? (c.delay_reason_other || null)
    : delayReasonLabel(c.delay_reason);
  const summary = c.summary || null;
  const hasReason = !!(reason || summary);
  const dd = delayDays(c.previous_expected_date, c.next_expected_date);

  return (
    <div className={`rounded-xl border border-red-200 bg-red-50/50 px-3.5 py-3 ${className}`}>
      <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-red-600">
        Why is this vehicle delayed?
      </p>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-3">
        <Field label="Previous ETA">
          {c.previous_expected_date ? fmtDate(c.previous_expected_date) : <span className="text-slate-400">—</span>}
        </Field>
        <Field label="New ETA">
          {c.next_expected_date ? fmtDate(c.next_expected_date) : <span className="text-slate-400">—</span>}
        </Field>
        <Field label="Delay">
          {dd != null
            ? <span className={dd > 0 ? 'font-semibold text-red-600' : 'text-slate-600'}>{dd > 0 ? `+${dd}` : dd} day{Math.abs(dd) === 1 ? '' : 's'}</span>
            : <span className="text-slate-400">—</span>}
        </Field>
        <Field label="Reason">
          {hasReason ? (
            <span className="block">
              {reason && <span>{reason}</span>}
              {summary && <span className={`block text-[13px] text-slate-500 ${reason ? 'mt-0.5' : ''}`}>“{summary}”</span>}
            </span>
          ) : (
            <span className="font-medium text-amber-600">No delay reason recorded</span>
          )}
        </Field>
        <Field label="Updated by">
          {c.by || <span className="text-slate-400">—</span>}
        </Field>
        <Field label="Updated">
          {c.at ? fmtDate(c.at) : <span className="text-slate-400">—</span>}
        </Field>
      </dl>
    </div>
  );
}
