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

/** How often this garage has actually done this repair, and how much of that was on this car's model. */
function historyLines(g, model, gr) {
  const n = g.at_garage || 0;
  if (n === 0) return [{ text: gr('plain.repairedNever') }];

  const lines = [{ text: n === 1 ? gr('plain.repairedOnce') : gr('plain.repairedTimes', { n }) }];
  const sm = g.same_model || 0;
  if (model) {
    lines.push({ text: sm > 0 ? gr('plain.onModel', { n: sm, model }) : gr('plain.onModelNone', { model }) });
  } else {
    lines.push({ text: sm > 0 ? gr('plain.onModelGeneric', { n: sm }) : gr('plain.onModelNoneGeneric') });
  }
  return lines;
}

/** How long the car is off the road — with the fleet fallback stated, never merely implied. */
function durationLine(g, gr) {
  const d = g.duration_days;
  if (!d || d.value == null || d.basis === 'unavailable') return { text: gr('plain.timeUnknown') };
  const fleet = d.basis === 'fleet';
  // A same-day median is a real measurement, but "about same day" is not a sentence.
  if (d.value < 0.5) return { text: gr(fleet ? 'plain.sameDayFleet' : 'plain.sameDay') };
  return { text: gr(fleet ? 'plain.takesAboutFleet' : 'plain.takesAbout', { d: days(d.value, gr) }) };
}

/**
 * Whether the repair is likely to stick.
 *
 * The measurement underneath is a 90-day recurrence rate, so the sentence is bounded by that window on
 * purpose — "comes back after about 40 days" would be a claim the data cannot make. "About 4 in 10 cars
 * needed this repair again within 3 months" is the same fact in the form an operator can act on.
 */
function durabilityLine(g, gr) {
  const cb = g.comeback_pct;
  if (!cb || cb.value == null || cb.basis === 'unavailable') return { text: gr('plain.holdsUnknown') };
  const fleet = cb.basis === 'fleet';
  const n = Math.round(cb.value / 10);
  if (n === 0) return { text: gr(fleet ? 'plain.holdsRarelyFleet' : 'plain.holdsRarely') };
  if (fleet) return { text: gr('plain.holdsFleet', { n }) };
  return { text: gr(cb.value < 25 ? 'plain.holds' : 'plain.returns', { n }) };
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
export function garageLines(g, model, gr) {
  return [...historyLines(g, model, gr), durationLine(g, gr), durabilityLine(g, gr), priceLine(g, gr)];
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

/** A garage's block: who it is, and the five facts. */
function Side({ g, lines, title, primary, onPick, isSel, gr }) {
  return (
    <div className={`flex-1 rounded-xl p-3 ring-1 ring-inset ${primary ? 'bg-emerald-50/60 ring-emerald-300' : 'bg-white ring-slate-200'}`}>
      <p className={`flex items-center gap-1.5 text-[14px] font-bold ${primary ? 'text-emerald-800' : 'text-slate-700'}`}>
        {primary && <span aria-hidden>✅</span>}
        <span className="truncate">{title}</span>
      </p>
      <ul className="mt-2 space-y-1.5">
        {lines.map((l, i) => (
          <li key={i} className="text-[13px] leading-snug text-slate-700">
            <span className="me-1 text-slate-300" aria-hidden>•</span>{l.text}
            {/* Where a figure is weaker than it looks, the caveat rides directly under it — not in a
                legend, not in a tooltip, not as a colour the reader has to have been taught. */}
            {l.note && <span className="mt-0.5 block ps-3 text-[11px] leading-snug text-slate-500">{l.note}</span>}
          </li>
        ))}
      </ul>
      <button
        type="button"
        onClick={() => onPick(g.vendor_id)}
        className={`mt-2.5 w-full rounded-lg px-3 py-1.5 text-[13px] font-semibold transition ${
          isSel(g.vendor_id) ? 'bg-emerald-600 text-white'
            : primary ? 'bg-emerald-600 text-white hover:bg-emerald-700'
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
 * @param fault  a row of the API's `per_fault` array
 * @param model  the vehicle model, so "none of those were on a YUKON" can name the car
 */
export default function FaultDecision({ fault, model, onPick, isSel, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  const { winner, alternative } = fault;
  const trades = tradeLines(fault, gr);
  // How this fault's answer squares with the ticket's answer. `agrees` is the ordinary case and needs
  // no words. The other two must never be printed as a plain green tick: one is advice to send this
  // fault elsewhere, the other is an admission that the chosen garage has never done this repair.
  const standing = fault.standing || 'agrees';
  const displaced = standing === 'displaced' || standing === 'pick_absent';
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
      {displaced && (
        <p className="mb-2 rounded-lg bg-amber-50 px-2.5 py-2 text-[12px] leading-snug text-amber-900 ring-1 ring-inset ring-amber-300">
          {gr(standing === 'pick_absent' ? 'plain.pickAbsent' : 'plain.pickDisplaced', { garage: winner.garage })}
        </p>
      )}

      <div className="flex flex-col gap-2 sm:flex-row">
        <Side
          g={winner} primary gr={gr} onPick={onPick} isSel={isSel}
          title={gr(displaced ? 'plain.strongestHere' : 'plain.recommended', { garage: winner.garage })}
          lines={garageLines(winner, model, gr)}
        />
        {alternative
          ? <Side
              g={alternative} gr={gr} onPick={onPick} isSel={isSel}
              title={gr('plain.alternative', { garage: alternative.garage })}
              lines={garageLines(alternative, model, gr)}
            />
          : <div className="flex-1 rounded-xl bg-slate-50 p-3 text-[13px] leading-snug text-slate-500 ring-1 ring-inset ring-slate-200">
              {gr('plain.noAlternative')}
            </div>}
      </div>

      {alternative && (
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
