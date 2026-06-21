import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { aed2, fmtDate, num } from '../lib/format';

export default function MaintenanceApprovals() {
  const toast = useToast();
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/approvals');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);
  const [approving, setApproving] = useState(0);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const jobs = data?.jobs || [];
  const threshold = data?.threshold || 500;
  const totalPending = jobs.reduce((s, j) => s + (Number(j.cost) || 0), 0);

  const approve = async (job) => {
    if (!window.confirm(`Approve this ${aed2(job.cost)} maintenance bill for ${job.plate || '#' + job.id}?`)) return;
    setApproving(job.id);
    try {
      await api.post(`/Maintenance/${job.id}/approve`);
      toast.success('Bill approved');
      setTimeout(reload, 500);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not approve');
    } finally {
      setApproving(0);
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Maintenance Approvals" subtitle={`Jobs over AED ${num(threshold)} need your sign-off before they're treated as final.`}>
          <Link to="/maintenance" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Maintenance board →</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Awaiting approval</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-amber-600">{num(jobs.length)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Total pending value</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{aed2(totalPending)}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Threshold</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{aed2(threshold)}</p>
          </div>
        </div>

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Car</th>
                  <th className="px-6 py-3">Garage</th>
                  <th className="px-6 py-3">Work</th>
                  <th className="px-6 py-3">Date</th>
                  <th className="px-6 py-3 text-right">Total</th>
                  <th className="px-6 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {jobs.map((j) => (
                  <tr key={j.id} className="hover:bg-gray-50/60">
                    <td className="px-6 py-3">
                      <Link to={`/contracts/${j.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{j.plate || `#${j.contract_no || j.id}`}</Link>
                      <div className="text-xs text-gray-400">{j.car || '—'}</div>
                    </td>
                    <td className="px-6 py-3 text-gray-700">{j.garage || <span className="text-gray-300">—</span>}</td>
                    <td className="px-6 py-3">
                      {j.reason && <Badge tone="indigo">{j.reason}</Badge>}
                      <div className="mt-1 space-y-0.5 text-xs text-gray-500">
                        {(j.items || []).slice(0, 4).map((it, i) => (
                          <div key={i}>{it.service || '—'} · {aed2(it.cost)}</div>
                        ))}
                      </div>
                    </td>
                    <td className="px-6 py-3 text-gray-500">{fmtDate(j.date)}</td>
                    <td className="px-6 py-3 text-right font-semibold text-gray-900">{aed2(j.cost)}</td>
                    <td className="px-6 py-3 text-right">
                      <Button size="sm" loading={approving === j.id} disabled={!!approving} onClick={() => approve(j)}>
                        Approve
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {jobs.length === 0 && (
            <EmptyState title="Nothing to approve 🎉" message={`No maintenance jobs above AED ${num(threshold)} are pending.`} />
          )}
        </Card>
      </div>
    </div>
  );
}
