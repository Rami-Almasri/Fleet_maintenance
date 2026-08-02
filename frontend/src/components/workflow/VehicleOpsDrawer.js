import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Drawer from '../ui/Drawer';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { SectionCard } from '../ui/Table';
import { aed, fmtDate } from '../../lib/format';
import { fmtDateTime } from './meta';
import { ProgressSpine } from './OpsCard';
import { tone, ROLE, shortDate } from './opsMeta';

// ─────────────────────────────────────────────────────────────────────────────────────────────
// The complete OPERATIONAL SUMMARY for one car in maintenance — what opens when a manager clicks a
// card on the Car Status Operations Dashboard. The card answers "what is happening"; this answers
// "…and everything behind it", in one scroll and without leaving the board:
//
//   • the primary maintenance reason + every reported issue, with each fault's own state
//   • the repair spine + the live blocker
//   • checkpoint history — every progress update, each with the ETA it moved (the ETA history)
//   • parts requested vs fitted
//   • the workflow timeline (who advanced it, when) + the supervisor's follow-up log
//   • photos & videos on the ticket
//   • the current cost position + its invoices
//   • responsibility: garage, stage owner, follow-up owners
//
// It reads three endpoints — the hydrated ticket, its checkpoints, its media — and shows only what
// each returns. This is the OPERATIONAL view; the vehicle's long-term record (components, ledger,
// warranty, documents) lives on the Vehicle Details page, linked from the footer.
// ─────────────────────────────────────────────────────────────────────────────────────────────

