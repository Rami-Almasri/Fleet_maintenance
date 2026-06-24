import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import { RadialGauge, FleetDonut, useCountUp } from '../components/ui/Gauge';
import { usePageStat } from '../components/PageStat';
import { MaintenanceBoardPanel } from './MaintenanceBoard';
import { aed, aed2, fmtDate } from '../lib/format';

// A small "plate" chip that reads like a real number plate.
function PlateChip({ plate }) {
  if (!plate) return <span className="text-slate-300">—</span>;
  return (
    <span className="inline-flex items-center rounded-md border border-slate-300 bg-slate-50 px-2 py-0.5 font-mono text-xs font-semibold tracking-wider text-slate-700">
      {plate}
    </span>
  );
}

// A clearer document-expiry pill: a colored dot + a primary label, with the
// exact day count as a muted sub-line. Red = expired, amber = due this week,
// blue = due this month, green = comfortably ahead.
function ExpiryPill({ days }) {
  if (days === null || days === undefined) return <span className="text-slate-300">—</span>;
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
        {sub && <span className="block text-[10px] text-slate-400">{sub}</span>}
      </span>
    </span>
  );
}

// A number that counts up from 0 on mount — used inside the hero header.
function CountUp({ value, format }) {
  const v = useCountUp(value);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

export default function Dashboard() {
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

  // KPI tiles — each maps to a semantic tone, a design-system icon, a short hint
  // and a tooltip that defines the metric. The whole tile links via MetricCard's `to`.
  const cards = [
    {
      label: 'Pending Approvals', key: 'pending_approvals', tone: 'amber', icon: <Icon.Check className="h-5 w-5" />,
      to: '/maintenance-approvals', hint: 'Awaiting sign-off',
      tooltip: 'Maintenance bills submitted by the garage that still need a manager to approve before they are booked as cost.',
    },
    {
      label: 'Overdue Rentals', key: 'overdue_rentals', tone: 'red', icon: <Icon.Clock className="h-5 w-5" />,
      to: '/overdue-rentals', hint: 'Past estimated return date',
      tooltip: 'Open rental contracts whose expected return date has already passed — contact the customer for an extension.',
    },
    {
      label: 'Cars in Maintenance', key: 'cars_in_maintenance', tone: 'amber', icon: <Icon.Wrench className="h-5 w-5" />,
      to: '/maintenance', hint: 'Currently in the garage',
      tooltip: 'Vehicles whose live operational status is "in maintenance" — not earning while under repair.',
    },
    {
      label: 'Active Contracts', key: 'active_contracts', tone: 'blue', icon: <Icon.Invoice className="h-5 w-5" />,
      to: '/contracts', hint: 'Cars currently out',
      tooltip: 'Open rental contracts — cars that are currently checked out to a customer.',
    },
    {
      label: 'Expiring Documents', key: 'expiring_registrations', tone: 'amber', icon: <Icon.Alert className="h-5 w-5" />,
      to: '/registrations', hint: 'Registration / insurance ≤ 7 days',
      tooltip: 'Vehicles whose registration (Mulkiya) or insurance expires within the next 7 days.',
    },
    {
      label: 'Vehicles For Sale', key: 'vehicles_for_sale', tone: 'violet', icon: <Icon.Flag className="h-5 w-5" />,
      to: '/vehicles', hint: 'Flagged for disposal',
      tooltip: 'Cars flagged to be sold or disposed and removed from the active rental fleet.',
    },
    {
      label: 'Negative Yield', key: 'negative_yield', value: (k) => k.negative_yield?.count, tone: 'red', icon: <Icon.TrendDown className="h-5 w-5" />,
      to: '/maintenance-foresight', hint: 'Real net profit below repair cost (12mo)',
      tooltip: 'Cars whose real net profit over the last 12 months is below what was spent repairing them — money-losers to review.',
    },
    {
      label: 'Repairs Without Cost', key: 'uncosted_repairs', tone: 'indigo', icon: <Icon.Coins className="h-5 w-5" />,
      to: '/cost-capture', hint: 'Tap to enter repair amounts (12mo)',
      tooltip: 'Closed repairs in the last 12 months that have no cost recorded yet — fill these in for accurate per-car profit.',
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Hero header */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 px-6 py-7 shadow-xl sm:px-8">
          <div className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-20 right-1/3 h-56 w-56 rounded-full bg-violet-500/10 blur-3xl" />
          <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-widest text-indigo-300/80">Fleet Command Center</p>
              <h1 className="mt-1 text-3xl font-bold tracking-tight text-white">Overview</h1>
              <p className="mt-1 text-sm text-slate-300">Live snapshot of your fleet's finances and operations.</p>
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
            <div className="flex items-center gap-1.5 border-b border-slate-100 px-6 py-4">
              <div>
                <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                  Fleet Composition
                  <InfoTip content="Every car split by its OfficeManager lifecycle status (available, rented, in maintenance, sold…). Mutually exclusive — the slices sum to the total fleet." />
                </h2>
                <p className="mt-0.5 text-xs text-slate-500">Where every car in the fleet is right now</p>
              </div>
            </div>
            <div className="px-6 py-7">
              {loading ? (
                <div className="flex justify-center py-6"><Skeleton className="h-48 w-48 rounded-full" /></div>
              ) : (
                <FleetDonut segments={fleetSegments} total={fleetTotal} centerLabel="vehicles" />
              )}
            </div>
          </Card>

          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Utilization
                <InfoTip content="Share of the fleet currently rented out (rented ÷ total). The headline operating metric — how much of your fleet is actually earning right now." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Share of fleet currently earning</p>
            </div>
            <div className="flex flex-col items-center justify-center gap-8 px-6 py-8 pb-12">
              {loading ? (
                <Skeleton className="h-32 w-32 rounded-full" />
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
        {loading ? (
          <MetricGridSkeleton count={8} />
        ) : (
          <MetricGrid cols={4}>
            {cards.map((card) => (
              <MetricCard
                key={card.label}
                label={card.label}
                value={Number((card.value ? card.value(kpis) : kpis[card.key]) || 0).toLocaleString()}
                tone={card.tone}
                icon={card.icon}
                hint={card.hint}
                tooltip={card.tooltip}
                to={card.to}
              />
            ))}
          </MetricGrid>
        )}

        {/* Maintenance Board — the operational core of the product, front and center.
            Reuses the live board panel so the Dashboard never drifts from /maintenance. */}
        <section className="space-y-4">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 className="text-xl font-bold tracking-tight text-slate-900">Maintenance Board</h2>
              <p className="mt-1 text-sm text-slate-500">Cars currently in the garage — due dates, issues, cost and live status.</p>
            </div>
            <div className="flex items-center gap-4">
              <Link to="/maintenance-analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Cost Analytics →</Link>
              <Link to="/maintenance" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Open full board →</Link>
            </div>
          </div>
          <MaintenanceBoardPanel />
        </section>

        {/* Expiring soon — registration / insurance due in the next 30 days */}
        <SectionCard
          title="Expiring Soon"
          subtitle="Registration & insurance due in the next 30 days"
          actions={
            <>
              <Badge tone="gray">next 30 days</Badge>
              <Link to="/registrations" className="hidden text-xs font-medium text-indigo-600 hover:text-indigo-700 sm:inline">View all →</Link>
            </>
          }
        >
          <DataTable
            rows={expiring}
            loading={loading}
            rowKey={(r, i) => `${r.plate_no || r.vehicle || 'exp'}-${i}`}
            empty="Nothing expiring soon 🎉"
            highlightRow={(r) => (r.registration_days_left != null && r.registration_days_left < 0) || (r.insurance_days_left != null && r.insurance_days_left < 0)}
            columns={[
              {
                key: 'vehicle', header: 'Vehicle', cellClass: 'font-medium text-slate-900',
                render: (r) => (
                  <span className="flex items-center gap-3">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                      <Icon.Car className="h-4 w-4" />
                    </span>
                    {r.vehicle || '—'}
                  </span>
                ),
              },
              { key: 'plate', header: 'Plate', render: (r) => <PlateChip plate={r.plate_no} /> },
              {
                key: 'registration', header: 'Registration', tooltip: 'Days until the vehicle registration (Mulkiya) expires.',
                render: (r) => <ExpiryPill days={r.registration_days_left} />,
              },
              {
                key: 'insurance', header: 'Insurance', tooltip: 'Days until the vehicle insurance policy expires.',
                render: (r) => <ExpiryPill days={r.insurance_days_left} />,
              },
            ]}
          />
        </SectionCard>

        {/* Overdue / Attention Needed — open rentals AND maintenance whose expected
            return date has passed. Monitoring only: nothing here closes a contract,
            so extensions and garage delays just surface for the team to action. */}
        {!loading && overdue.length > 0 && (
          <SectionCard
            className="ring-1 ring-red-200"
            title={
              <span className="flex items-center gap-2 text-red-700">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-100 text-red-600">!</span>
                Overdue Contracts / Attention Needed
              </span>
            }
            subtitle="Expected return date has passed — contact the customer for an extension or check with the garage."
            actions={<Badge tone="red">{overdue.length}</Badge>}
          >
            <DataTable
              rows={overdue.slice(0, 12)}
              rowKey={(r) => `${r.kind}-${r.id}`}
              highlightRow={() => true}
              columns={[
                {
                  key: 'car', header: 'Car', cellClass: 'font-medium',
                  render: (r) => (
                    <>
                      <Link to={`/contracts/${r.id}`} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.contract_no || r.id}`}</Link>
                      <div className="text-xs text-slate-400">{r.car || '—'}</div>
                    </>
                  ),
                },
                { key: 'type', header: 'Type', render: (r) => <Badge tone={r.kind === 'Maintenance' ? 'amber' : 'blue'}>{r.kind}</Badge> },
                {
                  key: 'customer', header: 'Customer / Garage',
                  render: (r) => (r.customer_id
                    ? <Link to={`/customers/${r.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{r.customer || '—'}</Link>
                    : (r.customer || r.garage || '—')),
                },
                { key: 'out', header: 'Out', cellClass: 'text-slate-500', render: (r) => fmtDate(r.out_date || r.since) },
                { key: 'due', header: 'Est. return', cellClass: 'text-slate-500', tooltip: 'Estimated return date on the contract.', render: (r) => fmtDate(r.due) },
                { key: 'late', header: 'Late', align: 'center', render: (r) => <Badge tone="red">{r.days_overdue}d late</Badge> },
                { key: 'balance', header: 'Balance', align: 'right', cellClass: 'tabular-nums', render: (r) => (r.balance ? aed2(r.balance) : '—') },
              ]}
            />
          </SectionCard>
        )}

      </div>
    </div>
  );
}
