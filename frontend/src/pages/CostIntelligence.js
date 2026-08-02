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
import FinancialBreakdownDrawer from '../components/FinancialBreakdownDrawer';
import CostIntelligenceAnalytics from '../components/analytics/CostIntelligenceAnalytics';
import { aed2, num } from '../lib/format';

const STATUS_TONE = { ready: 'green', rented: 'blue', maintenance: 'amber', sold: 'gray', disposed: 'gray' };
const ACTIVE_FLEET = ['rented', 'ready'];

// A null ratio/denominator = unknown (unmeasured car), shown as a muted dash — never a fake 0.
const money = (v) => (v == null ? <span className="text-slate-300">—</span> : aed2(v));
const count = (v) => (v == null ? <span className="text-slate-300">—</span> : num(v));

// Numeric compare that always sinks nulls to the bottom, whichever direction is active.
const cmp = (a, b, dir) => {
  if (a == null && b == null) return 0;
  if (a == null) return 1;
  if (b == null) return -1;
  return dir === 'asc' ? a - b : b - a;
};

/**
 * Cost Intelligence — maintenance cost per km / day / rental, per car, plus a rental-segment rollup.
 * Numerator is the same logged maintenance spend as the Profit Bridge; denominators are the platform's
 * validated distance, in-service days and rental count. A car with no measured distance shows "—".
 *
 * A date window scopes the maintenance spend, rentals and every derived total. While it's active,
 * Cost/km and Cost/day are lifetime-only concepts, so they read "—" rather than divide a period cost
 * by a lifetime denominator.
 */
