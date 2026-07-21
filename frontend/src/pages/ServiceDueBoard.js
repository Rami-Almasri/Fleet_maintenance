import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { num, fmtDate } from '../lib/format';

const SERVICE_TONE = { overdue: 'red', due_soon: 'amber' };
const SERVICE_LABEL = { overdue: 'Overdue', due_soon: 'Due soon' };

/**
 * Service-Due board — the actionable list of cars that are overdue for service or approaching it,
 * from the same km-interval forecast engine used across the platform (Vehicle::serviceStatus +
 * MaintenanceForecastService). Overdue first, then soonest. Projection is shown only where the car's
 * own usage rate can be measured — never guessed.
 */
export default function ServiceDueBoard() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/intelligence/service-due');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const navigate = useNavigate();

  const [q, setQ] = useState('');
  const [only, setOnly] = useState('all'); // all | overdue | due_soon

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (only !== 'all') list = list.filter((r) => r.service_status === only);
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    return list;
  }, [data, q, only]);

  const s = data?.summary || {};

  const columns = [
    {
      key: 'plate', align: 'left', cellClass: 'font-medium',
      header: 'Car',
      render: (r) => (
        <>
          <Link to={`/vehicles/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
          <div className="text-xs text-slate-400">{r.car || '—'}</div>
        </>
      ),
    },
    {
      key: 'service_status', header: 'Service',
      render: (r) => <Badge tone={SERVICE_TONE[r.service_status] || 'slate'}>{SERVICE_LABEL[r.service_status] || r.service_status}</Badge>,
    },
    {
      key: 'current', align: 'right', header: 'Odometer', cellClass: 'tabular-nums text-slate-600',
      tooltip: 'Current odometer reading.',
      render: (r) => (r.current != null ? `${num(r.current)} km` : <span className="text-slate-300">—</span>),
    },
    {
      key: 'interval', align: 'right', header: 'Interval', cellClass: 'tabular-nums text-slate-500',
      tooltip: 'Service interval (km) for this car.',
      render: (r) => (r.interval != null ? `${num(r.interval)} km` : <span className="text-slate-300">—</span>),
    },
    {
      key: 'remaining_km', align: 'right', header: 'Status', cellClass: 'tabular-nums font-semibold',
      tooltip: 'Kilometres overdue (past the interval) or remaining until it.',
      render: (r) =>
        r.service_status === 'overdue' ? (
          <span className="text-red-600">{num(r.overdue_km)} km over</span>
        ) : r.remaining_km != null ? (
          <span className="text-amber-600">{num(r.remaining_km)} km left</span>
        ) : (
          <span className="text-slate-300">—</span>
        ),
    },
    {
      key: 'usage_rate', align: 'right', header: 'Usage', cellClass: 'tabular-nums text-slate-500',
      tooltip: "The car's measured daily distance (km/day) from its recent odometer readings.",
      render: (r) => (r.usage_rate != null ? `${num(r.usage_rate)} km/d` : <span className="text-slate-300">—</span>),
    },
    {
      key: 'projected_date', align: 'right', header: 'Projected due', cellClass: 'tabular-nums text-slate-600',
      tooltip: 'Projected service date from the usage rate — shown only when the rate can be measured.',
      render: (r) => (r.projected_date ? fmtDate(r.projected_date) : <span className="text-slate-300">—</span>),
    },
  ];

  const filterBtn = (key, label) => (
    <button
      type="button"
      onClick={() => setOnly(key)}
      className={`rounded-full px-3 py-1 text-xs font-medium ${only === key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
    >
      {label}
    </button>
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Service Due"
          subtitle="Cars overdue for service or approaching it — by odometer interval and projected from each car's own usage rate. Overdue first, then soonest. Cars with no interval or reading are not guessed."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard label="Overdue" value={num(s.overdue || 0)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint="Past the service interval" />
            <MetricCard label="Due Soon" value={num(s.due_soon || 0)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint={`Within ${num(data?.thresholds?.near_due_km || 0)} km or ${num(data?.thresholds?.near_due_days || 0)} days`} />
            <MetricCard label="Actionable" value={num(s.actionable || 0)} tone="indigo" icon={<Icon.Wrench className="h-5 w-5" />} hint="Overdue + due soon" />
            <MetricCard label="On Track" value={num(s.ok || 0)} tone="emerald" icon={<Icon.Check className="h-5 w-5" />} hint={`${num(s.no_data || 0)} car${(s.no_data || 0) === 1 ? '' : 's'} with no interval data`} />
          </MetricGrid>
        )}

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
          <div className="flex items-center gap-2">
            {filterBtn('all', 'All')}
            {filterBtn('overdue', 'Overdue')}
            {filterBtn('due_soon', 'Due soon')}
          </div>
          <span className="ml-auto text-xs text-slate-400">{num(rows.length)} of {num(s.actionable || 0)} cars</span>
        </div>

        <SectionCard
          title="Cars needing service"
          actions={<span className="text-xs text-slate-400">{num(rows.length)} car{rows.length === 1 ? '' : 's'}</span>}
        >
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.vehicle_id}
            loading={loading}
            onRowClick={(r) => navigate(`/vehicles/${r.vehicle_id}`)}
            highlightRow={(r) => r.service_status === 'overdue'}
            empty={q ? 'No cars match your search.' : 'No cars are due for service. 🎉'}
          />
        </SectionCard>
      </div>
    </div>
  );
}
