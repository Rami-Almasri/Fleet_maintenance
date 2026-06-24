import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { Card, PageHeader } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { Tooltip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import { aed2, num } from '../lib/format';

const PERIODS = [
  { key: 'last_month', label: 'Last month' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_3m', label: 'Last 3 mo' },
  { key: 'last_12m', label: 'Last 12 mo' },
  { key: 'all', label: 'All time' },
];

const SORTS = [
  { key: 'days_maintenance', label: 'Most maintenance days' },
  { key: 'downtime_pct', label: 'Highest downtime %' },
  { key: 'utilization_asc', label: 'Lowest utilization' },
  { key: 'days_idle', label: 'Most idle days' },
  { key: 'days_rented', label: 'Most rented days' },
  { key: 'revenue_lost_downtime', label: 'Most rent lost to downtime' },
];

// Split of a car's owned days into rented / in-maintenance / idle. Denominator is the segment sum
// (not owned days) so the bar always fills exactly — rare rent/maintenance overlap can otherwise
// push the three past 100% of owned. The numeric columns keep the true owned-based percentages.
function SplitBar({ rented, maintenance, idle }) {
  const total = rented + maintenance + idle || 1;
  const seg = (v, cls, label) =>
    v > 0 ? <div className={cls} style={{ width: `${((v / total) * 100).toFixed(1)}%` }} title={`${label}: ${num(v)} days`} /> : null;
  return (
    <div className="flex h-2.5 w-full min-w-[7rem] overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200">
      {seg(rented, 'bg-emerald-500', 'Rented')}
      {seg(maintenance, 'bg-red-500', 'In maintenance')}
      {seg(idle, 'bg-slate-300', 'Idle')}
    </div>
  );
}

const pct = (v) => (v == null ? '—' : `${v}%`);
const days = (v) => (v == null ? '—' : `${num(v)}d`);

// The operational fleet: cars that can actually be rented or sent for maintenance.
const DEFAULT_STATUSES = ['rented', 'ready', 'out_of_order', 'returned'];
const statusLabel = (s) => s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function FleetUtilization() {
  const [period, setPeriod] = useState('last_12m');
  const [statuses, setStatuses] = useState(DEFAULT_STATUSES);
  const [sort, setSort] = useState('days_maintenance');
  const [q, setQ] = useState('');

  // Empty selection → send a sentinel so the API returns nothing (instead of falling back to default).
  const statusParam = statuses.length ? statuses.join(',') : '__none__';
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Vehicle/utilization', { params: { period, statuses: statusParam } });
    return data.data;
  }, [period, statusParam]);
  const { data, loading, error } = useFetch(fetcher, [period, statusParam]);

  const toggleStatus = (st) =>
    setStatuses((prev) => (prev.includes(st) ? prev.filter((x) => x !== st) : [...prev, st]));
  const statusOptions = data?.status_options || [];

  const rows = useMemo(() => {
    let list = data?.cars || [];
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter((c) =>
        [c.plate, c.code, c.car].filter(Boolean).some((s) => String(s).toLowerCase().includes(needle)),
      );
    }
    const cmp = {
      utilization_asc: (a, b) => (a.utilization_pct ?? 1e9) - (b.utilization_pct ?? 1e9),
      downtime_pct: (a, b) => (b.downtime_pct ?? -1) - (a.downtime_pct ?? -1),
      days_maintenance: (a, b) => (b.days_maintenance ?? -1) - (a.days_maintenance ?? -1),
      days_idle: (a, b) => (b.days_idle ?? -1) - (a.days_idle ?? -1),
      days_rented: (a, b) => (b.days_rented ?? -1) - (a.days_rented ?? -1),
      revenue_lost_downtime: (a, b) => (b.revenue_lost_downtime ?? -1) - (a.revenue_lost_downtime ?? -1),
    }[sort];
    return [...list].sort(cmp);
  }, [data, q, sort]);

  const s = data?.summary || {};
  const win = data?.window || {};

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Fleet Utilization"
          subtitle="For every car: how its days split between earning on rent, sitting in the workshop, and idle — measured from its In-Service Date (first rental), not purchase. Onboarding time before the first rental is excluded so new cars aren't branded as downtime."
        />

        {/* Context line — fleet scope for the selected window */}
        {loading ? (
          <Skeleton className="h-5 w-2/3" />
        ) : (
          <p className="flex flex-wrap items-center gap-x-1.5 text-sm text-slate-500">
            <Tooltip content="Performance is anchored on each car's In-Service Date (first rental). Time owned before the first rental is excluded so new cars aren't penalized as downtime.">
              <span className="cursor-help font-medium text-slate-600 underline decoration-dotted underline-offset-2">
                {win.lifetime ? 'Since each car’s In-Service Date (first rental)' : `Window ${win.from} → ${win.to}`}
              </span>
            </Tooltip>
            <span>·</span>
            <span className="font-semibold text-slate-700">{num(s.cars || 0)}</span> cars,
            <span className="font-semibold text-red-600">{num(s.cars_in_maintenance || 0)}</span> saw the workshop
            {s.pending_service > 0 && (
              <>
                ,
                <Tooltip content="Purchased but not yet rented — no performance metrics until the first rental contract.">
                  <span className="cursor-help font-semibold text-amber-600">{num(s.pending_service)} pending service</span>
                </Tooltip>
              </>
            )}
            .
          </p>
        )}

        {/* Hero KPIs */}
        {loading ? (
          <MetricGridSkeleton count={5} />
        ) : (
          <MetricGrid cols={5}>
            <MetricCard
              label="Avg utilization"
              value={pct(s.avg_utilization_pct)}
              tone="emerald"
              icon={<Icon.Percent className="h-5 w-5" />}
              hint="of in-service days on rent"
              tooltip="Utilization %: share of each car's in-service days that were on a paid rental, averaged across the fleet. Higher is better."
            />
            <MetricCard
              label="Avg downtime"
              value={pct(s.avg_downtime_pct)}
              tone="red"
              icon={<Icon.Wrench className="h-5 w-5" />}
              hint="of in-service days in workshop"
              tooltip="Downtime %: share of in-service days spent in the workshop with no active rental (true downtime), averaged across the fleet."
            />
            <MetricCard
              label="Maintenance days"
              value={num(s.total_days_maintenance || 0)}
              tone="slate"
              icon={<Icon.Clock className="h-5 w-5" />}
              hint="fleet total in window"
              tooltip="Total workshop days across the fleet in this window (days with no active rental)."
            />
            <MetricCard
              label="Idle days"
              value={num(s.total_days_idle || 0)}
              tone="amber"
              icon={<Icon.Activity className="h-5 w-5" />}
              hint="available, not earning"
              tooltip="Days a car was available (not rented, not in the workshop) — capacity that earned nothing."
            />
            <MetricCard
              label="Rent lost to downtime"
              value={aed2(s.revenue_lost_downtime || 0)}
              tone="red"
              icon={<Icon.Cash className="h-5 w-5" />}
              hint="downtime × daily rate"
              tooltip="Estimated rent foregone while cars sat in the workshop: downtime days × each car's daily rate."
            />
          </MetricGrid>
        )}

        {/* Controls */}
        <Card className="p-4">
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex flex-wrap gap-1.5">
              {PERIODS.map((p) => (
                <button
                  key={p.key}
                  onClick={() => setPeriod(p.key)}
                  className={`rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition ${
                    period === p.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
            <div className="ml-auto flex flex-wrap items-center gap-2">
              <select
                value={sort}
                onChange={(e) => setSort(e.target.value)}
                className="rounded-lg border-slate-200 bg-white py-1.5 pl-3 pr-8 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
              >
                {SORTS.map((o) => (
                  <option key={o.key} value={o.key}>{o.label}</option>
                ))}
              </select>
              <div className="relative">
                <Icon.Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Search plate / model…"
                  className="w-44 rounded-lg border-slate-200 bg-white py-1.5 pl-8 pr-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
                />
              </div>
            </div>
          </div>

          {/* Status checkboxes — tick which vehicle statuses to include (default = operational fleet) */}
          {statusOptions.length > 0 && (
            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Show statuses</span>
              {statusOptions.map((o) => {
                const on = statuses.includes(o.status);
                return (
                  <button
                    key={o.status}
                    onClick={() => toggleStatus(o.status)}
                    className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition ${
                      on ? 'bg-indigo-50 text-indigo-700 ring-indigo-300' : 'bg-white text-slate-500 ring-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <span className={`flex h-3.5 w-3.5 items-center justify-center rounded-[4px] ring-1 ring-inset ${on ? 'bg-indigo-600 ring-indigo-600' : 'bg-white ring-slate-300'}`}>
                      {on && (
                        <svg className="h-2.5 w-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round"><path d="M5 13l4 4L19 7" /></svg>
                      )}
                    </span>
                    {statusLabel(o.status)}
                    <span className={on ? 'text-indigo-400' : 'text-slate-400'}>{o.count}</span>
                  </button>
                );
              })}
              <span className="ml-auto flex items-center gap-2 text-xs">
                <button onClick={() => setStatuses(DEFAULT_STATUSES)} className="font-medium text-indigo-600 hover:text-indigo-700">Operational</button>
                <span className="text-slate-300">·</span>
                <button onClick={() => setStatuses(statusOptions.map((o) => o.status))} className="font-medium text-slate-500 hover:text-slate-700">All</button>
              </span>
            </div>
          )}
          <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-400">
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" /> Rented</span>
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-red-500" /> In maintenance</span>
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-slate-300" /> Idle</span>
            <span className="ml-auto italic">Maintenance = workshop days with no active rental (true downtime). A shop day during a paid rental counts as rented, shown as "+Nd paid on rent". The three always add up to days in service.</span>
          </p>
        </Card>

        {/* Table */}
        {error ? (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        ) : (
          <SectionCard
            title="Per-car breakdown"
            actions={!loading && <span className="text-xs text-slate-400">{num(rows.length)} cars</span>}
          >
            <DataTable
              rows={rows}
              rowKey={(c) => c.vehicle_id}
              loading={loading}
              empty="No vehicles match this filter."
              highlightRow={(c) => c.downtime_pct >= 15 || (c.utilization_pct != null && c.utilization_pct < 20)}
              columns={[
                {
                  key: 'car',
                  header: 'Car',
                  render: (c) => (
                    <>
                      <Link to={`/vehicles/${c.vehicle_id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
                        {c.plate || c.code || `#${c.vehicle_id}`}
                      </Link>
                      {c.pending_service && (
                        <span className="ml-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200" title="Purchased but not yet rented — no performance metrics until its first rental contract.">
                          Pending service
                        </span>
                      )}
                      {c.car && <span className="block text-xs text-slate-400">{[c.car, c.year].filter(Boolean).join(' · ')}</span>}
                    </>
                  ),
                },
                {
                  key: 'in_service',
                  header: 'In service',
                  align: 'right',
                  tooltip: 'In-Service anchor: days since the car\'s first rental. Onboarding time before the first rental is excluded.',
                  cellClass: 'tabular-nums text-slate-600',
                  render: (c) => (
                    <>
                      {days(c.days_in_service)}
                      {c.owned_since && (
                        <span className="block text-[11px] text-slate-400" title={`In service since ${c.in_service_date || '—'} · owned since ${c.owned_since}`}>
                          owned {days(c.days_owned)}
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'rented',
                  header: 'Rented',
                  align: 'right',
                  tooltip: 'Days on a paid rental, and the resulting Utilization % (rented ÷ in-service days).',
                  cellClass: 'tabular-nums',
                  render: (c) => (
                    <>
                      <span className="font-medium text-emerald-600">{days(c.days_rented)}</span>
                      <span className="block text-[11px] text-slate-400">{pct(c.utilization_pct)}</span>
                    </>
                  ),
                },
                {
                  key: 'maintenance',
                  header: 'Maintenance',
                  align: 'right',
                  tooltip: 'Workshop days with no active rental (true downtime), plus visit count and any onboarding visits excluded from this window.',
                  cellClass: 'tabular-nums',
                  render: (c) => (
                    <>
                      <span className={`font-medium ${c.downtime_pct >= 15 ? 'text-red-600' : 'text-slate-700'}`}>{days(c.days_maintenance)}</span>
                      <span className="block text-[11px] text-slate-400">
                        {pct(c.downtime_pct)}
                        {c.maintenance_visits > 0 ? (
                          <>
                            {' · '}
                            <Link
                              to={`/vehicles/${c.vehicle_id}?focus=maintenance`}
                              title="See this car's workshop visits"
                              className="font-medium text-indigo-600 underline decoration-dotted underline-offset-2 hover:text-indigo-700"
                            >
                              {num(c.maintenance_visits)} visits
                            </Link>
                          </>
                        ) : (
                          ` · ${num(c.maintenance_visits)} visits`
                        )}
                        {c.onboarding_visits > 0 && (
                          <span className="text-slate-400" title={`${num(c.onboarding_visits)} workshop visit(s) happened during onboarding, before the first rental — excluded here but shown in the car's lifetime total on its profile (${num((c.maintenance_visits || 0) + c.onboarding_visits)} lifetime).`}>
                            {' '}(+{num(c.onboarding_visits)} onboarding)
                          </span>
                        )}
                      </span>
                      {c.days_maintenance_on_rent > 0 && (
                        <span className="block text-[11px] font-medium text-emerald-600" title="Workshop days that fell inside an active rental — paid by the customer, so counted as rented, not downtime.">
                          +{num(c.days_maintenance_on_rent)}d paid on rent
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'idle',
                  header: 'Idle',
                  align: 'right',
                  tooltip: 'Available days that earned nothing (not rented, not in the workshop).',
                  cellClass: 'tabular-nums text-slate-500',
                  render: (c) => days(c.days_idle),
                },
                {
                  key: 'split',
                  header: 'Split',
                  headerClass: 'w-40',
                  render: (c) => <SplitBar rented={c.days_rented} maintenance={c.days_maintenance} idle={c.days_idle || 0} />,
                },
                {
                  key: 'rent_lost',
                  header: 'Rent lost',
                  align: 'right',
                  tooltip: 'Estimated rent foregone to downtime: downtime days × the car\'s daily rate.',
                  cellClass: 'tabular-nums text-slate-600',
                  render: (c) => (c.revenue_lost_downtime != null ? aed2(c.revenue_lost_downtime) : '—'),
                },
              ]}
            />
          </SectionCard>
        )}
      </div>
    </div>
  );
}
