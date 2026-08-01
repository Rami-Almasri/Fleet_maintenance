import { Fragment, useCallback, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, ErrorState } from '../components/ui/Misc';
import { num } from '../lib/format';

/**
 * The Intelligence Center — what the platform knows, how sure it is, and what is stopping it.
 *
 * Everything here existed only as `php artisan intelligence:evidence-health`. That was fine while
 * the audience was one engineer, and stopped being fine the moment the platform began making claims
 * inside a supervisor's workflow: someone shown a card that says "this fault came back three times"
 * is entitled to ask what it knows and how sure it is, and "ask an engineer to run a command" is not
 * an answer.
 *
 * THE EDITORIAL RULE THIS PAGE FOLLOWS: never report a number without what it means. A readiness
 * table full of percentages invites the reader to average them into a feeling. Every section here
 * leads with the sentence and keeps the figure as the evidence for it.
 */

const BAND = {
  healthy: { tone: 'green', bar: 'bg-emerald-500' },
  watch: { tone: 'amber', bar: 'bg-amber-500' },
  'at risk': { tone: 'red', bar: 'bg-rose-500' },
  blocked: { tone: 'slate', bar: 'bg-slate-400' },
};

/** Headline banner styling, keyed by the alert severity the backend emits. */
const BANNER = {
  critical: 'border-rose-200 bg-rose-50 text-rose-900',
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
  info: 'border-sky-200 bg-sky-50 text-sky-900',
  ok: 'border-emerald-200 bg-emerald-50 text-emerald-900',
};

/** Data-quality severity → Badge tone. A separate vocabulary from the banner, deliberately. */
const ISSUE_TONE = { high: 'red', medium: 'amber', low: 'slate' };

const DIMENSION_MEANING = {
  coverage: 'Are eligible events producing evidence at all?',
  volume: 'Is there enough of it?',
  freshness: 'Is it about today’s fleet?',
  evaluation: 'Has anyone recently checked whether it predicts?',
  trust: 'Proxy or measured — and promoted, or merely assumed?',
};

const pct = (v) => (v === null || v === undefined ? '—' : `${Math.round(v * 100)}%`);

function Meter({ value, className = '' }) {
  const width = Math.max(0, Math.min(1, value || 0)) * 100;
  return (
    <div className={`h-1.5 w-full overflow-hidden rounded-full bg-slate-100 ${className}`}>
      <div className="h-full rounded-full bg-slate-500" style={{ width: `${width}%` }} />
    </div>
  );
}

