import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { aed2, num } from '../lib/format';

const TIER = {
  act_now:   { label: 'Fix now',     tone: 'red',   ring: 'ring-red-200/70',   dot: 'bg-red-500',   chip: 'bg-red-50 text-red-700 ring-red-200',     bar: 'from-red-400 to-rose-500',      soft: 'bg-red-50 text-red-600' },
  plan_soon: { label: 'Plan soon',   tone: 'amber', ring: 'ring-amber-200/70', dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700 ring-amber-200', bar: 'from-amber-400 to-orange-500', soft: 'bg-amber-50 text-amber-600' },
  watch:     { label: 'Keep an eye', tone: 'blue',  ring: 'ring-slate-200/80', dot: 'bg-slate-400', chip: 'bg-slate-50 text-slate-600 ring-slate-200', bar: 'from-slate-300 to-slate-400',  soft: 'bg-slate-100 text-slate-500' },
};

// Simple car glyph used as the card avatar.
const CAR_ICON = 'M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v2a1 1 0 0 0 1 1h1m14-5a2 2 0 0 1 2 2v2a1 1 0 0 1-1 1h-1M7.5 16h.01M16.5 16h.01';

const SIGNAL_ICON = {
  service_overdue: 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2',
  service_due_soon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  chronic_fault: 'M4 4v5h.6M20 20v-5h-.6M5 9a7 7 0 0 1 13-2m1 8a7 7 0 0 1-13 2',
  frequent_breakdowns: 'M13 10V3L4 14h7v7l9-11h-7z',
  battery_overdue: 'M6 7h11a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zM20 10v4M9 7V5h4v2',
  workshop_stalling: 'M18.364 5.636A9 9 0 1 1 5.636 18.364 9 9 0 0 1 18.364 5.636zM5.636 5.636l12.728 12.728',
};

// Confidence in a cost line = how many past PRICED repairs back it (a real sample size, not a
// fabricated probability). 'fleet' means there is no priced history for that exact problem.
const CONF = {
  high:   { label: 'High',      cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  medium: { label: 'Medium',    cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  low:    { label: 'Low',       cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
  fleet:  { label: 'Fleet avg', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
};

function ConfidenceBadge({ level, samples }) {
  const c = CONF[level] || CONF.low;
  const title = level === 'fleet'
    ? 'No priced history for this exact problem yet — using the fleet-wide average repair cost.'
    : `${c.label} confidence — based on ${samples} past repair${samples === 1 ? '' : 's'} of this kind.`;
  return (
    <span title={title} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${c.cls}`}>
      {c.label}
      {level !== 'fleet' && <span className="opacity-70">· {samples}</span>}
    </span>
  );
}

// A KPI tile for the hero band.
function Kpi({ label, value, sub, accent = 'white' }) {
  const tone = {
    white: 'text-white',
    red: 'text-red-300',
    amber: 'text-amber-300',
    emerald: 'text-emerald-300',
  }[accent];
  return (
    <div className="rounded-2xl bg-white/5 p-4 ring-1 ring-inset ring-white/10 backdrop-blur">
      <p className="text-xs font-medium text-white/55">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
      {sub && <p className="mt-0.5 text-xs text-white/45">{sub}</p>}
    </div>
  );
}

function ForesightCard({ c, onIssue }) {
  const t = TIER[c.tier] || TIER.watch;
  return (
    <Card className={`group relative overflow-hidden p-5 pl-6 ring-1 ${t.ring} shadow-soft transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card`}>
      {/* tier accent rail */}
      <span aria-hidden className={`absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b ${t.bar}`} />

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          {/* car avatar, tinted by urgency */}
          <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ${t.soft} ring-1 ring-inset ring-black/5`}>
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={CAR_ICON} /></svg>
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <Link to={`/vehicles/${c.vehicle_id}`} className="text-lg font-bold tracking-tight text-slate-900 transition group-hover:text-indigo-600">
                {c.plate || c.code || `#${c.vehicle_id}`}
              </Link>
              <Badge tone={t.tone}>{t.label}</Badge>
              {c.parts_wait_risk && <Badge tone="violet" title="This kind of repair has kept cars stuck for weeks before">⏳ May wait for parts</Badge>}
              {c.negative_yield && <Badge tone="red" title="Real net profit over the last 12 months is below its repair spend — it costs more to keep than it earns">📉 Negative yield</Badge>}
            </div>
            <p className="mt-1 text-sm text-slate-500">
              {[c.car, c.year].filter(Boolean).join(' · ')}
              {c.odometer != null && <span> · {num(c.odometer)} km</span>}
            </p>
          </div>
        </div>
        <div className="shrink-0 rounded-xl bg-red-50 px-3.5 py-2 text-right ring-1 ring-inset ring-red-100">
          <p className="text-[10px] font-semibold uppercase tracking-wide text-red-400">Money at risk</p>
          <p className="text-xl font-extrabold tracking-tight text-red-600">{aed2(c.revenue_at_risk)}</p>
        </div>
      </div>

      {/* Why we flagged it — one tidy row per warning (icon · headline · plain reason · evidence),
          replacing the old chips-plus-bullets that showed each warning twice. */}
      <div className="mt-4 space-y-2">
        {c.signals.map((s, i) => {
          const st = TIER[s.tier] || TIER.watch;
          return (
            <div key={i} className="flex gap-3 rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
              <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${st.soft}`}>
                <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={SIGNAL_ICON[s.type] || SIGNAL_ICON.chronic_fault} /></svg>
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-800">
                  {s.label}
                  {s.basis ? <span className="ml-1 text-xs font-normal text-slate-400">({s.basis})</span> : null}
                </p>
                <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{s.detail}</p>
                {s.evidence?.length > 0 && (
                  <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Evidence</span>
                    {s.evidence.map((ev, k) => {
                      const recur = ev.gap_days != null;       // not the first episode
                      const label = ev.kind === 'contract'
                        ? `${ev.visits}× · contract #${ev.contract_no}`
                        : (recur ? `${ev.visits}× · gap ${ev.gap_days} days` : `${ev.visits}× · during rental`);
                      const gapTag = recur && ev.kind === 'contract' ? ` (${ev.gap_days} days later)` : '';
                      const body = <>{recur && <span className="mr-0.5">↻</span>}{label}{gapTag && <span className="opacity-60">{gapTag}</span>}</>;
                      return ev.contract_id ? (
                        <Link
                          key={k}
                          to={`/contracts/${ev.contract_id}`}
                          title="Open this contract's repair history"
                          className="inline-flex items-center gap-0.5 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 transition hover:ring-indigo-400"
                        >
                          {body}
                        </Link>
                      ) : (
                        <span key={k} className="inline-flex items-center gap-0.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                          {body}
                        </span>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* What it could cost */}
      <p className="mt-5 mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">If it goes into the garage</p>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Time off the road" value={`${c.predicted_downtime_days} days`} sub={c.worst_case_days ? `up to ${c.worst_case_days} days` : null} />
        <Stat label="Rent per day" value={aed2(c.daily_rate)} sub="lost while in the garage" />
        <Stat
          label="Repair cost"
          value={c.predicted_repair_cost != null ? aed2(c.predicted_repair_cost) : '—'}
          sub={c.predicted_repair_cost == null ? 'no past cost data'
            : (c.cost_breakdown?.length > 1 ? `all ${c.cost_breakdown.length} problems` : 'estimate')}
        />
        <Stat label="You could save" value={c.potential_saving > 0 ? aed2(c.potential_saving) : '—'} accent={c.potential_saving > 0 ? 'emerald' : 'slate'} />
      </div>

      {/* Real Net Profit — ground-truth profitability over the trailing window (replaces OM income) */}
      <p className="mt-5 mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Is it worth keeping?</p>
      <div className={`flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border p-3 ${c.negative_yield ? 'border-red-200 bg-red-50/50' : 'border-emerald-200/60 bg-emerald-50/40'}`}>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Real net profit · {c.yield_window || 12}mo</p>
          <p className={`text-lg font-bold ${(c.real_net_profit ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{aed2(c.real_net_profit || 0)}</p>
          {c.profit_breakdown && (
            <p className="mt-0.5 text-[11px] text-slate-500">
              Rent {num(c.profit_breakdown.rent_billed)}
              {c.profit_breakdown.discount > 0 && <span className="text-rose-500"> − Disc {num(c.profit_breakdown.discount)}</span>}
              {c.profit_breakdown.realized_usage > 0 && <span> + Usage {num(c.profit_breakdown.realized_usage)}</span>}
              {c.profit_breakdown.operating_cost > 0 && <span> − Costs {num(c.profit_breakdown.operating_cost)}</span>}
            </p>
          )}
        </div>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Repair spend · {c.yield_window || 12}mo</p>
          <p className="text-lg font-bold text-slate-700">{aed2(c.maintenance_spend || 0)}</p>
        </div>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Net yield</p>
          <p className={`text-lg font-bold ${(c.net_yield ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{c.net_yield != null ? aed2(c.net_yield) : '—'}</p>
        </div>
        {c.negative_yield && (
          <span className="ml-auto inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200">
            Costs more than it earns — review for sale
          </span>
        )}

        {/* Methodology note — how the real income figure is built (collapsed by default) */}
        <details className="basis-full">
          <summary className="flex cursor-pointer list-none items-center gap-1 text-[11px] font-medium text-slate-500 hover:text-slate-700">
            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 16v-4m0-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
            How is this calculated?
          </summary>
          <div className="mt-2 space-y-2 rounded-lg bg-white/70 p-3 text-[11px] leading-relaxed text-slate-600 ring-1 ring-inset ring-slate-200">
            <p>
              <span className="font-semibold text-slate-700">Real net profit</span> = Rent billed − Discount + Usage collected − Operating costs,
              added up across this car's <span className="font-medium">rental</span> contracts that went out in the last {c.yield_window || 12} months.
            </p>
            <ul className="space-y-0.5">
              <li><span className="font-semibold text-emerald-600">＋ Rent billed</span> — rent charged on each rental (counted when billed, even if not paid yet).</li>
              <li><span className="font-semibold text-rose-500">− Discount</span> — price cuts, taken off the revenue (not treated as an expense).</li>
              <li><span className="font-semibold text-emerald-600">＋ Usage collected</span> — km, fuel, Cardoo, extra-driver, CDW, GPS, co-driver — only once the customer actually pays.</li>
              <li><span className="font-semibold text-rose-500">− Operating costs</span> — salesman commissions and co-driver cost.</li>
            </ul>
            <p>
              <span className="font-semibold text-slate-700">Net yield</span> = Real net profit − Repair spend (workshop costs over the same {c.yield_window || 12} months).
              A car is flagged <span className="font-medium text-red-600">Negative yield</span> when it earned less than it cost to repair.
            </p>
            <p className="text-slate-400">
              Left out on purpose: VAT, deposits, damages &amp; breaches (wash entries), and booking / maintenance contracts.
              Rent is on a <span className="font-medium">billed</span> basis; usage surcharges on a <span className="font-medium">collected</span> basis.
            </p>
          </div>
        </details>
      </div>

      {/* Cost breakdown — one priced line per flagged problem, each with its confidence */}
      {c.cost_breakdown?.length > 0 && (
        <div className="mt-3 rounded-xl border border-slate-200/70 bg-slate-50/40 p-3">
          <div className="flex items-center justify-between gap-2">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Cost breakdown by problem</p>
            <span className="text-[11px] text-slate-400" title="The workshop records one all-in price per repair. The data does not split parts from labour, so each line is the full repair cost.">
              all-in price (parts + labour)
            </span>
          </div>
          <ul className="mt-2 divide-y divide-slate-200/60">
            {c.cost_breakdown.map((b, i) => (
              <li key={i} className="py-1.5">
                <button
                  type="button"
                  onClick={() => onIssue?.(b.issue)}
                  title="See every car we fixed this on, and what each repair cost"
                  className="group flex w-full items-center justify-between gap-3 text-left text-sm"
                >
                  <span className="flex min-w-0 flex-1 items-center gap-1 truncate capitalize text-slate-700 group-hover:text-indigo-600">
                    <span className="truncate underline decoration-dotted decoration-slate-300 underline-offset-2 group-hover:decoration-indigo-400">{b.issue}</span>
                    <svg className="h-3.5 w-3.5 shrink-0 text-slate-300 group-hover:text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                  </span>
                  <ConfidenceBadge level={b.confidence} samples={b.samples} />
                  <span
                    className={`w-24 shrink-0 text-right font-semibold tabular-nums ${b.variance ? 'text-orange-600' : 'text-slate-900'}`}
                    title={b.variance ? `${b.variance.model} averages ${aed2(b.variance.model_cost)} for this issue — ${b.variance.pct_over}% above the fleet (${b.variance.model_samples} priced repairs)` : undefined}
                  >
                    {b.variance && <span className="mr-0.5">⚠</span>}{aed2(b.cost)}
                  </span>
                </button>
                {b.variance && (
                  <p className="mt-1 flex items-start gap-1.5 rounded-md bg-orange-50 px-2 py-1 text-[11px] text-orange-700 ring-1 ring-inset ring-orange-200">
                    <svg className="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14.14A2 2 0 0 0 3.84 21h16.32a2 2 0 0 0 1.73-3L13.71 3.86a2 2 0 0 0-3.42 0z" /></svg>
                    <span>
                      <span className="font-semibold">Warning:</span> historical data suggests the{' '}
                      <span className="font-medium capitalize">{b.variance.model.toLowerCase()}</span>{' '}
                      costs more to repair than the fleet average for this issue —
                      ~{aed2(b.variance.model_cost)} ({b.variance.pct_over}% higher, {b.variance.model_samples} repairs).
                    </span>
                  </p>
                )}
              </li>
            ))}
            {c.cost_breakdown.length > 1 && (
              <li className="flex items-center justify-between gap-3 pt-2 text-sm">
                <span className="flex-1 font-semibold text-slate-900">Combined estimate</span>
                <span className="w-24 shrink-0 text-right font-bold tabular-nums text-slate-900">{aed2(c.predicted_repair_cost)}</span>
              </li>
            )}
          </ul>
        </div>
      )}

      {/* Recommended action */}
      <div className="mt-4 flex items-start gap-3 rounded-xl bg-gradient-to-r from-indigo-50 to-violet-50/60 px-4 py-3 ring-1 ring-inset ring-indigo-100">
        <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-indigo-500 shadow-sm ring-1 ring-inset ring-indigo-100">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
        </span>
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wide text-indigo-500">What to do</p>
          <p className="mt-0.5 text-sm font-medium text-slate-700">{c.recommended_action}</p>
        </div>
      </div>

      {/* Extra notes + where the numbers come from */}
      {(c.context?.length > 0 || c.estimate_basis) && (
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
          {c.context?.map((ctx, i) => <span key={i}>• {ctx}</span>)}
          {c.estimate_basis && <span className="ml-auto italic">Numbers {c.estimate_basis}</span>}
        </div>
      )}
    </Card>
  );
}

function Stat({ label, value, sub, accent = 'slate' }) {
  const tone = { slate: 'text-slate-900', emerald: 'text-emerald-600', red: 'text-red-600' }[accent];
  return (
    <div className="rounded-xl bg-gradient-to-b from-white to-slate-50/60 px-3 py-2.5 ring-1 ring-inset ring-slate-200/70 transition hover:ring-slate-300">
      <p className="text-[11px] font-medium text-slate-400">{label}</p>
      <p className={`mt-0.5 text-base font-bold tracking-tight ${tone}`}>{value}</p>
      {sub && <p className="text-[11px] text-slate-400">{sub}</p>}
    </div>
  );
}

// Drill-down: every car we fixed a given problem on, with each repair's all-in cost.
// Opened from a "Cost breakdown by problem" line — answers "show me the records and the cost".
function IssueHistoryModal({ issue, onClose }) {
  const [data, setData] = useState(null);
  const [err, setErr] = useState(null);

  useEffect(() => {
    let live = true;
    setData(null);
    setErr(null);
    api.get('/Maintenance/issue-history', { params: { issue } })
      .then((r) => { if (live) setData(r.data.data); })
      .catch((e) => { if (live) setErr(e?.response?.data?.message || 'Could not load the repair history.'); });
    return () => { live = false; };
  }, [issue]);

  const s = data?.summary;
  const subtitle = s
    ? `${num(s.priced)} priced repair${s.priced === 1 ? '' : 's'} · avg ${s.avg_cost != null ? aed2(s.avg_cost) : '—'}`
      + (s.priced > 0 ? ` · ${aed2(s.min_cost)}–${aed2(s.max_cost)} · ${aed2(s.total_cost)} total` : '')
    : 'Where we fixed this — on any car';

  return (
    <Modal open onClose={onClose} title={issue} subtitle={subtitle} size="xl">
      {err ? (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{err}</div>
      ) : !data ? (
        <div className="flex justify-center py-12"><Spinner className="h-7 w-7" /></div>
      ) : data.records.length === 0 ? (
        <EmptyState title="No records yet" message="No workshop repairs for this problem have been logged across the fleet." />
      ) : (
        // Cancel the Modal's body padding so the table runs edge-to-edge with its own scroll.
        <div className="-mx-6 -my-5">
          <div className="max-h-[58vh] overflow-y-auto">
            <table className="min-w-full divide-y divide-slate-100 text-sm">
              <thead className="sticky top-0 z-10 bg-slate-50/95 backdrop-blur">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="px-6 py-2.5">Car</th>
                  <th className="px-3 py-2.5">When</th>
                  <th className="px-3 py-2.5">Garage</th>
                  <th className="px-3 py-2.5 text-right">Days</th>
                  <th className="px-6 py-2.5 text-right">Cost</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {data.records.map((r, i) => (
                  <tr key={i} className="hover:bg-slate-50/60">
                    <td className="px-6 py-2.5">
                      <Link
                        to={`/vehicles/${r.vehicle_id}${r.event_id ? `?event=${r.event_id}` : ''}`}
                        onClick={onClose}
                        className="group block"
                        title="Open this car's maintenance log"
                      >
                        <span className="font-semibold text-indigo-600 group-hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</span>
                        {r.car && <span className="text-slate-400"> · {r.car}</span>}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-3 py-2.5 text-slate-600">
                      {r.out_date}
                      {r.actual_in_date && r.actual_in_date !== r.out_date && <span className="text-slate-400"> → {r.actual_in_date}</span>}
                    </td>
                    <td className="px-3 py-2.5 text-slate-600">{r.garage || <span className="text-slate-300">—</span>}</td>
                    <td className="px-3 py-2.5 text-right text-slate-600">{r.days != null ? r.days : '—'}</td>
                    <td className="px-6 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                      {r.cost != null ? aed2(r.cost) : <span className="text-slate-300">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="border-t border-slate-100 px-6 py-2.5 text-[11px] text-slate-400">
            Each row is one workshop visit — the all-in price (parts + labour). Click a car to open its full maintenance log.
          </p>
        </div>
      )}
    </Modal>
  );
}

export default function MaintenanceForesight() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/foresight');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const [tier, setTier] = useState('all');
  const [issue, setIssue] = useState(null);   // open the cost-line drill-down for this problem

  const cars = useMemo(() => {
    const list = data?.cars || [];
    return tier === 'all' ? list : list.filter((c) => c.tier === tier);
  }, [data, tier]);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;
  if (error) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      </div>
    );
  }

  const s = data?.summary || {};
  const parts = data?.parts_watch || [];
  const sessions = data?.longest_sessions || [];
  const stalling = data?.workshop_stalling || [];

  const FILTERS = [
    { key: 'all', label: 'All', n: s.flagged },
    { key: 'act_now', label: 'Fix now', n: s.act_now },
    { key: 'plan_soon', label: 'Plan soon', n: s.plan_soon },
    { key: 'watch', label: 'Keep an eye', n: s.watch },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Maintenance Foresight"
          subtitle="Cars that may break down soon — caught early. The time off the road and lost money are worked out from your own repair history."
        />

        {/* Hero band */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 shadow-card sm:p-8">
          <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-indigo-500/20 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-24 left-1/4 h-64 w-64 rounded-full bg-violet-500/10 blur-3xl" />
          <div className="relative">
            <p className="text-sm font-medium text-white/70">
              We are watching <span className="font-bold text-white">{num(s.flagged || 0)}</span> cars that may break down soon.
              If we ignore them, that's about <span className="font-bold text-amber-300">{num(s.downtime_days || 0)}</span> days with cars stuck in the garage.
            </p>
            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              <Kpi label="Cars to check" value={num(s.flagged || 0)} sub="before they break" />
              <Kpi label="Fix now" value={num(s.act_now || 0)} accent="red" sub="urgent" />
              <Kpi label="Money at risk" value={aed2(s.revenue_at_risk || 0)} accent="amber" sub="lost rent if they break" />
              <Kpi label="May wait for parts" value={num(s.parts_wait_cars || 0)} accent="white" sub="could get stuck" />
              <Kpi label="Worst-case loss" value={aed2(s.parts_wait_exposure || 0)} accent="red" sub="if they get stuck on parts" />
            </div>
          </div>
        </div>

        {/* Cars stuck the longest — one row per workshop VISIT (de-duped). A visit with several
            faults shows once, with its main problem + a "+N more" count, not one row per fault. */}
        {sessions.length > 0 && (
          <Card className="p-6">
            <div className="mb-1 flex items-center gap-2">
              <span className="text-lg">🅿️</span>
              <h3 className="text-sm font-semibold text-slate-900">Cars stuck the longest</h3>
            </div>
            <p className="mb-4 text-xs text-slate-500">
              One line per workshop visit — the longest stays, regardless of how many problems were fixed in the same session.
              <span className="font-medium text-slate-600"> Click a row to open the exact car and repair.</span>
            </p>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2">Car</th>
                    <th className="px-4 py-2">Workshop</th>
                    <th className="px-4 py-2">In → Out</th>
                    <th className="px-4 py-2 text-right">Stuck</th>
                    <th className="px-4 py-2">Problem(s)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {sessions.map((v, i) => {
                    const to = `/vehicles/${v.vehicle_id}?event=${v.event_id}`;
                    const extra = (v.issue_count || 1) - 1;
                    return (
                      <tr key={i} className="hover:bg-slate-50/60">
                        <td className="px-4 py-2">
                          <Link to={to} className="font-medium text-indigo-600 hover:text-indigo-700">{v.plate}</Link>
                          {v.car && <span className="text-slate-400"> · {v.car}</span>}
                        </td>
                        <td className="px-4 py-2 text-slate-600">{v.garage || '—'}</td>
                        <td className="px-4 py-2 text-slate-500">{v.out_date} → {v.actual_in_date}</td>
                        <td className="px-4 py-2 text-right font-semibold text-red-600">{v.days} days</td>
                        <td className="px-4 py-2">
                          <span className="font-medium text-slate-700">{v.primary}</span>
                          {extra > 0 && (
                            <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-500" title={(v.issues || []).join(', ')}>
                              +{extra} more
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        )}

        {/* Problems that keep cars stuck the longest — per-PROBLEM frequency (each fault counted
            every time it occurs), so you know which parts fail most and should be pre-ordered. */}
        {parts.length > 0 && (
          <Card className="p-6">
            <div className="mb-1 flex items-center gap-2">
              <span className="text-lg">⏳</span>
              <h3 className="text-sm font-semibold text-slate-900">Problems that keep cars stuck the longest</h3>
            </div>
            <p className="mb-4 text-xs text-slate-500">
              Per problem (not per visit): how often each fault shows up and its typical/longest stay when it's the main job — so you know which parts to pre-order. "Times" counts every occurrence, even when a fault shared a visit; "Longest" is credited to the visit's main problem.
              <span className="font-medium text-slate-600"> Click a "longest" number to jump to the exact car and repair.</span>
            </p>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50/60">
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2">Problem</th>
                    <th className="px-4 py-2 text-right">Times</th>
                    <th className="px-4 py-2 text-right">Usual</th>
                    <th className="px-4 py-2 text-right">Longest</th>
                    <th className="px-4 py-2">Worst car</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {parts.map((p, i) => {
                    const o = p.offender;
                    const to = o ? `/vehicles/${o.vehicle_id}?event=${o.event_id}` : null;
                    return (
                      <tr key={i}>
                        <td className="px-4 py-2 font-medium text-slate-900">{p.issue}</td>
                        <td className="px-4 py-2 text-right text-slate-600">{p.visits}</td>
                        <td className="px-4 py-2 text-right text-slate-600">{p.avg_days} days</td>
                        <td className="px-4 py-2 text-right">
                          {to ? (
                            <Link
                              to={to}
                              title={`Check: ${o.plate}${o.car ? ` (${o.car})` : ''} · ${o.out_date} → ${o.actual_in_date}${o.garage ? ` at ${o.garage}` : ''}`}
                              className="inline-flex items-center gap-1 font-semibold text-red-600 underline decoration-dotted underline-offset-2 hover:text-red-700"
                            >
                              {p.max_days} days
                              <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                            </Link>
                          ) : (
                            <span className="font-semibold text-red-600">{p.max_days} days</span>
                          )}
                        </td>
                        <td className="px-4 py-2">
                          {o ? (
                            <Link to={to} className="group block">
                              <span className="font-medium text-indigo-600 group-hover:text-indigo-700">{o.plate}</span>
                              {o.car && <span className="text-slate-400"> · {o.car}</span>}
                              {o.garage && <span className="block text-xs text-slate-400">at {o.garage} · {o.out_date} → {o.actual_in_date}</span>}
                            </Link>
                          ) : (
                            <span className="text-slate-300">—</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        )}

        {/* Workshop stalling — garages holding cars hostage */}
        {stalling.length > 0 && (
          <Card className="p-6">
            <div className="mb-1 flex items-center gap-2">
              <span className="text-lg">⛔</span>
              <h3 className="text-sm font-semibold text-slate-900">Garages that keep cars too long</h3>
            </div>
            <p className="mb-4 text-xs text-slate-500">
              Garages that took the <span className="font-medium text-slate-600">same car in 3 or more times within 10 days for the same problem</span> — they are slow or waiting for parts, not the car's fault. Push these garages, not the car.
            </p>
            <div className="space-y-3">
              {stalling.map((w, i) => (
                <div key={i} className="rounded-xl border border-amber-200 bg-amber-50/40 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-semibold text-slate-900">{w.vendor}</span>
                    <span className="text-xs text-slate-500">{w.incidents} time{w.incidents === 1 ? '' : 's'} · {w.cars} car{w.cars === 1 ? '' : 's'}</span>
                  </div>
                  <div className="mt-2 flex flex-wrap gap-2">
                    {w.samples.map((sm, j) => (
                      <Link key={j} to={`/vehicles/${sm.vehicle_id}`} className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs ring-1 ring-inset ring-amber-200 transition hover:ring-amber-300">
                        <span className="font-medium text-indigo-600">{sm.plate}</span>
                        <span className="text-slate-500">{sm.issue}</span>
                        <span className="font-semibold text-amber-700">{sm.visits} times in {sm.span_days} days</span>
                      </Link>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}

        {/* Tier filter */}
        <div className="flex flex-wrap gap-2">
          {FILTERS.map((f) => (
            <button
              key={f.key}
              onClick={() => setTier(f.key)}
              className={`rounded-full px-4 py-1.5 text-sm font-medium ring-1 ring-inset transition ${
                tier === f.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
              }`}
            >
              {f.label} <span className={tier === f.key ? 'text-white/60' : 'text-slate-400'}>· {num(f.n || 0)}</span>
            </button>
          ))}
        </div>

        {/* Cards */}
        {cars.length === 0 ? (
          <EmptyState title="All good" message="No cars need attention here right now." />
        ) : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {cars.map((c) => <ForesightCard key={c.vehicle_id} c={c} onIssue={setIssue} />)}
          </div>
        )}
      </div>

      {issue && <IssueHistoryModal issue={issue} onClose={() => setIssue(null)} />}
    </div>
  );
}
