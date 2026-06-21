import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { num } from '../lib/format';

const SEV = {
  critical: { tone: 'red', dot: 'bg-red-500', label: 'Must fix', ring: 'ring-red-200', head: 'bg-red-50/60 border-red-100 text-red-700' },
  warning: { tone: 'amber', dot: 'bg-amber-500', label: 'Incomplete', ring: 'ring-amber-200', head: 'bg-amber-50/60 border-amber-100 text-amber-700' },
  info: { tone: 'blue', dot: 'bg-blue-500', label: 'Nice to fill', ring: 'ring-blue-200', head: 'bg-blue-50/60 border-blue-100 text-blue-700' },
};

// The single best "open" link for a row: contract → car → customer.
function RowLink({ it }) {
  if (it.contract_id) return <Link to={`/contracts/${it.contract_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Contract →</Link>;
  if (it.vehicle_id) return <Link to={`/vehicles/${it.vehicle_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Car →</Link>;
  if (it.customer_id) return <Link to={`/customers/${it.customer_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Customer →</Link>;
  return <span className="text-gray-300">—</span>;
}

function Record({ it }) {
  // A car-based row, a customer-based row, or a bare contract row.
  if (it.vehicle_id || it.plate) {
    return (
      <div>
        {it.vehicle_id
          ? <Link to={`/vehicles/${it.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.plate || `#${it.vehicle_id}`}</Link>
          : <span className="font-medium text-gray-700">{it.plate || '—'}</span>}
        <div className="text-xs text-gray-400">{it.car || '—'}{it.contract_no ? ` · contract ${it.contract_no}` : ''}</div>
      </div>
    );
  }
  if (it.customer_id) {
    return (
      <Link to={`/customers/${it.customer_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.customer || `Customer #${it.customer_id}`}</Link>
    );
  }
  return <span className="text-gray-500">{it.contract_no ? `Contract ${it.contract_no}` : '—'}</span>;
}

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
              <th className="px-6 py-3">Record</th>
              <th className="px-6 py-3">What's missing</th>
              <th className="px-6 py-3 text-right">Open</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {g.items.map((it, i) => (
              <tr key={i} className="hover:bg-gray-50/60">
                <td className="px-6 py-3"><Record it={it} /></td>
                <td className="px-6 py-3 text-gray-600">{it.detail}</td>
                <td className="px-6 py-3 text-right"><RowLink it={it} /></td>
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

function Stat({ label, value, tone = 'text-gray-900' }) {
  return (
    <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
      <p className="text-xs font-medium text-gray-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
    </div>
  );
}

export default function DataHealth() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/DataHealth');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all data issues, the share that are must-fix.
  const totalIssues = data?.total_issues || 0;
  const mustFix = data?.critical || 0;
  usePageStat({
    percent: loading || !totalIssues ? null : (mustFix / totalIssues) * 100,
    label: 'Must-fix',
    color: 'red',
    hint: `${mustFix} of ${totalIssues} data issues are must-fix`,
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const groups = (data?.groups || []).filter((g) => g.count > 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Data Health" subtitle="Incomplete or broken records to clean up — missing VINs, mileage, unlinked contracts and more. Fix these to keep the fleet data reliable." />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label="Total issues" value={num(data?.total_issues)} />
          <Stat label="🔴 Must fix" value={num(data?.critical)} tone="text-red-600" />
          <Stat label="🟡 Incomplete" value={num(data?.warning)} tone="text-amber-600" />
          <Stat label="🔵 Nice to fill" value={num(data?.info)} tone="text-blue-600" />
        </div>

        {groups.length === 0
          ? <Card><EmptyState title="All clean 🎉" message="Every car, contract and customer has its key fields filled in." /></Card>
          : groups.map((g) => <Group key={g.key} g={g} />)}
      </div>
    </div>
  );
}
