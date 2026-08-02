// Maintenance Progress — the dashboard's operational monitoring centre for every car currently in the
// workshop. One row per active in-shop ticket: live ETA, colour-coded progress status, last checkpoint,
// responsible owner, and a quick action that opens the Checkpoint timeline + submit form. Data:
// GET /Dashboard/maintenance-progress (DashboardService::maintenanceProgress).

import { Fragment, useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { SectionCard } from '../ui/Table';
import { Skeleton } from '../ui/Skeleton';
import { InfoTip } from '../ui/Tooltip';
import { fmtDate } from '../../lib/format';
import { getMaintenanceProgress, PROGRESS_STATUS, SOURCE_META, resolveCheckpointTicket, statusLabel, delayReasonLabel } from '../../lib/maintenanceCheckpoints';
import CheckpointModal from '../maintenance/CheckpointModal';
import DelayExplanation from '../maintenance/DelayExplanation';

// Roll-up chips, worst-first.
const SUMMARY_CHIPS = [
  { key: 'overdue', label: 'Overdue', cls: 'bg-red-50 text-red-700 ring-red-200' },
  { key: 'needs_update', label: 'Checkpoint due', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  { key: 'on_schedule', label: 'On schedule', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  { key: 'ready_for_pickup', label: 'Ready for pickup', cls: 'bg-sky-50 text-sky-700 ring-sky-200' },
];

function StatusChip({ status }) {
  const s = PROGRESS_STATUS[status] || PROGRESS_STATUS.on_schedule;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${s.chip}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${s.dot} ${status === 'needs_update' || status === 'overdue' ? 'animate-pulse' : ''}`} />
      {s.label}
    </span>
  );
}

function daysCell(r) {
  if (r.overdue) return <span className="font-semibold text-red-600 tabular-nums">+{r.days_over}d overdue</span>;
  if (r.eta_status === 'due_today') return <span className="font-semibold text-amber-600">Due today</span>;
  if (r.expected_on) return <span className="tabular-nums text-slate-600">{r.days_left}d left</span>;
  return <span className="text-slate-400">—</span>;
}

// Where a row came from — 'Contract' vs 'Workshop' — badged so a mixed queue stays legible.
function SourceBadge({ source }) {
  const s = SOURCE_META[source];
  if (!s) return null;
  return (
    <span title={s.tip} className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ${s.chip}`}>
      {s.label}
    </span>
  );
}

// WHY the car is in the shop — the fault(s)/reason behind the visit. Shows the headline problem, the
// ticket-type as a small tag, and (when several faults exist) the full list on hover.
function ProblemCell({ row }) {
  const items = row.problem_items || [];
  const label = row.problem || (items[0] ?? null);
  if (!label) {
    return <span className="text-slate-300">—</span>;
  }
  const title = items.length > 1 ? items.join(' · ') : undefined;
  return (
    <div className="max-w-[220px]">
      <p className="truncate font-medium text-slate-700" title={title || label}>{label}</p>
      {row.problem_type && <p className="text-[11px] text-slate-400">{row.problem_type}</p>}
    </div>
  );
}

// Whole days the revised ETA slipped past the previous one (positive = later).
function checkpointDelayDays(prev, next) {
  if (!prev || !next) return null;
  const a = new Date(`${prev}T00:00:00`);
  const b = new Date(`${next}T00:00:00`);
  if (isNaN(a) || isNaN(b)) return null;
  return Math.round((b - a) / 86400000);
}

// The workshop's last update, clearly labelled: Previous → New ETA (+ delay days), the reason it moved,
// and who filed it when — so the cell reads as information, not two dates jammed together.
function LastCheckpointCell({ r }) {
  const c = r.last_checkpoint;
  if (!c) return <span className="text-amber-600">No update yet</span>;
  const workshop = statusLabel(c.status);
  const reason = c.delay_reason === 'other' ? (c.delay_reason_other || null) : delayReasonLabel(c.delay_reason);
  const etaMoved = !!c.next_expected_date
    && (!c.previous_expected_date || c.previous_expected_date !== c.next_expected_date);
  const dd = checkpointDelayDays(c.previous_expected_date, c.next_expected_date);
  return (
    <div className="min-w-0 max-w-[240px] leading-tight">
      {etaMoved ? (
        <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px]">
          {c.previous_expected_date && (
            <span className="text-slate-500"><span className="text-slate-400">Prev</span> <span className="line-through decoration-slate-300">{fmtDate(c.previous_expected_date)}</span></span>
          )}
          <span aria-hidden className="text-amber-500">→</span>
          <span className="font-semibold text-slate-700"><span className="text-slate-400">New</span> {fmtDate(c.next_expected_date)}</span>
          {dd != null && dd > 0 && (
            <span className="rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-600 ring-1 ring-red-200">+{dd}d</span>
          )}
        </div>
      ) : (
        <span className="text-[11px] text-slate-600">ETA confirmed {c.next_expected_date ? fmtDate(c.next_expected_date) : ''}</span>
      )}
      {etaMoved && (
        reason
          ? <p className="mt-0.5 text-[11px] text-amber-700"><span className="font-semibold">Reason:</span> {reason}</p>
          : <p className="mt-0.5 text-[11px] text-amber-600">No delay reason recorded</p>
      )}
      {workshop && <p className="text-[11px] text-slate-500">Workshop: {workshop}</p>}
      <p className="mt-0.5 text-[11px] text-slate-400">
        {c.by ? `By ${c.by}` : ''}{r.last_checkpoint_at ? `${c.by ? ' · ' : ''}${fmtDate(r.last_checkpoint_at)}` : ''}
      </p>
    </div>
  );
}

