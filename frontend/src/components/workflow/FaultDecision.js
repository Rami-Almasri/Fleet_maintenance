// The supervisor's view of one fault: two garages, five plain sentences each, and what the trade is.
//
// The engine layer next door (GarageRecommendations' technical section) is the *evidence* — coverage
// percentages, basis ladders, calibrated forecasts, score breakdowns. It answers "how did the engine
// decide?". This file answers the only question the person assigning the ticket actually has: "where do
// I send the car, and what do I give up if I send it elsewhere?"
//
// Two rules govern every sentence here:
//
//   1. NO ENGINE VOCABULARY. Not "fault experience", not "first-time resolution", not "confidence", not
//      "grain". A percentage nobody can restate in their own words is a number the reader either trusts
//      blindly or ignores — both are failures. So "55% fault experience" becomes "we have seen this
//      garage repair this fault 48 times", which is the fact the percentage was computed from anyway.
//
//   2. THE ORIGIN TRAVELS IN THE SENTENCE, not in a colour or a chip. A turnaround borrowed from the
//      fleet does not get greyed out and left to be misread — it says "we have no repair times from this
//      garage" in the same breath as the number. Dimming is not a statement; words are.
//
// Nothing here re-derives a judgement. Which garage wins, which alternative is worth naming, and what
// the material differences are all arrive from App\Services\Garage\PerFaultRecommender as reason codes.
// This module only chooses the sentence — see [[reason-code-contract]].

import { useState } from 'react';
import { days } from './format';

const CRIT_STYLE = {
  safety_critical:  'bg-rose-50 text-rose-700 ring-rose-600/20',
  major_mechanical: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  operational:      'bg-sky-50 text-sky-700 ring-sky-600/20',
  cosmetic:         'bg-slate-100 text-slate-500 ring-slate-300',
};

/**
 * The most specific price we hold for this garage on this fault.
 *
 * The fault-level figure when the ledger earned one, otherwise the garage's overall bill, otherwise
 * nothing. `basis` decides which caveat the sentence carries — a fleet median wearing a garage's name is
 * the single most misreadable figure on this panel.
 */
function priceFor(g) {
  const fc = g.fault_cost;
  if (fc && fc.value != null) return { value: fc.value, basis: fc.basis, sample: fc.sample };
  const c = g.cost_aed;
  if (c && c.value != null && c.basis !== 'unavailable') return { value: c.value, basis: c.basis, sample: c.sample };
  return null;
}

/**
 * How often this garage has actually done this repair, and how much of that was on this car's model.
 *
 * THE MODEL LINE IS OMITTED WHEN NO MODEL WAS ASKED ABOUT.
 *
 * It used to fall back to "None of those were on the same model." — which reads as a gap in the
 * garage's experience and is nothing of the kind. `same_model` is only ever incremented against a
 * model that was actually supplied (GarageRecommendationService::scoreRows keys it on
 * `$modelL !== null`), so with no model in the query it is zero BY CONSTRUCTION, for every garage,
 * always. The sentence could never have been true, and on the Garage Finder — where the model is
 * optional — it was printed on every card as if it were a finding.
 *
 * Nothing replaces it. There is no fact here to state: the question was never asked.
 */
function historyLines(g, model, gr) {
  const n = g.at_garage || 0;
  if (n === 0) return [{ text: gr('plain.repairedNever') }];

  const lines = [{ text: n === 1 ? gr('plain.repairedOnce') : gr('plain.repairedTimes', { n }) }];
  if (!model) return lines;

  const sm = g.same_model || 0;
  lines.push({ text: sm > 0 ? gr('plain.onModel', { n: sm, model }) : gr('plain.onModelNone', { model }) });

  return lines;
}

/**
 * THE SCOPE CAVEAT, for any figure that is about the GARAGE rather than about THIS REPAIR.
 *
 * The forecaster degrades down a ladder — garage+fault → garage → fleet — and only the bottom rung
 * used to announce itself. The middle one was silent, which produced the card that got questioned:
 *
 *     • We have never seen this garage repair this fault.
 *     • The repair usually takes about one day.
 *     • 60% of repairs here hold first time — about the same as the fleet's 60%.
 *     • Expect to pay about AED 865.
 *
 * Every line after the first is the garage's OVERALL record, borrowed because there is no record of
 * this fault to read. Only the price said so (`costGarageNote`), because cost was the one measure
 * whose ladder had been written out in full. The reader is left to conclude either that the first
 * line is wrong or that the system is guessing — and it was neither.
 *
 * Comeback has NO fault-level grain at all (GarageOutcomeForecaster only ever computes it per
 * garage), so this caveat is the normal case for durability, not an edge case.
 */
