import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader, EmptyState, Card } from '../../components/ui/Misc';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import DataTable, { SectionCard } from '../../components/ui/Table';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import FilterChips from '../../components/ui/FilterChips';
import Icon from '../../components/ui/Icon';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { num, fmtDate } from '../../lib/format';

const STATUS = {
  overdue:  { tone: 'red',   label: 'Overdue' },
  due_soon: { tone: 'amber', label: 'Due soon' },
  ok:       { tone: 'green', label: 'On track' },
  no_data:  { tone: 'slate', label: 'No due point' },
};

const PILLAR_TONE = { safety: 'red', operations: 'blue', compliance: 'violet', cleanliness: 'cyan' };

const cadence = (r) => [r.interval_days ? `every ${r.interval_days}d` : null, r.interval_km ? `every ${num(r.interval_km)} km` : null].filter(Boolean).join(' · ') || '—';

const remaining = (r) => {
  if (r.km_remaining != null) return `${num(Math.abs(r.km_remaining))} km ${r.km_remaining < 0 ? 'over' : 'left'}`;
  if (r.days_remaining != null) return `${Math.abs(r.days_remaining)}d ${r.days_remaining < 0 ? 'over' : 'left'}`;
  return '—';
};

export default function InspectionSchedules() {
  const { can } = usePermissions();
  const canManage = can('inspections.manage');
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [busyId, setBusyId] = useState(null);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/InspectionSchedules');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const rows = useMemo(() => data || [], [data]);
  const counts = useMemo(() => {
    const c = { all: rows.length, overdue: 0, due_soon: 0, ok: 0, no_data: 0 };
    rows.forEach((r) => { c[r.status] = (c[r.status] || 0) + 1; });
    return c;
  }, [rows]);

  const shown = filter === 'all' ? rows : rows.filter((r) => r.status === filter);

  const complete = async (r) => {
    setBusyId(r.id);
    try {
      await api.post(`/InspectionSchedules/${r.id}/complete`);
      toast.success(`Inspection logged for ${r.vehicle?.label || 'vehicle'} — next due advanced`);
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not log the inspection');
    } finally {
      setBusyId(null);
    }
  };

  const columns = [
    {
      key: 'vehicle', header: 'Vehicle', cellClass: 'font-medium',
      render: (r) => r.vehicle_id ? (
        <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
          {r.vehicle?.label || `#${r.vehicle_id}`}
        </Link>
      ) : <span className="text-slate-400">Fleet-wide</span>,
    },
    {
      key: 'name', header: 'Schedule',
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className="font-medium text-slate-700">{r.name}</span>
          {r.pillar && <Badge tone={PILLAR_TONE[r.pillar] || 'slate'}>{r.pillar}</Badge>}
          {!r.active && <Badge tone="gray">paused</Badge>}
        </div>
      ),
    },
    { key: 'cadence', header: 'Cadence', cellClass: 'text-slate-500 whitespace-nowrap', render: cadence },
    {
      key: 'last', header: 'Last inspected', cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => r.last_inspected_at ? fmtDate(r.last_inspected_at) : 'Never',
    },
    {
      key: 'next', header: 'Next due', cellClass: 'whitespace-nowrap font-medium text-slate-700',
      render: (r) => r.next_due_at ? fmtDate(r.next_due_at) : (r.next_due_odometer != null ? `${num(r.next_due_odometer)} km` : '—'),
    },
    { key: 'assignee', header: 'Assigned', cellClass: 'text-slate-500 whitespace-nowrap', render: (r) => r.assignee_name || '—' },
    {
      key: 'status', header: 'Status', align: 'right',
      render: (r) => {
        const s = STATUS[r.status] || STATUS.no_data;
        return (
          <div className="flex items-center justify-end gap-2">
            <span className="text-xs text-slate-400">{remaining(r)}</span>
            <Badge tone={s.tone}>{s.label}</Badge>
          </div>
        );
      },
    },
    {
      key: 'actions', header: '', align: 'right',
      render: (r) => canManage ? (
        <Button size="sm" variant="secondary" loading={busyId === r.id} onClick={() => complete(r)}>
          <Icon.Check className="h-3.5 w-3.5" /> Complete inspection
        </Button>
      ) : null,
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Inspection Schedules" subtitle="Recurring safety & operations inspections. Each plan computes its own next-due point from the car's clock and odometer.">
          <div className="flex flex-wrap items-center gap-3">
            <FilterChips
              value={filter}
              onChange={setFilter}
              options={[
                { key: 'all', label: 'All', count: counts.all },
                { key: 'overdue', label: 'Overdue', count: counts.overdue, tone: 'red' },
                { key: 'due_soon', label: 'Due soon', count: counts.due_soon, tone: 'amber' },
                { key: 'ok', label: 'On track', count: counts.ok, tone: 'green' },
              ]}
            />
            {canManage && (
              <Button size="sm" onClick={() => toast.info('Create Schedule modal coming soon')}>
                <Icon.Plus className="h-3.5 w-3.5" /> New Schedule
              </Button>
            )}
          </div>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading ? (
          <>
            <MetricGridSkeleton count={3} />
            <SectionCard title="Inspection schedules"><DataTable loading columns={columns} /></SectionCard>
          </>
        ) : (
          <>
            <MetricGrid cols={3}>
              <MetricCard label="Active schedules" value={num(counts.all)} tone="indigo" icon={<Icon.Calendar className="h-5 w-5" />} hint="Recurring inspection plans" />
              <MetricCard label="Overdue" value={num(counts.overdue)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint="Past their next-due point" />
              <MetricCard label="Due soon" value={num(counts.due_soon)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint="Within 3 days / 500 km of due" />
            </MetricGrid>

            {rows.length === 0 ? (
              <Card><EmptyState title="No inspection schedules yet" message="Create a recurring safety or operations inspection plan for a vehicle to start tracking due dates." /></Card>
            ) : (
              <SectionCard
                title="Inspection schedules"
                subtitle="Most-urgent first. “Complete inspection” logs it done and rolls the next-due point forward."
                actions={<span className="text-xs text-slate-400">{num(shown.length)} shown</span>}
              >
                <DataTable
                  rows={shown}
                  rowKey={(r) => r.id}
                  columns={columns}
                  highlightRow={(r) => r.status === 'overdue'}
                  empty="No schedules in this view."
                />
              </SectionCard>
            )}
          </>
        )}
      </div>
    </div>
  );
}
