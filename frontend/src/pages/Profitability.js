import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import ProfitBridge from '../components/ProfitBridge';
import { aed2, num } from '../lib/format';

// Status badge tone per OfficeManager lifecycle status.
const STATUS_TONE = { ready: 'green', rented: 'blue', maintenance: 'amber', sold: 'gray', disposed: 'gray' };

// Out-of-fleet cars (no longer earning) — hidden by default to keep the active fleet in focus.
const OUT_OF_FLEET = ['sold', 'disposed'];

// Purchased but never rented — still in the fleet, in onboarding. Show "Pending service" (matching
// Fleet Utilization), not a misleading AED 0 net. `pending_service` comes from the shared In-Service
// source; fall back to the rentals count if the field is absent.
const isPending = (r) => (r.pending_service ?? (r.rentals || 0) === 0) && !OUT_OF_FLEET.includes(r.status);

// Sortable column header — click to sort, click again to flip direction. Rendered inside a
// DataTable column `header` so the table keeps its shared polish while staying interactive.
function SortHeader({ label, col, sort, setSort, align = 'left' }) {
  const active = sort.col === col;
  return (
    <button
      type="button"
      onClick={() => setSort((s) => ({ col, dir: s.col === col && s.dir === 'desc' ? 'asc' : 'desc' }))}
      className={`inline-flex items-center gap-1 ${align === 'right' ? 'flex-row-reverse' : ''} font-semibold uppercase tracking-wide ${active ? 'text-indigo-600' : 'text-slate-500 hover:text-slate-700'}`}
    >
      {label}
      <span className={`text-[10px] ${active ? 'opacity-100' : 'opacity-0'}`}>{sort.dir === 'desc' ? '▼' : '▲'}</span>
    </button>
  );
}

/**
 * Fleet-wide profitability: one row per car, the LIFETIME Profit Bridge (gross rental revenue −
 * operating costs − logged maintenance = net profit) from RealProfitService. Sorted best-to-worst
 * so the top assets and the liabilities are both one glance away. Whole fleet in one table.
 */