function scopeNote(stat, gr) {
  return stat?.basis === 'garage' ? gr('plain.garageWideNote') : null;
}

/** How long the car is off the road — with the fallback stated, never merely implied. */
function durationLine(g, gr) {
  const d = g.duration_days;
  if (!d || d.value == null || d.basis === 'unavailable') return { text: gr('plain.timeUnknown') };
  const fleet = d.basis === 'fleet';
  const note = scopeNote(d, gr);
  // A same-day median is a real measurement, but "about same day" is not a sentence.
  if (d.value < 0.5) return { text: gr(fleet ? 'plain.sameDayFleet' : 'plain.sameDay'), note };
  return { text: gr(fleet ? 'plain.takesAboutFleet' : 'plain.takesAbout', { d: days(d.value, gr) }), note };
}

/**
 * Whether the repair is likely to stick.
 *
 * The measurement underneath is a 90-day recurrence rate, so the sentence is bounded by that window on
 * purpose — "comes back after about 40 days" would be a claim the data cannot make.
 *
 * IT IS JUDGED AGAINST THE FLEET, NOT AGAINST A ROUND NUMBER. This line used to read "Only a 66%
 * first-time fix rate" for anything above a hardcoded 25% comeback — and the fleet's own comeback rate
 * is about 40%. So the panel opened by disparaging the garage it was recommending, on a figure that was
 * in fact BETTER than the fleet average, and every garage in the fleet read as bad. A quality figure
 * with no baseline beside it cannot be judged by the reader, so the sentence carries the baseline.
 *
 * THE PERCENTAGE IS THE PRIMARY FIGURE. This line exists to be compared against the identical line on
 * the garage next to it, and "8 in 10" against "6 in 10" makes the reader do arithmetic before they can
 * rank two numbers that were percentages to begin with.
 *
 * The "about N in 10 stayed fixed" restatement is GONE. Once the sentence carries the fleet baseline it
 * already holds two numbers to compare, and a third rounding of the same measurement underneath it was
 * the line readers pointed at as noise. Two readings of one number are not two facts.
 *
 * `s` is derived from the rounded `c` so the two always sum to 100 on screen.
 */
const MATERIAL_PTS = 8;   // below this, "better" and "worse" are noise — say "about the same"

function durabilityLine(g, gr, fleet) {
  const cb = g.comeback_pct;
  if (!cb || cb.value == null || cb.basis === 'unavailable') return { text: gr('plain.holdsUnknown') };
  const borrowed = cb.basis === 'fleet';
  const c = Math.round(cb.value);
  const s = 100 - c;
  // ALWAYS present when the figure is the garage's own — the forecaster has no fault-level comeback
  // grain, so "60% of repairs here hold first time" is never a claim about this repair alone.
  const note = scopeNote(cb, gr);

  // Under 5% rounds to "none in ten", and "almost always hold" is a better sentence than "96% of
  // repairs hold first time; 4% needed the repair again" — same fact, one less number to parse.
  if (Math.round(c / 10) === 0) return { text: gr(borrowed ? 'plain.holdsRarelyFleet' : 'plain.holdsRarely'), note };
  if (borrowed) return { text: gr('plain.holdsFleet', { s, c }) };

  // The fleet's own comeback rate over the same corpus — the only thing that makes this number mean
  // anything. Absent it, state the figure plainly rather than grading it against an invented target.
  const f = fleet?.comeback_pct;
  if (f == null) return { text: gr('plain.holdsPlain', { s, c }), note };

  const fs = Math.round(100 - f);
  const delta = Math.round(f) - c;         // positive = fewer come back here than fleet-wide
  if (delta >= MATERIAL_PTS) return { text: gr('plain.holdsBetter', { s, fs }), note };
  if (delta <= -MATERIAL_PTS) return { text: gr('plain.holdsWorse', { s, fs, c }), note };
  return { text: gr('plain.holdsSame', { s, fs }), note };
}

