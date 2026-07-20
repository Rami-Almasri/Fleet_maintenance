import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
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
          <Link to="/maintenance-workflow" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Maintenance board →</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <MetricGrid cols={3}>
          <MetricCard label="Awaiting approval" value={num(jobs.length)} tone="amber" />
          <MetricCard label="Total pending value" value={aed2(totalPending)} tone="slate" />
          <MetricCard label="Threshold" value={aed2(threshold)} tone="slate" />
        </MetricGrid>

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Car</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Garage</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Work</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Date</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right">Total</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                {jobs.map((j) => (
                  <tr key={j.id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <Link to={`/contracts/${j.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{j.plate || `#${j.contract_no || j.id}`}</Link>
                      <div className="text-xs text-slate-400">{j.car || '—'}</div>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{j.garage || <span className="text-slate-300">—</span>}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      {j.reason && <Badge tone="indigo">{j.reason}</Badge>}
                      <div className="mt-1 space-y-0.5 text-xs text-slate-500">
                        {(j.items || []).slice(0, 4).map((it, i) => (
                          <div key={i}>{it.service || '—'} · {aed2(it.cost)}</div>
                        ))}
                      </div>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(j.date)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-right font-semibold tabular-nums text-slate-900">{aed2(j.cost)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-right">
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
