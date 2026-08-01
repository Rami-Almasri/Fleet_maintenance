// Recommendation Intelligence — does the operation actually take the garage engine's advice, and where
// does it overrule it?
//
// The whole page is built around one refusal: there is NO single accuracy number. An override because a
// customer asked for a particular workshop is not a model failure — it is information the engine never
// had and mostly should not have. Blending those into one rate produces a figure that gets worse the
// better the operation serves its customers, and that figure would then be used to argue the engine is
// broken. So the engine-judgeable rate leads, the raw rate sits beside it as context, and the excluded
// overrides are shown and named rather than quietly dropped.
//
// The second refusal is about sample size. Below the configured threshold the page shows PROGRESS, not
// conclusions — "12 of 20 decisions" reads as a system collecting evidence; "not enough data" reads as
// one that is broken. No weight suggestion is rendered at all until the gate is met.

import { useEffect, useState } from 'react';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';
import { PageHeader } from '../components/ui/Misc';

// What each axis means for US — the difference between "our weights are wrong" and "the engine is
// working correctly and was overruled on grounds it does not model".
const AXIS_TONE = {
  cost:         { chip: 'bg-amber-50 text-amber-800 ring-amber-600/20', scope: 'tunable' },
  speed:        { chip: 'bg-amber-50 text-amber-800 ring-amber-600/20', scope: 'tunable' },
  availability: { chip: 'bg-amber-50 text-amber-800 ring-amber-600/20', scope: 'tunable' },
  evidence:     { chip: 'bg-sky-50 text-sky-800 ring-sky-600/20', scope: 'data' },
  relationship: { chip: 'bg-slate-100 text-slate-600 ring-slate-400/20', scope: 'external' },
  external:     { chip: 'bg-slate-100 text-slate-600 ring-slate-400/20', scope: 'external' },
  other:        { chip: 'bg-slate-100 text-slate-600 ring-slate-400/20', scope: 'other' },
  not_stated:   { chip: 'bg-rose-50 text-rose-700 ring-rose-600/20', scope: 'gap' },
};

const KIND_TONE = {
  weights:  'bg-amber-50 ring-amber-300 text-amber-900',
  data:     'bg-sky-50 ring-sky-300 text-sky-900',
  capture:  'bg-rose-50 ring-rose-300 text-rose-900',
  tie_break: 'bg-violet-50 ring-violet-300 text-violet-900',
};

const READY_TONE = {
  ready:     { dot: 'bg-emerald-500', ring: 'ring-emerald-300', bar: 'bg-emerald-500' },
  emerging:  { dot: 'bg-amber-400', ring: 'ring-amber-300', bar: 'bg-amber-400' },
  not_ready: { dot: 'bg-slate-300', ring: 'ring-slate-300', bar: 'bg-slate-300' },
};

