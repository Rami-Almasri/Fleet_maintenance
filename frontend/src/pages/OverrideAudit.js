import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { num } from '../lib/format';

// "2026-06-22T12:41:51Z" -> "22 Jun 2026, 12:41"
const fmtDateTime = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d)) return String(v);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

/**
 * Override Audit — the transparency trail for the "Rental-First" policy. Every time a
 * manager knowingly opened a maintenance contract on a car that still had a live rental,
 * a row lands here: who allowed it, why (reason + notes), and which rental it broke.
 */
export default function OverrideAudit() {
  const fetcher = useCallback(async () => (await api.get('/Operations/overrides')).data.data, []);
  const { data, loading, error } = useFetch(fetcher);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const rows = data?.overrides || [];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Override Audit"
          subtitle='Every "Rental-First" override: a manager opened a maintenance contract on a car that still had a live rental. Who allowed it, why, and which rental it closed.'
        >
          <Link to="/vehicles" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Vehicles →</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">When</th>
                  <th className="px-6 py-3">Manager</th>
                  <th className="px-6 py-3">Car</th>
                  <th className="px-6 py-3">Reason</th>
                  <th className="px-6 py-3">Rental closed</th>
                  <th className="px-6 py-3">Maintenance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((r) => (
                  <tr key={r.id} className="align-top hover:bg-gray-50/60">
                    <td className="px-6 py-3 whitespace-nowrap text-gray-700">{fmtDateTime(r.created_at)}</td>
                    <td className="px-6 py-3 text-gray-700">{r.user_name || '—'}</td>
                    <td className="px-6 py-3">
                      {r.vehicle_id
                        ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                        : (r.plate || '—')}
                    </td>
                    <td className="px-6 py-3 text-gray-700">
                      <span className="font-medium text-gray-900">{r.reason_label || r.reason_code}</span>
                      {r.notes && <p className="mt-0.5 text-xs text-gray-500">{r.notes}</p>}
                    </td>
                    <td className="px-6 py-3 text-gray-600">{r.rental_contract_no || '—'}</td>
                    <td className="px-6 py-3 text-gray-600">{r.result_contract_no || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {rows.length === 0 && (
            <EmptyState
              title="No overrides yet"
              message="When a manager overrides the Rental-First block to open a maintenance contract on a rented car, it will appear here."
            />
          )}
          {rows.length > 0 && (
            <p className="px-6 py-3 text-xs text-gray-400">{num(rows.length)} override{rows.length === 1 ? '' : 's'} logged.</p>
          )}
        </Card>
      </div>
    </div>
  );
}
