import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Badge from '../components/ui/Badge';
import { Tooltip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import { usePageStat } from '../components/PageStat';
import { aed2, fmtDate, num } from '../lib/format';

// How alarming is "late by N days"? Drives the whole row's color language.
const severity = (d) =>
  d >= 14
    ? { key: 'critical', label: 'Critical', tone: 'red', text: 'text-red-700' }
    : d >= 4
    ? { key: 'high', label: 'Escalating', tone: 'amber', text: 'text-amber-700' }
    : { key: 'recent', label: 'Recently due', tone: 'slate', text: 'text-slate-600' };

export default function OverdueRentals() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Dashboard/overdue-rentals');
    return data.data || [];
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all overdue rentals, the share that are badly late
  // (14+ days). Hidden while loading or when nothing is overdue.
  const allRows = data || [];
  const severe = allRows.filter((r) => (r.days_overdue || 0) >= 14).length;
  usePageStat({
    percent: loading || allRows.length === 0 ? null : (severe / allRows.length) * 100,
    label: '14+ days late',
    color: 'red',
    hint: `${severe} of ${allRows.length} overdue rentals are 14+ days late`,
  });

  // Most-overdue first so the worst offenders lead the list.
  const rows = [...(data || [])].sort((a, b) => (b.days_overdue || 0) - (a.days_overdue || 0));
  const totalBalance = rows.reduce((sum, r) => sum + (Number(r.balance) || 0), 0);
  const worst = rows.reduce((m, r) => Math.max(m, r.days_overdue || 0), 0);
  // Severity buckets for the KPI row.
  const escalating = rows.filter((r) => (r.days_overdue || 0) >= 4 && (r.days_overdue || 0) < 14).length;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Overdue Rentals" subtitle="Open rentals whose estimated return date (handover date + rental days) has already passed.">
          <Link to="/" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">← Dashboard</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <>
            <MetricGridSkeleton count={4} />
            <SectionCard title="Overdue rentals">
              <DataTable loading columns={OVERDUE_COLUMNS} />
            </SectionCard>
          </>
        ) : (
          <>
            {/* Summary KPIs — overdue is urgency-driven, so the color language is red/amber. */}
            <MetricGrid cols={4}>
              <MetricCard
                label="Overdue rentals"
                value={num(rows.length)}
                tone="red"
                icon={<Icon.Clock className="h-5 w-5" />}
                hint="Open rentals past their estimated return"
                tooltip="Rentals whose handover date + planned rental days is already in the past."
              />
              <MetricCard
                label="Critical (14+ days)"
                value={num(severe)}
                tone="red"
                icon={<Icon.Alert className="h-5 w-5" />}
                hint={rows.length ? `${Math.round((severe / rows.length) * 100)}% of overdue` : 'None'}
                tooltip="Rentals that are 14 or more days past their expected return — the worst offenders."
              />
              <MetricCard
                label="Escalating (4–13 days)"
                value={num(escalating)}
                tone="amber"
                icon={<Icon.Calendar className="h-5 w-5" />}
                hint="Past due, trending toward critical"
                tooltip="Rentals 4 to 13 days past their expected return — review before they turn critical."
              />
              <MetricCard
                label="Outstanding balance"
                value={aed2(totalBalance)}
                tone="amber"
                icon={<Icon.Cash className="h-5 w-5" />}
                hint={worst ? `Most overdue: ${worst}d late` : 'Value at risk across overdue rentals'}
                tooltip="Sum of unpaid balances across all overdue rentals — the cash exposure of the overdue book."
              />
            </MetricGrid>

            {rows.length === 0 ? (
              <div className="rounded-2xl border border-slate-200/60 bg-white shadow-soft">
                <EmptyState title="No overdue rentals 🎉" message="Every rented car is still within its expected return window." />
              </div>
            ) : (
              <SectionCard
                title="Overdue rentals"
                subtitle="Sorted by most overdue first — the worst offenders lead."
                actions={<span className="text-xs text-slate-400">{num(rows.length)} total</span>}
              >
                <DataTable
                  rows={rows}
                  rowKey={(r) => r.id}
                  columns={OVERDUE_COLUMNS}
                  // Tint the critically-overdue (14+ days) rows so they stand out.
                  highlightRow={(r) => (r.days_overdue || 0) >= 14}
                  empty="No overdue rentals."
                />
              </SectionCard>
            )}
          </>
        )}

        <p className="text-xs text-slate-400">
          Note: a rental only appears here once its planned <span className="font-medium">rental days</span> are known (filled in by the
          OfficeManager <span className="font-medium">Contracts</span> sync). Rentals not yet synced won't show until that data arrives.
        </p>
      </div>
    </div>
  );
}

// Column defs live outside the component so the loading skeleton and the live table
// share one definition (and so highlightRow/render keep working identically).
const OVERDUE_COLUMNS = [
  {
    key: 'vehicle',
    header: 'Vehicle',
    cellClass: 'font-medium',
    render: (r) => (
      <div className="min-w-0">
        {r.vehicle_id ? (
          <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
            {r.plate || `#${r.vehicle_id}`}
          </Link>
        ) : (
          <span className="font-semibold text-slate-900">{r.plate || '—'}</span>
        )}
        <p className="truncate text-xs text-slate-400">{r.car || 'Unknown model'}</p>
      </div>
    ),
  },
  {
    key: 'customer',
    header: 'Customer',
    render: (r) =>
      r.customer_id ? (
        <Link to={`/customers/${r.customer_id}`} className="font-medium text-slate-700 hover:text-indigo-600">
          {r.customer || '—'}
        </Link>
      ) : (
        <span className="font-medium text-slate-700">{r.customer || '—'}</span>
      ),
  },
  {
    key: 'out_date',
    header: 'Out',
    cellClass: 'whitespace-nowrap text-slate-500',
    tooltip: 'Handover date — when the car left on this rental.',
    render: (r) => fmtDate(r.out_date),
  },
  {
    key: 'due',
    header: 'Expected return',
    cellClass: 'whitespace-nowrap',
    tooltip: 'Estimated return date = handover date + planned rental days.',
    render: (r) => {
      const s = severity(r.days_overdue || 0);
      return <span className={`font-semibold ${s.text}`}>{fmtDate(r.due)}</span>;
    },
  },
  {
    key: 'days_overdue',
    header: 'Days overdue',
    align: 'right',
    headerClass: 'tabular-nums',
    tooltip: 'How many days past the expected return date this rental is — higher means more critical.',
    render: (r) => {
      const d = r.days_overdue || 0;
      const s = severity(d);
      return (
        <div className="flex items-center justify-end gap-2">
          <Tooltip content={s.label}>
            <Badge tone={s.tone}>{`${d}d late`}</Badge>
          </Tooltip>
        </div>
      );
    },
  },
  {
    key: 'balance',
    header: 'Balance',
    align: 'right',
    tooltip: 'Outstanding amount still owed on this rental contract.',
    render: (r) => (
      <span className={`tabular-nums font-bold ${Number(r.balance) > 0 ? 'text-slate-900' : 'text-slate-400'}`}>
        {r.balance ? aed2(r.balance) : '—'}
      </span>
    ),
  },
  {
    key: 'contract',
    header: 'Contract',
    align: 'right',
    render: (r) => (
      <Link
        to={`/contracts/${r.id}`}
        className="inline-flex items-center gap-1 whitespace-nowrap font-semibold text-indigo-600 transition hover:text-indigo-700"
      >
        #{r.contract_no || r.id}
        <Icon.ArrowRight className="h-3.5 w-3.5" />
      </Link>
    ),
  },
];