/** A headline figure with the counts it rests on — never a bare percentage. */
function Stat({ label, value, sub, tone = 'text-slate-900', hint }) {
  return (
    <div className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-3xl font-extrabold tabular-nums ${tone}`}>{value}</p>
      {sub && <p className="mt-0.5 text-[12px] text-slate-500">{sub}</p>}
      {hint && <p className="mt-1 text-[11px] leading-snug text-slate-400">{hint}</p>}
    </div>
  );
}

/**
 * How much evidence exists, per conclusion.
 *
 * Gated separately because they mature at different rates: the acceptance rate is readable long before
 * any per-reason pattern is, and one blanket "not ready" would hide that the headline is already usable.
 */
function Readiness({ readiness, t }) {
  const tr = (k, v) => t(`recoIntel.${k}`, v);
  const tone = READY_TONE[readiness.level] || READY_TONE.not_ready;

  return (
    <section className={`rounded-xl bg-white p-4 ring-1 ring-inset ${tone.ring}`}>
      <div className="flex items-center gap-2">
        <span className={`inline-block h-2.5 w-2.5 rounded-full ${tone.dot}`} />
        <h2 className="text-sm font-bold text-slate-800">{tr('readiness.title')}</h2>
        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          {tr(`readiness.level.${readiness.level}`)}
        </span>
      </div>
      <p className="mt-1 text-[13px] leading-snug text-slate-600">{readiness.message}</p>

      <div className="mt-3 space-y-2">
        {readiness.gates.map((g) => (
          <div key={g.key}>
            <div className="flex items-baseline justify-between gap-2 text-[12px]">
              <span className={g.met ? 'font-semibold text-emerald-700' : 'text-slate-600'}>
                <span aria-hidden className="me-1">{g.met ? '✓' : '⚪'}</span>{tr(`readiness.gate.${g.key}`)}
              </span>
              <span className="tabular-nums text-slate-500">{tr('readiness.progress', { have: g.have, need: g.need })}</span>
            </div>
            <div className="mt-0.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div
                className={`h-full rounded-full ${g.met ? 'bg-emerald-500' : tone.bar}`}
                style={{ width: `${Math.min(100, g.need > 0 ? (g.have / g.need) * 100 : 100)}%` }}
              />
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

export default function RecommendationIntelligence() {
  const { t } = useI18n();
  const tr = (k, v) => t(`recoIntel.${k}`, v);
  const [days, setDays] = useState(180);
  const [state, setState] = useState({ loading: true, data: null, error: false });

  useEffect(() => {
    let alive = true;
    setState({ loading: true, data: null, error: false });
    api.get(`/intelligence/recommendation-learning?days=${days}`)
      .then((r) => alive && setState({ loading: false, data: r.data?.data || null, error: false }))
      .catch(() => alive && setState({ loading: false, data: null, error: true }));
    return () => { alive = false; };
  }, [days]);

  const { loading, data, error } = state;
  const label = (reason) => data?.taxonomy?.[reason]?.label || tr('notStated');

  return (
    <div className="space-y-4 p-4">
      <PageHeader title={tr('title')} subtitle={tr('subtitle')} />

      <div className="flex flex-wrap gap-1.5">
        {[30, 90, 180, 365].map((d) => (
          <button
            key={d}
            type="button"
            onClick={() => setDays(d)}
            className={`rounded-lg px-3 py-1.5 text-[13px] font-semibold transition ${
              days === d ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
          >
            {tr('window', { n: d })}
          </button>
        ))}
      </div>

      {loading && <p className="text-sm text-slate-400">{tr('loading')}</p>}
      {error && <p className="text-sm text-rose-600">{tr('error')}</p>}

      {data && (
        <>
          <Readiness readiness={data.readiness} t={t} />

          {/* THE TWO RATES, deliberately unmerged. The engine-judgeable one leads because it is the
              only one that says anything about the engine; the raw one is context, not a verdict. */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat
              label={tr('stat.adjusted')}
              value={data.adjusted_pct != null ? `${data.adjusted_pct}%` : '—'}
              sub={tr('stat.ofN', { a: data.followed, b: data.in_scope_total })}
              tone="text-emerald-700"
              hint={tr('stat.adjustedHint')}
            />
            <Stat
              label={tr('stat.raw')}
              value={data.acceptance_pct != null ? `${data.acceptance_pct}%` : '—'}
              sub={tr('stat.ofN', { a: data.followed, b: data.total })}
              tone="text-slate-400"
              hint={tr('stat.rawHint')}
            />
            <Stat
              label={tr('stat.overrides')}
              value={data.overridden}
              sub={tr('stat.outOfScope', { n: data.out_of_scope })}
              hint={tr('stat.overridesHint')}
            />
            <Stat
              label={tr('stat.unexplained')}
              value={data.unexplained}
              tone={data.unexplained > 0 ? 'text-rose-600' : 'text-slate-900'}
              hint={tr('stat.unexplainedHint')}
            />
          </div>

          {/* WHERE the disagreement is. Scope is shown per row so an "external" override is never read
              as a model miss sitting in the same list as a cost one. */}
          {data.by_reason?.length > 0 && (
            <section className="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-200">
              <h2 className="border-b border-slate-200 px-4 py-2 text-sm font-bold text-slate-800">{tr('reasons.title')}</h2>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-[13px]">
                  <thead className="text-[11px] uppercase tracking-wide text-slate-400">
                    <tr>
                      <th className="px-4 py-2 text-start font-semibold">{tr('reasons.reason')}</th>
                      <th className="px-4 py-2 text-start font-semibold">{tr('reasons.means')}</th>
                      <th className="px-4 py-2 text-end font-semibold">{tr('reasons.count')}</th>
                      <th className="px-4 py-2 text-end font-semibold">{tr('reasons.gap')}</th>
                      <th className="px-4 py-2 text-end font-semibold">{tr('reasons.backed')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.by_reason.map((r) => {
                      const tone = AXIS_TONE[r.axis] || AXIS_TONE.other;
                      return (
                        <tr key={r.reason} className="border-t border-slate-100">
                          <td className="px-4 py-2 font-medium text-slate-800">{r.reason === 'not_stated' ? tr('notStated') : label(r.reason)}</td>
                          <td className="px-4 py-2">
                            <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ring-1 ring-inset ${tone.chip}`}>
                              {tr(`scope.${tone.scope}`)}
                            </span>
                          </td>
                          <td className="px-4 py-2 text-end tabular-nums text-slate-700">{r.count}</td>
                          <td className="px-4 py-2 text-end tabular-nums text-slate-500">
                            {r.median_gap ?? '—'}
                            {r.near_ties > 0 && <span className="ms-1 text-[11px] text-violet-600">{tr('reasons.nearTies', { n: r.near_ties })}</span>}
                          </td>
                          {/* Only measurable axes can be corroborated. Nobody can check "the customer
                              asked for it" against a repair history, and printing 0/8 there would imply
                              the supervisor was making it up. */}
                          <td className="px-4 py-2 text-end tabular-nums text-slate-500">
                            {tone.scope === 'tunable' ? `${r.corroborated}/${r.count}` : <span className="text-slate-300">{tr('reasons.na')}</span>}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <p className="border-t border-slate-100 px-4 py-2 text-[11px] leading-snug text-slate-400">{tr('reasons.footnote')}</p>
            </section>
          )}

          {/* The same substitution, repeatedly — a standing preference the engine has not been told
              about. Invisible in the per-reason totals, and usually the most actionable thing here. */}
          {data.repeat_pairs?.length > 0 && (
            <section className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
              <h2 className="text-sm font-bold text-slate-800">{tr('pairs.title')}</h2>
              <p className="mt-0.5 text-[12px] leading-snug text-slate-500">{tr('pairs.intro')}</p>
              <div className="mt-2 space-y-1.5">
                {data.repeat_pairs.map((p) => (
                  <div key={`${p.recommended_vendor_id}-${p.chosen_vendor_id}`} className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-[13px] ring-1 ring-inset ring-slate-200">
                    <span className="font-semibold tabular-nums text-slate-800">{tr('pairs.times', { n: p.count })}</span>
                    <span className="text-slate-500">{p.recommended_garage}</span>
                    <span aria-hidden className="text-slate-400">→</span>
                    <span className="font-semibold text-slate-800">{p.chosen_garage}</span>
                    <span className="text-[11px] text-slate-500">{label(p.top_reason)}</span>
                    {p.median_gap != null && <span className="text-[11px] tabular-nums text-slate-400">{tr('pairs.gap', { n: p.median_gap })}</span>}
                    {!p.in_scope && (
                      <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 ring-1 ring-inset ring-slate-400/20">
                        {tr('scope.external')}
                      </span>
                    )}
                  </div>
                ))}
              </div>
            </section>
          )}

          {/* PROPOSALS — and only once the gate is met. The backend returns an empty list below the
              threshold, so there is nothing here to accidentally render from three decisions. */}
          <section className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
            <h2 className="text-sm font-bold text-slate-800">{tr('suggestions.title')}</h2>
            {data.suggestions?.length > 0 ? (
              <>
                <p className="mt-0.5 text-[12px] text-slate-500">{tr('suggestions.intro')}</p>
                <div className="mt-2 space-y-2">
                  {data.suggestions.map((s, i) => (
                    <div key={i} className={`rounded-lg p-3 ring-1 ring-inset ${KIND_TONE[s.kind] || KIND_TONE.data}`}>
                      <p className="text-[11px] font-bold uppercase tracking-wide opacity-70">{tr(`suggestions.kind.${s.kind}`)}</p>
                      <p className="mt-0.5 text-[13px] leading-snug">{s.detail}</p>
                      <p className="mt-1 text-[13px] font-semibold leading-snug">→ {s.action}</p>
                    </div>
                  ))}
                </div>
              </>
            ) : (
              <p className="mt-1 text-[13px] leading-snug text-slate-500">
                {data.sufficient ? tr('suggestions.none') : tr('suggestions.blocked')}
              </p>
            )}
          </section>
        </>
      )}
    </div>
  );
}
