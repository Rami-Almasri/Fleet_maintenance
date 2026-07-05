import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader } from '../../components/ui/Misc';
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

// Shared status language for reminders/schedules — overdue reads red, due-soon amber.
const STATUS = {
  overdue:  { tone: 'red',   label: 'Overdue' },
  due_soon: { tone: 'amber', label: 'Due soon' },
  ok:       { tone: 'green', label: 'OK' },
  no_data:  { tone: 'slate', label: 'No data' },
};

// A compact "how far from due" note (km first, else days).
const remaining = (r) => {
  if (r.km_remaining != null) return `${num(Math.abs(r.km_remaining))} km ${r.km_remaining < 0 ? 'over' : 'left'}`;
  if (r.days_remaining != null) return `${Math.abs(r.days_remaining)}d ${r.days_remaining < 0 ? 'over' : 'left'}`;
  return '—';
};

export default function ServiceReminders() {
  const { can } = usePermissions();
  const canManage = can('reminders.manage');
  const toast = useToast();
  // Open on the cars that actually need service (overdue + due soon). OK / No-data rows
  // are hidden by default but one click away via the "All" / "OK" chips.
  const [filter, setFilter] = useState('action');
  const [busyId, setBusyId] = useState(null);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/ServiceReminders');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const rows = useMemo(() => data || [], [data]);
  const counts = useMemo(() => {
    const c = { all: rows.length, overdue: 0, due_soon: 0, ok: 0, no_data: 0 };
    rows.forEach((r) => { c[r.status] = (c[r.status] || 0) + 1; });
    return c;
  }, [rows]);

  const shown = useMemo(() => {
    if (filter === 'all') return rows;
    // "Action needed" = the two states that call for a wrench: overdue + due soon.
    if (filter === 'action') return rows.filter((r) => r.status === 'overdue' || r.status === 'due_soon');
    return rows.filter((r) => r.status === filter);
  }, [rows, filter]);

  const markDone = async (r) => {
    setBusyId(r.id);
    try {
      await api.post(`/ServiceReminders/${r.id}/complete`);
      toast.success(`${r.name} logged for ${r.vehicle?.label || 'vehicle'} — next due advanced`);
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not log the service');
    } finally {
      setBusyId(null);
    }
  };

  const columns = [
    {
      key: 'vehicle', header: 'Vehicle', cellClass: 'font-medium',
      render: (r) => (
        <div className="min-w-0">
          {r.vehicle_id ? (
            <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
              {r.vehicle?.label || `#${r.vehicle_id}`}
            </Link>
          ) : <span className="font-semibold text-slate-900">—</span>}
          <p className="truncate text-xs text-slate-400">{[r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—'}</p>
        </div>
      ),
    },
    {
      key: 'name', header: 'Service',
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className="font-medium text-slate-700">{r.name}</span>
          {r.source === 'auto' && <Badge tone="slate">auto</Badge>}
          {r.is_muted && <Badge tone="gray">muted</Badge>}
        </div>
      ),
    },
    {
      key: 'interval', header: 'Interval', cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => [r.interval_km ? `${num(r.interval_km)} km` : null, r.interval_days ? `${r.interval_days}d` : null].filter(Boolean).join(' · ') || '—',
    },
    {
      key: 'last', header: 'Last service', cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => r.last_service_odometer != null ? `${num(r.last_service_odometer)} km` : (r.last_service_at ? fmtDate(r.last_service_at) : '—'),
    },
    {
      key: 'next', header: 'Next due', cellClass: 'whitespace-nowrap font-medium text-slate-700',
      render: (r) => r.next_due_odometer != null ? `${num(r.next_due_odometer)} km` : (r.next_due_at ? fmtDate(r.next_due_at) : '—'),
    },
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
        <Button size="sm" variant="secondary" loading={busyId === r.id} onClick={() => markDone(r)}>
          <Icon.Check className="h-3.5 w-3.5" /> Mark done
        </Button>
      ) : null,
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Service Reminders" subtitle="Technical maintenance due points per car (oil, filters, brakes…). Oil-change reminders are auto-derived from each car's service interval; edit any to override.">
          <FilterChips
            value={filter}
            onChange={setFilter}
            options={[
              { key: 'action', label: 'Action needed', count: counts.overdue + counts.due_soon, tone: 'amber' },
              { key: 'all', label: 'All', count: counts.all },
              { key: 'overdue', label: 'Overdue', count: counts.overdue, tone: 'red' },
              { key: 'due_soon', label: 'Due soon', count: counts.due_soon, tone: 'amber' },
              { key: 'ok', label: 'OK', count: counts.ok, tone: 'green' },
            ]}
          />
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading ? (
          <>
            <MetricGridSkeleton count={3} />
            <SectionCard title="Service reminders"><DataTable loading columns={columns} /></SectionCard>
          </>
        ) : (
          <>
            <MetricGrid cols={3}>
              <MetricCard label="Total reminders" value={num(counts.all)} tone="indigo" icon={<Icon.Wrench className="h-5 w-5" />} hint="Across the fleet" />
              <MetricCard label="Overdue" value={num(counts.overdue)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint="Past their due point — service now" />
              <MetricCard label="Due soon" value={num(counts.due_soon)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint="Within 500 km / 7 days of due" />
            </MetricGrid>

            <SectionCard
              title="Service reminders"
              subtitle="Most-urgent first. “Mark done” logs the service and rolls the next-due point forward."
              actions={<span className="text-xs text-slate-400">{num(shown.length)} shown</span>}
            >
              <DataTable
                rows={shown}
                rowKey={(r) => r.id}
                columns={columns}
                highlightRow={(r) => r.status === 'overdue'}
                empty={filter === 'action' ? 'All caught up — nothing due right now.' : 'No service reminders in this view.'}
              />
            </SectionCard>
          </>
        )}
      </div>
    </div>
  );
}
