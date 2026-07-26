// Maintenance Progress — the dashboard's operational monitoring centre for every car currently in the
// workshop. One row per active in-shop ticket: live ETA, colour-coded progress status, last checkpoint,
// responsible owner, and a quick action that opens the Checkpoint timeline + submit form. Data:
// GET /Dashboard/maintenance-progress (DashboardService::maintenanceProgress).

import { useEffect, useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { SectionCard } from '../ui/Table';
import { Skeleton } from '../ui/Skeleton';
import { InfoTip } from '../ui/Tooltip';
import { fmtDate } from '../../lib/format';
import { getMaintenanceProgress, PROGRESS_STATUS } from '../../lib/maintenanceCheckpoints';
import CheckpointModal from '../maintenance/CheckpointModal';

// Roll-up chips, worst-first.
const SUMMARY_CHIPS = [
  { key: 'overdue', label: 'Overdue', cls: 'bg-red-50 text-red-700 ring-red-200' },
  { key: 'critical', label: 'Critical', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
  { key: 'needs_update', label: 'Checkpoint due', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  { key: 'delayed', label: 'Delayed', cls: 'bg-orange-50 text-orange-700 ring-orange-200' },
  { key: 'on_track', label: 'On track', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
];

function StatusChip({ status }) {
  const s = PROGRESS_STATUS[status] || PROGRESS_STATUS.on_track;
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

export default function MaintenanceProgress() {
  const [data, setData] = useState({ summary: {}, items: [] });
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState(null); // { ticketId, label }
  const [toast, setToast] = useState('');

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
          <InfoTip content="The operational monitoring centre for every car currently in the workshop. Each car's progress status comes from its latest checkpoint (On Track / Delayed / Critical) or turns amber when a checkpoint is due and red when it goes past its expected completion with no update. The responsible users (Waleed/Abdullah, or a ticket's assigned owners) are reminded automatically a day before the promised date and chased until they file an update." />
        </span>
      }
      subtitle="Cars in maintenance · checkpoint status, ETA & responsible owner"
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
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                <th className="py-2 pe-3">Vehicle</th>
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
                <tr key={r.ticket_id} className={`hover:bg-slate-50/60 ${r.overdue ? 'bg-red-50/40' : ''}`}>
                  <td className="py-2.5 pe-3">
                    {r.vehicle_id
                      ? <Link to={`/vehicles/${r.vehicle_id}?tab=checkpoints`} className="font-semibold text-slate-800 hover:text-indigo-600">{r.plate || `#${r.vehicle_id}`}</Link>
                      : <span className="font-semibold text-slate-800">Ticket #{r.ticket_id}</span>}
                    {r.car && <p className="text-[11px] text-slate-400">{r.car}</p>}
                  </td>
                  <td className="py-2.5 pe-3 text-slate-600">{r.garage || <span className="text-slate-300">—</span>}</td>
                  <td className="py-2.5 pe-3"><StatusChip status={r.status} /></td>
                  <td className="py-2.5 pe-3">
                    {daysCell(r)}
                    {r.expected_on && <p className="text-[11px] text-slate-400">{fmtDate(r.expected_on)}{r.is_estimated ? ' (est.)' : ''}</p>}
                  </td>
                  <td className="py-2.5 pe-3">
                    {r.last_checkpoint_at
                      ? <span className="text-slate-600">{fmtDate(r.last_checkpoint_at)}</span>
                      : <span className="text-amber-600">No update yet</span>}
                  </td>
                  <td className="py-2.5 pe-3">
                    <span className="text-slate-600">
                      {(r.responsible || []).slice(0, 2).map((u) => u.name).join(', ') || '—'}
                      {(r.responsible || []).length > 2 ? ` +${r.responsible.length - 2}` : ''}
                    </span>
                  </td>
                  <td className="py-2.5 pe-1 text-right">
                    <button
                      type="button"
                      onClick={() => setActive({ ticketId: r.ticket_id, label: r.plate || `Ticket #${r.ticket_id}`, sub: r.garage })}
                      className="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-700"
                    >
                      Checkpoint
                    </button>
                  </td>
                </tr>
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