/** What it is likely to cost, and how much of a claim about THIS garage that figure really is. */
function priceLine(g, gr) {
  const c = priceFor(g);
  if (!c) return { text: gr('plain.costUnknown') };
  const note = c.basis === 'fleet' ? gr('plain.costFleetNote')
    : c.basis === 'garage' ? gr('plain.costGarageNote')
      : gr('plain.costFaultNote', { n: c.sample });
  return { text: gr('plain.costs', { n: Math.round(c.value) }), note };
}

/** The five operational facts about one garage, in the order a supervisor asks for them. */
export function garageLines(g, model, gr, fleet) {
  return [...historyLines(g, model, gr), durationLine(g, gr), durabilityLine(g, gr, fleet), priceLine(g, gr)];
}

/**
 * One clause of the engine's verdict, rewritten as a whole sentence that names the garage.
 *
 * The engine sends comparison clauses ("cheaper (AED 300 vs 550)") built to be joined into one sentence
 * about both garages. The operational view wants them standalone and named, so each code gets its own
 * plain phrasing. An unrecognised code is DROPPED rather than rendered through the technical labels —
 * a stray "more experienced with Engine (84% vs 59%)" would undo the whole panel.
 */
function tradeLine(reason, garage, gr) {
  const { code, params = {} } = reason || {};
  if (!code) return null;

  if (code === 'faster_than') {
    const delta = Number(params.b) - Number(params.a);
    return Number.isFinite(delta) && delta >= 1
      ? gr('plain.side.fasterBy', { garage, d: days(delta, gr) })
      : gr('plain.side.faster', { garage });
  }
  if (code === 'cheaper_than') {
    const delta = Math.round(Math.abs(Number(params.b) - Number(params.a)));
    return Number.isFinite(delta) ? gr('plain.side.cheaperBy', { garage, n: delta }) : gr('plain.side.cheaper', { garage });
  }

  const key = `plain.side.${code}`;
  const out = gr(key, { garage });
  return out === `workflow.garageRec.${key}` ? null : out;
}

/**
 * The trade, as a short list of ticks covering BOTH garages.
 *
 * Both sides get positive statements. A comparison where only the recommended garage earns ticks is
 * advocacy dressed as analysis — the supervisor overruling the engine has to see what they would be
 * choosing, not only what they would be giving up.
 */
function tradeLines(fault, gr) {
  const v = fault.verdict;
  if (!v?.parts) return [];
  const w = v.params?.winner || fault.winner?.garage;
  const a = v.params?.alternative || fault.alternative?.garage;
  return [
    ...(v.parts.winner_side || []).map((r) => tradeLine(r, w, gr)),
    ...(v.parts.alternative_side || []).map((r) => tradeLine(r, a, gr)),
  ].filter(Boolean);
}

/**
 * The component name a supervisor reads, not the one the engine stores.
 *
 * Falls back to the backend's own label so a component added server-side still renders rather than
 * printing a raw key — the engine stays the source of truth for WHICH components exist.
 */
function compLabel(c, gr) {
  const key = `plain.score.comp.${c.key}`;
  const out = gr(key);
  return out === `workflow.garageRec.${key}` ? c.label : out;
}

/**
 * The 0–100, decomposed. Every component with the points it earned, the points it could have earned,
 * and the counted facts behind them.
 *
 * The maxima come from the backend and are guaranteed to sum to 100, and the awarded values to sum to
 * the headline — so this renders with no client-side arithmetic and cannot drift from the engine. A
 * component that could not be measured says so instead of showing a zero, because zero is a verdict and
 * "we never asked" is not.
 */
