import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { aed2, fmtDate, num } from '../lib/format';

// How alarming is "late by N days"?
const lateTone = (d) => (d >= 14 ? 'red' : d >= 4 ? 'amber' : 'gray');

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

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const rows = data || [];
  const totalBalance = rows.reduce((sum, r) => sum + (Number(r.balance) || 0), 0);
  const worst = rows.reduce((m, r) => Math.max(m, r.days_overdue || 0), 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Overdue Rentals" subtitle="Open rentals whose estimated return date (handover date + rental days) has already passed.">
          <Link to="/" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">← Dashboard</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Overdue rentals</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-red-600">{num(rows.length)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Most overdue</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{worst ? `${worst}d` : '—'}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Outstanding balance</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{aed2(totalBalance)}</p>
          </div>
        </div>

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Car</th>
                  <th className="px-6 py-3">Contract</th>
                  <th className="px-6 py-3">Customer</th>
                  <th className="px-6 py-3">Out</th>
                  <th className="px-6 py-3 text-center">Rental days</th>
                  <th className="px-6 py-3">Est. return</th>
                  <th className="px-6 py-3 text-center">Overdue</th>
                  <th className="px-6 py-3 text-right">Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-gray-50/60">
                    <td className="px-6 py-3">
                      {r.vehicle_id
                        ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                        : <span className="font-medium text-gray-700">{r.plate || '—'}</span>}
                      <div className="text-xs text-gray-400">{r.car || '—'}</div>
                    </td>
                    <td className="px-6 py-3">
                      <Link to={`/contracts/${r.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.contract_no ? `#${r.contract_no}` : `#${r.id}`}</Link>
                    </td>
                    <td className="px-6 py-3 text-gray-700">
                      {r.customer_id
                        ? <Link to={`/customers/${r.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{r.customer || '—'}</Link>
                        : (r.customer || '—')}
                    </td>
                    <td className="px-6 py-3 text-gray-500">{fmtDate(r.out_date)}</td>
                    <td className="px-6 py-3 text-center text-gray-500">{r.days}d</td>
                    <td className="px-6 py-3 text-gray-500">{fmtDate(r.due)}</td>
                    <td className="px-6 py-3 text-center"><Badge tone={lateTone(r.days_overdue)}>{r.days_overdue}d late</Badge></td>
                    <td className="px-6 py-3 text-right text-gray-700">{r.balance ? aed2(r.balance) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {rows.length === 0 && (
            <EmptyState title="No overdue rentals 🎉" message="Every rented car is still within its expected return window." />
          )}
        </Card>

        <p className="text-xs text-gray-400">
          Note: a rental only appears here once its planned <span className="font-medium">rental days</span> are known (filled in by the
          OfficeManager <span className="font-medium">Contracts</span> sync). Rentals not yet synced won't show until that data arrives.
        </p>
      </div>
    </div>
  );
}
