import Icon from '../ui/Icon';
import Badge from '../ui/Badge';
import { tone, ROLE, days, shortDate } from './opsMeta';

// ─────────────────────────────────────────────────────────────────────────────────────────────
// The Operations card — one car in maintenance, answering the six questions a Maintenance or
// Operations Manager asks in the first three seconds, WITHOUT opening the vehicle:
//
//   Why is it here?         → the primary maintenance reason, as the card's headline
//   What's happening now?   → the operational state (Diagnosing / Waiting for Parts / Road Test…)
//   What's blocking it?     → the single blocker + its detail
//   How far has it got?     → the checkpoint reached + the repair spine
//   Who is responsible?     → garage · stage owner · follow-up owners
//   Does it need chasing?   → the alert strip (overdue, silent, escalated)
//
// Every value is read straight off the server's `ops` block (MaintenanceOpsCardService) — the card
// derives nothing of its own, so what it shows is exactly what the data says.
// ─────────────────────────────────────────────────────────────────────────────────────────────

export default function OpsCard({ tk, onOpen }) {
  const o = tk.ops;
  if (!o) return null;

  const state = o.state || {};
  const st = tone(state.tone);
  const blocker = o.blocker;
  const cp = o.checkpoint;
  const timing = o.timing || {};
  const resp = o.responsibility || {};
  const role = ROLE[resp.owner_role] || ROLE.none;
  // "Nothing blocking" may only be claimed when no alert is warning about this car either.
  const allClear = !blocker && !(o.alerts || []).some((a) => a.tone === 'red' || a.tone === 'amber');

  return (
    <button
      type="button"
      onClick={() => onOpen?.(tk)}
      className="group flex w-full flex-col rounded-2xl bg-white text-left shadow-soft ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-lg hover:ring-indigo-300"
    >
      {/* State accent — the card's colour IS its operational state, readable across a room. */}
      <div className="h-1 w-full rounded-t-2xl" style={{ background: st.bar }} />

      <div className="flex flex-1 flex-col gap-3 p-4">
        {/* ── Identity + what's happening right now ───────────────────────────── */}
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="font-display text-lg font-bold leading-none tracking-tight text-slate-900">
              {tk.plate || `#${tk.id}`}
            </div>
            {tk.car && <div className="mt-1 truncate text-xs text-slate-500">{tk.car}</div>}
          </div>
          <span
            className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset ${st.chip}`}
          >
            {state.label}
          </span>
        </div>

        {/* ── 1. The primary maintenance reason — the headline of the card ────── */}
        <div className="rounded-xl bg-slate-50 px-3 py-2.5 ring-1 ring-inset ring-slate-200/70">
          <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
            <Icon.Wrench className="h-3 w-3" /> Maintenance reason
          </div>
          <div className="mt-1 flex items-baseline gap-1.5">
            <span className="min-w-0 flex-1 font-display text-[15px] font-bold leading-snug text-slate-900">
              {o.reason?.primary || 'Not recorded'}
            </span>
            {o.reason?.extra > 0 && (
              <span className="shrink-0 text-xs font-semibold text-slate-400">
                +{o.reason.extra} more
              </span>
            )}
          </div>
          {tk.fault_severity_label && (
            <div className="mt-1.5">
              <Badge tone={tk.fault_severity_tone || 'slate'} dot>{tk.fault_severity_label}</Badge>
            </div>
          )}
        </div>

        {/* ── 4. What is blocking progress ───────────────────────────────────── */}
        {blocker ? (
          <div className={`rounded-xl px-3 py-2 ring-1 ring-inset ${tone(blocker.tone).chip}`}>
            <div className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide">
              <Icon.Alert className="h-3.5 w-3.5" /> {blocker.label}
            </div>
            {blocker.detail && <div className="mt-0.5 text-[11px] leading-snug opacity-80">{blocker.detail}</div>}
          </div>
        ) : allClear ? (
          // The all-clear is only honest when nothing else on the card is warning. A car nobody has
          // reported on for a week has no BLOCKER, but it is not "fine" — there the alert strip speaks
          // and this stays silent rather than contradicting it.
          <div className="flex items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
            <Icon.Check className="h-3.5 w-3.5" /> Nothing blocking — work in progress
          </div>
        ) : null}

        {/* ── 5. Parts the car is still owed ─────────────────────────────────── */}
        {o.parts?.length > 0 && (
          <div className="rounded-xl bg-violet-50/70 px-3 py-2 ring-1 ring-inset ring-violet-100">
            <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-violet-500">
              <Icon.Coins className="h-3 w-3" /> Parts outstanding · {o.parts.length}
            </div>
            <ul className="mt-1 space-y-0.5">
              {o.parts.slice(0, 4).map((p) => (
                <li key={p.id ?? p.name} className="flex items-center justify-between gap-2 text-[11px] text-violet-800">
                  <span className="truncate">
                    {/* A dot marks the parts that are actually holding the repair up — the rest are
                        ordered/arrived but not yet fitted, which blocks nothing. */}
                    {p.blocking && <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-violet-500 align-middle" />}
                    {p.name}
                  </span>
                  <span className="shrink-0 font-medium capitalize text-violet-500">
                    {String(p.status || '').replace(/_/g, ' ')}
                  </span>
                </li>
              ))}
              {o.parts.length > 4 && (
                <li className="text-[11px] font-medium text-violet-500">+{o.parts.length - 4} more</li>
              )}
            </ul>
          </div>
        )}

        {/* ── 8. Repair progress — the spine, so "where are we" needs no reading ── */}
        <ProgressSpine steps={o.progress || []} />

        {/* ── 3. The checkpoint reached, and how fresh it is ─────────────────── */}
        <div className="flex items-start gap-2 border-t border-slate-100 pt-2.5 text-[11px]">
          <Icon.Flag className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          {cp ? (
            <div className="min-w-0">
              <span className="font-semibold text-slate-700">{cp.label}</span>
              <span className="text-slate-400">
                {' · '}
                {cp.days_since === 0 ? 'updated today' : `updated ${cp.days_since}d ago`}
                {cp.by ? ` · ${cp.by}` : ''}
              </span>
              {cp.summary && <div className="mt-0.5 line-clamp-2 text-slate-500">{cp.summary}</div>}
            </div>
          ) : (
            <span className="font-medium italic text-slate-400">No checkpoint filed yet</span>
          )}
        </div>

        {/* ── 6. Time & ETA ──────────────────────────────────────────────────── */}
        <div className="grid grid-cols-3 gap-2">
          <Metric label="In maintenance" value={days(timing.days_in_maintenance) ?? '—'} />
          <Metric
            label={timing.eta_is_estimated ? 'ETA (est.)' : 'ETA'}
            value={shortDate(timing.eta) ?? '—'}
            tone={timing.days_over > 0 ? 'text-red-600' : undefined}
            sub={timing.days_over > 0 ? `${timing.days_over}d over` : (timing.days_left > 0 ? `${timing.days_left}d left` : null)}
          />
          <Metric
            label="Last update"
            value={days(timing.days_since_update) ?? '—'}
            tone={(timing.days_since_checkpoint ?? 0) >= 3 ? 'text-amber-600' : undefined}
          />
        </div>

        {/* ── 7. Responsibility ──────────────────────────────────────────────── */}
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
          <span className="inline-flex items-center gap-1">
            <Icon.Wrench className="h-3 w-3 text-slate-400" />
            <span className="font-semibold text-slate-700">{resp.garage || 'No garage'}</span>
            {resp.transfer_to && <span className="text-slate-400">→ {resp.transfer_to}</span>}
          </span>
          <span className="inline-flex items-center gap-1">
            <Icon.Users className="h-3 w-3 text-slate-400" />
            {role.label}:{' '}
            {resp.owner_name
              ? <span className="font-semibold text-slate-700">{resp.owner_name}</span>
              : <span className="italic text-slate-400">{role.waiting}</span>}
          </span>
          {resp.followers?.length > 0 && (
            <span className="inline-flex items-center gap-1">
              <Icon.Shield className="h-3 w-3 text-slate-400" /> {resp.followers.join(', ')}
            </span>
          )}
        </div>

        {/* ── 9. Operational alerts ──────────────────────────────────────────── */}
        {o.alerts?.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {o.alerts.map((a) => (
              <span
                key={a.key}
                className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${tone(a.tone).chip}`}
              >
                <span className={`h-1.5 w-1.5 rounded-full ${tone(a.tone).dot}`} /> {a.label}
              </span>
            ))}
          </div>
        )}
      </div>
    </button>
  );
}