function ScoreCard({ breakdown, faultCount, gr }) {
  if (!breakdown?.components?.length) return null;

  const total = breakdown.total;
  // Bands around the same idea the strip uses: a score is a comparison, so the colour is a reading of
  // the number rather than decoration. Deliberately generous at the low end — this bar sits on the
  // garage the panel is RECOMMENDING, and painting it red is the panel arguing with itself.
  const tone = total >= 70 ? 'bg-emerald-500' : total >= 45 ? 'bg-sky-500' : 'bg-amber-400';

  return (
    <details className="group mt-2.5 rounded-lg bg-white/70 ring-1 ring-inset ring-slate-200">
      {/* CLOSED BY DEFAULT. The five sentences above are the answer; this is the audit trail behind
          them. Presenting a five-row arithmetic table as the first thing under a recommendation made
          the panel read as a test result the reader had to pass, which is exactly the reaction that
          sent this back for a rewrite. The headline stays visible — nothing is hidden, it is ranked. */}
      <summary className="flex cursor-pointer list-none items-center gap-2 p-2 [&::-webkit-details-marker]:hidden">
        <span className="shrink-0 text-[11px] font-semibold text-slate-500">{gr('plain.score.title')}</span>
        <span className="h-1.5 min-w-8 flex-1 overflow-hidden rounded-full bg-slate-100">
          <span className={`block h-full rounded-full ${tone}`} style={{ width: `${Math.max(total, 2)}%` }} />
        </span>
        <span className="shrink-0 text-[12px] font-bold tabular-nums text-slate-800">
          {total}<span className="font-medium text-slate-400"> / 100</span>
        </span>
        <span aria-hidden className="shrink-0 text-slate-400 transition-transform group-open:rotate-180">▾</span>
      </summary>

      <div className="space-y-1.5 border-t border-slate-200 p-2">
        {breakdown.components.map((c) => {
          const pct = c.applicable && c.max > 0 ? Math.round((c.awarded / c.max) * 100) : 0;
          return (
            <div key={c.key}>
              <div className="flex items-baseline gap-1.5">
                <span className="shrink-0 text-[12px] font-medium text-slate-700">{compLabel(c, gr)}</span>
                <span aria-hidden className="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-slate-300" />
                {c.applicable ? (
                  // Muted, and phrased "of" rather than "/" — an exam-style fraction in bold is what
                  // makes a breakdown read like a grade being handed down.
                  <span className="shrink-0 text-[11px] font-medium tabular-nums text-slate-500">
                    {gr('plain.score.outOf', { n: c.awarded, max: c.max })}
                  </span>
                ) : (
                  <span className="shrink-0 text-[11px] font-medium text-slate-400">{gr('plain.score.notMeasured')}</span>
                )}
              </div>
              {c.applicable && (
                <div className="mt-0.5 h-1 overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={`h-full rounded-full ${pct >= 70 ? 'bg-emerald-500' : pct >= 40 ? 'bg-sky-400' : 'bg-slate-300'}`}
                    style={{ width: `${Math.max(pct, 2)}%` }}
                  />
                </div>
              )}
              {/* WHY it scored that — the countable events, not a restatement of the number. */}
              <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{c.detail}</p>
            </div>
          );
        })}

        {/* Slate, not amber. This note explains the score's denominator; in warning colours it read as
            a defect report on a car that simply has less history than average. */}
        {breakdown.note && <p className="pt-0.5 text-[11px] leading-snug text-slate-500">{breakdown.note}</p>}
        {/* On a multi-fault ticket this score is not a verdict on THIS fault, and the reader will
            assume it is unless told. */}
        {faultCount > 1 && (
          <p className="text-[11px] leading-snug text-slate-500">{gr('plain.score.ticketWide', { n: faultCount })}</p>
        )}
      </div>
    </details>
  );
}

// WHY THERE IS NO "Why X ranked higher" SECTION.
//
// There used to be one, per fault: the two score breakdowns subtracted component by component, biggest
// gap first, with both garages' evidence under every row. It was accurate and it was a third telling of
// the same story — the five sentences say what each garage is like, the score card says how that scored,
// and the trade-off says what you give up. Repeating all of it a fourth time as arithmetic, on EVERY
// fault of a multi-fault ticket, buried the two things the reader came for.
//
// The card still shows both derivations side by side (ScoreCard, on each garage) and both totals, so
// the ranking remains auditable; what is gone is the subtraction, which the reader can now do on the two
// numbers in front of them. The backend stopped emitting `score_gap` with it, rather than leaving a
// computed field with no consumer ([[evidence-layer-governance]]).

/**
 * A garage's block: who it is, the five facts, and how it scored.
 *
 * `primary` marks the pick; `proven` says whether that pick rests on any record of THIS repair. The
 * two used to be one flag, which is how a fault nobody in the fleet has ever repaired still got a
 * green tick and the word "Recommended" — see the banner in FaultDecision.
 */
