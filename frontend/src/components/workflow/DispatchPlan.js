// STAGE 3 — THE DISPATCH PLAN.
//
// The assign step used to render a report: fault chips, a scan table, one full card per fault, then —
// at position six — the actual verdict. A supervisor had to read roughly two thousand words to find the
// answer, and the same fact ("this garage has stronger Engine history") appeared in five places.
//
// This is a plan you edit, not a report you read. Four layers, in the order the questions get asked:
//
//   0  THE CALL      one sentence, one confidence, one button. Trust the engine and you are done here.
//   1  THE PLAN      one row per fault under the CURRENTLY chosen garage — is it the best for that
//                    fault, and if not, who is and by how much.
//   2  THE IMPACT    cost, days off road, first-time-fix for the plan as it now stands, each with a
//                    live delta against the recommendation. This is "what happens if I ignore you".
//   3  THE EVIDENCE  everything that used to be on screen, one disclosure away.
//
// WHY THE GARAGE PICKER IS THE ONLY EDITABLE CONTROL. A maintenance ticket is a single-garage container
// ([[maintenance-tasks-container-model]]), so a per-row garage dropdown would be a promise the backend
// cannot keep. Instead the ONE choice is ticket-level, and the plan shows what that choice costs EACH
// fault — which is the honest version of the same question, and needs no schema change. Where the engine
// would rather split the work, it says so as advice and names what the single-garage call gives up.
//
// See [[garage-recommendation-engine]], [[reason-code-contract]], [[explainability-platform]].

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import GarageRecommendations from './GarageRecommendations';
import RepairIntelligencePanel from '../knowledge/RepairIntelligencePanel';
import { renderReasons } from './reasons';
import { days } from './format';

const CRIT_STYLE = {
  safety_critical:  'bg-rose-50 text-rose-700 ring-rose-600/20',
  major_mechanical: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  operational:      'bg-sky-50 text-sky-700 ring-sky-600/20',
  cosmetic:         'bg-slate-100 text-slate-500 ring-slate-300',
};

// Coverage tier as colour. The whole point of the plan table is that a supervisor reads DOWN the
// rightmost column and sees, without reading a number, which faults this garage is weak on.
const TIER = {
  exact:   { dot: 'bg-emerald-500', text: 'text-emerald-700' },
  domain:  { dot: 'bg-sky-500',     text: 'text-sky-700' },
  general: { dot: 'bg-amber-400',   text: 'text-amber-700' },
  none:    { dot: 'bg-slate-300',   text: 'text-slate-400' },
};

const CONF_STYLE = {
  high:   'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
  medium: 'bg-amber-100 text-amber-800 ring-amber-600/20',
  low:    'bg-slate-100 text-slate-600 ring-slate-500/20',
};


/**
 * A figure for the plan as it stands, and how far that is from the recommendation.
 *
 * The delta is the entire point. "AED 550" tells a supervisor nothing about whether their override is
 * expensive; "AED 550, +130 vs recommended" tells them exactly what the choice costs. Direction is
 * coloured by whether it is GOOD, not by its sign — fewer days is good, lower first-time-fix is not.
 */
/**
 * PREVIOUS SIMILAR REPAIRS — what happened last time this fleet repaired this fault.
 *
 * It sits on the assign step because it is the one block here that is about the GARAGES: which shop
 * fixed this fault before, how long it took them, and whether it came back. That is evidence for the
 * choice being made on this screen, and it was previously stranded on the ticket drawer where the
 * choice is already history. The expected work ("What the garage will do") went the other way — see
 * [[RepairOutlook]].
 *
 * One panel per FINDING, keyed on the real task where the fault has been promoted to one, and falling
 * back to a symptom preview for a finding that has not. Collapsed by default: the call, the plan and
 * the impact answer the question for most tickets, and this is the second opinion you go looking for.
 */
