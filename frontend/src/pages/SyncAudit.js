import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { num } from '../lib/format';

const STATUS_TONE = {
  done: 'green', completed: 'green', partial: 'amber',
  failed: 'red', running: 'blue', 'dry-run': 'slate',
};

// "2026-06-20T12:41:51Z" -> "20 Jun 2026, 12:41"
const fmtDateTime = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d)) return String(v);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const cell = (v) => (v === null || v === undefined ? <span className="text-gray-300">—</span> : num(v));

function Detail({ runId }) {
  const fetcher = useCallback(async () => (await api.get(`/Sync/audit/${runId}`)).data.data, [runId]);
  const { data, loading, error } = useFetch(fetcher, [runId]);

  if (loading) return <div className="flex justify-center py-10"><Spinner className="h-6 w-6" /></div>;
  if (error) return <div className="px-6 py-4 text-sm text-red-600">{error}</div>;

  const corrections = data?.corrections || [];
  const run = data?.run || {};

  return (
    <div className="border-t border-gray-100 bg-gray-50/40">
      <div className="px-6 py-4">
        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
          What changed in this run
        </h4>
        <p className="mt-1 text-xs text-gray-500">
          {run.scanned != null && <>Scanned {num(run.scanned)} contracts · </>}
          Created {num(run.created)} · Updated {num(run.updated)} ·{' '}
          <span className="font-medium text-indigo-600">{num(run.corrections)} auto-corrections</span>
          {run.preserved ? <> · {num(run.preserved)} fields preserved (still had a value)</> : null}
        </p>

        {corrections.length === 0 ? (
          <p className="mt-3 text-sm text-gray-400">No stale fields needed clearing in this run.</p>
        ) : (
          <div className="mt-3 overflow-hidden rounded-xl border border-gray-100 bg-white">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-4 py-2">Contract</th>
                  <th className="px-4 py-2">Change</th>
                  <th className="px-4 py-2">Previous value</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {corrections.map((c, i) => (
                  <tr key={i} className="hover:bg-gray-50/60">
                    <td className="px-4 py-2">
                      {c.contract_id
                        ? <Link to={`/contracts/${c.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.contract_id}</Link>
                        : <span className="font-medium text-gray-700">#{c.contract_no || '—'}</span>}
                    </td>
                    <td className="px-4 py-2 text-gray-700">
                      <code className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700">{c.field}</code>
                      <Badge tone="amber" className="ml-2 align-middle">{c.action}</Badge>
                    </td>
                    <td className="px-4 py-2 text-gray-500">{c.old_value || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data?.corrections_shown < run.corrections && (
              <div className="border-t border-gray-100 px-4 py-2 text-xs text-gray-400">
                Showing the first {num(data.corrections_shown)} of {num(run.corrections)}.
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export default function SyncAudit() {
  const fetcher = useCallback(async () => (await api.get('/Sync/audit')).data.data, []);
  const { data, loading, error } = useFetch(fetcher);
  const [openId, setOpenId] = useState(null);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const runs = data?.runs || [];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Sync Audit Report"
          subtitle="What each CMD sync execution scanned, updated, and auto-corrected. Run the sync from the command line, then refresh this page."
        >
          <Link to="/sync" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Data Sync →</Link>
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
                  <th className="px-6 py-3">Run</th>
                  <th className="px-6 py-3 text-right">Scanned</th>
                  <th className="px-6 py-3 text-right">Updates</th>
                  <th className="px-6 py-3 text-right">Corrections</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {runs.map((r) => {
                  const isOpen = openId === r.id;
                  return [
                    <tr key={r.id} className="cursor-pointer hover:bg-gray-50/60" onClick={() => setOpenId(isOpen ? null : r.id)}>
                      <td className="px-6 py-3 text-gray-700">{fmtDateTime(r.started_at)}</td>
                      <td className="px-6 py-3 text-gray-600">{r.action || '—'}</td>
                      <td className="px-6 py-3 text-right text-gray-700">{cell(r.scanned)}</td>
                      <td className="px-6 py-3 text-right text-gray-700">{cell(r.updated)}</td>
                      <td className="px-6 py-3 text-right">
                        {r.corrections > 0
                          ? <Badge tone="indigo" className="font-semibold">{num(r.corrections)}</Badge>
                          : <span className="text-gray-300">0</span>}
                      </td>
                      <td className="px-6 py-3"><Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status}</Badge></td>
                      <td className="px-6 py-3 text-right text-xs font-medium text-indigo-600">{isOpen ? 'Hide' : 'Details'}</td>
                    </tr>,
                    isOpen ? <tr key={`${r.id}-d`}><td colSpan="7" className="p-0"><Detail runId={r.id} /></td></tr> : null,
                  ];
                })}
              </tbody>
            </table>
          </div>
          {runs.length === 0 && (
            <EmptyState title="No sync runs yet" message="Run the CMD sync (php artisan om:sync --contracts) and refresh to see the audit here." />
          )}
        </Card>
      </div>
    </div>
  );
}
