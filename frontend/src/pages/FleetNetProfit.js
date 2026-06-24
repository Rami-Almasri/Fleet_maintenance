import { useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { Card, PageHeader } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { aed2, fmtDate, num } from '../lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export default function FleetNetProfit() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1); // 1-based

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Reconciliation/fleet', { params: { year, month } });
    return data.data;
  }, [year, month]);
  const { data, loading, error } = useFetch(fetcher, [year, month]);

  // Month stepper (don't let the user walk into the future).
  const step = (delta) => {
    let m = month + delta, y = year;
    if (m < 1) { m = 12; y -= 1; }
    if (m > 12) { m = 1; y += 1; }
    if (y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1)) return;
    setMonth(m); setYear(y);
  };
  const isCurrent = year === now.getFullYear() && month === now.getMonth() + 1;

  const t = data?.totals;
  const netPositive = Number(t?.net_profit) >= 0;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Net Profit — Monthly"
          subtitle="Fleet-wide cash-basis Net Profit for the month: total net cash collected on rentals returned in the month, minus the cost of maintenance contracts closed in the month."
        />

        {/* Month stepper */}
        <div className="flex items-center justify-center gap-3">
          <button onClick={() => step(-1)} className="rounded-lg border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50 active:scale-95" aria-label="Previous month">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          </button>
          <span className="min-w-[10rem] text-center text-lg font-semibold text-slate-800">{MONTHS[month - 1]} {year}</span>
          <button onClick={() => step(1)} disabled={isCurrent} className="rounded-lg border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50 active:scale-95 disabled:opacity-40" aria-label="Next month">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
          </button>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading && (
          <>
            <div className="shimmer h-32 rounded-2xl bg-slate-100" />
            <MetricGridSkeleton count={3} />
          </>
        )}

        {data && t && !loading && (
          <>
            {/* Headline */}
            <Card className={`ring-1 ${netPositive ? 'ring-emerald-200' : 'ring-red-200'}`}>
              <div className="px-6 py-6 text-center">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Fleet Net Profit · {data.period.label}</p>
                <p className={`mt-1 text-4xl font-bold tracking-tight tabular-nums ${netPositive ? 'text-emerald-600' : 'text-red-600'}`}>
                  {aed2(t.net_profit)}
                </p>
                <p className="mt-2 inline-flex flex-wrap items-center justify-center gap-x-1 text-sm text-slate-500">
                  Net collected <span className="font-semibold text-slate-700">{aed2(t.net_collected)}</span>
                  {' − '}Maintenance cost <span className="font-semibold text-slate-700">{aed2(t.maintenance_cost)}</span>
                </p>
              </div>
            </Card>

            {/* Components */}
            <MetricGrid cols={3}>
              <MetricCard
                label="Net Collected"
                value={aed2(t.net_collected)}
                tone="emerald"
                icon={<Icon.Coins className="h-5 w-5" />}
                hint={`${num(data.counts.rentals)} rentals returned · billed ${aed2(t.billed)}`}
                tooltip="Cash actually collected on rentals returned this month (recorded collected − refunds)."
              />
              <MetricCard
                label="Maintenance Cost"
                value={aed2(t.maintenance_cost)}
                tone="amber"
                icon={<Icon.Wrench className="h-5 w-5" />}
                hint={`${num(data.counts.maintenance)} maintenance contracts closed`}
                tooltip="Total cost of maintenance (type-U) contracts closed within this month."
              />
              <MetricCard
                label="Net Profit"
                value={aed2(t.net_profit)}
                tone={netPositive ? 'emerald' : 'red'}
                icon={<Icon.Scale className="h-5 w-5" />}
                big
                hint={t.workshop_cost ? `Workshop-log spend (ref): ${aed2(t.workshop_cost)}` : 'Cash basis'}
                tooltip="Net collected minus maintenance cost, on a cash basis."
              />
            </MetricGrid>

            {/* Basis note */}
            <div className="flex items-start gap-2.5 rounded-xl bg-blue-50/60 px-4 py-3 text-xs text-blue-700 ring-1 ring-inset ring-blue-600/15">
              <Icon.Info className="mt-px h-4 w-4 shrink-0 text-blue-500" />
              <p>
                The fleet rollup uses <span className="font-semibold">synced figures</span> (net collected ≈ recorded collected − refunds), not a live accounting call per contract — so it stays fast at fleet scale.
                To verify the <span className="font-semibold">real cash</span> for any single contract, open it and use <span className="font-semibold">Reconcile</span>.
              </p>
            </div>

            {/* Top rentals by net collected */}
            <SectionCard
              title="Top rentals — net collected"
              actions={<span className="text-xs text-slate-400">{num(data.counts.rentals)} total{data.counts.rentals > data.list_cap ? ` · showing top ${data.list_cap}` : ''}</span>}
            >
              <DataTable
                rows={data.rentals}
                rowKey={(r) => r.id}
                empty="No rentals returned this month."
                columns={[
                  {
                    key: 'contract', header: 'Contract', cellClass: 'font-medium',
                    render: (r) => (
                      <>
                        <Link to={`/contracts/${r.id}`} className="text-indigo-600 hover:text-indigo-700">#{r.contract_no || r.id}</Link>
                        {r.vehicle && <span className="ml-2 text-xs text-slate-400">{r.vehicle}</span>}
                      </>
                    ),
                  },
                  { key: 'customer', header: 'Customer', render: (r) => r.customer || '—' },
                  { key: 'returned', header: 'Returned', cellClass: 'text-slate-500', render: (r) => fmtDate(r.in_date) },
                  { key: 'billed', header: 'Billed', align: 'right', cellClass: 'tabular-nums text-slate-500', render: (r) => aed2(r.billed) },
                  {
                    key: 'net', header: 'Net Collected', align: 'right', tooltip: 'Cash collected after refunds.',
                    cellClass: 'tabular-nums font-semibold text-emerald-600', render: (r) => aed2(r.net_collected),
                  },
                ]}
              />
            </SectionCard>

            {/* Top maintenance by cost */}
            <SectionCard
              title="Top maintenance — cost"
              actions={<span className="text-xs text-slate-400">{num(data.counts.maintenance)} total{data.counts.maintenance > data.list_cap ? ` · showing top ${data.list_cap}` : ''}</span>}
            >
              <DataTable
                rows={data.maintenance}
                rowKey={(r) => r.id}
                empty="No maintenance contracts closed this month."
                columns={[
                  {
                    key: 'contract', header: 'Contract', cellClass: 'font-medium',
                    render: (m) => <Link to={`/contracts/${m.id}`} className="text-indigo-600 hover:text-indigo-700">#{m.contract_no || m.id}</Link>,
                  },
                  { key: 'vehicle', header: 'Vehicle', render: (m) => m.vehicle || '—' },
                  { key: 'closed', header: 'Closed', cellClass: 'text-slate-500', render: (m) => fmtDate(m.in_date) },
                  { key: 'cost', header: 'Cost', align: 'right', cellClass: 'tabular-nums font-semibold text-amber-600', render: (m) => aed2(m.cost) },
                ]}
              />
            </SectionCard>
          </>
        )}
      </div>
    </div>
  );
}