function Side({ g, lines, title, primary, proven = true, onPick, isSel, gr, faultCount }) {
  const led = primary && proven;
  return (
    <div className={`flex-1 rounded-xl p-3 ring-1 ring-inset ${led ? 'bg-emerald-50/60 ring-emerald-300' : primary ? 'bg-slate-50 ring-slate-300' : 'bg-white ring-slate-200'}`}>
      <p className={`flex items-center gap-1.5 text-[14px] font-bold ${led ? 'text-emerald-800' : 'text-slate-700'}`}>
        {led && <span aria-hidden>✅</span>}
        <span className="truncate">{title}</span>
      </p>
      {/* Tagged so the no-engine-vocabulary test can assert on the SENTENCES specifically. The score
          sections below are deliberately technical; the rule was only ever about the prose. */}
      <ul data-testid="garage-facts" className="mt-2 space-y-1.5">
        {lines.map((l, i) => (
          <li key={i} className="text-[13px] leading-snug text-slate-700">
            <span className="me-1 text-slate-300" aria-hidden>•</span>{l.text}
            {/* Where a figure is weaker than it looks, the caveat rides directly under it — not in a
                legend, not in a tooltip, not as a colour the reader has to have been taught. */}
            {l.note && <span className="mt-0.5 block ps-3 text-[11px] leading-snug text-slate-500">{l.note}</span>}
          </li>
        ))}
      </ul>
      {/* The score, on the same card as the facts that earned it. Both garages get one, so the reader
          can compare the derivations and not just the verdicts. */}
      <ScoreCard breakdown={g.breakdown} faultCount={faultCount} gr={gr} />
      <button
        type="button"
        onClick={() => onPick(g.vendor_id)}
        className={`mt-2.5 w-full rounded-lg px-3 py-1.5 text-[13px] font-semibold transition ${
          isSel(g.vendor_id) ? 'bg-emerald-600 text-white'
            : led ? 'bg-emerald-600 text-white hover:bg-emerald-700'
              : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
      >
        {isSel(g.vendor_id) ? gr('plain.isSelected', { garage: g.garage }) : gr('plain.sendTo', { garage: g.garage })}
      </button>
    </div>
  );
}

/**
 * One fault, answered.
 *
 * @param fault       a row of the API's `per_fault` array
 * @param model       the vehicle model, so "none of those were on a YUKON" can name the car
 * @param faultCount  how many faults the ticket carries — the match score weighs all of them, so on a
 *                    multi-fault ticket the card has to say the score is not about this fault alone
 * @param fleet       the fleet's own outcome baselines, so a quality figure can be judged against what
 *                    the fleet actually achieves instead of against a round number
 */
export default function FaultDecision({ fault, model, onPick, isSel, t, faultCount = 1, fleet = null }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  const { winner, alternative } = fault;
  const trades = tradeLines(fault, gr);
  // How this fault's answer squares with the ticket's answer. `agrees` is the ordinary case and needs
  // no words. The other two must never be printed as a plain green tick: one is advice to send this
  // fault elsewhere, the other is an admission that the chosen garage has never done this repair.
  const standing = fault.standing || 'agrees';
  const displaced = standing === 'displaced' || standing === 'pick_absent';

  // NOBODY HAS EVER REPAIRED THIS FAULT.
  //
  // The per-fault winner is chosen by fault coverage, so if IT has none, no garage does. That happens
  // for real: `Safety & Driver Assist` is the one catalogue category with no mapping into the
  // historical corpus at all — the sheet never separated airbags, belts and ADAS from general
  // electrical work — so every garage scores zero on it and the ranking falls through to general fit.
  //
  // The card used to draw a green tick and the word "Recommended" over "We have never seen this
  // garage repair this fault" — a contradiction, or worse, the system appearing to invent experience.
  //
  // Naming a garage is now not even the default. Once the reader knows nobody has done this repair,
  // two columns of borrowed figures and a trade-off computed from them are ten sentences that cannot
  // answer the question they appear to answer: every number in them is about the garage's OTHER work,
  // and comparing two garages on work unrelated to this fault is a comparison of nothing in
  // particular. The suggestion stays one click away for whoever wants a starting point.
  //
  // BOTH sides must be empty before the comparison is suppressed. The per-fault winner is picked by
  // fault coverage, so in practice a winner with no record means nobody has one — but "No garage has
  // repaired this before" is a claim about every garage, and it must not be made while a named
  // alternative on the same card has thirty of them.
  const proven = (winner?.at_garage || 0) > 0 || (alternative?.at_garage || 0) > 0;
  const [showAnyway, setShowAnyway] = useState(false);
  const compare = proven || showAnyway;
  // How much the two PRICES can be leaned on is a separate question from how good either one is, and
  // it is the one place a plain-language panel must not stay silent: "AED 300 cheaper" reads identically
  // whether it rests on sixty matching repairs or nine assorted ones.
  const weakPrices = ['none', 'low'].includes(fault.cost_confidence?.level);

  return (
    <div className="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-300">
      <div className="mb-2 flex flex-wrap items-center gap-1.5">
        <span className="text-[15px] font-bold text-slate-900">{fault.symptom}</span>
        {fault.criticality && (
          <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${CRIT_STYLE[fault.criticality]}`}>
            {fault.criticality_label}
          </span>
        )}
      </div>

      {/* When this fault disagrees with the ticket's garage, the disagreement is the headline — stated
          before either column, in the words that make it actionable. */}
      {displaced && proven && (
        <p className="mb-2 rounded-lg bg-amber-50 px-2.5 py-2 text-[12px] leading-snug text-amber-900 ring-1 ring-inset ring-amber-300">
          {gr(standing === 'pick_absent' ? 'plain.pickAbsent' : 'plain.pickDisplaced', { garage: winner.garage })}
        </p>
      )}

      {/* Slate, not amber. Nothing has gone wrong — we simply have no history for this repair, and a
          warning colour would tell the supervisor to hesitate over a car that still has to go
          somewhere today. */}
      {!proven && (
        <div className="rounded-lg bg-slate-100 px-2.5 py-2 ring-1 ring-inset ring-slate-300">
          <p className="text-[12px] font-semibold text-slate-800">{gr('plain.noHistoryTitle')}</p>
          <p className="mt-0.5 text-[12px] leading-snug text-slate-600">{gr('plain.noHistoryBody')}</p>
          <button
            type="button"
            onClick={() => setShowAnyway((v) => !v)}
            className="mt-1.5 text-[12px] font-semibold text-slate-500 underline decoration-dotted hover:text-slate-700"
            aria-expanded={showAnyway}
          >
            {gr(showAnyway ? 'plain.hideSuggestions' : 'plain.showSuggestions')}
          </button>
        </div>
      )}

      {compare && (
      <div className={`flex flex-col gap-2 sm:flex-row${proven ? '' : ' mt-2'}`}>
        <Side
          g={winner} primary proven={proven} gr={gr} onPick={onPick} isSel={isSel} faultCount={faultCount}
          title={gr(!proven ? 'plain.suggested' : displaced ? 'plain.strongestHere' : 'plain.recommended', { garage: winner.garage })}
          lines={garageLines(winner, model, gr, fleet)}
        />
        {alternative
          ? <Side
              g={alternative} gr={gr} onPick={onPick} isSel={isSel} faultCount={faultCount}
              title={gr(proven ? 'plain.alternative' : 'plain.otherOption', { garage: alternative.garage })}
              lines={garageLines(alternative, model, gr, fleet)}
            />
          : <div className="flex-1 rounded-xl bg-slate-50 p-3 text-[13px] leading-snug text-slate-500 ring-1 ring-inset ring-slate-200">
              {gr('plain.noAlternative')}
            </div>}
      </div>
      )}

      {compare && alternative && (
        <div className="mt-2 rounded-lg bg-slate-50 p-2.5 ring-1 ring-inset ring-slate-200">
          <p className="mb-1 text-[11px] font-bold uppercase tracking-wide text-slate-500">{gr('plain.tradeoff')}</p>
          {trades.length > 0 ? (
            <ul className="space-y-1">
              {trades.map((line, i) => (
                <li key={i} className="flex items-start gap-1.5 text-[13px] leading-snug text-slate-700">
                  <span aria-hidden className="shrink-0 font-bold text-emerald-600">✓</span>{line}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-[13px] leading-snug text-slate-600">{gr('plain.evenlyMatched')}</p>
          )}
          {/* Stated whenever the comparison is thin, INCLUDING when a price difference is on screen —
              that is exactly the case where it would otherwise be over-read. */}
          {weakPrices && <p className="mt-1.5 text-[12px] leading-snug text-amber-800">{gr('plain.pricesIncomparable')}</p>}
        </div>
      )}
    </div>
  );
}
