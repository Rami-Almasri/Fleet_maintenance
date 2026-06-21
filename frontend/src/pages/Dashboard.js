import { useCallback } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card } from '../components/ui/Misc';
import { RadialGauge, FleetDonut, useCountUp } from '../components/ui/Gauge';
import { usePageStat } from '../components/PageStat';
import { MaintenanceBoardPanel } from './MaintenanceBoard';
import { aed, aed2, fmtDate } from '../lib/format';

// A small "plate" chip that reads like a real number plate.
function PlateChip({ plate }) {
  if (!plate) return <span className="text-gray-300">—</span>;
  return (
    <span className="inline-flex items-center rounded-md border border-gray-300 bg-gray-50 px-2 py-0.5 font-mono text-xs font-semibold tracking-wider text-gray-700">
      {plate}
    </span>
  );
}

// A clearer document-expiry pill: a colored dot + a primary label, with the
// exact day count as a muted sub-line. Red = expired, amber = due this week,
// blue = due this month, green = comfortably ahead.
function ExpiryPill({ days }) {
  if (days === null || days === undefined) return <span className="text-gray-300">—</span>;
  const abs = Math.abs(days);
  let tone, label, sub;
  if (days < 0)        { tone = 'red';   label = 'Expired';       sub = `${abs}d ago`; }
  else if (days === 0) { tone = 'red';   label = 'Due today'; }
  else if (days <= 7)  { tone = 'amber'; label = `${days}d left`; sub = 'this week'; }
  else if (days <= 30) { tone = 'blue';  label = `${days}d left`; sub = 'this month'; }
  else                 { tone = 'green'; label = `${days}d left`; }
  const dot = { red: 'bg-red-500', amber: 'bg-amber-500', blue: 'bg-blue-500', green: 'bg-emerald-500' }[tone];
  const text = { red: 'text-red-700', amber: 'text-amber-700', blue: 'text-blue-700', green: 'text-emerald-700' }[tone];
  return (
    <span className="inline-flex items-center gap-2">
      <span className={`h-2 w-2 shrink-0 rounded-full ${dot}`} />
      <span className="leading-tight">
        <span className={`block text-xs font-semibold ${text}`}>{label}</span>
        {sub && <span className="block text-[10px] text-gray-400">{sub}</span>}
      </span>
    </span>
  );
}

