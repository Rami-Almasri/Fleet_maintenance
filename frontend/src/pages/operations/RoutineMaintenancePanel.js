import { useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { EmptyState } from '../../components/ui/Misc';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import usePanelFilter from './usePanelFilter';

// Friendly label when a ticket has no explicit maintenance type — we fall back to WHY it exists.
const TRIGGER_LABEL = {
  periodic: 'Routine service',
  customer: 'Customer complaint',
  customer_reported: 'Customer complaint',
  test_drive: 'Test-drive finding',
  inspector_pickup: 'Inspector pick-up',
  breakdown: 'Breakdown',
  diagnostic: 'Diagnostic',
};

// The car's physical whereabouts, collapsed to the On-Site vs In-Shop lens this tab cares about.
// `phase`/`moving` come from Maintenance::livePosition().
function locationOf(phase, moving) {
  if (phase === 'in_workshop') return { label: 'In-Shop', tone: 'red', Glyph: Icon.Wrench };
  if (phase === 'in_transit' || moving) return { label: 'In Transit', tone: 'blue', Glyph: Icon.Truck };
  return { label: 'On-Site', tone: 'slate', Glyph: Icon.Car };
}

// fault_severity_tone (red/amber/green) → the hub's shared urgency bucket; fall back to the live
// position tone (red = In Workshop, amber = awaiting, etc.) when the ticket carries no severity grade.
function bucketOf(t) {
  const tone = t.fault_severity_tone || t.position?.tone;
  if (tone === 'red' || tone === 'orange') return 'urgent';
  if (tone === 'amber') return 'attention';
  return 'ok';
}

// Card optimised for the Routine Maintenance context: [Ticket ID] | [Task Type] | [Location] | [Status].
function RoutineMaintenanceCard({ c }) {
  const { Glyph } = c.location;
  return (
    <Link
      to={`/maintenance-workflow/${c.id}`}
      className="hover-lift block rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft"
    >
      <div className="flex items-start justify-between gap-2">
        <span className="font-display text-sm font-bold text-slate-900">
          #{c.id}{c.emoji ? ` ${c.emoji}` : ''}
        </span>
        <Badge tone={c.location.tone} className="normal-case">
          <Glyph className="mr-1 h-3 w-3" />{c.location.label}
        </Badge>
      </div>
      <p className="mt-2 truncate text-sm font-semibold text-slate-800" title={c.vehicle}>{c.vehicle}</p>
      <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
        <Icon.Wrench className="h-3.5 w-3.5 shrink-0 text-slate-400" />
        <span className="truncate" title={c.taskType}>{c.taskType}</span>
      </p>
      <div className="mt-3 border-t border-slate-100 pt-2.5">
        <Badge tone={c.statusTone}>{c.statusLabel}</Badge>
      </div>
    </Link>
  );
}

/**
 * Tab 1 — Routine Maintenance. Every open ticket in the maintenance pipeline, framed as recurring
 * upkeep work. Sourced from GET /maintenance-tickets/board (the same board the Workflow page reads),
 * flattened out of its pipeline columns so it reads as a flat card wall here.
 */
export default function RoutineMaintenancePanel({ search, filter, onStats }) {
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 30000 });

  const items = useMemo(() => {
    const columns = data?.columns || {};
    const seen = new Set();
    const list = [];
    Object.values(columns).forEach((arr) => (arr || []).forEach((t) => {
      if (seen.has(t.id)) return;
      seen.add(t.id);
      const pos = t.position || {};
      const location = locationOf(pos.phase, pos.moving);
      const taskType = t.maintenance_type_label || TRIGGER_LABEL[t.trigger_reason] || 'Maintenance';
      const vehicle = t.plate || t.car || `Vehicle #${t.vehicle_id ?? ''}`;
      list.push({
        id: t.id,
        emoji: t.fault_severity_emoji,
        taskType,
        vehicle,
        location,
        statusLabel: t.status_label || pos.label || 'Open',
        statusTone: pos.tone || 'slate',
        bucket: bucketOf(t),
        haystack: [t.id, vehicle, taskType, t.status_label].filter(Boolean).join(' ').toLowerCase(),
      });
    }));
    return list;
  }, [data]);

  const shown = usePanelFilter(items, { search, filter, onStats });

  if (loading && !items.length) return <MetricGridSkeleton count={6} />;
  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;
  }
  if (!shown.length) {
    return (
      <EmptyState
        icon={<Icon.Wrench className="h-7 w-7" />}
        title={items.length ? 'No tickets match' : 'No open maintenance'}
        message={items.length ? 'Try clearing the search or filter.' : 'Every car is out of the workshop — nothing in the pipeline right now.'}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {shown.map((c) => <RoutineMaintenanceCard key={c.id} c={c} />)}
    </div>
  );
}
