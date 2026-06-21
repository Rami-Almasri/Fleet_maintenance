import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { useToast } from '../components/ui/Toast';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { aed2, fmtDate, num } from '../lib/format';

const SEV = {
  critical: { tone: 'red', dot: 'bg-red-500', label: 'Conflict', ring: 'ring-red-200', head: 'bg-red-50/60 border-red-100 text-red-700' },
  warning: { tone: 'amber', dot: 'bg-amber-500', label: 'Gap', ring: 'ring-amber-200', head: 'bg-amber-50/60 border-amber-100 text-amber-700' },
  review: { tone: 'indigo', dot: 'bg-indigo-500', label: 'Review', ring: 'ring-indigo-200', head: 'bg-indigo-50/60 border-indigo-100 text-indigo-700' },
};

const gapLabel = (h) => (h === 0 ? 'same day' : h < 0 ? `${Math.abs(h)}h overlap` : `${h}h gap`);

// Interactive group for "Pending Exchange Links" — each row is a parent→child swap the
// staff can approve (link) and optionally record the carried balance for.
function ExchangeGroup({ g }) {
  const toast = useToast();
  const sev = SEV.review;
  const [done, setDone] = useState({}); // child_id -> 'linked'
  const [busy, setBusy] = useState(0);

  const link = async (it, carry) => {
    setBusy(it.child_id);
    try {
      await api.post(`/Contract/${it.child_id}/exchange/link`, { parent_id: it.parent_id, carry });
      toast.success(carry === 'both' ? `Linked · ${aed2(it.carried_total)} recorded to carry` : 'Exchange linked');
      setDone((d) => ({ ...d, [it.child_id]: 'linked' }));
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not link');
    } finally {
      setBusy(0);
    }
  };

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
              <th className="px-6 py-3">Customer</th>
              <th className="px-6 py-3">Returned → Took</th>
              <th className="px-6 py-3">Swap</th>
              <th className="px-6 py-3 text-right">Carry-over</th>
              <th className="px-6 py-3 text-right">Approve</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {g.items.map((it) => {
              const isDone = done[it.child_id];
              return (
                <tr key={it.child_id} className="hover:bg-gray-50/60">
                  <td className="px-6 py-3">
                    {it.customer_id
                      ? <Link to={`/customers/${it.customer_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.customer || '—'}</Link>
                      : (it.customer || '—')}
                  </td>
                  <td className="px-6 py-3 text-gray-700">
                    <Link to={`/contracts/${it.parent_id}`} className="text-indigo-600 hover:text-indigo-700">{it.parent_vehicle}</Link>
                    <span className="mx-1.5 text-gray-400">→</span>
                    <Link to={`/contracts/${it.child_id}`} className="text-indigo-600 hover:text-indigo-700">{it.child_vehicle}</Link>
                    <div className="text-xs text-gray-400">returned {fmtDate(it.returned_on)} · took {fmtDate(it.picked_up_on)}{it.held_days > 0 ? ` · held ${it.held_days}d` : ''}</div>
                  </td>
                  <td className="px-6 py-3">
                    <Badge tone="slate">{gapLabel(it.gap_hours)}</Badge>
                  </td>
                  <td className="px-6 py-3 text-right">
                    {it.carried_total > 0
                      ? <Badge tone="emerald" className="font-semibold">{aed2(it.carried_total)}</Badge>
                      : <span className="text-gray-300">—</span>}
                  </td>
                  <td className="px-6 py-3 text-right">
                    {isDone ? (
                      <Badge tone="green" className="font-semibold">Linked ✓</Badge>
                    ) : (
                      <div className="flex justify-end gap-2">
                        {it.carried_total > 0 && (
                          <Button size="sm" variant="success" loading={busy === it.child_id} disabled={!!busy} onClick={() => link(it, 'both')}>
                            Link &amp; Carry
                          </Button>
                        )}
                        <Button size="sm" variant={it.carried_total > 0 ? 'secondary' : 'primary'} loading={busy === it.child_id} disabled={!!busy} onClick={() => link(it, 'none')}>
                          Link
                        </Button>
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
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
              <th className="px-6 py-3">Customer</th>
              <th className="px-6 py-3">What's wrong</th>
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
                <td className="px-6 py-3 text-gray-700">
                  {it.customer_id
                    ? <Link to={`/customers/${it.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{it.customer || '—'}</Link>
                    : (it.customer || <span className="text-gray-300">—</span>)}
                </td>
                <td className="px-6 py-3 text-gray-600">
                  {it.tag && <Badge tone="red" className="mr-2 align-middle font-semibold">{it.tag}</Badge>}
                  {it.detail}
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

export default function Anomalies() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Anomalies');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all flagged cases, the share that are critical
  // (impossible states) vs. lower-severity cleanup items.
  const totalCases = data?.total_cases || 0;
  const critCases = (data?.groups || []).filter((g) => g.severity === 'critical').reduce((s, g) => s + g.count, 0);
  usePageStat({
    percent: loading || !totalCases ? null : (critCases / totalCases) * 100,
    label: 'Critical',
    color: 'red',
    hint: `${critCases} of ${totalCases} flagged cases are critical (impossible states)`,
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const allGroups = (data?.groups || []).filter((g) => g.count > 0);
  // Exchange suggestions are a review queue, not anomalies — render them separately.
  const exchangeGroups = allGroups.filter((g) => g.severity === 'review');
  const groups = allGroups.filter((g) => g.severity !== 'review');
  const criticalCases = groups.filter((g) => g.severity === 'critical').reduce((s, g) => s + g.count, 0);
  const warningCases = groups.filter((g) => g.severity === 'warning').reduce((s, g) => s + g.count, 0);
  const reviewCases = data?.review_cases || exchangeGroups.reduce((s, g) => s + g.count, 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Exceptional Cases" subtitle="Data conflicts and operational gaps to review — impossible states first, then things worth cleaning up." />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Total cases</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{num(data?.total_cases)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">🔴 Conflicts</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-red-600">{num(criticalCases)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">🟡 Gaps</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-amber-600">{num(warningCases)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">🔁 Pending exchanges</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-indigo-600">{num(reviewCases)}</p>
          </div>
        </div>

        {exchangeGroups.map((g) => <ExchangeGroup key={g.key} g={g} />)}

        {groups.length === 0
          ? <Card><EmptyState title="No exceptional cases 🎉" message="Every car and contract looks consistent." /></Card>
          : groups.map((g) => <Group key={g.key} g={g} />)}
      </div>
    </div>
  );
}