/** The one line at the top. Severity drives the colour; the text always names an action. */
function Headline({ headline, alert, generatedAt, cached, onRefresh, busy }) {
  const tone = BANNER[headline?.severity] || BANNER.info;
  return (
    <div className={`rounded-xl border px-5 py-4 ${tone}`}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide opacity-70">
            {alert ? 'Action required' : 'Attention first'}
          </p>
          <p className="mt-1 text-base font-semibold">{headline?.text}</p>
          {alert && <p className="mt-1 max-w-3xl text-sm opacity-90">{alert.detail}</p>}
        </div>
        <div className="text-right text-xs opacity-70">
          <div>Read {generatedAt ? new Date(generatedAt).toLocaleString() : '—'}</div>
          {cached && <div className="italic">from cache</div>}
          <button
            type="button"
            onClick={onRefresh}
            disabled={busy}
            className="mt-1 rounded-md border border-current/30 px-2 py-0.5 font-medium hover:bg-white/40 disabled:opacity-50"
          >
            {busy ? 'Reading…' : 'Re-read now'}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * One capability, whole. Health, readiness and freshness are shown together on purpose — the command
 * splits them into three tables only because a terminal cannot show fifteen columns, and reading one
 * without the others is exactly how a capability with great volume and year-old evidence gets called
 * ready.
 */
function Capability({ c }) {
  const [open, setOpen] = useState(false);
  const band = BAND[c.band] || BAND.watch;

  return (
    <Card className="overflow-hidden">
      <button type="button" onClick={() => setOpen((v) => !v)} className="w-full px-5 py-4 text-left hover:bg-slate-50">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl font-semibold tabular-nums text-slate-800">
              {c.score === null ? '—' : Math.round(c.score)}
            </span>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-semibold text-slate-800">{c.label}</span>
                <Badge tone={band.tone}>{c.band}</Badge>
                {c.quality === 'proxy'
                  ? <Badge tone="amber" title="Reasoning from a stand-in, not a measurement">proxy</Badge>
                  : <Badge tone="green">measured</Badge>}
                {c.shipped && <Badge tone="blue">shipped</Badge>}
              </div>
              <p className="mt-0.5 text-sm text-slate-500">{c.attention}</p>
            </div>
          </div>
          <div className="text-right text-xs text-slate-500">
            <div className="font-medium text-slate-700">{num(c.current)} / {num(c.threshold)}</div>
            <div>{c.ready_label}</div>
          </div>
        </div>

        {/* The five dimensions, always beside the total — the score is never the whole story. */}
        <div className="mt-3 grid grid-cols-5 gap-3">
          {Object.entries(c.dimensions || {}).map(([key, value]) => (
            <div key={key} title={DIMENSION_MEANING[key]}>
              <div className="mb-1 flex items-baseline justify-between text-[11px] text-slate-400">
                <span className={key === c.weakest ? 'font-semibold text-slate-600' : ''}>{key}</span>
                <span className="tabular-nums">{pct(value)}</span>
              </div>
              <Meter value={value} />
            </div>
          ))}
        </div>
      </button>

      {open && (
        <div className="border-t bg-slate-50/60 px-5 py-4 text-sm">
          {c.blocker && (
            <p className="mb-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
              <span className="font-semibold">Blocked by data, not by build.</span> {c.blocker}
              <span className="mt-1 block text-xs text-slate-500">
                Waiting will not fix this — the fleet’s record-keeping has to change first.
              </span>
            </p>
          )}

          <dl className="grid gap-x-8 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
            <Fact label="Evidence counted">{c.evidence}</Fact>
            <Fact label="Still needed">{c.remaining > 0 ? `${num(c.remaining)} more` : 'threshold met'}</Fact>
            <Fact label="Arriving at">{c.weekly_rate > 0 ? `${c.weekly_rate}/week` : 'nothing arriving'}</Fact>
            <Fact label="Coverage">{pct(c.coverage)}</Fact>
            <Fact label="Median evidence age">
              {c.median_age_days === null ? '—' : `${num(c.median_age_days)} days`}
              {c.evidence_stale && <span className="ml-1 text-amber-600">· over a year old</span>}
            </Fact>
            <Fact label="Observed between">{c.oldest_at || '—'} → {c.newest_at || '—'}
              {c.feed_quiet && <span className="ml-1 text-amber-600">· feed quiet 30d+</span>}
            </Fact>
            <Fact label="Last evaluated">{c.last_evaluated_at || 'never'}</Fact>
            <Fact label="Corpus last grew">{c.dataset_age_days === null ? '—' : `${num(c.dataset_age_days)} days ago`}</Fact>
          </dl>

          {c.reevaluation_reason && (
            <p className="mt-3 text-xs text-amber-700">
              ↻ Re-evaluate: {c.reevaluation_reason}. The previous decision is not wrong — it answered a
              question about a different corpus.
            </p>
          )}
        </div>
      )}
    </Card>
  );
}

function Fact({ label, children }) {
  return (
    <div>
      <dt className="text-[11px] uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-slate-700">{children}</dd>
    </div>
  );
}

/** QC throughput — the shape behind the rate. 3/week reads the same as 24 then five silent weeks. */
function Throughput({ qc }) {
  const series = qc?.throughput || [];
  const peak = Math.max(1, ...series.map((w) => w.count));

  return (
    <Card className="p-5">
      <h3 className="font-semibold text-slate-800">QC verdict throughput</h3>
      <p className="mt-0.5 text-sm text-slate-500">
        The platform’s only ground truth. A verdict missed at close cannot be recovered later — the car has gone.
      </p>

      <div className="mt-4 flex items-end gap-1.5" style={{ height: 72 }}>
        {series.map((w) => (
          <div key={w.week} className="flex flex-1 flex-col items-center gap-1" title={`${w.week}: ${w.count} verdicts (${w.conclusive} conclusive)`}>
            <div className="flex w-full flex-1 items-end">
              <div
                className={`w-full rounded-t ${w.count === 0 ? 'bg-slate-100' : 'bg-indigo-400'}`}
                style={{ height: `${Math.max(w.count === 0 ? 2 : 6, (w.count / peak) * 100)}%` }}
              />
            </div>
            <span className="text-[10px] text-slate-400">{w.week.slice(5)}</span>
          </div>
        ))}
      </div>

      <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <Stat label="Repaired & closed" value={num(qc?.closed)} />
        <Stat label="With a verdict" value={`${num(qc?.with_verdict)} · ${pct(qc?.coverage)}`} />
        <Stat label="Usable for statistics" value={num(qc?.conclusive)} hint="conclusive verdicts only" />
        <Stat label="Evidence lost" value={num(qc?.lost)} hint="closed unverified — unrecoverable" tone="rose" />
      </dl>

      {qc?.unverifiable > 0 && (
        <p className="mt-3 text-xs text-slate-500">
          {num(qc.unverifiable)} inspection{qc.unverifiable === 1 ? '' : 's'} could not verify the repair. That counts as
          covered — the inspector attended and answered honestly — but it is excluded from every statistic.
        </p>
      )}
    </Card>
  );
}

function Stat({ label, value, hint, tone }) {
  return (
    <div>
      <div className={`text-lg font-semibold tabular-nums ${tone === 'rose' ? 'text-rose-600' : 'text-slate-800'}`}>{value}</div>
      <div className="text-[11px] uppercase tracking-wide text-slate-400">{label}</div>
      {hint && <div className="text-[11px] text-slate-400">{hint}</div>}
    </div>
  );
}

export default function IntelligenceCenter() {
  const [refreshing, setRefreshing] = useState(false);

  const fetcher = useCallback(async () => (await api.get('/intelligence/center')).data, []);
  const { data, loading, error, reload } = useFetch(fetcher, []);

  const hardRefresh = async () => {
    setRefreshing(true);
    try {
      await api.get('/intelligence/center', { params: { refresh: 1 } });
      await reload({ silent: true });
    } finally {
      setRefreshing(false);
    }
  };

  if (loading && !data) return <div className="flex justify-center py-20"><Spinner className="h-8 w-8" /></div>;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;
  if (!data) return null;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Intelligence Center"
        subtitle="What the platform knows, how sure it is, and what is stopping it from knowing more."
      />

      {/*
        A section that crashed says so. Rendering it empty would be worse than the 500 this replaced:
        "no data-quality problems" and "the data-quality check failed" look identical and mean the
        opposite. Everything else on the page is still readable.
      */}
      {Object.keys(data.failed_sections || {}).length > 0 && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-rose-900">
          <p className="font-semibold">Part of this page could not be read.</p>
          <ul className="mt-2 space-y-1 text-sm">
            {Object.entries(data.failed_sections).map(([key, message]) => (
              <li key={key}><span className="font-mono text-xs">{key}</span> — {message}</li>
            ))}
          </ul>
          <p className="mt-2 text-xs opacity-80">
            The rest of the page is accurate. Nothing here is cached while a section is failing.
          </p>
        </div>
      )}

      <Headline
        headline={data.headline}
        alert={data.alert}
        generatedAt={data.generated_at}
        cached={data.cached}
        onRefresh={hardRefresh}
        busy={refreshing}
      />

      <section className="space-y-3">
        <SectionHead
          title="Capability health"
          note="Worst first. A blocker CAPS the score rather than averaging away — a capability that cannot work cannot be healthy."
        />
        {(data.capabilities || []).map((c) => <Capability key={c.id} c={c} />)}
      </section>

      <Throughput qc={data.qc} />

      <div className="grid gap-6 lg:grid-cols-2">
        <DataQuality issues={data.data_quality} />
        <div className="space-y-6">
          <Flags flags={data.flags} />
          <Jobs jobs={data.jobs} />
        </div>
      </div>

      <Promotions rows={data.promotions} versions={data.versions} />
    </div>
  );
}

