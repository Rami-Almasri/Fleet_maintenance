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
import { aed2, num } from '../lib/format';

const STATUS_TONE = { ready: 'green', rented: 'blue', maintenance: 'amber', sold: 'gray', disposed: 'gray' };
const OUT_OF_FLEET = ['sold', 'disposed'];

// A null ratio/denominator = unknown (unmeasured car), shown as a muted dash — never a fake 0.
const money = (v) => (v == null ? <span className="text-slate-300">—</span> : aed2(v));
const count = (v) => (v == null ? <span className="text-slate-300">—</span> : num(v));

/**
 * Cost Intelligence — maintenance cost per km / day / rental, per car. Numerator is the same logged
 * maintenance spend as the Profit Bridge; denominators are the platform's validated distance,
 * in-service days and rental count. A car with no measured distance shows "—", not a misleading 0.
 */
export default function CostIntelligence() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/intelligence/cost');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const navigate = useNavigate();

  const [q, setQ] = useState('');
  const [hideOutOfFleet, setHideOutOfFleet] = useState(true);

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (hideOutOfFleet) list = list.filter((r) => !OUT_OF_FLEET.includes(r.status));
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    return list;
  }, [data, q, hideOutOfFleet]);

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
      key: 'status', header: 'Status',
      render: (r) => <Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status || '—'}</Badge>,
    },
    {
      key: 'maintenance_cost', align: 'right', header: 'Maintenance', cellClass: 'tabular-nums text-amber-600',
      tooltip: 'Sum of all recorded repairs for this car (same source as the Profit Bridge).',
      render: (r) => (r.maintenance_cost ? aed2(r.maintenance_cost) : <span className="text-slate-300">—</span>),
    },
    {
      key: 'distance_km', align: 'right', header: 'Distance (km)', cellClass: 'tabular-nums text-slate-500',
      tooltip: 'Validated lifetime travel (last odometer IN − first OUT). "—" when no reliable reading exists.',
      render: (r) => count(r.distance_km),
    },
    {
      key: 'cost_per_km', align: 'right', header: 'Cost / km', cellClass: 'tabular-nums font-semibold text-slate-800',
      tooltip: 'Maintenance cost ÷ validated distance.',
      render: (r) => money(r.cost_per_km),
    },
    {
      key: 'cost_per_day', align: 'right', header: 'Cost / day', cellClass: 'tabular-nums text-slate-600',
      tooltip: 'Maintenance cost ÷ in-service days.',
      render: (r) => money(r.cost_per_day),
    },
    {
      key: 'cost_per_rental', align: 'right', header: 'Cost / rental', cellClass: 'tabular-nums text-slate-600',
      tooltip: 'Maintenance cost ÷ number of rentals.',
      render: (r) => money(r.cost_per_rental),
    },
    {
      key: 'rentals', align: 'right', header: 'Rentals', cellClass: 'tabular-nums text-slate-500',
      render: (r) => r.rentals || <span className="text-slate-300">—</span>,
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Cost Intelligence"
          subtitle="Maintenance cost per kilometre, per day and per rental for every car — the true running cost of each asset. Cars with no measured distance show “—”, never a misleading zero."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard
              label="Fleet Cost / km"
              value={s.fleet_cost_per_km != null ? aed2(s.fleet_cost_per_km) : '—'}
              tone="indigo"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint="Total maintenance ÷ total km"
              tooltip="Fleet maintenance spend divided by total validated distance. Computed on totals, not an average of per-car ratios."
            />
            <MetricCard
              label="Fleet Cost / day"
              value={s.fleet_cost_per_day != null ? aed2(s.fleet_cost_per_day) : '—'}
              tone="blue"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint="Total maintenance ÷ in-service days"
            />
            <MetricCard
              label="Fleet Cost / rental"
              value={s.fleet_cost_per_rental != null ? aed2(s.fleet_cost_per_rental) : '—'}
              tone="violet"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint="Total maintenance ÷ total rentals"
            />
            <MetricCard
              label="Total Maintenance"
              value={aed2(s.total_maintenance)}
              tone="amber"
              icon={<Icon.Wrench className="h-5 w-5" />}
              hint={`${num(s.km_unknown || 0)} car${(s.km_unknown || 0) === 1 ? '' : 's'} without a distance reading`}
              tooltip="Fleet-wide logged maintenance spend. Cars without a reliable odometer reading are excluded from the per-km figure."
            />
          </MetricGrid>
        )}

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" checked={hideOutOfFleet} onChange={(e) => setHideOutOfFleet(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
            Hide sold & disposed cars
          </label>
          <span className="ml-auto text-xs text-slate-400">{num(rows.length)} of {num(s.vehicles)} cars</span>
        </div>

        <SectionCard
          title="Fleet — running cost per car"
          actions={<span className="text-xs text-slate-400">{num(rows.length)} car{rows.length === 1 ? '' : 's'}</span>}
        >
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.vehicle_id}
            loading={loading}
            onRowClick={(r) => navigate(`/vehicles/${r.vehicle_id}`)}
            empty={q ? 'No cars match your search.' : 'No vehicles found.'}
          />
        </SectionCard>
      </div>
    </div>
  );
}