export default function VehicleOpsDrawer({ ticketId, onClose }) {
  const navigate = useNavigate();

  const fetcher = useCallback(async () => {
    if (!ticketId) return null;
    const [ticket, checkpoints, media] = await Promise.all([
      api.get(`/maintenance-tickets/${ticketId}`).then((r) => r.data.data),
      api.get(`/maintenance-tickets/${ticketId}/checkpoints`).then((r) => r.data.data).catch(() => null),
      api.get(`/maintenance-tickets/${ticketId}/media`).then((r) => r.data.data).catch(() => null),
    ]);
    return { ticket, checkpoints, media };
  }, [ticketId]);

  const { data, loading, error } = useFetch(fetcher, [ticketId]);

  const tk = data?.ticket;
  const o = tk?.ops;
  const checkpoints = data?.checkpoints?.checkpoints || [];
  const mediaItems = (Array.isArray(data?.media) ? data.media : data?.media?.media) || [];

  return (
    <Drawer
      open={!!ticketId}
      onClose={onClose}
      eyebrow="Operations summary"
      title={tk ? (tk.plate || `Ticket #${ticketId}`) : 'Loading…'}
      subtitle={tk ? [tk.car, tk.status_label].filter(Boolean).join(' · ') : ''}
      width="lg"
      footer={
        tk && (
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" size="sm" onClick={() => { navigate(`/maintenance-workflow/${tk.id}`); onClose?.(); }}>
              Open ticket
            </Button>
            {tk.vehicle_id && (
              <Button variant="secondary" size="sm" onClick={() => { navigate(`/car-status/${tk.vehicle_id}`); onClose?.(); }}>
                Vehicle details
              </Button>
            )}
            <span className="ms-auto text-[11px] text-slate-400">
              Ticket #{tk.id} · opened {fmtDate(tk.created_at)}
            </span>
          </div>
        )
      }
    >
      {loading && (
        <div className="space-y-3 p-5">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-40 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      )}

      {error && !loading && (
        <div className="p-5 text-sm text-red-600">Couldn’t load this vehicle’s operations summary.</div>
      )}

      {tk && !loading && (
        <div className="space-y-4 p-5">
          {/* ── Headline: why it's here + what's happening + what's blocking ──── */}
          <div className="rounded-2xl bg-white p-4 shadow-soft ring-1 ring-slate-200">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                  Primary maintenance reason
                </div>
                <div className="mt-0.5 font-display text-xl font-bold leading-tight text-slate-900">
                  {o?.reason?.primary || 'Not recorded'}
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  {tk.fault_severity_label && (
                    <Badge tone={tk.fault_severity_tone || 'slate'} dot>{tk.fault_severity_label}</Badge>
                  )}
                  {o?.reason?.extra > 0 && (
                    <span className="text-xs font-medium text-slate-500">+{o.reason.extra} more open issues</span>
                  )}
                  {o?.reason?.source && (
                    <span className="text-[10px] uppercase tracking-wide text-slate-400">
                      Source: {o.reason.source === 'fault' ? 'inspector fault' : o.reason.source}
                    </span>
                  )}
                </div>
              </div>
              {o?.state && (
                <span className={`shrink-0 rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset ${tone(o.state.tone).chip}`}>
                  {o.state.label}
                </span>
              )}
            </div>

            {o?.progress?.length > 0 && (
              <div className="mt-4">
                <ProgressSpine steps={o.progress} />
              </div>
            )}

            {o?.blocker && (
              <div className={`mt-3 rounded-xl px-3 py-2 ring-1 ring-inset ${tone(o.blocker.tone).chip}`}>
                <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide">
                  <Icon.Alert className="h-4 w-4" /> {o.blocker.label}
                </div>
                {o.blocker.detail && <div className="mt-0.5 text-xs opacity-80">{o.blocker.detail}</div>}
              </div>
            )}

            {/* Clock + responsibility, the two facts a manager quotes on a call. */}
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Stat label="In maintenance" value={fmtDays(o?.timing?.days_in_maintenance)} />
              <Stat
                label={o?.timing?.eta_is_estimated ? 'ETA (estimated)' : 'ETA'}
                value={shortDate(o?.timing?.eta) || '—'}
                sub={o?.timing?.days_over > 0 ? `${o.timing.days_over}d overdue` : null}
                tone={o?.timing?.days_over > 0 ? 'text-red-600' : undefined}
              />
              <Stat label="Since last update" value={fmtDays(o?.timing?.days_since_update)} />
              <Stat label="Garage" value={o?.responsibility?.garage || '—'} />
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-500">
              <span>
                <span className="font-semibold text-slate-700">
                  {(ROLE[o?.responsibility?.owner_role] || ROLE.none).label}:
                </span>{' '}
                {o?.responsibility?.owner_name || 'unassigned'}
              </span>
              {o?.responsibility?.followers?.length > 0 && (
                <span>
                  <span className="font-semibold text-slate-700">Follow-up:</span>{' '}
                  {o.responsibility.followers.join(', ')}
                </span>
              )}
              {o?.responsibility?.transfer_to && (
                <span className="text-amber-600">Transferring → {o.responsibility.transfer_to}</span>
              )}
            </div>

            {o?.alerts?.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-1.5">
                {o.alerts.map((a) => (
                  <span key={a.key} className={`rounded-md px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${tone(a.tone).chip}`}>
                    {a.label}
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* ── Every reported issue ───────────────────────────────────────────── */}
          <SectionCard title="Reported issues" subtitle={`${tk.tasks?.length || 0} on this ticket`} bodyClass="p-0">
            {tk.tasks?.length ? (
              <ul className="divide-y divide-slate-100">
                {tk.tasks.map((f) => (
                  <li key={f.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="text-sm font-semibold text-slate-900">{f.symptom}</div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-500">
                          {f.current_garage && <span>{f.current_garage}</span>}
                          {f.root_cause && <span>Root cause: {f.root_cause}</span>}
                          {f.repair_gate === 'pending' && <span className="font-semibold text-red-600">Repair frozen — awaiting approval</span>}
                        </div>
                        {(f.notes || f.resolution_note) && (
                          <div className="mt-1 text-[11px] leading-snug text-slate-500">
                            {f.resolution_note || f.notes}
                          </div>
                        )}
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <Badge tone={f.is_terminal ? 'green' : 'amber'} dot>
                          {String(f.status || '').replace(/_/g, ' ')}
                        </Badge>
                        {f.severity_label && (
                          <span className="text-[10px] font-semibold text-slate-400">{f.severity_label}</span>
                        )}
                      </div>
                    </div>
                    {f.parts?.length > 0 && (
                      <div className="mt-1.5 flex flex-wrap gap-1">
                        {f.parts.map((p) => (
                          <span key={p.id} className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                            {p.part_name} · {String(p.status || '').replace(/_/g, ' ')}
                          </span>
                        ))}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <Empty>No faults recorded on this ticket.</Empty>
            )}
          </SectionCard>

          {/* ── Parts: requested vs fitted ─────────────────────────────────────── */}
          <SectionCard title="Parts" subtitle="Requested and fitted across every fault" bodyClass="p-0">
            <PartsPanel tasks={tk.tasks || []} />
          </SectionCard>

          {/* ── Checkpoint history = the ETA history ───────────────────────────── */}
          <SectionCard
            title="Checkpoint history"
            subtitle="Every progress update, and the ETA it moved"
            bodyClass="p-0"
          >
            {checkpoints.length ? (
              <ol className="divide-y divide-slate-100">
                {checkpoints.map((c) => (
                  <li key={c.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="text-sm font-semibold capitalize text-slate-900">
                          {String(c.status || 'Progress update').replace(/_/g, ' ')}
                        </div>
                        {c.summary && <div className="mt-0.5 text-[11px] leading-snug text-slate-600">{c.summary}</div>}
                        {c.delay_reason && (
                          <div className="mt-0.5 text-[11px] capitalize text-amber-600">
                            Delay: {String(c.delay_reason).replace(/_/g, ' ')}
                            {c.delay_reason_other ? ` — ${c.delay_reason_other}` : ''}
                          </div>
                        )}
                      </div>
                      <div className="shrink-0 text-end text-[11px] text-slate-400">
                        <div>{fmtDate(c.created_at)}</div>
                        {c.submitted_by_name && <div className="font-medium text-slate-500">{c.submitted_by_name}</div>}
                      </div>
                    </div>
                    <div className="mt-1 text-[11px] text-slate-500">
                      ETA{' '}
                      {c.previous_expected_date ? (
                        <>
                          <span className="line-through">{fmtDate(c.previous_expected_date)}</span>
                          {' → '}
                        </>
                      ) : (
                        'set to '
                      )}
                      <span className="font-semibold text-slate-700">{fmtDate(c.next_expected_date)}</span>
                    </div>
                  </li>
                ))}
              </ol>
            ) : (
              <Empty>No progress checkpoints have been filed for this repair yet.</Empty>
            )}
          </SectionCard>

          {/* ── The workflow timeline + the follow-up log ──────────────────────── */}
          <SectionCard title="Timeline" subtitle="How the ticket moved, and who moved it" bodyClass="p-0">
            <Timeline tk={tk} />
          </SectionCard>

          {/* ── Photos & videos ────────────────────────────────────────────────── */}
          <SectionCard title="Photos & videos" subtitle={`${mediaItems.length} attached`} bodyClass="p-4">
            {mediaItems.length ? (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {mediaItems.map((m) => (
                  <a
                    key={m.id}
                    href={m.url}
                    target="_blank"
                    rel="noreferrer"
                    className="group flex items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-2 text-[11px] ring-1 ring-inset ring-slate-200 transition hover:ring-indigo-300"
                  >
                    {m.kind === 'video' ? <Icon.Video className="h-4 w-4 shrink-0 text-slate-400" /> : <Icon.Camera className="h-4 w-4 shrink-0 text-slate-400" />}
                    <span className="truncate text-slate-600">{m.note || m.original_name || m.kind}</span>
                  </a>
                ))}
              </div>
            ) : (
              <div className="py-2 text-xs text-slate-400">Nothing attached to this ticket.</div>
            )}
          </SectionCard>

          {/* ── Cost position ──────────────────────────────────────────────────── */}
          <SectionCard title="Cost so far" subtitle="What this repair has been billed" bodyClass="p-4">
            <div className="grid grid-cols-3 gap-2">
              <Stat label="Parts" value={tk.parts_total != null ? aed(tk.parts_total) : '—'} />
              <Stat label="Labour" value={tk.labor_total != null ? aed(tk.labor_total) : '—'} />
              <Stat label="Total" value={tk.cost != null ? aed(tk.cost) : (tk.cost_pending ? 'Pending invoice' : '—')} />
            </div>
            {tk.invoices?.length > 0 && (
              <ul className="mt-3 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                {tk.invoices.map((inv) => (
                  <li key={inv.id} className="flex items-center justify-between gap-2 px-3 py-2 text-[11px]">
                    <span className="truncate text-slate-600">
                      {inv.vendor_name || (inv.is_internal ? 'In-house' : `Invoice #${inv.id}`)}
                      {inv.invoice_no ? ` · ${inv.invoice_no}` : ''}
                    </span>
                    <span className="shrink-0 font-semibold tabular-nums text-slate-800">{aed(inv.amount ?? 0)}</span>
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>
        </div>
      )}
    </Drawer>
  );
}

// ── pieces ───────────────────────────────────────────────────────────────────

function Stat({ label, value, sub, tone: toneCls }) {
  return (
    <div className="rounded-lg bg-slate-50 px-2.5 py-2 ring-1 ring-inset ring-slate-200/70">
      <div className="truncate text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`truncate text-sm font-bold ${toneCls || 'text-slate-900'}`}>{value ?? '—'}</div>
      {sub && <div className={`text-[10px] ${toneCls || 'text-slate-400'}`}>{sub}</div>}
    </div>
  );
}

function Empty({ children }) {
  return <div className="px-4 py-5 text-center text-xs text-slate-400">{children}</div>;
}

const fmtDays = (n) => (n === null || n === undefined ? '—' : n === 0 ? 'Today' : `${n} days`);

/** Requested vs fitted, flattened across every fault — the "what's on order / what went in" answer. */
function PartsPanel({ tasks }) {
  const parts = tasks.flatMap((f) => (f.parts || []).map((p) => ({ ...p, fault: f.symptom })));
  if (!parts.length) return <Empty>No parts have been raised for this repair.</Empty>;

  const fitted = parts.filter((p) => ['installed', 'completed'].includes(p.status));
  const open = parts.filter((p) => !['installed', 'completed', 'rejected', 'cancelled'].includes(p.status));

  const Row = ({ p }) => (
    <li className="flex items-start justify-between gap-2 px-4 py-2">
      <div className="min-w-0">
        <div className="text-xs font-medium text-slate-800">{p.part_name}</div>
        <div className="truncate text-[10px] text-slate-400">{p.fault}</div>
      </div>
      <span className="shrink-0 text-[10px] font-semibold capitalize text-slate-500">
        {String(p.status || '').replace(/_/g, ' ')}
      </span>
    </li>
  );

  return (
    <div>
      <GroupHeader>Outstanding · {open.length}</GroupHeader>
      {open.length ? <ul className="divide-y divide-slate-100">{open.map((p) => <Row key={p.id} p={p} />)}</ul> : <Empty>Nothing outstanding.</Empty>}
      <GroupHeader>Fitted · {fitted.length}</GroupHeader>
      {fitted.length ? <ul className="divide-y divide-slate-100">{fitted.map((p) => <Row key={p.id} p={p} />)}</ul> : <Empty>Nothing fitted yet.</Empty>}
    </div>
  );
}

function GroupHeader({ children }) {
  return (
    <div className="border-y border-slate-100 bg-slate-50 px-4 py-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">
      {children}
    </div>
  );
}

/**
 * The ticket's audit trail — the stamped handoffs (who advanced it, when) followed by the supervisor's
 * free-text follow-up log. Both come straight off the ticket; nothing is reconstructed.
 */
const HANDOFF_LABEL = {
  requested: 'Inspection requested',
  reviewed: 'Request reviewed',
  inspected: 'Test drive / diagnosis',
  dispatched: 'Picked up for the garage',
  repair_started: 'Arrived at the garage',
  ready: 'Garage finished',
  picked_up_from_garage: 'Collected from the garage',
  park_arrived: 'Back at our park',
  closed: 'Closed',
};

function Timeline({ tk }) {
  const steps = Object.entries(HANDOFF_LABEL)
    .map(([key, label]) => ({ key, label, stamp: tk.handoffs?.[key] }))
    .filter((s) => s.stamp);

  const followUps = (tk.follow_ups || []).slice().reverse();

  if (!steps.length && !followUps.length) return <Empty>Nothing has been recorded yet.</Empty>;

  return (
    <div>
      <ol className="divide-y divide-slate-100">
        {steps.map((s) => (
          <li key={s.key} className="flex items-start justify-between gap-2 px-4 py-2.5">
            <div className="min-w-0">
              <div className="text-xs font-semibold text-slate-800">{s.label}</div>
              <div className="truncate text-[11px] text-slate-400">
                {[s.stamp.name, s.stamp.garage || s.stamp.destination].filter(Boolean).join(' · ') || '—'}
              </div>
            </div>
            <div className="shrink-0 text-[11px] text-slate-400">{fmtDateTime(s.stamp.at) || '—'}</div>
          </li>
        ))}
      </ol>

      {followUps.length > 0 && (
        <>
          <GroupHeader>Follow-up log · {followUps.length}</GroupHeader>
          <ul className="divide-y divide-slate-100">
            {followUps.map((f, i) => (
              <li key={`${f.at || i}-${i}`} className="px-4 py-2.5">
                <div className="text-[11px] leading-snug text-slate-700">{f.text}</div>
                <div className="mt-0.5 text-[10px] text-slate-400">
                  {[f.by_name || f.by, fmtDateTime(f.at)].filter(Boolean).join(' · ')}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