function SectionHead({ title, note }) {
  return (
    <div>
      <h2 className="text-lg font-semibold text-slate-800">{title}</h2>
      {note && <p className="text-sm text-slate-500">{note}</p>}
    </div>
  );
}

function DataQuality({ issues = [] }) {
  return (
    <Card className="p-5">
      <h3 className="font-semibold text-slate-800">Data quality</h3>
      <p className="mt-0.5 text-sm text-slate-500">
        Problems no capability can work around. None of these is an engineering task.
      </p>
      {issues.length === 0 ? (
        <p className="mt-4 text-sm text-slate-500">Nothing blocking — every input is arriving usable.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {issues.map((it) => (
            <li key={it.key} className="rounded-lg border border-slate-200 p-3">
              <div className="flex items-start justify-between gap-3">
                <span className="font-medium text-slate-800">{it.title}</span>
                <Badge tone={ISSUE_TONE[it.severity] || 'slate'}>{num(it.count)}</Badge>
              </div>
              <p className="mt-1 text-sm text-slate-600">{it.detail}</p>
              <p className="mt-1 text-xs text-slate-500"><span className="font-medium">Fix:</span> {it.remedy}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/** Flags whose consequence is invisible everywhere else — which is the only reason they are here. */
function Flags({ flags = [] }) {
  return (
    <Card className="p-5">
      <h3 className="font-semibold text-slate-800">Feature flags</h3>
      <ul className="mt-3 space-y-3">
        {flags.map((f) => {
          const drifted = f.enabled !== f.expected;
          return (
            <li key={f.key} className="rounded-lg border border-slate-200 p-3">
              <div className="flex items-center justify-between gap-3">
                <span className="font-mono text-xs text-slate-700">{f.key}</span>
                <Badge tone={f.enabled ? 'green' : 'slate'}>{f.enabled ? 'ON' : 'OFF'}</Badge>
              </div>
              <p className="mt-1 text-sm text-slate-600">{f.label}</p>
              <p className="mt-1 text-xs text-slate-500">{f.impact}</p>
              {drifted && (
                <p className="mt-1 text-xs font-medium text-amber-700">
                  ⚠ Expected {f.expected ? 'ON' : 'OFF'} — this was changed deliberately, or it was changed and nobody said so.
                </p>
              )}
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

const JOB_TONE = { ok: 'green', stale: 'red', unscheduled: 'red', 'never produced output': 'amber' };

function Jobs({ jobs = [] }) {
  return (
    <Card className="p-5">
      <h3 className="font-semibold text-slate-800">Background jobs</h3>
      <p className="mt-0.5 text-sm text-slate-500">
        Judged by the trace each job leaves in the data, not by a run log — a scheduler that runs
        perfectly and writes nothing is not healthy.
      </p>
      <ul className="mt-3 space-y-3">
        {jobs.map((j) => (
          <li key={j.command} className="rounded-lg border border-slate-200 p-3">
            <div className="flex items-start justify-between gap-3">
              <span className="font-mono text-xs text-slate-700">{j.command}</span>
              <Badge tone={JOB_TONE[j.status] || 'slate'}>{j.status}</Badge>
            </div>
            <p className="mt-1 text-sm text-slate-600">{j.purpose}</p>
            <p className="mt-1 text-xs text-slate-500">
              {j.registered ? `Runs ${j.scheduled}` : 'NOT in the scheduler — someone is running this by hand'}
              {' · '}
              {j.last_effect_at ? `${j.evidence}: ${j.age_days}d ago` : `${j.evidence}: none yet`}
            </p>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/**
 * The decision history — refusals included, deliberately. A refusal is the most informative row
 * here: it is the platform declining to trust better-looking evidence because it predicted worse.
 */
function Promotions({ rows = [], versions }) {
  const [open, setOpen] = useState(null);

  return (
    <Card className="p-5">
      <h3 className="font-semibold text-slate-800">Promotion decisions</h3>
      <p className="mt-0.5 text-sm text-slate-500">
        Append-only. Promotion is evidence-driven, not calendar-driven — reaching the threshold buys the
        right to run the comparison, not the promotion.
      </p>

      {rows.length === 0 ? (
        <p className="mt-4 text-sm text-slate-500">No comparison has ever run.</p>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="text-left text-[11px] uppercase tracking-wide text-slate-400">
              <tr>
                <th className="pb-2">When</th>
                <th className="pb-2">Capability</th>
                <th className="pb-2">Decision</th>
                <th className="pb-2">Evidence</th>
                <th className="pb-2">Reason</th>
                <th className="pb-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((p) => (
                <Fragment key={p.id}>
                  <tr className="align-top">
                    <td className="py-2 whitespace-nowrap text-slate-500">{(p.decided_at || '').slice(0, 10)}</td>
                    <td className="py-2 text-slate-700">{p.capability_id}</td>
                    <td className="py-2">
                      <Badge tone={p.promoted ? 'green' : 'slate'}>{p.promoted ? 'promoted' : 'refused'}</Badge>
                      {p.superseded && <div className="mt-1 text-[11px] text-amber-600">ground moved</div>}
                    </td>
                    <td className="py-2 tabular-nums text-slate-500">{num(p.evidence_count)}/{num(p.evidence_threshold)}</td>
                    <td className="py-2 text-slate-600">{p.reason}</td>
                    <td className="py-2 text-right">
                      <button type="button" onClick={() => setOpen(open === p.id ? null : p.id)} className="text-xs font-medium text-indigo-600">
                        {open === p.id ? 'hide' : 'provenance'}
                      </button>
                    </td>
                  </tr>
                  {open === p.id && (
                    <tr>
                      <td colSpan={6} className="bg-slate-50 px-3 py-3">
                        <dl className="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2 lg:grid-cols-4">
                          {Object.entries(p.provenance || {}).map(([k, v]) => (
                            <div key={k}>
                              <dt className="text-slate-400">{k.replace(/_/g, ' ')}</dt>
                              <dd className="font-mono text-slate-700 break-all">{String(v ?? '—')}</dd>
                            </div>
                          ))}
                        </dl>
                        {p.superseded && (
                          <p className="mt-2 text-xs text-amber-700">
                            This decision was made on a different dataset or methodology. It is not wrong — it
                            answered a question about a different corpus — but it should be re-run rather than trusted.
                          </p>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {versions && (
        <div className="mt-5 border-t pt-4">
          <p className="text-[11px] uppercase tracking-wide text-slate-400">In force now</p>
          <p className="mt-1 font-mono text-xs text-slate-600">
            dataset {versions.dataset} · query layer {versions.query_layer} · backtest {versions.evaluation?.backtest_version}
          </p>
        </div>
      )}
    </Card>
  );
}
