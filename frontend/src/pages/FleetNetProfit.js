import { useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { Card, PageHeader, Spinner } from '../components/ui/Misc';
import { aed2, fmtDate, num } from '../lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// A headline metric tile.
function Metric({ label, value, tone = 'text-slate-900', hint, big }) {
  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-1 font-bold tracking-tight tabular-nums ${big ? 'text-3xl' : 'text-2xl'} ${tone}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}

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
          <button onClick={() => step(-1)} className="rounded-lg border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50" aria-label="Previous month">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          </button>
          <span className="min-w-[10rem] text-center text-lg font-semibold text-slate-800">{MONTHS[month - 1]} {year}</span>
          <button onClick={() => step(1)} disabled={isCurrent} className="rounded-lg border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50 disabled:opacity-40" aria-label="Next month">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
          </button>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}
        {loading && <div className="flex justify-center py-16"><Spinner className="h-8 w-8" /></div>}

        {data && t && (
          <>
            {/* Headline */}
            <Card className={`ring-1 ${netPositive ? 'ring-emerald-200' : 'ring-red-200'}`}>
              <div className="px-6 py-6 text-center">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Fleet Net Profit · {data.period.label}</p>
                <p className={`mt-1 text-4xl font-bold tracking-tight tabular-nums ${netPositive ? 'text-emerald-600' : 'text-red-600'}`}>
                  {aed2(t.net_profit)}
                </p>
                <p className="mt-2 text-sm text-slate-500">
                  Net collected <span className="font-semibold text-slate-700">{aed2(t.net_collected)}</span>
                  {' − '}Maintenance cost <span className="font-semibold text-slate-700">{aed2(t.maintenance_cost)}</span>
                </p>
              </div>
            </Card>

            {/* Components */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Metric label="Net Collected" value={aed2(t.net_collected)} tone="text-emerald-600" hint={`${num(data.counts.rentals)} rentals returned · billed ${aed2(t.billed)}`} />
              <Metric label="Maintenance Cost" value={aed2(t.maintenance_cost)} tone="text-amber-600" hint={`${num(data.counts.maintenance)} maintenance contracts closed`} />
              <Metric label="Net Profit" value={aed2(t.net_profit)} tone={netPositive ? 'text-emerald-600' : 'text-red-600'} big hint={t.workshop_cost ? `Workshop-log spend (ref): ${aed2(t.workshop_cost)}` : 'Cash basis'} />
            </div>

            {/* Basis note */}
            <div className="rounded-xl bg-blue-50/60 px-4 py-3 text-xs text-blue-700 ring-1 ring-inset ring-blue-600/15">
              The fleet rollup uses <span className="font-semibold">synced figures</span> (net collected ≈ recorded collected − refunds), not a live accounting call per contract — so it stays fast at fleet scale.
              To verify the <span className="font-semibold">real cash</span> for any single contract, open it and use <span className="font-semibold">Reconcile</span>.
            </div>

            {/* Top rentals by net collected */}
            <Card>
              <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <h3 className="text-base font-semibold text-slate-900">Top rentals — net collected</h3>
                <span className="text-xs text-slate-400">{num(data.counts.rentals)} total{data.counts.rentals > data.list_cap ? ` · showing top ${data.list_cap}` : ''}</span>
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                  <thead className="bg-slate-50/60">
                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="px-6 py-3">Contract</th>
                      <th className="px-6 py-3">Customer</th>
                      <th className="px-6 py-3">Returned</th>
                      <th className="px-6 py-3 text-right">Billed</th>
                      <th className="px-6 py-3 text-right">Net Collected</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {data.rentals.map((r) => (
                      <tr key={r.id} className="hover:bg-slate-50/60">
                        <td className="px-6 py-3 font-medium">
                          <Link to={`/contracts/${r.id}`} className="text-indigo-600 hover:text-indigo-700">#{r.contract_no || r.id}</Link>
                          {r.vehicle && <span className="ml-2 text-xs text-slate-400">{r.vehicle}</span>}
                        </td>
                        <td className="px-6 py-3 text-slate-600">{r.customer || '—'}</td>
                        <td className="px-6 py-3 text-slate-500">{fmtDate(r.in_date)}</td>
                        <td className="px-6 py-3 text-right tabular-nums text-slate-500">{aed2(r.billed)}</td>
                        <td className="px-6 py-3 text-right tabular-nums font-semibold text-emerald-600">{aed2(r.net_collected)}</td>
                      </tr>
                    ))}
                    {data.rentals.length === 0 && <tr><td colSpan="5" className="px-6 py-8 text-center text-slate-400">No rentals returned this month.</td></tr>}
                  </tbody>
                </table>
              </div>
            </Card>

            {/* Top maintenance by cost */}
            <Card>
              <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <h3 className="text-base font-semibold text-slate-900">Top maintenance — cost</h3>
                <span className="text-xs text-slate-400">{num(data.counts.maintenance)} total{data.counts.maintenance > data.list_cap ? ` · showing top ${data.list_cap}` : ''}</span>
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                  <thead className="bg-slate-50/60">
                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="px-6 py-3">Contract</th>
                      <th className="px-6 py-3">Vehicle</th>
                      <th className="px-6 py-3">Closed</th>
                      <th className="px-6 py-3 text-right">Cost</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {data.maintenance.map((m) => (
                      <tr key={m.id} className="hover:bg-slate-50/60">
                        <td className="px-6 py-3 font-medium">
                          <Link to={`/contracts/${m.id}`} className="text-indigo-600 hover:text-indigo-700">#{m.contract_no || m.id}</Link>
                        </td>
                        <td className="px-6 py-3 text-slate-600">{m.vehicle || '—'}</td>
                        <td className="px-6 py-3 text-slate-500">{fmtDate(m.in_date)}</td>
                        <td className="px-6 py-3 text-right tabular-nums font-semibold text-amber-600">{aed2(m.cost)}</td>
                      </tr>
                    ))}
                    {data.maintenance.length === 0 && <tr><td colSpan="4" className="px-6 py-8 text-center text-slate-400">No maintenance contracts closed this month.</td></tr>}
                  </tbody>
                </table>
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
