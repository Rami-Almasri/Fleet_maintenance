// Inspection Intelligence Center — Mission Control for the AUTOMATIC inspection engine (the Proactive
// Diagnostic Monitor, `inspections:generate-tasks`, run daily at 07:30). This is a read-only monitoring
// & debugging dashboard, NOT a CRUD/admin page: it exists so Fleet Management can see exactly why the
// system requests inspections, which rule fired, which cars qualify right now, which requests were
// created, and which were skipped (and why). Every number comes from GET /InspectionEngine/monitor,
// which derives it live from DiagnosticGateService + the periodic maintenances/audit rows.

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, EmptyState, ErrorState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';

// ── formatting helpers ───────────────────────────────────────────────────────────────────────────
const num = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString());

function ago(iso) {
  if (!iso) return 'never';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.round(hrs / 24)}d ago`;
}

function clock(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

function when(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const SEV_TONE = { critical: 'red', moderate: 'amber', routine: 'emerald' };
const SEV_DOT = { critical: 'bg-red-500', moderate: 'bg-amber-500', routine: 'bg-emerald-500' };
const HEALTH = {
  healthy:  { tone: 'emerald', label: 'Healthy' },
  armed:    { tone: 'blue',    label: 'Armed — cars matching' },
  idle:     { tone: 'slate',   label: 'Idle — none matching' },
  disabled: { tone: 'gray',    label: 'Disabled' },
};
const SKIP_TONE = {
  already_pending: 'blue',
  implausible_odometer: 'amber',
  for_sale: 'violet',
  vehicle_inactive: 'slate',
  scan_error: 'red',
};

function plate(v) {
  return v.plate || v.code || `#${v.vehicle_id}`;
}