export default function Profitability() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Profitability');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const navigate = useNavigate();

  const [q, setQ] = useState('');
  const [sort, setSort] = useState({ col: 'net', dir: 'desc' });
  const [hideIdle, setHideIdle] = useState(false); // hide cars with no income AND no maintenance
  const [hideOutOfFleet, setHideOutOfFleet] = useState(true); // hide sold / disposed cars by default

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (hideOutOfFleet) list = list.filter((r) => !OUT_OF_FLEET.includes(r.status));
    if (hideIdle) list = list.filter((r) => r.gross_revenue !== 0 || r.maintenance !== 0);
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    const { col, dir } = sort;
    const mul = dir === 'desc' ? -1 : 1;
    return [...list].sort((a, b) => {
      const av = a[col] ?? 0;
      const bv = b[col] ?? 0;
      if (typeof av === 'string' || typeof bv === 'string') return mul * String(av).localeCompare(String(bv));
      return mul * (av - bv);
    });
  }, [data, q, sort, hideIdle, hideOutOfFleet]);

  const s = data?.summary || {};
  const netPositive = Number(s.total_net) >= 0;

  const columns = [
    {
      key: 'plate', align: 'left', cellClass: 'font-medium',
      header: <SortHeader label="Car" col="plate" sort={sort} setSort={setSort} />,
      render: (r) => (
        <>
          <Link to={`/vehicles/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
          <div className="text-xs text-slate-400">{r.car || '—'}</div>
        </>
      ),
    },
    {
      key: 'status',
      header: <SortHeader label="Status" col="status" sort={sort} setSort={setSort} />,
      render: (r) => (
        <>
          <Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status || '—'}</Badge>
          {r.for_sale && <Badge tone="amber" className="ml-1">For sale</Badge>}
        </>
      ),
    },
    {
      key: 'rentals', align: 'right', cellClass: 'tabular-nums text-slate-600',
      header: <SortHeader label="Rentals" col="rentals" sort={sort} setSort={setSort} align="right" />,
      render: (r) => r.rentals || <span className="text-slate-300">—</span>,
    },
    {
      key: 'gross_revenue', align: 'right', cellClass: 'tabular-nums text-emerald-600',
      tooltip: 'Rent − discount + collected usage, summed over the asset lifetime.',
      header: <SortHeader label="Gross Revenue" col="gross_revenue" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.gross_revenue ? aed2(r.gross_revenue) : <span className="text-slate-300">—</span>),
    },
    {
      key: 'operating_cost', align: 'right', cellClass: 'tabular-nums text-slate-500',
      tooltip: 'Commissions + co-driver fees.',
      header: <SortHeader label="Operating" col="operating_cost" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.operating_cost ? aed2(r.operating_cost) : <span className="text-slate-300">—</span>),
    },
    {
      key: 'maintenance', align: 'right', cellClass: 'tabular-nums text-amber-600',
      tooltip: 'Sum of all recorded repairs for this car.',
      header: <SortHeader label="Maintenance" col="maintenance" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.maintenance ? aed2(r.maintenance) : <span className="text-slate-300">—</span>),
    },
    {
      key: 'net', align: 'right',
      tooltip: 'Lifetime net profit = gross revenue − operating costs − maintenance. Cars not yet rented show "Pending service" instead of a misleading AED 0.',
      header: <SortHeader label="Net Profit" col="net" sort={sort} setSort={setSort} align="right" />,
      render: (r) =>
        isPending(r) ? (
          <span title={r.owned_since ? `Owned since ${r.owned_since} — not yet rented (onboarding)` : 'Not yet rented (onboarding)'}>
            <Badge tone="amber">Pending service</Badge>
          </span>
        ) : (
          <span className={`tabular-nums font-semibold ${r.net > 0 ? 'text-emerald-600' : r.net < 0 ? 'text-red-600' : 'text-slate-400'}`}>
            {r.net > 0 ? '+' : r.net < 0 ? '−' : ''}{aed2(Math.abs(r.net))}
          </span>
        ),
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Fleet Profitability"
          subtitle="Lifetime Net Profit per car — what each asset actually pocketed: gross rental revenue minus operating costs and logged maintenance. Best assets at the top."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft lg:col-span-2">
                <Skeleton className="mb-4 h-3 w-48" />
                <div className="space-y-3">
                  <Skeleton className="h-3 w-full" />
                  <Skeleton className="h-3 w-full" />
                  <Skeleton className="h-3 w-full" />
                  <Skeleton className="h-4 w-2/3" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-1">
                <MetricGridSkeleton count={2} />
              </div>
            </div>
            <SectionCard title="Fleet — lifetime net profit per car">
              <DataTable columns={columns} rows={[]} loading rowKey={() => 0} />
            </SectionCard>
          </>
        ) : (
          <>
            {/* Fleet totals — the same Profit Bridge as the car profile, summed across the fleet. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <ProfitBridge
                className="lg:col-span-2"
                title="Fleet Profit Bridge — gross to net"
                netLabel="Total Net Profit"
                bridge={{
                  gross_revenue: s.total_gross,
                  maintenance: s.total_maintenance,
                  operating_cost: s.total_operating,
                  net_profit: s.total_net,
                }}
              />
              <MetricGrid cols={2} className="lg:grid-cols-1">
                <MetricCard
                  label="Net Profit"
                  value={aed2(s.total_net)}
                  tone={netPositive ? 'emerald' : 'red'}
                  icon={netPositive ? <Icon.TrendUp className="h-5 w-5" /> : <Icon.TrendDown className="h-5 w-5" />}
                  hint="Fleet-wide, all assets"
                  tooltip="Sum of every car's lifetime net profit: gross revenue minus operating costs and maintenance."
                />
                <MetricCard
                  label="Profitable / Loss"
                  value={`${num(s.profitable)} / ${num(s.loss_making)}`}
                  tone="slate"
                  icon={<Icon.Scale className="h-5 w-5" />}
                  hint="Cars in the black vs in the red"
                  tooltip="How many cars have a positive lifetime net profit versus a negative one."
                />
              </MetricGrid>
            </div>

            {/* Controls */}
            <div className="flex flex-wrap items-center gap-3">
              <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
              <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={hideOutOfFleet} onChange={(e) => setHideOutOfFleet(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                Hide sold & disposed cars
              </label>
              <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={hideIdle} onChange={(e) => setHideIdle(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                Hide cars with no income & no maintenance
              </label>
              <span className="ml-auto text-xs text-slate-400">{num(rows.length)} of {num(s.vehicles)} cars</span>
            </div>

            <SectionCard
              title="Fleet — lifetime net profit per car"
              actions={<span className="text-xs text-slate-400">{num(rows.length)} car{rows.length === 1 ? '' : 's'}</span>}
            >
              <DataTable
                columns={columns}
                rows={rows}
                rowKey={(r) => r.vehicle_id}
                onRowClick={(r) => navigate(`/vehicles/${r.vehicle_id}`)}
                highlightRow={(r) => !isPending(r) && r.net < 0}
                empty={q ? 'No cars match your search.' : 'No vehicles found.'}
              />
            </SectionCard>
          </>
        )}
      </div>
    </div>
  );
}
