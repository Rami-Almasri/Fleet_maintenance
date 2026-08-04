// STAGE 3 — THE DISPATCH PLAN.
//
// The assign step used to render a report: fault chips, a scan table, one full card per fault, then —
// at position six — the actual verdict. A supervisor had to read roughly two thousand words to find the
// answer, and the same fact ("this garage has stronger Engine history") appeared in five places.
//
// This is a plan you edit, not a report you read. ONE card, read top to bottom, in the order the
// questions get asked:
//
//   THE CALL      one sentence, one confidence word. Trust the engine and you are done here.
//   WHAT TO EXPECT  cost, days off road, repairs that hold — for the plan as it now stands, each
//                   carrying a live delta against the recommendation when the two differ. This is
//                   "what happens if I ignore you", and it is silent when there is nothing to compare.
//   FAULT BY FAULT  one line per fault under the CURRENTLY chosen garage, as a phrase — is this the
//                   best shop for that fault, and if not, who is.
//   THE EVIDENCE  the scores, tiers and working, one disclosure away.
//
// WHY ONE CARD AND NOT THREE. The call, the plan and the impact each used to be a separate framed
// card with its own uppercase heading, and the fault table printed a percentage, a tier word, a
// criticality label and a weight multiplier on every row. Three frames and four numbers to answer one
// question — "where does this car go". The facts did not change here; the furniture around them did.
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

// CRIT_STYLE / TIER / CONF_STYLE lived here — the criticality chip, the coverage-tier dot and the
// confidence pill that the dispatch card printed on every fault row. They went with the card.

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
 *
 * TWO CLICKS, NOT ONE. Opening this used to render every fault's panel at once — on a four-fault ticket
 * that is four knowledge cards, each with its own match score, its matched wording, its co-occurrence
 * lines, its usual causes and its usual fixes, stacked into a wall nobody reads. The first click now
 * lists the faults and nothing else; the second opens the ONE you asked about, and closes whichever was
 * open before. It is also what stops four panels each firing their own fetch for history the supervisor
 * never asked to see.
 */
function PriorRepairs({ faults, vehicleId, open, onToggle, t }) {
  const [openFault, setOpenFault] = useState(null);

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
        <ul className="border-t border-slate-200">
          {faults.map((f, i) => {
            const key = f.task_id || `${f.symptom}-${i}`;
            const isOpen = openFault === key;
            return (
              <li key={key} className="border-b border-slate-100 last:border-b-0">
                <button
                  type="button"
                  onClick={() => setOpenFault(isOpen ? null : key)}
                  aria-expanded={isOpen}
                  className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-[12.5px] font-semibold transition ${
                    isOpen ? 'bg-slate-50 text-slate-900' : 'text-slate-700 hover:bg-slate-50'}`}
                >
                  <span className="min-w-0 truncate">{f.symptom || f.label}</span>
                  <Icon.ChevronDown className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition ${isOpen ? 'rotate-180' : ''}`} />
                </button>
                {isOpen && (
                  <div className="bg-slate-50/60 p-3 pt-0">
                    <RepairIntelligencePanel
                      taskId={f.task_id || undefined}
                      preview={!f.task_id && vehicleId ? { vehicleId, symptom: f.symptom, categoryKey: f.category_key } : undefined}
                    />
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

// `Expect` — one figure of the "what to expect" strip (value, label, and the delta against the
// recommendation) — was deleted with the card that was its only caller.

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
  // Per FINDING, not per category — `per_fault` keeps only the first symptom of each category, so a car
  // with two engine faults would otherwise show prior repairs for one of them.
  const faultsDetail = useMemo(() => data?.ticket?.faults_detail || [], [data]);
  // The engine's top-ranked garage. Nothing on this screen names it any more; it survives as the test
  // for whether the engine scored ANYTHING, which is the difference between showing the evidence
  // disclosure and showing the "no proven garage yet" notice.
  const recommended = data?.primary?.[0] || null;

  // GONE WITH THE CARD: `chosen` / `unscored` (which garage the plan was describing), `coverageByCat`
  // (per-fault coverage for that garage), `viewingRecommendation` / `hasAccepted` (override framing),
  // `impact` (downtime and repairs-hold, with the delta against the recommendation) and `weakSpots`.
  // The comparison that arithmetic fed is still available inside the evidence panel, computed there.

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

  const strategy = data?.strategy;
  // The engine would rather split, but a ticket goes to ONE garage — so the split is advice about what
  // this call gives up, never an action. Saying it plainly is better than hiding a real finding.
  const wantsSplit = strategy?.mode === 'split' && (strategy?.legs?.length || 0) > 1;

  return (
    <div className="space-y-2.5">
      {/* ─── THE DISPATCH CARD — REMOVED ────────────────────────────────────────────────────────
          This is where the card stated the call: "Send all 3 faults to X", the confidence word, the
          one-line trade-off, the expected downtime and repairs-hold figures, and then every fault
          with its coverage percentage under the garage currently chosen.

          All of it is gone by request. The engine still ranks the garages — that working now lives
          entirely in the evidence disclosure at the bottom of this component, which a supervisor
          opens when they want it, rather than being asserted at the top of the step. What remains
          above the picker is the split advice (when the engine wanted one) and what the platform
          knows about the faults themselves.

          Deliberately NOT deleted with it: `recommended` is still computed, because the
          no-recommendation notice above and the evidence panel below both depend on it. */}

      {/* A split the engine wanted but the ticket model cannot execute. Advice, stated as advice. */}
      {wantsSplit && (
        <div className="rounded-xl bg-amber-50/70 px-3 py-2 text-[12px] leading-snug text-amber-800 ring-1 ring-inset ring-amber-500/25">
          <p className="font-semibold">{dp('split.title')}</p>
          <p className="mt-0.5">{strategy.reason}</p>
          <p className="mt-0.5 text-[11px] opacity-80">{dp('split.note')}</p>
        </div>
      )}

      {/* WHAT HAPPENED LAST TIME. The card above argues from coverage and outcomes; this is the raw
          cohort those numbers were computed over — the actual jobs, the shops that did them, and
          whether they held. It belongs on this screen because it is evidence about GARAGES, and it is
          collapsed because the call above already used it.

          Keyed per FINDING, not per fault category. Two engine faults on one ticket are two panels;
          folding them into a single "Engine" row (which is how the fault lines above are keyed) would
          silently drop the second symptom. */}
      {faultsDetail.length > 0 && (
        <PriorRepairs
          faults={faultsDetail}
          vehicleId={data?.ticket?.vehicle_id}
          open={priorOpen}
          onToggle={() => setPriorOpen((o) => !o)}
          t={t}
        />
      )}

      {/* ─── THE EVIDENCE ───────────────────────────────────────────────────────────────────────
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
