import { useCallback } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Drawer from '../ui/Drawer';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { fmtSeconds } from '../../lib/format';

// Absolute local timestamp "05 Jul 2026, 3:42 PM" from an ISO string.
const fmtDT = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
};

// Timeline dot colour (matches the tone the backend assigns per event).
const DOT = {
  blue: 'bg-blue-500', amber: 'bg-amber-500', green: 'bg-emerald-500',
  red: 'bg-red-500', violet: 'bg-violet-500', gray: 'bg-slate-400',
};
// Who acted → a small role chip tone.
const SOURCE_TONE = { inspector: 'blue', garage: 'amber' };
const SOURCE_LABEL = { inspector: 'Inspection side', garage: 'Garage side' };

// One stage in a journey: what happened, WHO was responsible, and HOW LONG the car sat in it.
function StageRow({ s }) {
  return (
    <li className="relative ps-6">
      <span className={`absolute start-0 top-1.5 h-3 w-3 rounded-full ring-4 ring-white ${DOT[s.tone] || DOT.gray}`} />
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p className="text-sm font-semibold text-slate-800">{s.label}</p>
        {s.duration_seconds != null && (
          <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${s.running ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-500'}`}>
            {fmtSeconds(s.duration_seconds)}{s.running ? ' so far' : ''}
          </span>
        )}
      </div>

      {/* Responsible person — the headline the manager asked for: who did this stage. */}
      <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
        <Icon.Users className="h-3.5 w-3.5 text-slate-400" />
        <span className="font-medium text-slate-700">{s.responsible || 'Unattributed'}</span>
        {s.source && <Badge tone={SOURCE_TONE[s.source] || 'slate'}>{SOURCE_LABEL[s.source] || s.source}</Badge>}
      </div>

      {s.description && <p className="mt-1 text-xs leading-relaxed text-slate-500">{s.description}</p>}

      <div className="mt-1 flex flex-wrap items-center gap-x-3 text-[11px] text-slate-400">
        <span>{fmtDT(s.at)}</span>
        {s.garage && (
          <span className="inline-flex items-center gap-1">
            <Icon.Wrench className="h-3 w-3" /> {s.garage}
          </span>
        )}
      </div>
    </li>
  );
}

// One journey = one workshop ticket, its stages laid out top→bottom with a spine.
function Journey({ j }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Badge tone={j.open ? 'amber' : 'green'}>{j.outcome}</Badge>
          {j.ticket_id && <span className="text-xs text-slate-400">Ticket #{j.ticket_id}</span>}
        </div>
        <div className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
          <Icon.Clock className="h-3.5 w-3.5 text-slate-400" />
          {j.stage_count} stage{j.stage_count === 1 ? '' : 's'} · {fmtSeconds(j.total_seconds)} total
        </div>
      </div>
      <ol className="relative space-y-4">
        <span className="absolute inset-y-1.5 start-[5px] w-px bg-slate-200" aria-hidden />
        {j.stages.map((s, i) => <StageRow key={i} s={s} />)}
      </ol>
    </section>
  );
}

// The drill-down: pick a car → its full maintenance history, every stage, who was responsible, how long.
export default function MaintenanceTimelineDrawer({ vehicle, period, onClose }) {
  const open = !!vehicle;
  const fetcher = useCallback(async () => {
    if (!vehicle) return null;
    const { data } = await api.get(`/vehicle-status/${vehicle.id}/timeline`, { params: period && period !== 'live' ? { period } : {} });
    return data.data; // { vehicle, journeys }
  }, [vehicle, period]);
  const { data, loading, error } = useFetch(fetcher, [vehicle?.id, period]);

  const journeys = data?.journeys || [];

  return (
    <Drawer
      open={open}
      onClose={onClose}
      eyebrow="Maintenance history"
      title={vehicle?.title || 'Vehicle'}
      subtitle={vehicle?.subtitle}
      width="lg"
    >
      {loading && !data ? (
        <div className="space-y-4">
          <Skeleton className="h-40 rounded-2xl" />
          <Skeleton className="h-40 rounded-2xl" />
        </div>
      ) : error ? (
        <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      ) : journeys.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 text-center text-sm text-slate-400">
          <Icon.Check className="mb-2 h-8 w-8 text-slate-300" />
          No maintenance history for this car in the selected period.
        </div>
      ) : (
        <div className="space-y-4">
          {journeys.map((j, i) => <Journey key={j.ticket_id || i} j={j} />)}
        </div>
      )}
    </Drawer>
  );
}