// ── Section 1: Engine health ──────────────────────────────────────────────────────────────────────
function EngineStrip({ engine, health }) {
  const items = [
    ['Engine', engine.name],
    ['Command', engine.command],
    ['Schedule', engine.schedule],
    ['Status', engine.enabled ? 'Enabled' : 'Disabled'],
    ['Downtime limit', `${engine.downtime_limit} days idle`],
    ['Last run', `${when(health.last_run)} · ${ago(health.last_run)}`],
    ['Next run', when(health.next_run)],
    ['Requests land in', engine.lands_in],
  ];
  return (
    <div className="rounded-2xl border border-indigo-100 bg-indigo-50/40 px-5 py-4">
      <div className="flex items-center gap-2 text-sm font-semibold text-indigo-800">
        <span aria-hidden>🤖</span> {engine.writer}
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-3 lg:grid-cols-4">
        {items.map(([k, v]) => (
          <div key={k} className="min-w-0">
            <dt className="text-[11px] uppercase tracking-wide text-slate-400">{k}</dt>
            <dd className="truncate text-xs font-medium text-slate-700" title={String(v)}>{v}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

// ── Section 2: Live trigger queue ─────────────────────────────────────────────────────────────────
function QueueCard({ v }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-soft">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/vehicles/${v.vehicle_id}`} className="font-mono text-sm font-bold text-indigo-600 hover:text-indigo-700">
            {plate(v)}
          </Link>
          <p className="truncate text-xs text-slate-400">{v.car || 'Vehicle'}</p>
        </div>
        <Badge tone={SEV_TONE[v.priority] || 'slate'} dot className="capitalize">{v.priority}</Badge>
      </div>

      <div className="mt-3 space-y-1.5">
        {v.triggers.map((t, i) => (
          <div key={i} className="flex items-start gap-2 text-xs text-slate-700">
            <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${SEV_DOT[t.severity] || 'bg-slate-400'}`} />
            <span><span className="font-medium text-slate-800">{t.label}</span>{t.why && <span className="text-slate-500"> — {t.why}</span>}</span>
          </div>
        ))}
      </div>

      {(v.threshold || v.exceeded) && (
        <dl className="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-slate-50 px-3 py-2 text-center">
          <div><dt className="text-[10px] uppercase text-slate-400">Current</dt><dd className="font-mono text-xs font-semibold text-slate-700">{num(v.current_km)}{v.current_km != null ? ' km' : ''}</dd></div>
          <div><dt className="text-[10px] uppercase text-slate-400">{v.metric_label || 'Threshold'}</dt><dd className="font-mono text-xs font-semibold text-slate-700">{v.threshold || '—'}</dd></div>
          <div><dt className="text-[10px] uppercase text-slate-400">Exceeded</dt><dd className="font-mono text-xs font-semibold text-red-600">{v.exceeded || '—'}</dd></div>
        </dl>
      )}

      {v.anomaly && (
        <p className="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-700 ring-1 ring-inset ring-amber-200">⚠ Oil condition dropped — {v.anomaly}</p>
      )}

      <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-xs">
        <Badge tone="emerald" dot>Would create → {v.workflow_stage}</Badge>
        <span className="text-slate-400 capitalize">{(v.rental_status || 'idle').replace(/_/g, ' ')}</span>
      </div>
    </div>
  );
}

// ── Section 3: Inspection rules ───────────────────────────────────────────────────────────────────
function RuleCard({ r }) {
  const h = HEALTH[r.health] || HEALTH.idle;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-soft">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h4 className="text-sm font-semibold text-slate-900">{r.name}</h4>
          <p className="mt-0.5 text-[11px] font-mono text-slate-400">{r.source}</p>
        </div>
        <Badge tone={h.tone} dot>{h.label}</Badge>
      </div>
      <p className="mt-2 text-xs leading-relaxed text-slate-500">{r.description}</p>

      <div className="mt-3 flex flex-wrap gap-1.5 text-[11px]">
        <Badge tone={r.enabled ? 'emerald' : 'gray'}>{r.enabled ? 'Enabled' : 'Disabled'}</Badge>
        <Badge tone="blue">Auto · {r.frequency}</Badge>
        {r.matching_now > 0 && <Badge tone="indigo">{r.matching_now} matching now</Badge>}
      </div>

      <dl className="mt-3 grid grid-cols-4 gap-2 border-t border-slate-100 pt-3 text-center">
        {[['Today', r.requests_today], ['This week', r.requests_week], ['Total', r.requests_total], ['Pending', r.pending]].map(([k, val]) => (
          <div key={k}><dt className="text-[10px] uppercase text-slate-400">{k}</dt><dd className="font-mono text-sm font-semibold text-slate-800">{num(val)}</dd></div>
        ))}
      </dl>
      <p className="mt-2 text-[11px] text-slate-400">Last fired {ago(r.last_fired_at)}</p>
    </div>
  );
}

// ── Section 5: Skipped vehicles (grouped by reason) ──────────────────────────────────────────────
function SkippedGroup({ code, label, rows }) {
  return (
    <div>
      <div className="mb-2 flex items-center gap-2">
        <Badge tone={SKIP_TONE[code] || 'slate'} dot>{label}</Badge>
        <span className="text-xs text-slate-400">{rows.length}</span>
      </div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((v) => (
          <div key={v.vehicle_id} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs">
            <div className="flex items-center justify-between gap-2">
              <Link to={`/vehicles/${v.vehicle_id}`} className="font-mono font-bold text-indigo-600">{plate(v)}</Link>
              <span className="truncate text-slate-400" title={v.car}>{v.car}</span>
            </div>
            <div className="mt-1 flex flex-wrap gap-1">
              {v.matched.map((m, i) => (
                <span key={i} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">
                  <span className={`h-1 w-1 rounded-full ${SEV_DOT[m.severity] || 'bg-slate-400'}`} />{m.label}
                </span>
              ))}
            </div>
            {v.existing_ticket && (
              <p className="mt-1 text-[11px] text-slate-500">
                Open ticket <Link to={`/maintenance-workflow/${v.existing_ticket.id}`} className="font-medium text-indigo-600">#{v.existing_ticket.id}</Link> · {v.existing_ticket.stage.replace(/_/g, ' ')}
              </p>
            )}
            {v.detail && <p className="mt-1 text-[11px] text-amber-600">{v.detail}</p>}
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Idle Fleet Watch — cars sitting idle (no rental, unused) for ≥ N days ─────────────────────────
function IdleWatch() {
  const [minDays, setMinDays] = useState(15);
  const fetcher = useCallback(async () => (await api.get(`/InspectionEngine/idle-watch?min_days=${minDays}`)).data.data, [minDays]);
  const { data, loading, error } = useFetch(fetcher, null, { refreshInterval: 60000 });

  const rows = data?.vehicles || [];
  const limit = data?.limit;

  const CHOICES = [15, 21, 30];

  return (
    <SectionCard
      title="Idle Fleet Watch"
      subtitle={`Free cars (not rented / in the shop) that haven't been tested in at least ${minDays} days — the clock runs from each car's last test, not its last rental${limit != null ? ` · engine flags at ${limit} days` : ''}`}
      actions={(
        <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
          {CHOICES.map((d) => (
            <button
              key={d}
              type="button"
              onClick={() => setMinDays(d)}
              className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${minDays === d ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
            >
              ≥ {d}d
            </button>
          ))}
        </div>
      )}
    >
      {error && !data ? (
        <div className="px-5 py-4 text-sm text-red-600">{error}</div>
      ) : (
        <DataTable
          loading={loading && !data}
          columns={[
            {
              key: 'v', header: 'Vehicle', render: (r) => (
                <div className="min-w-0">
                  <Link to={`/vehicles/${r.vehicle_id}`} className="font-mono text-sm font-bold text-indigo-600">{plate(r)}</Link>
                  <p className="truncate text-xs text-slate-400">{r.car || 'Vehicle'}</p>
                </div>
              ),
            },
            {
              key: 'idle_days', header: 'Idle', align: 'right', render: (r) => (
                <div>
                  <span className={`font-mono text-base font-bold ${r.over_limit ? 'text-red-600' : 'text-slate-700'}`}>{num(r.idle_days)}d</span>
                  {r.over_limit && r.over_by > 0 && <p className="text-[11px] text-red-400">+{num(r.over_by)}d over limit</p>}
                </div>
              ),
            },
            { key: 'anchor', header: 'Idle since', cellClass: 'text-xs text-slate-500', render: (r) => r.anchor },
            {
              key: 'last_test', header: 'Last test', render: (r) => (
                r.last_test ? (
                  <div className="min-w-0 max-w-[16rem]">
                    <div className="flex items-center gap-1.5">
                      <span className="text-xs font-medium text-slate-700">{when(r.last_test.at)}</span>
                      <span className="text-[11px] text-slate-400">· {r.last_test.ago_days}d ago</span>
                      {r.last_test.severity && (
                        <span className={`h-1.5 w-1.5 rounded-full ${SEV_DOT[r.last_test.severity] || 'bg-slate-400'}`} title={r.last_test.severity} />
                      )}
                    </div>
                    {r.last_test.summary && <p className="truncate text-[11px] text-slate-500" title={r.last_test.summary}>{r.last_test.summary}</p>}
                    {r.last_test.by && <p className="text-[11px] text-slate-400">by {r.last_test.by}</p>}
                  </div>
                ) : (
                  <Badge tone="amber">Never inspected</Badge>
                )
              ),
            },
            {
              key: 'request', header: 'Engine action', render: (r) => (
                r.request
                  ? <Link to={`/maintenance-workflow/${r.request.id}`}><Badge tone="blue" dot>Flagged · {r.request.stage.replace(/_/g, ' ')}</Badge></Link>
                  : <Badge tone="amber" dot>Not yet flagged</Badge>
              ),
            },
          ]}
          rows={rows}
          rowKey={(r) => r.vehicle_id}
          highlightRow={(r) => r.over_limit}
          empty={`No free car has gone ${minDays}+ days since its last test. Every car sitting free was tested more recently than that.`}
        />
      )}
    </SectionCard>
  );
}

export default function InspectionIntelligenceCenter() {
  const fetcher = useCallback(async () => (await api.get('/InspectionEngine/monitor')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, null, { refreshInterval: 30000 });

  if (loading && !data) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
          <Skeleton className="h-24 rounded-2xl" />
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-28 rounded-2xl" />)}
          </div>
        </div>
      </div>
    );
  }

  if (error && !data) {
    return <div className="py-8"><div className="mx-auto max-w-7xl px-4"><ErrorState onRetry={reload} message={error} /></div></div>;
  }

  const { engine, health, queue = [], skipped = [], rules = [], timeline = [], log = [], future = [] } = data || {};

  // Group skipped rows by reason for Section 5.
  const skipGroups = skipped.reduce((acc, v) => {
    (acc[v.reason_code] = acc[v.reason_code] || []).push(v);
    return acc;
  }, {});
  const skipOrder = ['already_pending', 'implausible_odometer', 'for_sale', 'vehicle_inactive', 'scan_error'];
  const skipLabel = (rows) => rows[0]?.reason || '';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Inspection Intelligence Center"
          subtitle="Mission Control for the automatic inspection engine — why the system requests inspections, which rule fired, who qualifies, and what was skipped."
        >
          <button
            type="button"
            onClick={() => reload({ silent: true })}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
          >
            <Icon.Refresh className="h-4 w-4" /> Refresh
          </button>
        </PageHeader>

        <EngineStrip engine={engine} health={health} />

        {/* SECTION 1 — Engine health */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Activity className="h-4 w-4" /> Engine Health</h2>
          <MetricGrid cols={4}>
            <MetricCard label="Fleet cars evaluated" value={num(health.scanned)} icon={<Icon.Car className="h-5 w-5" />} tone="slate" hint="Active + live fleet walked this pass" />
            <MetricCard label="Automatic requests today" value={num(health.auto_created)} icon={<Icon.Wrench className="h-5 w-5" />} tone="indigo" hint="System · Auto-Check" />
            <MetricCard label="Manual requests today" value={num(health.manual_created)} icon={<Icon.Users className="h-5 w-5" />} tone="violet" hint="Driver-raised" />
            <MetricCard label="Would create now" value={num(health.would_create)} icon={<Icon.Flag className="h-5 w-5" />} tone={health.would_create > 0 ? 'amber' : 'emerald'} hint="Cars the next run would flag" />
            <MetricCard label="Already pending" value={num(health.already_pending)} icon={<Icon.Clock className="h-5 w-5" />} tone="blue" hint="Matched but in the pipeline" />
            <MetricCard label="Skipped" value={num(health.skipped)} icon={<Icon.XCircle className="h-5 w-5" />} tone="slate" hint="Matched a rule, no new request" />
            <MetricCard label="Data anomalies" value={num(health.anomalies)} icon={<Icon.Alert className="h-5 w-5" />} tone={health.anomalies > 0 ? 'amber' : 'slate'} hint="Implausible odometer — oil dropped" />
            <MetricCard label="Scan errors" value={num(health.errors)} icon={<Icon.Shield className="h-5 w-5" />} tone={health.errors > 0 ? 'red' : 'emerald'} hint="Cars the engine couldn't evaluate" />
          </MetricGrid>
        </section>

        {/* SECTION 2 — Live trigger queue */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Flag className="h-4 w-4" /> Live Trigger Queue <span className="font-normal normal-case text-slate-400">— cars the engine would request an inspection for right now</span></h2>
          {queue.length === 0 ? (
            <SectionCard>
              <EmptyState
                icon={<Icon.Check className="h-7 w-7" />}
                title="Queue clear"
                message="No active-fleet car currently qualifies for a NEW request. Cars that match a rule are already in the pipeline (see Skipped) — that's the dedup guard working."
              />
            </SectionCard>
          ) : (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {queue.map((v) => <QueueCard key={v.vehicle_id} v={v} />)}
            </div>
          )}
        </section>

        {/* Idle Fleet Watch — the cars behind the Post-Downtime rule: sitting unused for ≥ N days */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Clock className="h-4 w-4" /> Idle Fleet Watch <span className="font-normal normal-case text-slate-400">— cars sitting unused, with no rental opened</span></h2>
          <IdleWatch />
        </section>

        {/* SECTION 3 — Inspection rules */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Gauge className="h-4 w-4" /> Inspection Rules <span className="font-normal normal-case text-slate-400">— every discovered automatic trigger</span></h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {rules.map((r) => <RuleCard key={r.key} r={r} />)}
          </div>
        </section>

        {/* SECTION 4 — Today's timeline */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Clock className="h-4 w-4" /> Today's Timeline</h2>
          <SectionCard bodyClass="p-5">
            {timeline.length === 0 ? (
              <p className="py-6 text-center text-sm text-slate-400">No inspection requests raised today yet.</p>
            ) : (
              <ol className="relative space-y-4 border-s border-slate-200 ps-5">
                {timeline.map((t) => (
                  <li key={t.id} className="relative">
                    <span className={`absolute -start-[1.42rem] top-1 h-2.5 w-2.5 rounded-full ring-2 ring-white ${t.auto ? 'bg-indigo-500' : 'bg-violet-500'}`} />
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                      <span className="font-mono text-xs font-semibold text-slate-500">{clock(t.time)}</span>
                      {t.vehicle_id && <Link to={`/vehicles/${t.vehicle_id}`} className="font-mono text-xs font-bold text-indigo-600">#{t.vehicle_id}</Link>}
                      <Badge tone={t.auto ? 'indigo' : 'violet'}>{t.trigger}</Badge>
                      <span className="text-slate-600">{t.action}</span>
                      <span className="text-slate-400">· {String(t.result).replace(/_/g, ' ')}</span>
                    </div>
                    {t.detail && <p className="mt-0.5 text-xs text-slate-400">{t.detail}</p>}
                  </li>
                ))}
              </ol>
            )}
          </SectionCard>
        </section>

        {/* SECTION 5 — Skipped vehicles */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.XCircle className="h-4 w-4" /> Skipped Vehicles <span className="font-normal normal-case text-slate-400">— matched a rule but got no new request</span></h2>
          {skipped.length === 0 ? (
            <SectionCard><EmptyState title="Nothing skipped" message="Every matching car was actioned." /></SectionCard>
          ) : (
            <div className="space-y-6">
              {skipOrder.filter((c) => skipGroups[c]).map((c) => (
                <SkippedGroup key={c} code={c} label={skipLabel(skipGroups[c])} rows={skipGroups[c]} />
              ))}
            </div>
          )}
        </section>

        {/* SECTION 6 — Rule coverage matrix */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Chart className="h-4 w-4" /> Rule Coverage</h2>
          <SectionCard>
            <DataTable
              columns={[
                { key: 'name', header: 'Rule', render: (r) => <span className="font-medium text-slate-800">{r.name}</span> },
                { key: 'enabled', header: 'Enabled', render: (r) => <Badge tone={r.enabled ? 'emerald' : 'gray'}>{r.enabled ? 'Yes' : 'No'}</Badge> },
                { key: 'matching_now', header: 'Matching now', align: 'right', cellClass: 'font-mono', render: (r) => num(r.matching_now) },
                { key: 'requests_today', header: 'Today', align: 'right', cellClass: 'font-mono', render: (r) => num(r.requests_today) },
                { key: 'requests_total', header: 'Total', align: 'right', cellClass: 'font-mono', render: (r) => num(r.requests_total) },
                { key: 'last_fired_at', header: 'Last fired', render: (r) => ago(r.last_fired_at) },
                { key: 'never', header: 'Never fired', render: (r) => (r.never_fired ? <Badge tone="amber">Never</Badge> : <span className="text-slate-400">—</span>) },
                { key: 'health', header: 'Health', render: (r) => { const h = HEALTH[r.health] || HEALTH.idle; return <Badge tone={h.tone} dot>{h.label}</Badge>; } },
              ]}
              rows={rules}
              rowKey={(r) => r.key}
              highlightRow={(r) => r.never_fired && r.matching_now > 0}
            />
          </SectionCard>
        </section>

        {/* SECTION 7 — Engine log */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Activity className="h-4 w-4" /> Engine Log <span className="font-normal normal-case text-slate-400">— recent automatic inspection events</span></h2>
          <SectionCard>
            <DataTable
              columns={[
                { key: 'time', header: 'Time', render: (r) => <span className="whitespace-nowrap font-mono text-xs text-slate-500">{when(r.time)}</span> },
                { key: 'vehicle_id', header: 'Vehicle', render: (r) => r.vehicle_id ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-mono text-xs font-bold text-indigo-600">#{r.vehicle_id}</Link> : '—' },
                { key: 'trigger', header: 'Trigger', render: (r) => <Badge tone="indigo">{r.trigger}</Badge> },
                { key: 'action', header: 'Action', cellClass: 'font-mono text-xs', render: (r) => r.action },
                { key: 'result', header: 'Result', render: (r) => <Badge tone="blue">{String(r.result).replace(/_/g, ' ')}</Badge> },
                { key: 'detail', header: 'Detail', cellClass: 'text-xs text-slate-500', render: (r) => <span className="line-clamp-1 max-w-md">{r.detail}</span> },
              ]}
              rows={log}
              rowKey={(r) => r.id}
              empty="No automatic inspection events recorded yet."
            />
          </SectionCard>
        </section>

        {/* Verification — scenarios the engine does NOT cover */}
        <section>
          <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500"><Icon.Info className="h-4 w-4" /> Potential Future Inspection Triggers <span className="font-normal normal-case text-slate-400">— gaps in today's coverage (not implemented)</span></h2>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {future.map((f, i) => (
              <div key={i} className="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-4">
                <h4 className="flex items-center gap-2 text-sm font-semibold text-slate-700"><span className="text-slate-400">◇</span> {f.title}</h4>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">{f.detail}</p>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}