// A number that counts up from 0 on mount — used inside the KPI cards.
function CountUp({ value, format }) {
  const v = useCountUp(value);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

export default function Dashboard() {
  const navigate = useNavigate();

  const fetcher = useCallback(async () => {
    const [kpiRes, expRes, overRentRes, overMaintRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Fleet/expiring', { params: { days: 30 } }),
      api.get('/Dashboard/overdue-rentals'),
      api.get('/Dashboard/overdue-maintenance'),
    ]);
    // Merge overdue rentals + maintenance into one "attention needed" list,
    // most-overdue first. Monitoring only — nothing here auto-closes a contract.
    const rentals = (overRentRes.data.data || []).map((r) => ({ ...r, kind: 'Rental' }));
    const maint = (overMaintRes.data.data || []).map((r) => ({ ...r, kind: 'Maintenance' }));
    return {
      kpis: kpiRes.data.data || {},
      expiring: (expRes.data.data || []).slice(0, 8),
      overdue: [...rentals, ...maint].sort((a, b) => (b.days_overdue || 0) - (a.days_overdue || 0)),
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const expiring = data?.expiring || [];
  const overdue = data?.overdue || [];

  const fleet = kpis.fleet_status || {};
  const fleetTotal = fleet.total || 0;
  const utilization = fleetTotal ? Math.round(((fleet.rented || 0) / fleetTotal) * 100) : 0;

  // Headline percent for the floating page gauge: fleet utilization.
  usePageStat({
    percent: loading || !fleetTotal ? null : utilization,
    label: 'Utilization',
    color: 'indigo',
    hint: `${fleet.rented || 0} of ${fleetTotal} cars currently rented out`,
  });

  // One slice per OfficeManager lifecycle status — mutually exclusive, sums to total.
  // Zero-count statuses are auto-hidden by the donut, so only what you actually have shows.
  const fleetSegments = [
    { label: 'Available',       value: fleet.available || 0,    color: 'emerald' },
    { label: 'Rented',          value: fleet.rented || 0,       color: 'blue' },
    { label: 'In maintenance',  value: fleet.maintenance || 0,  color: 'amber' },
    { label: 'Office use',      value: fleet.office_use || 0,   color: 'cyan' },
    { label: 'Out of order',    value: fleet.out_of_order || 0, color: 'red' },
    { label: 'Suspended',       value: fleet.suspended || 0,    color: 'indigo' },
    { label: 'Returned',        value: fleet.returned || 0,     color: 'violet' },
    { label: 'Sold',            value: fleet.sold || 0,         color: 'slate' },
    { label: 'Disposed',        value: fleet.disposed || 0,     color: 'slate' },
    { label: 'Other',           value: fleet.other || 0,        color: 'slate' },
  ];

  const cards = [
    { label: 'Pending Approvals', key: 'pending_approvals', caption: 'Maintenance bills awaiting sign-off', ring: 'bg-amber-50 text-amber-600', to: '/maintenance-approvals', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' },
    { label: 'Overdue Rentals', key: 'overdue_rentals', caption: 'Past estimated return date', ring: 'bg-red-50 text-red-600', to: '/overdue-rentals', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' },
    { label: 'Cars in Maintenance', key: 'cars_in_maintenance', caption: 'Currently in the garage', ring: 'bg-amber-50 text-amber-600', to: '/maintenance', icon: 'M11 4a4 4 0 0 0-1 7.9V20a2 2 0 1 0 4 0v-8.1A4 4 0 0 0 11 4zM14.5 4.5l-2 2 3 3 2-2' },
    { label: 'Active Contracts', key: 'active_contracts', caption: 'Cars currently out', ring: 'bg-blue-50 text-blue-600', to: '/contracts', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z' },
    { label: 'Expiring Documents', key: 'expiring_registrations', caption: 'Registration / insurance ≤ 7 days', ring: 'bg-amber-50 text-amber-600', to: '/registrations', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z' },
    { label: 'Vehicles For Sale', key: 'vehicles_for_sale', caption: 'Flagged for disposal', ring: 'bg-violet-50 text-violet-600', to: '/vehicles', icon: 'M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7 7h.01' },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
        {/* Hero header */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-gray-900 via-indigo-950 to-gray-900 px-6 py-7 shadow-xl sm:px-8">
          <div className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-20 right-1/3 h-56 w-56 rounded-full bg-violet-500/10 blur-3xl" />
          <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-widest text-indigo-300/80">Fleet Command Center</p>
              <h1 className="mt-1 text-3xl font-bold tracking-tight text-white">Overview</h1>
              <p className="mt-1 text-sm text-gray-300">Live snapshot of your fleet's finances and operations.</p>
            </div>
            <div className="flex items-center gap-6">
              <div className="text-right">
                <p className="text-xs font-medium text-indigo-200/70">Outstanding balance</p>
                <p className="text-2xl font-bold text-white tabular-nums">
                  {loading ? '—' : <CountUp value={kpis.total_outstanding_balance || 0} format={aed} />}
                </p>
              </div>
              <div className="hidden h-12 w-px bg-white/10 sm:block" />
              <div className="hidden text-right sm:block">
                <p className="text-xs font-medium text-indigo-200/70">Fleet size</p>
                <p className="text-2xl font-bold text-white tabular-nums">
                  {loading ? '—' : <CountUp value={fleetTotal} />}
                </p>
              </div>
            </div>
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Fleet composition — the circular indicators front and center */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <div className="border-b border-gray-100 px-6 py-4">
              <h2 className="text-base font-semibold text-gray-900">Fleet Composition</h2>
              <p className="mt-0.5 text-xs text-gray-500">Where every car in the fleet is right now</p>
            </div>
            <div className="px-6 py-7">
              {loading ? (
                <div className="flex justify-center py-6"><div className="h-48 w-48 animate-pulse rounded-full bg-gray-100" /></div>
              ) : (
                <FleetDonut segments={fleetSegments} total={fleetTotal} centerLabel="vehicles" />
              )}
            </div>
          </Card>

          <Card>
            <div className="border-b border-gray-100 px-6 py-4">
              <h2 className="text-base font-semibold text-gray-900">Utilization</h2>
              <p className="mt-0.5 text-xs text-gray-500">Share of fleet currently earning</p>
            </div>
            <div className="flex flex-col items-center justify-center gap-8 px-6 py-8 pb-12">
              {loading ? (
                <div className="h-32 w-32 animate-pulse rounded-full bg-gray-100" />
              ) : (
                <RadialGauge value={utilization} max={100} color="indigo" label="Rented out" format={(v) => `${Math.round(v)}%`} size={148} />
              )}
              <div className="grid w-full grid-cols-2 gap-3">
                <div className="rounded-xl bg-emerald-50 px-3 py-2.5 text-center">
                  <p className="text-lg font-bold text-emerald-700 tabular-nums">{loading ? '—' : (fleet.available || 0)}</p>
                  <p className="text-[11px] font-medium text-emerald-600/80">Available</p>
                </div>
                <div className="rounded-xl bg-amber-50 px-3 py-2.5 text-center">
                  <p className="text-lg font-bold text-amber-700 tabular-nums">{loading ? '—' : (fleet.maintenance || 0)}</p>
                  <p className="text-[11px] font-medium text-amber-600/80">In maintenance</p>
                </div>
              </div>
            </div>
          </Card>
        </div>

        {/* KPI cards */}
        <div className="stagger grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          {cards.map((card) => (
            <button
              key={card.label}
              onClick={() => navigate(card.to)}
              className="hover-lift group relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-6 text-left shadow-sm ring-1 ring-gray-900/5 hover:ring-indigo-500/20"
            >
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-500">{card.label}</p>
                  <p className="mt-2 text-3xl font-bold tracking-tight text-gray-900">
                    {loading ? <span className="inline-block h-8 w-24 animate-pulse rounded bg-gray-100" /> : <CountUp value={Number(kpis[card.key] || 0)} />}
                  </p>
                </div>
                <span className={`flex h-11 w-11 items-center justify-center rounded-xl transition group-hover:scale-110 ${card.ring}`}>
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d={card.icon} />
                  </svg>
                </span>
              </div>
              <p className="mt-4 text-xs font-medium text-gray-400">{card.caption}</p>
              <div className={`absolute inset-x-0 bottom-0 h-1 origin-left scale-x-0 transition-transform duration-300 group-hover:scale-x-100 ${card.ring}`} />
            </button>
          ))}
        </div>

        {/* Maintenance Board — the operational core of the product, front and center.
            Reuses the live board panel so the Dashboard never drifts from /maintenance. */}
        <section className="space-y-4">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 className="text-xl font-bold tracking-tight text-gray-900">Maintenance Board</h2>
              <p className="mt-1 text-sm text-gray-500">Cars currently in the garage — due dates, issues, cost and live status.</p>
            </div>
            <div className="flex items-center gap-4">
              <Link to="/maintenance-analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Cost Analytics →</Link>
              <Link to="/maintenance" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Open full board →</Link>
            </div>
          </div>
          <MaintenanceBoardPanel />
        </section>

        {/* Expiring soon — registration / insurance due in the next 30 days */}
        <Card>
          <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div>
              <h3 className="flex items-center gap-2 text-base font-semibold text-gray-900">
                <svg className="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M12 8v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
                </svg>
                Expiring Soon
              </h3>
              <p className="mt-0.5 text-xs text-gray-500">Registration &amp; insurance due in the next 30 days</p>
            </div>
            <div className="flex shrink-0 items-center gap-3">
              <Badge tone="gray">next 30 days</Badge>
              <Link to="/registrations" className="hidden text-xs font-medium text-indigo-600 hover:text-indigo-700 sm:inline">View all →</Link>
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Vehicle</th>
                  <th className="px-6 py-3">Plate</th>
                  <th className="px-6 py-3">Registration</th>
                  <th className="px-6 py-3">Insurance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {!loading && expiring.map((row, i) => {
                  const reg = row.registration_days_left;
                  const ins = row.insurance_days_left;
                  // Flag the row when any document has already lapsed.
                  const expired = (reg != null && reg < 0) || (ins != null && ins < 0);
                  return (
                    <tr key={i} className={`transition-colors ${expired ? 'bg-red-50/30 hover:bg-red-50/60' : 'hover:bg-gray-50/60'}`}>
                      <td className="px-6 py-3">
                        <div className="flex items-center gap-3">
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                              <path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1m-12 0v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m2 0h10m1 0v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1M7.5 14h.01M16.5 14h.01" />
                            </svg>
                          </span>
                          <span className="font-medium text-gray-900">{row.vehicle || '—'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-3"><PlateChip plate={row.plate_no} /></td>
                      <td className="px-6 py-3"><ExpiryPill days={reg} /></td>
                      <td className="px-6 py-3"><ExpiryPill days={ins} /></td>
                    </tr>
                  );
                })}
                {loading && Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i}>
                    {Array.from({ length: 4 }).map((__, c) => (
                      <td key={c} className="px-6 py-3.5"><div className="h-3 w-28 animate-pulse rounded bg-gray-100" /></td>
                    ))}
                  </tr>
                ))}
                {!loading && expiring.length === 0 && (
                  <tr><td colSpan="4" className="px-6 py-10 text-center text-gray-400">Nothing expiring soon 🎉</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </Card>

        {/* Overdue / Attention Needed — open rentals AND maintenance whose expected
            return date has passed. Monitoring only: nothing here closes a contract,
            so extensions and garage delays just surface for the team to action. */}
        {!loading && overdue.length > 0 && (
          <Card className="ring-1 ring-red-200">
            <div className="flex items-center justify-between gap-3 border-b border-red-100 bg-red-50/60 px-6 py-4">
              <div>
                <h3 className="flex items-center gap-2 text-base font-semibold text-red-700">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-100 text-red-600">!</span>
                  Overdue Contracts / Attention Needed
                </h3>
                <p className="mt-0.5 text-xs text-red-500">Expected return date has passed — contact the customer for an extension or check with the garage.</p>
              </div>
              <Badge tone="red">{overdue.length}</Badge>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-100 text-sm">
                <thead className="bg-gray-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th className="px-6 py-3">Car</th>
                    <th className="px-6 py-3">Type</th>
                    <th className="px-6 py-3">Customer / Garage</th>
                    <th className="px-6 py-3">Out</th>
                    <th className="px-6 py-3">Est. return</th>
                    <th className="px-6 py-3 text-center">Late</th>
                    <th className="px-6 py-3 text-right">Balance</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {overdue.slice(0, 12).map((r) => (
                    <tr key={`${r.kind}-${r.id}`} className="hover:bg-red-50/40">
                      <td className="px-6 py-3">
                        <Link to={`/contracts/${r.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.contract_no || r.id}`}</Link>
                        <div className="text-xs text-gray-400">{r.car || '—'}</div>
                      </td>
                      <td className="px-6 py-3"><Badge tone={r.kind === 'Maintenance' ? 'amber' : 'blue'}>{r.kind}</Badge></td>
                      <td className="px-6 py-3 text-gray-700">
                        {r.customer_id
                          ? <Link to={`/customers/${r.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{r.customer || '—'}</Link>
                          : (r.customer || r.garage || '—')}
                      </td>
                      <td className="px-6 py-3 text-gray-500">{fmtDate(r.out_date || r.since)}</td>
                      <td className="px-6 py-3 text-gray-500">{fmtDate(r.due)}</td>
                      <td className="px-6 py-3 text-center"><Badge tone="red">{r.days_overdue}d late</Badge></td>
                      <td className="px-6 py-3 text-right text-gray-700">{r.balance ? aed2(r.balance) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}

      </div>
    </div>
  );
}
