import { useCallback, useEffect, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import { Card, PageHeader } from '../components/ui/Misc';
import { num } from '../lib/format';

// READ-ONLY monitoring dashboard. Syncs are NOT triggered from the browser anymore — they run
// on the server via the CLI runner (sync-fleet) on a schedule. This page only WATCHES: live
// counts, the running job's progress, the active OS processes, and the recent-run history.
// (Every CLI/scheduled run writes a SyncRun row, so it all shows up here automatically.)

const STAT_TONES = {
  blue: 'bg-blue-50 text-blue-600',
  indigo: 'bg-indigo-50 text-indigo-600',
  violet: 'bg-violet-50 text-violet-600',
  cyan: 'bg-cyan-50 text-cyan-600',
  emerald: 'bg-emerald-50 text-emerald-600',
  amber: 'bg-amber-50 text-amber-600',
  gray: 'bg-gray-100 text-gray-500',
};

function Stat({ label, value, sub, icon, tone = 'gray' }) {
  return (
    <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5 transition hover:shadow-md">
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium text-gray-500">{label}</p>
        {icon && (
          <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${STAT_TONES[tone] || STAT_TONES.gray}`}>
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
          </span>
        )}
      </div>
      <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{value}</p>
      {sub && <p className="mt-0.5 text-xs text-gray-400">{sub}</p>}
    </div>
  );
}

// "2026-06-13 11:00:00" (server local time) -> ms; tolerant of nulls.
function toMs(dt) {
  if (!dt) return NaN;
  return new Date(String(dt).replace(' ', 'T')).getTime();
}

function fmtSecs(sec) {
  if (sec == null || !isFinite(sec) || sec < 0) return '—';
  sec = Math.round(sec);
  if (sec < 60) return `${sec}s`;
  const m = Math.floor(sec / 60), s = sec % 60;
  if (m < 60) return s ? `${m}m ${s}s` : `${m}m`;
  return `${Math.floor(m / 60)}h ${m % 60}m`;
}

// Estimate seconds remaining from elapsed time and the processed/total rate.
function etaSeconds(run) {
  if (!run || !run.total || run.processed <= 0) return null;
  const elapsed = (Date.now() - toMs(run.started_at)) / 1000;
  if (!isFinite(elapsed) || elapsed <= 0) return null;
  const rate = run.processed / elapsed; // records/sec
  if (rate <= 0) return null;
  return (run.total - run.processed) / rate;
}

function CurrentRun({ run }) {
  const pct = run.total ? Math.min(100, Math.round((run.processed / run.total) * 100)) : 0;
  const eta = etaSeconds(run);
  return (
    <Card className="border-amber-200 bg-amber-50/50 p-6">
      <div className="flex items-end justify-between gap-4">
        <div className="min-w-0">
          <h3 className="text-base font-semibold text-gray-900">⏳ {run.action || 'Sync'} running…</h3>
          {run.phase && <p className="mt-0.5 text-xs text-gray-500">Now: <span className="font-medium text-gray-700">{run.phase}</span></p>}
        </div>
        <div className="shrink-0 text-right">
          <p className="text-2xl font-bold tracking-tight text-amber-600">{pct}%</p>
          <p className="text-xs text-gray-500">{eta != null ? `~${fmtSecs(eta)} left` : 'estimating…'}</p>
        </div>
      </div>
      <div className="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-amber-100">
        <div className="h-full rounded-full bg-amber-500 transition-all duration-700" style={{ width: `${pct}%` }} />
      </div>
      <p className="mt-2 text-xs text-gray-500">
        {num(run.processed)} / {run.total ? num(run.total) : '…'} records
      </p>
    </Card>
  );
}

function RecentRuns({ runs }) {
  if (!Array.isArray(runs) || runs.length === 0) return null;
  return (
    <Card className="p-6">
      <h3 className="mb-3 text-base font-semibold text-gray-900">Recent syncs — what changed</h3>
      <ul className="divide-y divide-gray-100">
        {runs.map((r, i) => (
          <li key={i} className="flex items-start justify-between gap-4 py-3">
            <div className="min-w-0">
              <p className="flex items-center gap-2 text-sm font-medium text-gray-900">
                {r.status === 'done'
                  ? <Badge tone="green">done</Badge>
                  : r.status === 'failed'
                    ? <Badge tone="red">failed</Badge>
                    : r.status === 'partial'
                      ? <Badge tone="amber">partial</Badge>
                      : <Badge tone="amber">{r.status}</Badge>}
                {r.action}
              </p>
              {/* On a partial run both matter: what saved (changed) and what failed (error). */}
              {r.changed && r.changed !== 'no changes' && (
                <p className="mt-0.5 break-words text-xs text-gray-500">{r.changed}</p>
              )}
              {r.error && (
                <p className="mt-0.5 break-words text-xs text-red-600">{r.error}</p>
              )}
              {!r.error && (!r.changed || r.changed === 'no changes') && (
                <p className="mt-0.5 break-words text-xs text-gray-500">{r.changed}</p>
              )}
            </div>
            <div className="shrink-0 text-right text-xs text-gray-400">
              <p>{r.finished_at ? String(r.finished_at).slice(0, 16).replace('T', ' ') : ''}</p>
              {r.seconds != null && <p>took {fmtSecs(r.seconds)}</p>}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export default function Sync() {
  const toast = useToast();
  const fetcher = useCallback(async () => (await api.get('/Sync/status')).data.data || {}, []);
  const { data, loading, reload } = useFetch(fetcher);
  const [killing, setKilling] = useState(0);

  const s = data || {};
  const procs = s.processes || [];
  const jobsActive = procs.length > 0;

  // poll the status every 5s so the counts climb live while a sync runs
  useEffect(() => {
    const t = setInterval(reload, 5000);
    return () => clearInterval(t);
  }, [reload]);

  const [preview, setPreview] = useState(null);     // { endpoint, total, sample }
  const [previewing, setPreviewing] = useState('');
  const [previewErr, setPreviewErr] = useState('');

  const loadPreview = async (endpoint) => {
    setPreviewing(endpoint);
    setPreviewErr('');
    try {
      const { data: res } = await api.get('/Sync/preview', { params: { endpoint } });
      setPreview(res.data);
    } catch (e) {
      setPreview(null);
      setPreviewErr(e.response?.data?.message || 'Could not reach the API');
    } finally {
      setPreviewing('');
    }
  };

  // Stopping a clearly-stuck job is an ops safety action (it never starts or deletes data).
  const killProc = async (p) => {
    if (!window.confirm(`Stop "${p.job}" (PID ${p.pid})? This only ends a stuck job — it never deletes data, and the job is safe to re-run.`)) return;
    setKilling(p.pid);
    try {
      const { data: res } = await api.post('/Sync/kill', { pid: p.pid });
      toast.success(res.message || 'Process stopped');
      setTimeout(reload, 800);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not stop process');
    } finally {
      setKilling(0);
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Data Sync — Monitor" subtitle="Read-only dashboard. Syncs run on the server on a schedule; this page shows live status, progress and history.">
          {jobsActive || s.sync_running
            ? <Badge tone="amber">⏳ {jobsActive ? `${procs.length} job${procs.length > 1 ? 's' : ''} running…` : 'a sync is running…'}</Badge>
            : <Badge tone="green">idle</Badge>}
        </PageHeader>

        {/* Read-only notice — syncing happens via the CLI runner, not the browser. */}
        <Card className="border-blue-200 bg-blue-50/50 p-5">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 16v-4m0-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
            </span>
            <div className="text-sm text-gray-700">
              <p className="font-semibold text-gray-900">This page is read-only.</p>
              <p className="mt-0.5 text-gray-600">
                Data syncs run on the server through the scheduled <span className="font-mono text-xs">sync-fleet</span> runner (CLI), which is far more reliable than the browser for long jobs. Everything those runs do shows up here live — counts, progress and history. To start a sync manually, run <span className="font-mono text-xs">sync-fleet.cmd</span> on the server (see <span className="font-mono text-xs">DEPLOYMENT.md</span>).
              </p>
            </div>
          </div>
        </Card>

        {/* What's stored locally */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          <Stat label="Contracts" tone="blue" icon="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z" value={loading ? '…' : num(s.contracts)} />
          <Stat label="Invoices" tone="indigo" icon="M3 10h18M7 15h1m4 0h5m-9 4h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z" value={loading ? '…' : num(s.invoices)} />
          <Stat label="Customers" tone="violet" icon="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM4 21a8 8 0 0 1 16 0z" value={loading ? '…' : num(s.customers)} sub={`${num(s.customers_named)} named`} />
          <Stat label="Vehicles" tone="cyan" icon="M3 13l2-5a2 2 0 0 1 1.9-1.3h10.2A2 2 0 0 1 19 8l2 5v5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1v-1H6v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5zM7 16h.01M17 16h.01" value={loading ? '…' : num(s.vehicles)} />
          <Stat label="Financials filled" tone="emerald" icon="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" value={loading ? '…' : num(s.contracts_enriched)} sub="contracts enriched" />
          <Stat label="Last sync" tone="amber" icon="M4 4v5h5M20 20v-5h-5M20 9A8 8 0 0 0 5.6 5.6L4 7m0 10 1.6-1.4A8 8 0 0 0 20 13" value={s.last_synced ? String(s.last_synced).slice(0, 10) : '—'} sub={s.last_synced ? String(s.last_synced).slice(11, 16) : ''} />
        </div>

        {/* Live progress + ETA for the running sync */}
        {s.current_run && <CurrentRun run={s.current_run} />}

        {/* Active background processes — live OS-level jobs, with a manual stop for a stuck one */}
        {jobsActive && (
          <Card className="ring-1 ring-amber-200">
            <div className="flex items-center justify-between border-b border-amber-100 bg-amber-50/60 px-6 py-4">
              <h3 className="flex items-center gap-2 text-base font-semibold text-amber-800">
                <span className="relative flex h-2.5 w-2.5">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                  <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                </span>
                Active Background Processes
              </h3>
              <Badge tone="amber">{procs.length} running</Badge>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-100 text-sm">
                <thead className="bg-gray-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th className="px-6 py-3">Job</th>
                    <th className="px-6 py-3">PID</th>
                    <th className="px-6 py-3">Started</th>
                    <th className="px-6 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {procs.map((p) => (
                    <tr key={p.pid} className="hover:bg-gray-50/60">
                      <td className="px-6 py-3">
                        <span className="font-medium text-gray-900">{p.job}</span>
                        <div className="font-mono text-xs text-gray-400">{p.command}</div>
                      </td>
                      <td className="px-6 py-3 font-mono text-gray-500">{p.pid}</td>
                      <td className="px-6 py-3 text-gray-500">{p.started || '—'}</td>
                      <td className="px-6 py-3 text-right">
                        <Button variant="danger" size="sm" loading={killing === p.pid} disabled={!!killing} onClick={() => killProc(p)}>
                          Stop
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="px-6 py-2 text-xs text-gray-400">Only stop a job that's clearly stuck. Sync jobs are safe to re-run — they resume where they left off.</p>
          </Card>
        )}

        {/* Inspect the raw API response (read-only GET) */}
        <Card className="p-6">
          <h3 className="mb-1 flex items-center gap-2 text-base font-semibold text-gray-900">
            <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M8 9l-3 3 3 3m8-6 3 3-3 3M14 5l-4 14" /></svg>
            </span>
            Inspect API Response
          </h3>
          <p className="mb-4 text-xs text-gray-400">See the live raw data the OfficeManager API returns (every field) — read-only.</p>
          <div className="flex flex-wrap gap-2">
            {[
              { key: 'vehicles', label: 'Cars' },
              { key: 'contracts', label: 'Contracts' },
              { key: 'invoices', label: 'Invoices' },
            ].map((e) => (
              <Button
                key={e.key}
                variant={preview?.endpoint === e.key ? 'primary' : 'secondary'}
                loading={previewing === e.key}
                disabled={!!previewing}
                onClick={() => loadPreview(e.key)}
              >
                {e.label}
              </Button>
            ))}
          </div>

          {previewErr && (
            <div className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{previewErr}</div>
          )}

          {preview && !previewErr && (
            <div className="mt-4">
              <p className="mb-2 text-xs text-gray-500">
                <span className="font-medium capitalize text-gray-700">{preview.endpoint}</span> — API reports{' '}
                <span className="font-semibold">{num(preview.total)}</span> total · showing {preview.showing} sample record(s)
              </p>
              <pre className="max-h-96 overflow-auto rounded-lg bg-gray-900 px-4 py-3 text-xs leading-relaxed text-gray-100">
                {JSON.stringify(preview.sample, null, 2)}
              </pre>
            </div>
          )}
        </Card>

        {/* What each recent sync changed */}
        <RecentRuns runs={s.recent_runs} />

        <p className="text-xs text-gray-400">
          The status badge and counts refresh automatically every few seconds. Syncs are scheduled on the server — see DEPLOYMENT.md to change the schedule or run one manually.
        </p>
      </div>
    </div>
  );
}