export default function MaintenanceProgress() {
  const [data, setData] = useState({ summary: {}, items: [] });
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState(null); // { ticketId, label }
  const [opening, setOpening] = useState(null); // row key currently resolving its ticket
  const [toast, setToast] = useState('');

  // Contract rows have no ticket until now — lazily link one before opening the modal.
  const openCheckpoint = async (r) => {
    const key = `${r.source}-${r.ticket_id ?? r.contract_id}`;
    if (r.ticket_id) {
      setActive({ ticketId: r.ticket_id, label: r.plate || `Ticket #${r.ticket_id}`, sub: r.garage });
      return;
    }
    setOpening(key);
    try {
      const ticketId = await resolveCheckpointTicket(r);
      if (ticketId) setActive({ ticketId, label: r.plate || `Ticket #${ticketId}`, sub: r.garage });
      else setToast('Could not open a checkpoint for this car.');
    } catch (e) {
      setToast('Could not open a checkpoint for this car.');
    } finally {
      setOpening(null);
    }
  };

  const load = useCallback(() => {
    setLoading(true);
    getMaintenanceProgress()
      .then((d) => setData(d || { summary: {}, items: [] }))
      .catch(() => setData({ summary: {}, items: [] }))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const { summary = {}, items = [] } = data;

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Maintenance Progress
          <InfoTip content="The operational monitoring centre for every car currently in the workshop. Each car's progress status is derived automatically from its promised completion date — green while on schedule, amber when a checkpoint is due, red once it goes past its expected completion with no update. The responsible users (Waleed/Abdullah, or a ticket's assigned owners) are reminded automatically a day before the promised date and chased until they file an update." />
        </span>
      }
      subtitle="Cars in maintenance · checkpoint status, ETA & responsible owner"
      actions={(
        <Link to="/maintenance-progress" className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
          Open full queue <span aria-hidden>→</span>
        </Link>
      )}
    >
      {/* Roll-up chips */}
      {!loading && (
        <div className="mb-3 flex flex-wrap gap-2">
          {SUMMARY_CHIPS.map((c) => (
            <span key={c.key} className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${c.cls}`}>
              {c.label}
              <span className="tabular-nums">{summary[c.key] ?? 0}</span>
            </span>
          ))}
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-11 rounded-xl" />)}
        </div>
      ) : items.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">No cars are in the workshop right now.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-start text-xs font-semibold uppercase tracking-wide text-slate-400">
                <th className="py-2 pe-3">Vehicle</th>
                <th className="py-2 pe-3">Problem</th>
                <th className="py-2 pe-3">Workshop</th>
                <th className="py-2 pe-3">Progress</th>
                <th className="py-2 pe-3">ETA</th>
                <th className="py-2 pe-3">Last checkpoint</th>
                <th className="py-2 pe-3">Responsible</th>
                <th className="py-2 pe-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {items.map((r) => (
                <Fragment key={`${r.source}-${r.ticket_id ?? r.contract_id}`}>
                <tr className={`hover:bg-slate-50/60 ${r.overdue ? 'bg-red-50/40' : ''}`}>
                  <td className="py-2.5 pe-3">
                    <div className="flex items-center gap-2">
                      {r.vehicle_id
                        ? <Link to={`/vehicles/${r.vehicle_id}?tab=checkpoints`} className="font-semibold text-slate-800 hover:text-indigo-600">{r.plate || `#${r.vehicle_id}`}</Link>
                        : <span className="font-semibold text-slate-800">Ticket #{r.ticket_id}</span>}
                      <SourceBadge source={r.source} />
                    </div>
                    {r.car && <p className="text-[11px] text-slate-400">{r.car}</p>}
                  </td>
                  <td className="py-2.5 pe-3">
                    <ProblemCell row={r} />
                  </td>
                  <td className="py-2.5 pe-3 text-slate-600">{r.garage || <span className="text-slate-300">—</span>}</td>
                  <td className="py-2.5 pe-3"><StatusChip status={r.status} /></td>
                  <td className="py-2.5 pe-3">
                    {daysCell(r)}
                    {r.expected_on && <p className="text-[11px] text-slate-400">{fmtDate(r.expected_on)}{r.is_estimated ? ' (est.)' : ''}</p>}
                  </td>
                  <td className="py-2.5 pe-3">
                    <LastCheckpointCell r={r} />
                  </td>
                  <td className="py-2.5 pe-3">
                    <span className="text-slate-600">
                      {(r.responsible || []).slice(0, 2).map((u) => u.name).join(', ') || '—'}
                      {(r.responsible || []).length > 2 ? ` +${r.responsible.length - 2}` : ''}
                    </span>
                  </td>
                  <td className="py-2.5 pe-1 text-end">
                    <button
                      type="button"
                      disabled={opening === `${r.source}-${r.ticket_id ?? r.contract_id}`}
                      onClick={() => openCheckpoint(r)}
                      className="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                      Checkpoint
                    </button>
                  </td>
                </tr>
                {r.overdue && (
                  <tr className="bg-red-50/40">
                    <td colSpan={8} className="px-3 pb-3 pt-0">
                      <DelayExplanation checkpoint={r.last_checkpoint} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {toast && <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">{toast}</p>}

      {active && (
        <CheckpointModal
          open={!!active}
          ticketId={active.ticketId}
          title={`Checkpoint · ${active.label}`}
          subtitle={active.sub || undefined}
          onClose={() => setActive(null)}
          onDone={(msg) => { setToast(msg); load(); setTimeout(() => setToast(''), 3000); }}
        />
      )}
    </SectionCard>
  );
}
