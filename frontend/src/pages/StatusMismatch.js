import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { num } from '../lib/format';

const SEV = {
  critical: { tone: 'red', dot: 'bg-red-500', label: 'Car is out', ring: 'ring-red-200', head: 'bg-red-50/60 border-red-100 text-red-700' },
  warning: { tone: 'amber', dot: 'bg-amber-500', label: 'Stale status', ring: 'ring-amber-200', head: 'bg-amber-50/60 border-amber-100 text-amber-700' },
};

function Group({ g }) {
  const sev = SEV[g.severity] || SEV.warning;
  return (
    <Card className={`ring-1 ${sev.ring}`}>
      <div className={`border-b px-6 py-4 ${sev.head}`}>
        <div className="flex items-center justify-between gap-4">
          <h3 className="flex items-center gap-2 text-base font-semibold">
            <span className={`h-2.5 w-2.5 rounded-full ${sev.dot}`} />
            {g.title}
            <Badge tone={sev.tone}>{num(g.count)}</Badge>
          </h3>
          <span className="text-xs font-medium uppercase tracking-wide opacity-70">{sev.label}</span>
        </div>
        <p className="mt-1 text-xs font-normal text-gray-500">{g.description}</p>
      </div>

      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-gray-100 text-sm">
          <thead className="bg-gray-50/60">
            <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <th className="px-6 py-3">Car</th>
              <th className="px-6 py-3">Vehicle status</th>
              <th className="px-6 py-3">Contract reality</th>
              <th className="px-6 py-3">Customer</th>
              <th className="px-6 py-3 text-right">Open</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {g.items.map((it, i) => (
              <tr key={i} className="hover:bg-gray-50/60">
                <td className="px-6 py-3">
                  {it.vehicle_id
                    ? <Link to={`/vehicles/${it.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.plate || `#${it.vehicle_id}`}</Link>
                    : <span className="text-gray-400">{it.plate || '—'}</span>}
                  <div className="text-xs text-gray-400">{it.car || '—'}</div>
                </td>
                <td className="px-6 py-3">
                  <Badge tone={sev.tone}>{it.vehicle_status}</Badge>
                </td>
                <td className="px-6 py-3 text-gray-600">{it.contract_state}</td>
                <td className="px-6 py-3 text-gray-700">
                  {it.customer_id
                    ? <Link to={`/customers/${it.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{it.customer || '—'}</Link>
                    : (it.customer || <span className="text-gray-300">—</span>)}
                </td>
                <td className="px-6 py-3 text-right">
                  {it.contract_id
                    ? <Link to={`/contracts/${it.contract_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Contract →</Link>
                    : (it.vehicle_id ? <Link to={`/vehicles/${it.vehicle_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Car →</Link> : <span className="text-gray-300">—</span>)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {g.shown < g.count && (
        <div className="border-t border-gray-100 px-6 py-2 text-xs text-gray-400">
          Showing the first {num(g.shown)} of {num(g.count)}.
        </div>
      )}
    </Card>
  );
}

export default function StatusMismatch({ embedded = false }) {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/StatusMismatch');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all mismatches, the share that are critical
  // (out on a contract but not flagged busy) vs. merely stale status.
  const allGroups = (data?.groups || []).filter((g) => g.count > 0);
  const outTotal = allGroups.filter((g) => g.severity === 'critical').reduce((s, g) => s + g.count, 0);
  const mismatchTotal = allGroups.reduce((s, g) => s + g.count, 0);
  usePageStat({
    percent: loading || !mismatchTotal ? null : (outTotal / mismatchTotal) * 100,
    label: 'Out, wrong',
    color: 'red',
    hint: `${outTotal} of ${mismatchTotal} mismatches are cars out on a contract but not flagged busy`,
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const groups = (data?.groups || []).filter((g) => g.count > 0);
  const outCases = groups.filter((g) => g.severity === 'critical').reduce((s, g) => s + g.count, 0);
  const staleCases = groups.filter((g) => g.severity === 'warning').reduce((s, g) => s + g.count, 0);

  const inner = (
    <>
      {error && (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Total mismatches</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{num(data?.total_mismatches)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">🔴 Out, status wrong</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-red-600">{num(outCases)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">🟡 Stale (no contract)</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-amber-600">{num(staleCases)}</p>
          </div>
        </div>

      {groups.length === 0
        ? <Card><EmptyState title="No status mismatches 🎉" message="Every car's status matches its open contracts." /></Card>
        : groups.map((g) => <Group key={g.key} g={g} />)}
    </>
  );

  if (embedded) return inner;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Status Mismatches" subtitle="Cars whose status doesn't match their contracts — out on a contract but not flagged busy, or flagged busy with no open contract." />
        {inner}
      </div>
    </div>
  );
}