export default function CostIntelligence() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [q, setQ] = useState('');
  const [activeOnly, setActiveOnly] = useState(true);
  const [cat, setCat] = useState(null);              // clicked category filter (null = all)
  const [sort, setSort] = useState({ key: 'maintenance_cost', dir: 'desc' });
  const [drill, setDrill] = useState(null);          // { vehicleId, metric } — open the traceability drawer

  const fetcher = useCallback(async () => {
    const params = {};
    if (from) params.from = from;
    if (to) params.to = to;
    const { data } = await api.get('/intelligence/cost', { params });
    return data.data;
  }, [from, to]);
  const { data, loading, error } = useFetch(fetcher, [from, to]);
  const navigate = useNavigate();

  const windowed = !!(from || to);

  // Every number becomes a button that opens the drill-down drawer at the matching section, so a
  // clicked figure shows exactly how it was calculated (numerator ÷ denominator, itemised tickets).
  const drillNum = (metric, node, id) => (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); setDrill({ vehicleId: id, metric }); }}
      className="cursor-pointer decoration-dotted underline-offset-2 hover:text-indigo-700 hover:underline"
      title="Show how this number is calculated"
    >
      {node}
    </button>
  );

  const onSort = (key) =>
    setSort((s) => (s.key === key ? { key, dir: s.dir === 'desc' ? 'asc' : 'desc' } : { key, dir: 'desc' }));

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (activeOnly) list = list.filter((r) => ACTIVE_FLEET.includes(r.status));
    if (cat != null) list = list.filter((r) => (r.category || 'Uncategorized') === cat);
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    return [...list].sort((a, b) => cmp(a[sort.key], b[sort.key], sort.dir));
  }, [data, q, activeOnly, cat, sort]);

  const categories = data?.by_category || [];
  const maxCatCost = categories.reduce((m, c) => Math.max(m, c.maintenance_cost || 0), 0) || 1;
  const s = data?.summary || {};

  const columns = [
    {
      key: 'plate', align: 'left', cellClass: 'font-medium',
      header: 'Car',
      render: (r) => (
        <>
          <Link to={`/vehicles/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
          <div className="text-xs text-slate-400">{r.car || '—'}{r.category ? ` · ${r.category}` : ''}</div>
        </>
      ),
    },
    {
      key: 'status', header: 'Status',
      render: (r) => <Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status || '—'}</Badge>,
    },
    {
      key: 'maintenance_cost', align: 'right', header: 'Maintenance', cellClass: 'tabular-nums text-amber-600', sortable: true,
      tooltip: 'Sum of all recorded repairs for this car (same source as the Profit Bridge).',
      render: (r) => drillNum('maintenance', r.maintenance_cost ? aed2(r.maintenance_cost) : <span className="text-slate-300">—</span>, r.vehicle_id),
    },
    {
      key: 'distance_km', align: 'right', header: 'Distance (km)', cellClass: 'tabular-nums text-slate-500', sortable: true,
      tooltip: 'Validated lifetime travel (last odometer IN − first OUT). "—" when no reliable reading exists.',
      render: (r) => drillNum('distance', count(r.distance_km), r.vehicle_id),
    },
    {
      key: 'cost_per_km', align: 'right', header: 'Cost / km', cellClass: 'tabular-nums font-semibold text-slate-800', sortable: true,
      tooltip: 'Maintenance cost ÷ validated distance. Lifetime-only — shows "—" while a date filter is active.',
      render: (r) => drillNum('cost_per_km', money(r.cost_per_km), r.vehicle_id),
    },
    {
      key: 'cost_per_day', align: 'right', header: 'Cost / day', cellClass: 'tabular-nums text-slate-600', sortable: true,
      tooltip: 'Maintenance cost ÷ in-service days. Lifetime-only — shows "—" while a date filter is active.',
      render: (r) => drillNum('cost_per_day', money(r.cost_per_day), r.vehicle_id),
    },
    {
      key: 'cost_per_rental', align: 'right', header: 'Cost / rental', cellClass: 'tabular-nums text-slate-600', sortable: true,
      tooltip: 'Maintenance cost ÷ number of rentals.',
      render: (r) => drillNum('cost_per_rental', money(r.cost_per_rental), r.vehicle_id),
    },
    {
      key: 'rentals', align: 'right', header: 'Rentals', cellClass: 'tabular-nums text-slate-500', sortable: true,
      render: (r) => drillNum('rentals', r.rentals || <span className="text-slate-300">—</span>, r.vehicle_id),
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Cost Intelligence"
          subtitle="Running cost per car — expense per km, per day and per rental across the fleet."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Date window — scopes maintenance spend, rentals and the category rollup. */}
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200/70 bg-white px-4 py-3 shadow-soft">
          <span className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600">
            <Icon.Calendar className="h-4 w-4 text-slate-400" /> Period
          </span>
          <label className="inline-flex items-center gap-1.5 text-sm text-slate-500">
            From
            <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)}
              className="rounded-lg border-slate-300 text-sm text-slate-700 focus:border-indigo-500 focus:ring-indigo-500" />
          </label>
          <label className="inline-flex items-center gap-1.5 text-sm text-slate-500">
            To
            <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)}
              className="rounded-lg border-slate-300 text-sm text-slate-700 focus:border-indigo-500 focus:ring-indigo-500" />
          </label>
          {windowed ? (
            <button type="button" onClick={() => { setFrom(''); setTo(''); }}
              className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200">
              Clear · lifetime
            </button>
          ) : (
            <span className="text-xs text-slate-400">Lifetime (all dates)</span>
          )}
          {windowed && (
            <span className="ms-auto text-xs text-amber-600">Cost/km &amp; Cost/day are lifetime-only — shown as “—” while filtered.</span>
          )}
        </div>

        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard
              label="Fleet Cost / km"
              value={s.fleet_cost_per_km != null ? aed2(s.fleet_cost_per_km) : '—'}
              tone="indigo"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint={windowed ? 'Lifetime-only while filtered' : 'Total maintenance ÷ total km'}
              tooltip="Fleet maintenance spend divided by total validated distance. Computed on totals, not an average of per-car ratios."
            />
            <MetricCard
              label="Fleet Cost / day"
              value={s.fleet_cost_per_day != null ? aed2(s.fleet_cost_per_day) : '—'}
              tone="blue"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint={windowed ? 'Lifetime-only while filtered' : 'Total maintenance ÷ in-service days'}
            />
            <MetricCard
              label="Fleet Cost / rental"
              value={s.fleet_cost_per_rental != null ? aed2(s.fleet_cost_per_rental) : '—'}
              tone="violet"
              icon={<Icon.TrendUp className="h-5 w-5" />}
              hint="Total maintenance ÷ total rentals"
            />
            <MetricCard
              label={windowed ? 'Maintenance (period)' : 'Total Maintenance'}
              value={aed2(s.total_maintenance)}
              tone="amber"
              icon={<Icon.Wrench className="h-5 w-5" />}
              hint={windowed ? 'Spend within the selected dates' : `${num(s.km_unknown || 0)} car${(s.km_unknown || 0) === 1 ? '' : 's'} without a distance reading`}
              tooltip="Fleet-wide logged maintenance spend. Cars without a reliable odometer reading are excluded from the per-km figure."
            />
          </MetricGrid>
        )}

        {/* Cost by rental segment — which category costs the fleet the most. */}
        <SectionCard
          title="Cost by category"
          subtitle="Rental segments ranked by maintenance spend. Click a segment to filter the fleet below."
          actions={cat != null && (
            <button type="button" onClick={() => setCat(null)} className="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100">
              Clear “{cat}” filter
            </button>
          )}
        >
          {loading ? (
            <div className="space-y-2 p-5">
              {Array.from({ length: 4 }).map((_, i) => <div key={i} className="shimmer h-8 w-full rounded-lg bg-slate-100" />)}
            </div>
          ) : categories.length === 0 ? (
            <div className="px-5 py-10 text-center text-sm text-slate-400">No categorized cars yet — run the vehicle-status sheet import to populate categories.</div>
          ) : (
            <div className="divide-y divide-slate-100">
              {categories.map((c) => {
                const label = c.category || 'Uncategorized';
                const selected = cat === label;
                return (
                  <button
                    key={label}
                    type="button"
                    onClick={() => setCat(selected ? null : label)}
                    className={`flex w-full items-center gap-4 px-5 py-3 text-start transition-colors hover:bg-indigo-50/40 ${selected ? 'bg-indigo-50/60' : ''}`}
                  >
                    <div className="w-40 shrink-0">
                      <div className="truncate text-sm font-medium text-slate-800">{label}</div>
                      <div className="text-xs text-slate-400">
                        {num(c.vehicles)} car{c.vehicles === 1 ? '' : 's'} · {num(c.vehicles_costing)} with spend
                      </div>
                    </div>
                    <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                      <div className="absolute inset-y-0 start-0 rounded-full bg-amber-400" style={{ width: `${Math.max(2, (c.maintenance_cost / maxCatCost) * 100)}%` }} />
                    </div>
                    <div className="w-32 shrink-0 text-end tabular-nums text-sm font-semibold text-amber-600">{aed2(c.maintenance_cost)}</div>
                    <div className="hidden w-24 shrink-0 text-end tabular-nums text-sm text-slate-500 sm:block" title="Cost per rental">
                      {c.cost_per_rental != null ? `${aed2(c.cost_per_rental)}/rental` : '—'}
                    </div>
                  </button>
                );
              })}
            </div>
          )}
        </SectionCard>

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" checked={activeOnly} onChange={(e) => setActiveOnly(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
            Only rented &amp; ready cars
          </label>
          {cat != null && (
            <Badge tone="indigo">Category: {cat}</Badge>
          )}
          <span className="ms-auto text-xs text-slate-400">{num(rows.length)} of {num(s.vehicles)} cars</span>
        </div>

        {/* Analytics — same filtered rows as the table below. */}
        {!loading && !error && rows.length > 0 && (
          <CostIntelligenceAnalytics rows={rows} windowed={windowed} />
        )}

        <SectionCard
          title="Fleet — running cost per car"
          actions={<span className="text-xs text-slate-400">{num(rows.length)} car{rows.length === 1 ? '' : 's'}</span>}
        >
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.vehicle_id}
            loading={loading}
            sortKey={sort.key}
            sortDir={sort.dir}
            onSort={onSort}
            onRowClick={(r) => navigate(`/vehicles/${r.vehicle_id}`)}
            empty={q ? 'No cars match your search.' : 'No vehicles found.'}
          />
        </SectionCard>

        {/* Calculation drill-down — opens from any number and shows exactly how it was derived. */}
        <FinancialBreakdownDrawer
          vehicleId={drill?.vehicleId}
          metric={drill?.metric}
          onClose={() => setDrill(null)}
        />
      </div>
    </div>
  );
}