/** One number in the card's timing row. */
function Metric({ label, value, sub, tone: toneCls }) {
  return (
    <div className="rounded-lg bg-slate-50 px-2 py-1.5 ring-1 ring-inset ring-slate-200/70">
      <div className="truncate text-[9px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`text-sm font-bold tabular-nums leading-tight ${toneCls || 'text-slate-900'}`}>{value}</div>
      {sub && <div className={`truncate text-[10px] ${toneCls || 'text-slate-400'}`}>{sub}</div>}
    </div>
  );
}

/**
 * The repair spine: seven fixed steps, each done / active / todo. Rendered as connected pills so the eye
 * lands on the ACTIVE one — "we're at Waiting for Parts" — without reading a word.
 */
export function ProgressSpine({ steps = [], compact = false }) {
  if (!steps.length) return null;
  const active = steps.find((s) => s.state === 'active');

  return (
    <div>
      <div className="flex items-center gap-1">
        {steps.map((s) => (
          <div key={s.key} className="flex flex-1 flex-col items-center gap-1" title={s.label}>
            <span
              className={`h-1.5 w-full rounded-full ${
                s.state === 'done' ? 'bg-emerald-400'
                  : s.state === 'active' ? 'bg-indigo-500'
                  : 'bg-slate-200'
              }`}
            />
            {!compact && (
              <span
                className={`w-full truncate text-center text-[8px] font-semibold uppercase tracking-tight ${
                  s.state === 'active' ? 'text-indigo-600' : s.state === 'done' ? 'text-slate-400' : 'text-slate-300'
                }`}
              >
                {s.label}
              </span>
            )}
          </div>
        ))}
      </div>
      {compact && active && (
        <div className="mt-1 text-[10px] font-semibold text-indigo-600">{active.label}</div>
      )}
    </div>
  );
}
