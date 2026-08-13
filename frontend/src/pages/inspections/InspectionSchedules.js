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
import { useI18n } from '../../i18n/I18nContext';
import { num, fmtDate } from '../../lib/format';

// `label` is an English phrase key — resolved through t() at render time.
const STATUS = {
  overdue:  { tone: 'red',   label: 'Overdue' },
  due_soon: { tone: 'amber', label: 'Due soon' },
  ok:       { tone: 'green', label: 'On track' },
  no_data:  { tone: 'slate', label: 'No due point' },
};

const PILLAR_TONE = { safety: 'red', operations: 'blue', compliance: 'violet', cleanliness: 'cyan' };

// Module-level helpers that return words take the translator as an argument — the hook can only be
// called from a component body.
const cadence = (r, t) => [
  r.interval_days ? t('every {n}d', { n: r.interval_days }) : null,
  r.interval_km ? t('every {km} km', { km: num(r.interval_km) }) : null,
].filter(Boolean).join(' · ') || '—';

const remaining = (r, t) => {
  if (r.km_remaining != null) {
    const km = num(Math.abs(r.km_remaining));
    return r.km_remaining < 0 ? t('{km} km over', { km }) : t('{km} km left', { km });
  }
  if (r.days_remaining != null) {
    const n = Math.abs(r.days_remaining);
    return r.days_remaining < 0 ? t('{n}d over', { n }) : t('{n}d left', { n });
  }
  return '—';
};

export default function InspectionSchedules() {
  const { can } = usePermissions();
  const { t } = useI18n();
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
      toast.success(t('Inspection logged for {vehicle} — next due advanced', { vehicle: r.vehicle?.label || t('vehicle') }));
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not log the inspection'));
    } finally {
      setBusyId(null);
    }
  };

  const columns = [
    {
      key: 'vehicle', header: t('Vehicle'), cellClass: 'font-medium',
      render: (r) => r.vehicle_id ? (
        <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
          {r.vehicle?.label || `#${r.vehicle_id}`}
        </Link>
      ) : <span className="text-slate-400">{t('Fleet-wide')}</span>,
    },
    {
      key: 'name', header: t('Schedule'),
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className="font-medium text-slate-700">{r.name}</span>
          {r.pillar && <Badge tone={PILLAR_TONE[r.pillar] || 'slate'}>{r.pillar}</Badge>}
          {!r.active && <Badge tone="gray">{t('paused')}</Badge>}
        </div>
      ),
    },
    { key: 'cadence', header: t('Cadence'), cellClass: 'text-slate-500 whitespace-nowrap', render: (r) => cadence(r, t) },
    {
      key: 'last', header: t('Last inspected'), cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => r.last_inspected_at ? fmtDate(r.last_inspected_at) : t('Never'),
    },
    {
      key: 'next', header: t('Next due'), cellClass: 'whitespace-nowrap font-medium text-slate-700',
      render: (r) => r.next_due_at ? fmtDate(r.next_due_at) : (r.next_due_odometer != null ? `${num(r.next_due_odometer)} km` : '—'),
    },
    { key: 'assignee', header: t('Assigned'), cellClass: 'text-slate-500 whitespace-nowrap', render: (r) => r.assignee_name || '—' },
    {
      key: 'status', header: t('Status'), align: 'right',
      render: (r) => {
        const s = STATUS[r.status] || STATUS.no_data;
        return (
          <div className="flex items-center justify-end gap-2">
            <span className="text-xs text-slate-400">{remaining(r, t)}</span>
            <Badge tone={s.tone}>{t(s.label)}</Badge>
          </div>
        );
      },
    },
    {
      key: 'actions', header: '', align: 'right',
      render: (r) => canManage ? (
        <Button size="sm" variant="secondary" loading={busyId === r.id} onClick={() => complete(r)}>
          <Icon.Check className="h-3.5 w-3.5" /> {t('Complete inspection')}
        </Button>
      ) : null,
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('Inspection Schedules')} subtitle={t("Recurring safety & operations inspections. Each plan computes its own next-due point from the car's clock and odometer.")}>
          <div className="flex flex-wrap items-center gap-3">
            <FilterChips
              value={filter}
              onChange={setFilter}
              options={[
                { key: 'all', label: t('All'), count: counts.all },
                { key: 'overdue', label: t('Overdue'), count: counts.overdue, tone: 'red' },
                { key: 'due_soon', label: t('Due soon'), count: counts.due_soon, tone: 'amber' },
                { key: 'ok', label: t('On track'), count: counts.ok, tone: 'green' },
              ]}
            />
            {canManage && (
              <Button size="sm" onClick={() => toast.info(t('Create Schedule modal coming soon'))}>
                <Icon.Plus className="h-3.5 w-3.5" /> {t('New Schedule')}
              </Button>
            )}
          </div>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading ? (
          <>
            <MetricGridSkeleton count={3} />
            <SectionCard title={t('Inspection schedules')}><DataTable loading columns={columns} /></SectionCard>
          </>
        ) : (
          <>
            <MetricGrid cols={3}>
              <MetricCard label={t('Active schedules')} value={num(counts.all)} tone="indigo" icon={<Icon.Calendar className="h-5 w-5" />} hint={t('Recurring inspection plans')} />
              <MetricCard label={t('Overdue')} value={num(counts.overdue)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint={t('Past their next-due point')} />
              <MetricCard label={t('Due soon')} value={num(counts.due_soon)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint={t('Within 3 days / 500 km of due')} />
            </MetricGrid>

            {rows.length === 0 ? (
              <Card><EmptyState title={t('No inspection schedules yet')} message={t('Create a recurring safety or operations inspection plan for a vehicle to start tracking due dates.')} /></Card>
            ) : (
              <SectionCard
                title={t('Inspection schedules')}
                subtitle={t('Most-urgent first. “Complete inspection” logs it done and rolls the next-due point forward.')}
                actions={<span className="text-xs text-slate-400">{t('{n} shown', { n: num(shown.length) })}</span>}
              >
                <DataTable
                  rows={shown}
                  rowKey={(r) => r.id}
                  columns={columns}
                  highlightRow={(r) => r.status === 'overdue'}
                  empty={t('No schedules in this view.')}
                />
              </SectionCard>
            )}
          </>
        )}
      </div>
    </div>
  );
}