function PriorRepairs({ faults, vehicleId, open, onToggle, t }) {
  return (
    <div className="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-300">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start transition ${
          open ? 'bg-white' : 'hover:bg-slate-50'}`}
      >
        <span className="min-w-0">
          <span className="block text-[12px] font-semibold text-slate-700">{t('repairIntel.title')}</span>
          <span className="block text-[11px] leading-snug text-slate-400">
            {t('repairIntel.dispatchSubtitle', { n: faults.length })}
          </span>
        </span>
        <Icon.ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="space-y-2.5 border-t border-slate-100 bg-slate-50/60 p-3">
          {faults.map((f, i) => (
            <div key={f.task_id || `${f.symptom}-${i}`} className="space-y-1">
              <p className="text-[12px] font-semibold text-slate-800">{f.symptom || f.label}</p>
              <RepairIntelligencePanel
                taskId={f.task_id || undefined}
                preview={!f.task_id && vehicleId ? { vehicleId, symptom: f.symptom, categoryKey: f.category_key } : undefined}
              />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function Impact({ label, value, delta, goodWhenLower, hint }) {
  const meaningful = delta != null && Math.abs(delta) >= 0.05;
  const better = meaningful && (goodWhenLower ? delta < 0 : delta > 0);

  return (
    <div className="min-w-0 flex-1 px-3 py-2">
      <p className="truncate text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 text-[17px] font-bold leading-none tabular-nums text-slate-900">{value}</p>
      {meaningful ? (
        <p className={`mt-1 text-[11px] font-semibold tabular-nums ${better ? 'text-emerald-700' : 'text-rose-700'}`}>
          {delta > 0 ? '+' : '−'}{Math.abs(Math.round(delta * 10) / 10)} {hint}
        </p>
      ) : (
        <p className="mt-1 text-[11px] text-slate-300">{hint}</p>
      )}
    </div>
  );
}

export default function DispatchPlan({ ticketId, garages = [], selectedVendorId, onPick, onResult }) {
  const { t } = useI18n();
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  // Nested under garageRec so every label this feature owns lives in one place.
  const dp = (k, v) => t(`workflow.garageRec.dispatchPlan.${k}`, v);

  const [state, setState] = useState({ loading: true, data: null, error: false });
  const [showEvidence, setShowEvidence] = useState(false);
  // CLOSED by default, unlike the expected work that used to sit here: prior repairs are a second
  // opinion on the call, not the first thing to read. Each panel inside also fetches on its own, so
  // opening it is what pays for it.
  const [priorOpen, setPriorOpen] = useState(false);

  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    setState({ loading: true, data: null, error: false });
    api
      .get(`/maintenance-tickets/${ticketId}/garage-recommendations`)
      .then((res) => {
        if (!alive) return;
        const data = res.data?.data || null;
        setState({ loading: false, data, error: false });
        if (data && onResult) onResult(data);
      })
      .catch(() => alive && setState({ loading: false, data: null, error: true }));
    return () => { alive = false; };
  }, [ticketId]); // eslint-disable-line react-hooks/exhaustive-deps

  const { loading, data, error } = state;
  const primary = useMemo(() => data?.primary || [], [data]);
  const perFault = useMemo(() => data?.per_fault || [], [data]);
  // Per FINDING, not per category — `per_fault` keeps only the first symptom of each category, so a car
  // with two engine faults would otherwise show prior repairs for one of them.
  const faultsDetail = useMemo(() => data?.ticket?.faults_detail || [], [data]);
  const recommended = primary[0] || null;

  // The garage the plan is currently describing: whatever is selected, falling back to the engine's
  // pick so the plan is never blank while the supervisor is still deciding.
  const chosen = useMemo(() => {
    if (!selectedVendorId) return recommended;
    return primary.find((p) => String(p.vendor_id) === String(selectedVendorId)) || null;
  }, [primary, recommended, selectedVendorId]);

  // A garage picked from the full list that the engine never scored — no history for this vehicle+fault
  // combination at all. It has to be sayable, not silently blank.
  const unscored = selectedVendorId && !chosen
    ? garages.find((g) => String(g.id ?? g.vendor_id) === String(selectedVendorId)) || null
    : null;

  const coverageByCat = useMemo(() => {
    const map = {};
    (chosen?.fault_coverage || []).forEach((fc) => { map[fc.category_key] = fc; });
    return map;
  }, [chosen]);

  // TWO different questions that must never share one flag.
  //
  //   viewingRecommendation — is the plan describing the engine's pick? Drives the FRAMING: whether the
  //                           impact strip reads "what to expect" or "what your choice changes", and
  //                           whether the plan header is tagged as an override.
  //   hasAccepted           — has the supervisor actually committed to it? Drives Layer 0 alone.
  //
  // Collapsing them made an UNTOUCHED form label itself "· your choice" and "what your choice changes",
  // accusing the supervisor of an override they had not made — and then show no deltas, because there
  // was nothing to compare. Two names, two jobs.
  const viewingRecommendation = !!chosen && !!recommended
    && String(chosen.vendor_id) === String(recommended.vendor_id);
  const hasAccepted = !!selectedVendorId && viewingRecommendation;

  // ── Layer 2 arithmetic — the chosen plan against the recommended one ────────────────────────────
  const impact = useMemo(() => {
    const o = chosen?.outcomes;
    const r = recommended?.outcomes;
    if (!o) return null;
    const d = (a, b) => (a?.value != null && b?.value != null ? a.value - b.value : null);
    return {
      cost:    { v: o.cost_aed?.value, delta: viewingRecommendation ? null : d(o.cost_aed, r?.cost_aed) },
      duration: { v: o.duration_days?.value, delta: viewingRecommendation ? null : d(o.duration_days, r?.duration_days) },
      success: { v: o.success_pct?.value, delta: viewingRecommendation ? null : d(o.success_pct, r?.success_pct) },
    };
  }, [chosen, recommended, viewingRecommendation]);

  if (loading) {
    return (
      <div className="rounded-xl bg-slate-50 p-4 text-xs text-slate-400 ring-1 ring-inset ring-slate-200">
        {gr('loading')}
      </div>
    );
  }
  if (error || !data) return null;
  if (!recommended) {
    return (
      <div className="rounded-xl border border-amber-300 bg-amber-50/70 p-3">
        <p className="flex items-center gap-1.5 text-[13px] font-bold text-amber-800">
          <Icon.Alert className="h-4 w-4 shrink-0" /> {gr('noPrimaryTitle')}
        </p>
        <p className="mt-1 text-[12px] leading-snug text-amber-800/90">{gr('noPrimaryNoAlso')}</p>
      </div>
    );
  }

  const summary = data?.strategy?.summary;
  const strategy = data?.strategy;
  // The engine would rather split, but a ticket goes to ONE garage — so the split is advice about what
  // this call gives up, never an action. Saying it plainly is better than hiding a real finding.
  const wantsSplit = strategy?.mode === 'split' && (strategy?.legs?.length || 0) > 1;

  // Faults this garage is measurably weaker on than the best available shop for that fault.
  const weakSpots = perFault.filter((f) => {
    const cov = coverageByCat[f.category_key];
    const best = f.winner;
    if (!chosen || !best) return false;
    if (String(best.vendor_id) === String(chosen.vendor_id)) return false;
    // Same bar as the column above — a warning that fires on faults the table calls "comparable" is
    // the panel contradicting itself in two places at once.
    const RANK = { exact: 3, domain: 2, general: 1, none: 0 };
    return (best.coverage_pct ?? 0) - (cov?.pct ?? 0) >= 10
      && (RANK[best.tier] ?? 0) >= (RANK[cov?.tier] ?? 0);
  });

  return (
    <div className="space-y-2.5">
      {/* ─── LAYER 0 · THE CALL ─────────────────────────────────────────────────────────────────
          One sentence, one confidence. A supervisor who trusts the engine reads this, picks that
          garage below and never reads past here. */}
      <div className="rounded-xl border-2 border-indigo-500/70 bg-white p-3.5 shadow-sm">
        <p className="text-[10px] font-bold uppercase tracking-wide text-indigo-600">{dp('call.eyebrow')}</p>
        <div className="mt-1 flex flex-wrap items-baseline justify-between gap-2">
          <p className="text-[19px] font-bold leading-tight text-slate-900">
            {perFault.length === 1
              ? dp('call.headlineOne', { garage: recommended.garage })
              : dp('call.headline', { n: perFault.length, garage: recommended.garage })}
          </p>
          <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${CONF_STYLE[recommended.confidence] || CONF_STYLE.low}`}>
            {gr(`confidence.${recommended.confidence}`)} · {recommended.match_score}/100
          </span>
        </div>

        {/* The one line of trade-off. Never more — the detail is Layer 3's job. */}
        {summary?.reason && <p className="mt-1.5 text-[12px] leading-snug text-slate-500">{summary.reason}</p>}

        {/* A LOW-confidence call is a materially different thing from a confident one, and the chip
            alone does not say so — "Low · 66/100" reads as a grade, not as a warning to go looking. */}
        {recommended.confidence === 'low' && (
          <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] leading-snug text-amber-800 ring-1 ring-inset ring-amber-600/15">
            <Icon.Alert className="mt-px h-3.5 w-3.5 shrink-0" /> {dp('call.lowConfidence')}
          </p>
        )}

        {/* NO ACCEPT BUTTON. The card states the call; the garage picker below is where a choice is
            made. Two controls that both set the same field — one of them pre-filling it with the
            engine's answer — meant the supervisor could "accept" here and then be looking at a
            dropdown that appears to be asking the question again. The confirmation stays: once the
            picker holds the recommended garage, this says so. */}
        {hasAccepted && (
          <p className="mt-3 flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-[13px] font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20">
            <Icon.Check className="h-4 w-4 shrink-0" /> {dp('call.confirmed')}
          </p>
        )}
      </div>

      {/* A split the engine wanted but the ticket model cannot execute. Advice, stated as advice. */}
      {wantsSplit && (
        <div className="rounded-xl bg-amber-50/70 px-3 py-2 text-[12px] leading-snug text-amber-800 ring-1 ring-inset ring-amber-500/25">
          <p className="font-semibold">{dp('split.title')}</p>
          <p className="mt-0.5">{strategy.reason}</p>
          <p className="mt-0.5 text-[11px] opacity-80">{dp('split.note')}</p>
        </div>
      )}

      {/* WHAT HAPPENED LAST TIME. The garage table argues from coverage and outcomes; this is the raw
          cohort those numbers were computed over — the actual jobs, the shops that did them, and
          whether they held. It belongs on this screen because it is evidence about GARAGES, and it is
          collapsed because the call above already used it.

          Keyed per FINDING, not per fault category. Two engine faults on one ticket are two panels;
          folding them into a single "Engine" row (which is how the per-fault cards below are keyed)
          would silently drop the second symptom. */}
      {faultsDetail.length > 0 && (
        <PriorRepairs
          faults={faultsDetail}
          vehicleId={data?.ticket?.vehicle_id}
          open={priorOpen}
          onToggle={() => setPriorOpen((o) => !o)}
          t={t}
        />
      )}

      {/* ─── LAYER 1 · THE PLAN ─────────────────────────────────────────────────────────────────
          Every fault, under the garage currently chosen. The question this answers is not "who is
          best overall" but "what does MY choice mean for each thing wrong with this car". */}
      <div className="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-300">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
          <p className="text-[11px] font-bold uppercase tracking-wide text-slate-500">{dp('plan.title')}</p>
          <p className="truncate text-[12px] font-semibold text-slate-700">
            {chosen?.garage || unscored?.name || unscored?.label || dp('plan.noGarage')}
            {!viewingRecommendation && chosen && <span className="ms-1.5 font-normal text-amber-700">{dp('plan.overridden')}</span>}
          </p>
        </div>

        {unscored && (
          <p className="border-b border-slate-100 bg-amber-50/60 px-3 py-2 text-[11px] leading-snug text-amber-800">
            {dp('plan.unscored', { garage: unscored.name || unscored.label })}
          </p>
        )}

        <table className="w-full text-[12px]">
          <thead>
            <tr className="text-[10px] uppercase tracking-wide text-slate-400">
              <th className="px-3 py-1.5 text-start font-semibold">{dp('plan.colFault')}</th>
              <th className="px-3 py-1.5 text-start font-semibold">{dp('plan.colHere')}</th>
              <th className="px-3 py-1.5 text-end font-semibold">{dp('plan.colBest')}</th>
            </tr>
          </thead>
          <tbody>
            {perFault.map((f) => {
              const cov = coverageByCat[f.category_key];
              const tier = TIER[cov?.tier] || TIER.none;
              const best = f.winner;
              const isBestHere = chosen && best && String(best.vendor_id) === String(chosen.vendor_id);
              const gap = (best?.coverage_pct ?? 0) - (cov?.pct ?? 0);

              // Is the other garage ACTUALLY better, or merely ranked first by a different yardstick?
              //
              // per_fault.winner is ranked on fault-coverage points; the ticket-level call is ranked on
              // overall fit. On a single-fault ticket those can disagree by a couple of points and the
              // panel then argues with itself — Layer 0 naming one garage while this column implies
              // another. Worse, coverage % is comparable ACROSS tiers: a garage with same-model history
              // can score lower than one with only general history, and printing the higher number wins
              // the argument for the weaker evidence.
              //
              // So another garage is named here only when it is materially ahead AND not on thinner
              // evidence. Everything else reads as what it is: comparable.
              const RANK = { exact: 3, domain: 2, general: 1, none: 0 };
              const materiallyBetter = !!best && !isBestHere
                && gap >= 10
                && (RANK[best.tier] ?? 0) >= (RANK[cov?.tier] ?? 0);

              return (
                <tr key={f.category_key} className="border-t border-slate-100 align-top">
                  <td className="px-3 py-2">
                    <span className="font-semibold text-slate-800">{f.symptom}</span>
                    {f.criticality && (
                      <span className={`ms-1.5 rounded px-1 py-0.5 text-[9px] font-semibold ring-1 ring-inset ${CRIT_STYLE[f.criticality]}`}>
                        {f.criticality_label} ×{f.weight}
                      </span>
                    )}
                  </td>

                  {/* What the CHOSEN garage brings to this specific fault. */}
                  <td className="px-3 py-2">
                    {cov ? (
                      <span className={`flex items-center gap-1.5 font-semibold tabular-nums ${tier.text}`}>
                        <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${tier.dot}`} />
                        {cov.pct}%
                        <span className="font-normal text-slate-400">{gr(`tier.${cov.tier}`)}</span>
                      </span>
                    ) : (
                      <span className="text-slate-400">{dp('plan.noRecord')}</span>
                    )}
                  </td>

                  {/* …and whether anyone is meaningfully better at it. */}
                  <td className="px-3 py-2 text-end">
                    {isBestHere ? (
                      <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700">
                        <Icon.Check className="h-3.5 w-3.5" /> {dp('plan.bestAlready')}
                      </span>
                    ) : materiallyBetter ? (
                      <>
                        <span className="block font-semibold text-amber-800">{best.garage}</span>
                        <span className="block text-[11px] tabular-nums text-amber-700">
                          {best.coverage_pct}% · {dp('plan.gap', { n: Math.round(gap) })}
                        </span>
                        {f.short_reason?.length > 0 && (
                          <span className="block text-[10px] leading-snug text-slate-400">
                            {renderReasons(t, f.short_reason, ' + ')}
                          </span>
                        )}
                      </>
                    ) : best ? (
                      // Someone else ranks first on this fault, but not by enough — or not on better
                      // evidence — to be worth unsettling the call over. Say so plainly instead of
                      // printing a rival name that silently contradicts Layer 0.
                      <span className="inline-flex items-center gap-1 text-[11px] text-slate-400">
                        {dp('plan.comparable', { garage: best.garage })}
                      </span>
                    ) : (
                      <span className="text-[11px] text-slate-400">{dp('plan.noAlternative')}</span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        {/* The single sentence a supervisor most needs before overriding. */}
        {weakSpots.length > 0 && (
          <p className="border-t border-slate-100 bg-amber-50/60 px-3 py-2 text-[11px] leading-snug text-amber-800">
            <Icon.Alert className="me-1 inline h-3.5 w-3.5 -translate-y-px" />
            {dp('plan.weakWarning', { faults: weakSpots.map((f) => f.symptom).join(gr('listComma')) })}
          </p>
        )}

      </div>

      {/* ─── LAYER 2 · THE IMPACT ───────────────────────────────────────────────────────────────
          What this plan is expected to cost, take, and hold — and how far that is from the
          recommendation. Nothing in the old panel answered "what happens if I choose differently". */}
      {impact && (
        <div className="rounded-xl bg-white ring-1 ring-inset ring-slate-300">
          <p className="border-b border-slate-200 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
            {viewingRecommendation ? dp('impact.title') : dp('impact.titleOverride')}
          </p>
          <div className="flex divide-x divide-slate-100">
            <Impact
              label={dp('impact.cost')}
              value={impact.cost.v != null ? gr('outcomes.aed', { n: Math.round(impact.cost.v) }) : '—'}
              delta={impact.cost.delta} goodWhenLower hint={dp('impact.vsRec')}
            />
            <Impact
              label={dp('impact.duration')}
              value={days(impact.duration.v, gr)}
              delta={impact.duration.delta} goodWhenLower hint={dp('impact.vsRec')}
            />
            <Impact
              label={dp('impact.success')}
              value={impact.success.v != null ? `${impact.success.v}%` : '—'}
              delta={impact.success.delta} hint={dp('impact.vsRec')}
            />
          </div>
          {/* Cost here is attributed from past spending, not a quote. Saying so once, at the number
              itself, is what stops it being read as a price the garage has agreed to. */}
          <p className="border-t border-slate-100 px-3 py-1.5 text-[10px] leading-snug text-slate-400">
            {gr('costBasis.budgetNote')}
          </p>
        </div>
      )}

      {/* ─── LAYER 3 · THE EVIDENCE ─────────────────────────────────────────────────────────────
          In place, not on another page. Sending the supervisor away to read the working meant losing
          the form they were filling in; the real problem was never the location, it was that the dialog
          was 512px wide and everything inside it wrapped. The assign step now opens at `xl`, which is
          the room the comparison cards were always designed for. */}
      <div className="overflow-hidden rounded-xl ring-1 ring-inset ring-slate-200">
        <button
          type="button"
          onClick={() => setShowEvidence((v) => !v)}
          aria-expanded={showEvidence}
          className={`flex w-full items-center justify-between gap-3 px-3.5 py-3 text-start transition ${
            showEvidence ? 'bg-white' : 'bg-slate-50 hover:bg-slate-100'}`}
        >
          <span className="min-w-0">
            <span className="block text-[12px] font-semibold text-slate-700">{dp('evidence.title')}</span>
            <span className="block text-[11px] leading-snug text-slate-400">{dp('evidence.subtitle')}</span>
          </span>
          <Icon.ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${showEvidence ? 'rotate-180' : ''}`} />
        </button>
        {showEvidence && (
          <div className="border-t border-slate-100 bg-slate-50/60 p-3">
            <GarageRecommendations
              payload={data}
              selectedVendorId={selectedVendorId}
              onPick={onPick}
              chrome={false}
            />
          </div>
        )}
      </div>
    </div>
  );
}
