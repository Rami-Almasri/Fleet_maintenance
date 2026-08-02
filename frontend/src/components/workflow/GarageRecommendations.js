// FleetView's garage recommendation for the assign step, in two layers.
//
// THE DEFAULT VIEW IS OPERATIONAL. One card per fault (FaultDecision), each naming the recommended
// garage and the best alternative in five plain sentences a supervisor can read in under ten seconds:
// how often the garage has done this repair, how much of that was on this model, how long it takes,
// whether the repair sticks, what it costs. No percentages that need decoding, no engine vocabulary.
//
// THE TECHNICAL LAYER IS EVERYTHING ELSE — the scan table, the per-fault evidence cards, the decision
// summary, the score breakdown, the calibrated forecasts, the cost-basis ladder, the runner-ups. It is
// kept whole, unchanged and one click away under "Technical details", because the analysts who audit a
// recommendation and the supervisor who acts on one need genuinely different screens; collapsing them
// into a compromise is what made this panel unreadable for both.
//
// Tapping any garage pre-fills the picker (onPick); the full result is lifted (onResult) so the modal
// can record suggested-vs-chosen and show the prefill note.

import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import { renderReason, renderReasons, renderComposed } from './reasons';
import { days } from './format';
import FaultDecision from './FaultDecision';

const CONF_STYLE = {
  high: 'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
  medium: 'bg-amber-100 text-amber-800 ring-amber-600/20',
  low: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};
const CONF_DOT = { high: 'bg-emerald-500', medium: 'bg-amber-500', low: 'bg-slate-400' };

// Per-fault evidence tier — how close the garage's history is to THIS fault on THIS car. Colour carries
// the ladder (exact > domain > general > none) so the operator reads coverage without reading numbers.
// Fault priority — how much a fault was allowed to influence the recommendation. Colour is the fastest
// way to show that a brake fault and a scratch were never weighed equally.
const CRIT_STYLE = {
  safety_critical:  'bg-rose-50 text-rose-700 ring-rose-600/20',
  major_mechanical: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  operational:      'bg-sky-50 text-sky-700 ring-sky-600/20',
  cosmetic:         'bg-slate-100 text-slate-500 ring-slate-300',
};

const TIER_STYLE = {
  exact:   { dot: 'bg-emerald-500', text: 'text-emerald-700', icon: 'Check' },
  domain:  { dot: 'bg-sky-500',     text: 'text-sky-700',     icon: 'Check' },
  general: { dot: 'bg-amber-400',   text: 'text-amber-700',   icon: 'Alert' },
  none:    { dot: 'bg-slate-300',   text: 'text-slate-500',   icon: 'Alert' },
};

// How much a figure can be trusted as EVIDENCE. A configured business rule and a counted historical fact
// must not look alike on screen — presenting them identically implies the policy is evidence.
const KIND_STYLE = {
  policy:   'bg-violet-50 text-violet-700 ring-violet-600/20',
  measured: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  forecast: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  derived:  'bg-slate-100 text-slate-600 ring-slate-400/30',
};

/** The full definition — what it means, how it was calculated, which records produced it. */
function MetricPopover({ def, onClose, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  return (
    <span className="absolute start-0 top-full z-30 mt-1 block w-64 rounded-lg bg-white p-2.5 text-start shadow-lg ring-1 ring-slate-300">
      <span className="mb-1 flex items-center justify-between gap-2">
        <b className="text-[12px] text-slate-800">{def.label}</b>
        <span className={`rounded px-1.5 py-0.5 text-[9px] font-bold uppercase ring-1 ring-inset ${KIND_STYLE[def.kind] || KIND_STYLE.derived}`}>
          {t(`workflow.garageRec.metric.kind.${def.kind}`)}
        </span>
      </span>
      <span className="mb-1 block text-[10px] italic leading-snug text-slate-400">
        {t(`workflow.garageRec.metric.kindHint.${def.kind}`)}
      </span>
      {['means', 'method', 'source'].map((k) => (
        <span key={k} className="mb-1 block text-[11px] leading-snug text-slate-600">
          <b className="text-slate-500">{gr(`metric.${k}`)}: </b>{def[k]}
        </span>
      ))}
      {def.caveat && (
        <span className="block rounded bg-amber-50 p-1.5 text-[11px] leading-snug text-amber-800">
          <b>{gr('metric.caveat')}: </b>{def.caveat}
        </span>
      )}
      <button type="button" onClick={onClose} className="mt-1.5 text-[11px] font-semibold text-indigo-600">
        {gr('metric.close')}
      </button>
    </span>
  );
}

/**
 * A number that can explain itself.
 *
 * Every figure on this panel is a claim the supervisor is being asked to act on, so each one carries an
 * affordance that answers what it means, how it was calculated and which records produced it. The
 * definitions come from the API (one shared dictionary) rather than being written here, so the interface
 * can never end up explaining a number differently from the engine that produced it.
 *
 * Inline form — for tight places like a table cell, where only the ⓘ fits.
 */
function Metric({ id, value, metrics, t, className = '', muted }) {
  const [open, setOpen] = useState(false);
  const def = metrics?.[id];
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);

  return (
    <span className="relative inline-flex items-baseline gap-0.5">
      <span className={`${className} ${muted ? 'text-slate-400' : ''}`}>{value}</span>
      {def && (
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-label={gr('metric.whatIsThis')}
          title={def.short || gr('metric.whatIsThis')}
          className="translate-y-[-1px] text-[10px] font-bold text-slate-300 transition hover:text-indigo-600"
        >
          ⓘ
        </button>
      )}
      {open && def && <MetricPopover def={def} t={t} onClose={() => setOpen(false)} />}
    </span>
  );
}

/**
 * A labelled figure that states its own meaning WITHOUT being clicked.
 *
 * A percentage whose meaning is one tap away has already been misread by the time the tap happens — the
 * supervisor's first reaction to "84%" is "84% of what?", and the answer has to be on screen before the
 * question forms. So the one-line definition sits under the label, and where the figure was counted from
 * records, those counts are printed beneath it. The popover is still there for the full method; it is no
 * longer the only place the number is explained.
 */
function Figure({ id, metrics, t, value, evidence, note, stat }) {
  const [open, setOpen] = useState(false);
  const def = metrics?.[id];
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  // A figure borrowed from the fleet is NOT a claim about this garage, and greying it is not a statement
  // — an operator reads a dimmed number as a number. It has to say so in words, or it will be compared
  // against a real garage figure as though the two were the same kind of thing.
  const borrowed = stat?.basis === 'fleet';
  const muted = borrowed;

  return (
    <div className="relative">
      <div className="flex items-baseline justify-between gap-2">
        <span className="flex items-baseline gap-0.5 text-[11px] font-medium text-slate-600">
          {def?.label || id}
          {def && (
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-label={gr('metric.whatIsThis')}
              title={gr('metric.whatIsThis')}
              className="translate-y-[-1px] text-[10px] font-bold text-slate-300 transition hover:text-indigo-600"
            >
              ⓘ
            </button>
          )}
        </span>
        <span className={`shrink-0 text-[12px] font-bold tabular-nums ${muted ? 'text-slate-400' : 'text-slate-800'}`}>{value}</span>
      </div>
      {def?.short && <p className="text-[10px] leading-snug text-slate-400">{def.short}</p>}
      {/* PROVENANCE, always: how confident, how many repairs, and at what grain. A cost with no sample
          count behind it is a guess wearing a currency symbol. */}
      {stat?.basis && stat.basis !== 'unavailable' && (
        <p className={`flex items-center gap-1 text-[10px] leading-snug ${borrowed ? 'text-amber-700' : 'text-slate-500'}`}>
          {stat.confidence && <span className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${CONF_DOT[stat.confidence] || CONF_DOT.low}`} />}
          {stat.confidence && <span className="font-medium">{t(`workflow.garageRec.confidence.${stat.confidence}`)}</span>}
          <span>· {gr('costBasis.line', { n: stat.sample, scope: gr(`costBasis.${stat.basis}`) })}</span>
        </p>
      )}
      {stat?.basis === 'unavailable' && stat?.reason && (
        <p className="text-[10px] leading-snug text-slate-400">{stat.reason}</p>
      )}
      {/* The records the figure was counted from — the direct answer to "percent of what?". */}
      {evidence?.length > 0 && (
        <ul className="mt-0.5 space-y-px">
          {evidence.map((e, i) => (
            <li key={i} className="flex gap-1 text-[10px] leading-snug text-slate-500">
              <span aria-hidden className="text-slate-300">•</span>{renderReason(t, e)}
            </li>
          ))}
        </ul>
      )}
      {note && <p className="text-[10px] leading-snug text-slate-400">{note}</p>}
      {open && def && <MetricPopover def={def} t={t} onClose={() => setOpen(false)} />}
    </div>
  );
}

/**
 * The ten-second read: one row per fault, who wins it, how well covered it is. Deliberately the first
 * thing on the panel — a supervisor scanning a three-fault car needs the shape of the answer before any
 * of the reasoning.
 */
function FaultWinnerTable({ perFault, metrics, t }) {
  if (!perFault?.length) return null;
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);

  return (
    <div className="mb-2.5 overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-300">
      <p className="border-b border-slate-200 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
        {gr('perFault.overview')}
      </p>
      <table className="w-full text-[12px]">
        <thead>
          <tr className="text-[10px] uppercase tracking-wide text-slate-400">
            <th className="px-3 py-1 text-start font-semibold">{gr('perFault.colFault')}</th>
            <th className="px-3 py-1 text-start font-semibold">{gr('perFault.colWinner')}</th>
            <th className="px-3 py-1 text-end font-semibold">{gr('perFault.colCoverage')}</th>
          </tr>
        </thead>
        <tbody>
          {perFault.map((f) => (
            <tr key={f.category_key} className="border-t border-slate-100">
              <td className="px-3 py-1.5">
                <span className="font-medium text-slate-700">{f.symptom}</span>
                {f.criticality && (
                  <span className={`ms-1.5 rounded px-1 py-0.5 text-[9px] font-semibold ring-1 ring-inset ${CRIT_STYLE[f.criticality]}`}>
                    <Metric id="criticality_weight" metrics={metrics} t={t} value={`×${f.weight}`} />
                  </span>
                )}
              </td>
              <td className="px-3 py-1.5">
                <span className="font-semibold text-slate-800">{f.winner.garage}</span>
                {/* The reason belongs IN the scan row. A supervisor reading three faults needs to know
                    why each winner won without opening three cards. */}
                {f.short_reason?.length > 0 && (
                  <span className="block text-[10px] font-normal leading-snug text-slate-500">{renderReasons(t, f.short_reason, ' + ')}</span>
                )}
              </td>
              <td className="px-3 py-1.5 text-end tabular-nums text-slate-700">
                <Metric id="coverage_pct" metrics={metrics} t={t} value={`${f.winner.coverage_pct}%`} />
                {/* Even in the scan row the figure carries its basis — the first evidence line is the
                    one that answers "of what?", and a bare percentage in a summary table is exactly
                    where a magic number does the most damage. */}
                {f.winner.evidence?.[0] && (
                  <span className="block text-[10px] font-normal leading-snug text-slate-400">{renderReason(t, f.winner.evidence[0])}</span>
                )}
                {/* Price reliability, as a colour, right where the fault is scanned. */}
                {f.cost_compare?.winner && (
                  <span className="mt-0.5 flex justify-end">
                    <CostSource basis={f.cost_compare.winner.basis} t={t} />
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** One fault, side by side: the recommended garage against the best real alternative. */
function FaultCard({ fault, metrics, onPick, isSel, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  const { winner, alternative } = fault;

  const Side = ({ g, tone, title, primary }) => (
    <div className={`flex-1 rounded-lg p-2.5 ring-1 ring-inset ${tone}`}>
      <p className="text-[10px] font-bold uppercase tracking-wide opacity-70">{title}</p>
      <p className="mt-0.5 truncate text-[13px] font-bold text-slate-800">{g.garage}</p>
      <div className="mt-1.5 space-y-1.5">
        <Figure id="coverage_pct" metrics={metrics} t={t} value={`${g.coverage_pct}%`} evidence={g.evidence} />
        <Figure id="duration_days" metrics={metrics} t={t} stat={g.duration_days} value={days(g.duration_days?.value, gr)} />
        {/* First-time resolution and repeat repair are ONE measurement — they always total 100%. Printed
            as two independent rows they read as two separate problems ("only 55% succeed AND 44% come
            back?"), so the mirror figure rides along as a note instead of claiming its own line. */}
        <Figure id="success_pct" metrics={metrics} t={t} stat={g.success_pct}
          value={g.success_pct?.value != null ? `${g.success_pct.value}%` : '—'}
          note={g.comeback_pct?.value != null ? gr('perFault.mirror', { n: g.comeback_pct.value }) : null} />
        {/* PHASE 2: where the ledger has earned a price for THIS fault category at THIS garage, that is
            the number the supervisor wants — "engine work here runs AED 700", not "this garage bills
            AED 550 on average". It appears only when the sample supports it; otherwise the job-level
            estimate stands in and says so. */}
        {g.fault_cost
          ? <Figure id="fault_cost" metrics={metrics} t={t}
              stat={{ ...g.fault_cost, confidence: g.fault_cost.sample >= 10 ? 'high' : g.fault_cost.sample >= 5 ? 'medium' : 'low' }}
              value={gr('outcomes.aed', { n: Math.round(g.fault_cost.value) })}
              note={g.cost_aed?.value != null ? gr('perFault.jobTotal', { n: Math.round(g.cost_aed.value) }) : null} />
          : <Figure id="cost_aed" metrics={metrics} t={t} stat={g.cost_aed}
              value={g.cost_aed?.value != null ? gr('outcomes.aed', { n: Math.round(g.cost_aed.value) }) : '—'} />}
      </div>
      <button
        type="button"
        onClick={() => onPick(g.vendor_id)}
        className={`mt-1.5 w-full rounded-md px-2 py-1 text-[11px] font-semibold transition ${
          isSel(g.vendor_id) ? 'bg-indigo-600 text-white'
            : primary ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
              : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
      >
        {isSel(g.vendor_id) ? t('workflow.garageRec.selected') : t('workflow.garageRec.use')}
      </button>
    </div>
  );

  return (
    <div className="rounded-xl bg-white p-2.5 ring-1 ring-inset ring-slate-200">
      <div className="mb-1.5 flex items-center gap-1.5">
        <span className="text-[13px] font-bold text-slate-900">{fault.symptom}</span>
        {fault.criticality && (
          <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${CRIT_STYLE[fault.criticality]}`}>
            {fault.criticality_label}
            <Metric id="criticality_weight" metrics={metrics} t={t} value={` ×${fault.weight}`} />
          </span>
        )}
      </div>

      {/* THE THIRTY-SECOND READ — both sides as ticks, before any of the detail. */}
      <DecisionFactors fault={fault} t={t} />

      <div className="flex flex-col gap-2 sm:flex-row">
        <Side g={winner} primary tone="bg-indigo-50/70 ring-indigo-200" title={gr('perFault.winner')} />
        {alternative
          ? <Side g={alternative} tone="bg-slate-50 ring-slate-200" title={gr('perFault.alternative')} />
          : <div className="flex-1 rounded-lg bg-slate-50 p-2.5 text-[11px] leading-snug text-slate-500 ring-1 ring-inset ring-slate-200">
              {gr('perFault.noAlternative')}
            </div>}
      </div>

      {/* MONEY, next to the operational picture rather than buried inside one column — the trade-off
          is between the two garages, so the two prices belong side by side. */}
      <CostCompare compare={fault.cost_compare} confidence={fault.cost_confidence} t={t} />

      {/* The trade-off in one sentence, naming both garages and both sides. Never resolves to "pick the
          cheaper one": cost is one axis of four and the call belongs to the supervisor. */}
      {fault.verdict && (
        <p className="mt-1.5 rounded-md bg-slate-50 px-2 py-1.5 text-[11px] leading-snug text-slate-700 ring-1 ring-inset ring-slate-200">
          <b className="text-slate-500">{gr('perFault.tradeoffTitle')}: </b>{renderComposed(t, fault.verdict)}
        </p>
      )}

      {/* WHY, as ticks and minuses. A supervisor deciding in ten seconds scans marks, not sentences —
          the prose version stays below for the cases where the nuance actually matters. */}
      <div className="mt-2 flex flex-col gap-2 border-t border-slate-100 pt-1.5 sm:flex-row">
        <Bullets
          title={gr('perFault.whyWon')}
          items={(fault.winner_points || []).map((s) => ({ mark: '✓', tone: 'text-emerald-600', text: renderReason(t, s) }))}
        />
        {alternative && (
          <Bullets
            title={gr('perFault.whyNotAlt', { garage: alternative.garage })}
            items={[
              ...(fault.alt_cons || []).map((s) => ({ mark: '−', tone: 'text-rose-500', text: renderReason(t, s) })),
              ...(fault.alt_pros || []).map((s) => ({ mark: '+', tone: 'text-slate-400', text: renderReason(t, s) })),
            ]}
            empty={gr('perFault.evenlyMatched')}
          />
        )}
      </div>

      <details className="mt-1.5">
        <summary className="cursor-pointer text-[11px] font-semibold text-indigo-600">{gr('perFault.inWords')}</summary>
        <p className="mt-1 text-[11px] leading-snug text-slate-600">{renderComposed(t, fault.reason)}</p>
        {fault.tradeoff && <p className="mt-0.5 text-[11px] leading-snug text-slate-600">{renderComposed(t, fault.tradeoff)}</p>}
      </details>
    </div>
  );
}

// How reliable a price is, at a glance. A supervisor with thirty seconds reads a colour, not a basis
// key — green means the figure came from this garage repairing this exact fault, grey means it is the
// fleet's number wearing the garage's name.
// Is the expense ledger behind every cost figure still being written to? A frozen source is the one
// data failure that produces NO visible symptom — the medians keep computing and keep looking precise
// while describing a period that has ended.
const FRESH_TONE = {
  current:   null,   // nothing to say; silence is the correct output for a healthy source
  declining: 'bg-amber-50 text-amber-800 ring-amber-500/30',
  stale:     'bg-amber-50 text-amber-800 ring-amber-500/30',
  frozen:    'bg-rose-50 text-rose-800 ring-rose-500/30',
  unknown:   'bg-slate-100 text-slate-600 ring-slate-400/30',
};

/** Shown ONLY when the cost source is unhealthy — never a green "all good" badge nobody reads. */
function CostFreshness({ freshness, t }) {
  const tone = freshness?.status ? FRESH_TONE[freshness.status] : undefined;
  if (!freshness || !tone) return null;
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);

  return (
    <div className={`mb-2.5 rounded-lg px-3 py-2 text-[12px] leading-snug ring-1 ring-inset ${tone}`}>
      <p className="font-bold uppercase tracking-wide">
        {gr(`costFresh.${freshness.status}`)}
        {freshness.last_entry && <span className="ms-1 font-normal normal-case opacity-80">· {gr('costFresh.lastEntry', { d: freshness.last_entry })}</span>}
      </p>
      <p className="mt-0.5">{freshness.message}</p>
      <p className="mt-0.5 text-[11px] opacity-80">{gr('costFresh.impact')}</p>
    </div>
  );
}

const COST_SOURCE = {
  garage_fault_model: { dot: 'bg-emerald-500', tone: 'text-emerald-700' },
  garage_fault:       { dot: 'bg-emerald-500', tone: 'text-emerald-700' },
  garage:             { dot: 'bg-amber-400',   tone: 'text-amber-700' },
  fleet:              { dot: 'bg-slate-300',   tone: 'text-slate-500' },
  unavailable:        { dot: 'bg-slate-200',   tone: 'text-slate-400' },
};

const CONF_TONE = {
  high: 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
  medium: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  low: 'bg-rose-50 text-rose-800 ring-rose-600/20',
  none: 'bg-slate-100 text-slate-600 ring-slate-400/20',
};

/** Where a price came from, as a colour and three words. */
function CostSource({ basis, t }) {
  const s = COST_SOURCE[basis] || COST_SOURCE.unavailable;
  return (
    <span className={`inline-flex items-center gap-1 text-[10px] font-medium ${s.tone}`}>
      <span className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${s.dot}`} />
      {t(`workflow.garageRec.costBasis.source.${basis}`)}
    </span>
  );
}

/**
 * The trade-off as two columns of ticks — the thirty-second read.
 *
 * Both sides get positive statements. A comparison where only the recommended garage earns ticks is
 * advocacy dressed as analysis: the alternative is a real option, and a supervisor overruling the
 * engine needs to see what they would be choosing, not just what they would be giving up.
 */
function DecisionFactors({ fault, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  const { factors, winner, alternative } = fault;
  if (!alternative || (!factors?.winner?.length && !factors?.alternative?.length)) return null;

  const Col = ({ title, items, tone }) => (
    <div className="flex-1">
      <p className={`mb-0.5 text-[10px] font-bold uppercase tracking-wide ${tone}`}>{title}</p>
      <ul className="space-y-0.5">
        {items.map((f, i) => (
          <li key={i} className="flex items-start gap-1 text-[11px] leading-snug text-slate-700">
            <span aria-hidden className="shrink-0 font-bold text-emerald-600">✓</span>{renderReason(t, f)}
          </li>
        ))}
      </ul>
    </div>
  );

  return (
    <div className="mb-2 flex flex-col gap-2 rounded-lg bg-slate-50/80 p-2 ring-1 ring-inset ring-slate-200 sm:flex-row">
      {factors.winner?.length > 0 && (
        <Col title={gr('perFault.whyWins', { garage: winner.garage })} items={factors.winner} tone="text-indigo-700" />
      )}
      {factors.alternative?.length > 0 && (
        <Col title={gr('perFault.whyRelevant', { garage: alternative.garage })} items={factors.alternative} tone="text-slate-500" />
      )}
    </div>
  );
}

// The cost basis ladder, most specific first. Rendering it in full — with the rungs we could NOT use
// greyed rather than hidden — is the difference between "AED 550" and "AED 550, and here is exactly how
// far down we had to go to say that".
const COST_LADDER = ['garage_fault_model', 'garage_fault', 'garage', 'fleet'];

/**
 * Which rung of the confidence ladder a cost figure came from, and what was underneath it.
 *
 * A fallback that happens silently is the whole problem: the number looks equally confident whether it
 * was earned on this garage's engine repairs or borrowed from the fleet. Showing the ladder makes the
 * demotion a visible event rather than an invisible one.
 */
function CostLadder({ stat, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  if (!stat?.basis || stat.basis === 'unavailable') return null;
  const support = stat.support || {};
  const activeAt = COST_LADDER.indexOf(stat.basis);

  return (
    <div className="mt-1 rounded-md bg-slate-50 p-1.5 ring-1 ring-inset ring-slate-200">
      <p className="mb-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-400">{gr('costBasis.ladderTitle')}</p>
      <ol className="space-y-px">
        {COST_LADDER.map((rung, i) => {
          const n = support[rung] ?? 0;
          const used = i === activeAt;
          // Rungs above the one we used were TRIED and found too thin — that is the informative part.
          const skipped = i < activeAt;
          return (
            <li key={rung} className={`flex items-baseline gap-1 text-[10px] leading-snug ${used ? 'font-semibold text-emerald-700' : skipped ? 'text-slate-400 line-through' : 'text-slate-300'}`}>
              <span aria-hidden className="w-3 shrink-0">{used ? '▸' : skipped ? '✕' : '·'}</span>
              <span className="flex-1">{gr(`costBasis.${rung}`)}</span>
              <span className="tabular-nums">{n > 0 ? gr('costBasis.repairs', { n }) : gr('costBasis.noneHere')}</span>
            </li>
          );
        })}
      </ol>
    </div>
  );
}

/** The two prices for one fault, side by side, with the difference only where it is honest to state it. */
function CostCompare({ compare, confidence, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  if (!compare?.winner) return null;
  const { winner, alternative, comparison, same_grain: sameGrain } = compare;

  const Cell = ({ c, strong }) => (
    <div className="min-w-0 flex-1">
      <p className="truncate text-[10px] text-slate-500">{c.garage}</p>
      <p className={`text-[13px] tabular-nums ${strong ? 'font-bold text-slate-800' : 'font-semibold text-slate-600'}`}>
        {gr('outcomes.aed', { n: Math.round(c.value) })}
      </p>
      {/* Colour first, then the count. Reliability has to land before the number is believed. */}
      <p className="flex flex-wrap items-center gap-x-1 leading-snug">
        <CostSource basis={c.basis} t={t} />
        <span className="text-[9px] text-slate-400">· {gr('costBasis.repairs', { n: c.sample })}</span>
      </p>
    </div>
  );

  return (
    <div className="mt-2 rounded-lg bg-amber-50/50 p-2 ring-1 ring-inset ring-amber-200/70">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-1">
        <p className="text-[10px] font-bold uppercase tracking-wide text-amber-700">{gr('costBasis.compareTitle')}</p>
        {/* How much the COMPARISON can be leaned on — a different question from how good either
            price is. "AED 250 cheaper" reads identically whether it rests on 60 fault-specific
            repairs or 9 assorted ones, and that difference decides how hard to lean on it. */}
        {confidence && (
          <span className={`rounded px-1.5 py-0.5 text-[9px] font-bold uppercase ring-1 ring-inset ${CONF_TONE[confidence.level] || CONF_TONE.none}`}>
            {gr('costBasis.confidenceChip', { level: t(`workflow.garageRec.confidence.${confidence.level}`) })}
          </span>
        )}
      </div>
      <div className="flex items-start gap-3">
        <Cell c={winner} strong />
        {alternative && <Cell c={alternative} />}
      </div>
      {comparison && comparison.cheaper !== 'same' && (
        <p className="mt-1 text-[10px] leading-snug text-slate-600">
          {gr(comparison.cheaper === 'alternative' ? 'costBasis.altCheaper' : 'costBasis.winnerCheaper', {
            n: Math.abs(comparison.delta), scope: gr(`costBasis.grain.${comparison.grain}`),
          })}
        </p>
      )}
      {comparison && comparison.cheaper === 'same' && (
        <p className="mt-1 text-[10px] leading-snug text-slate-500">{gr('costBasis.aboutSame')}</p>
      )}
      {/* The trap this guards: one garage has an earned price for THIS fault, the other only an
          all-work average. Those are different questions and the gap between them is not a saving. */}
      {alternative && !sameGrain && (
        <p className="mt-1 text-[10px] leading-snug text-amber-800">{gr('costBasis.mixedGrain')}</p>
      )}
      {confidence?.reason && <p className="mt-1 text-[10px] leading-snug text-slate-500">{renderReason(t, confidence.reason)}</p>}
      <p className="mt-1 text-[9px] font-medium leading-snug text-amber-800">{gr('costBasis.budgetNote')}</p>
    </div>
  );
}

/** A short marked list — the scannable half of a reason. */
function Bullets({ title, items, empty }) {
  return (
    <div className="flex-1">
      <p className="mb-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">{title}</p>
      {items.length > 0 ? (
        <ul className="space-y-0.5">
          {items.map((it, i) => (
            <li key={i} className="flex items-start gap-1 text-[11px] leading-snug text-slate-600">
              <span aria-hidden className={`shrink-0 font-bold ${it.tone}`}>{it.mark}</span>{it.text}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-[11px] leading-snug text-slate-400">{empty}</p>
      )}
    </div>
  );
}

/**
 * The answer to "where did 95/100 come from?" — every component with the points it earned, the points it
 * could have earned, and the facts behind them. Laid out as a dotted-leader ledger so the eye runs
 * straight down the numbers and the total reads as an actual sum. The backend guarantees the awarded
 * values sum to the headline and the maxima sum to 100, so this renders with no client-side arithmetic.
 */
function ScoreBreakdown({ breakdown, t }) {
  if (!breakdown?.components?.length) return null;
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);

  const Row = ({ label, value, strong, bar }) => (
    <div>
      <div className={`flex items-baseline gap-1.5 ${strong ? 'font-bold text-slate-800' : 'text-slate-700'}`}>
        <span className="shrink-0 text-[12px]">{label}</span>
        {/* the dotted leader */}
        <span aria-hidden className="min-w-4 flex-1 translate-y-[-3px] border-b border-dotted border-slate-300" />
        <span className="shrink-0 text-[12px] tabular-nums">{value}</span>
      </div>
      {bar}
    </div>
  );

  return (
    <div className="mt-2 rounded-lg bg-white p-2.5 ring-1 ring-inset ring-slate-200">
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{gr('breakdown.title')}</p>
      <div className="space-y-2">
        {breakdown.components.map((c) => {
          const pct = c.max > 0 ? Math.round((c.awarded / c.max) * 100) : 0;
          return (
            <div key={c.key}>
              <Row
                label={c.label}
                value={c.applicable
                  ? <>{c.awarded}<span className="font-medium text-slate-400">/{c.max}</span></>
                  : <span className="text-[11px] font-medium text-slate-400">{gr('breakdown.notMeasured')}</span>}
                bar={c.applicable && (
                  <div className="mt-0.5 h-1 overflow-hidden rounded-full bg-slate-100">
                    <div
                      className={`h-full rounded-full ${pct >= 75 ? 'bg-emerald-500' : pct >= 40 ? 'bg-amber-400' : 'bg-rose-400'}`}
                      style={{ width: `${Math.max(pct, 2)}%` }}
                    />
                  </div>
                )}
              />
              {/* The FACTS — the countable events that earned those points. */}
              <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{c.detail}</p>
            </div>
          );
        })}
      </div>
      <div className="mt-2 border-t border-slate-200 pt-1.5">
        <Row
          strong
          label={gr('breakdown.total')}
          value={<span className="text-[13px] font-extrabold text-indigo-700">{breakdown.total}/100</span>}
        />
      </div>
      {breakdown.note && <p className="mt-1 text-[11px] leading-snug text-amber-700">{breakdown.note}</p>}
    </div>
  );
}

/**
 * "If we send it here, what is likely to happen?" — the forward-looking half of the decision.
 *
 * Every figure is rendered WITH its basis. A turnaround learned from this garage's own repairs and a
 * fleet median wearing the garage's name are very different claims, and collapsing them into one
 * confident-looking number is the failure mode this whole panel exists to avoid. Fleet-basis figures are
 * visibly demoted; unmeasured ones say "not measured" instead of showing a zero.
 */
function ExpectedOutcomes({ outcomes, fleet, t }) {
  if (!outcomes) return null;
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);

  // A figure is only a claim about THIS garage when it was derived from this garage's own history.
  const Stat = ({ label, stat, render, invert }) => {
    // MISSING DATA IS EXPLAINED, never merely hidden. A blank metric reads as a broken system; a stated
    // reason reads as a system that chose honesty over estimation — and tells you what would fix it.
    if (!stat || stat.value === null || stat.basis === 'unavailable') {
      return (
        <div>
          <div className="flex items-baseline justify-between gap-2">
            <span className="text-[11px] text-slate-500">{label}</span>
            <span className="text-[11px] font-semibold text-slate-400">{gr('outcomes.unavailable')}</span>
          </div>
          {stat?.reason && (
            <p className="text-[10px] leading-snug text-slate-400">
              <span className="font-medium">{gr('unavailableReason')}:</span> {stat.reason}
            </p>
          )}
        </div>
      );
    }
    const weak = stat.basis === 'fleet';
    // For comeback risk, lower is better — the colour has to flip or the panel lies with colour.
    const fleetRef = invert ? fleet?.comeback_pct : null;
    const worseThanFleet = fleetRef != null && stat.value > fleetRef;
    const support = Object.entries(stat.support || {}).filter(([, n]) => n > 0);

    return (
      <div>
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-[11px] text-slate-500">{label}</span>
          <span className={`text-[12px] font-bold tabular-nums ${weak ? 'text-slate-400' : worseThanFleet ? 'text-rose-600' : 'text-slate-800'}`}>
            {render(stat)}
          </span>
        </div>
        {/* Confidence, then the counts it rests on — so a fleet fallback is obvious at a glance. */}
        {stat.confidence && (
          <div className="flex items-center gap-1">
            <span className={`inline-block h-1.5 w-1.5 rounded-full ${CONF_DOT[stat.confidence] || CONF_DOT.low}`} />
            <span className="text-[10px] text-slate-400">
              {gr('confidenceLabel')}: {t(`workflow.garageRec.confidence.${stat.confidence}`)}
            </span>
          </div>
        )}
        {support.length > 0 && (
          <p className="text-[10px] leading-snug text-slate-400">
            {gr('basedOnCounts')}: {support.map(([scope, n]) => gr('supportRow', { n, scope: gr(`scope.${scope}`) })).join(' · ')}
          </p>
        )}
        {stat.reason && <p className="text-[10px] leading-snug text-amber-700">{stat.reason}</p>}
      </div>
    );
  };

  const anyFleet = ['duration_days', 'success_pct', 'cost_aed'].some((k) => outcomes[k]?.basis === 'fleet');

  return (
    <div className="mt-2 rounded-lg bg-slate-50 p-2.5 ring-1 ring-inset ring-slate-200">
      <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{gr('outcomes.title')}</p>
      <div className="grid grid-cols-2 gap-x-3 gap-y-1.5">
        {/* Turnaround is right-skewed and the calibration backtest shows the forecast running LOW, so
            the normal case and the risk case are reported as two separate figures. Widening one number
            into a range would blur the weakness rather than state it. */}
        <Stat label={gr('outcomes.duration')} stat={outcomes.duration_days} render={(s) => days(s.value, gr)} />
        <Stat
          label={gr('outcomes.durationRisk')}
          stat={outcomes.duration_p90}
          render={(s) => gr('rangeUpTo', { n: s.value })}
        />
        <Stat label={gr('outcomes.success')} stat={outcomes.success_pct} render={(s) => `${s.value}%`} />
        <Stat label={gr('outcomes.comeback')} stat={outcomes.comeback_pct} render={(s) => `${s.value}%`} invert />
        <Stat label={gr('outcomes.cost')} stat={outcomes.cost_aed} render={(s) => gr('outcomes.aed', { n: Math.round(s.value) })} />
        <Stat label={gr('outcomes.transport')} stat={outcomes.transport} render={(s) => s.value} />
      </div>
      {/* How far down the ladder this garage's cost figure had to go, with the rungs it could not use. */}
      <CostLadder stat={outcomes.cost_aed} t={t} />
      <p className="mt-1 text-[10px] font-medium leading-snug text-amber-800">{gr('costBasis.budgetNote')}</p>
      {/* Side by side these two look like two independent verdicts on the garage. They are one number
          and its complement, and saying so is the difference between "half its repairs fail" and "this
          is the repeat rate, stated twice". */}
      {outcomes.success_pct?.value != null && outcomes.comeback_pct?.value != null && (
        <p className="mt-1 text-[10px] leading-snug text-slate-400">{gr('outcomes.mirrorNote')}</p>
      )}
      <div className="mt-1.5 flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-t border-slate-200 pt-1.5 text-[11px] text-slate-600">
        <span>{gr('outcomes.queue')}: <b className="tabular-nums">{outcomes.queue_open?.value ?? 0}</b></span>
        <span>{gr('outcomes.start')}: <b>{outcomes.start_in_days > 0 ? gr('outcomes.inDays', { n: outcomes.start_in_days }) : gr('outcomes.now')}</b></span>
        {outcomes.complete_in_days != null && (
          <span>{gr('outcomes.complete')}: <b>{gr('outcomes.inDays', { n: outcomes.complete_in_days })}</b></span>
        )}
      </div>
      {anyFleet && <p className="mt-1 text-[10px] leading-snug text-amber-700">{gr('outcomes.fleetWarn')}</p>}
    </div>
  );
}

/**
 * The whole decision in ten seconds, with the two axes shown SIDE BY SIDE and deliberately unmerged.
 *
 * Engineering quality and commercial preference are different questions with different owners, and a
 * single blended number would hide which one drove the call. So the panel names the technical winner,
 * names the business winner (or says there is no trade worth making), then states the final call and the
 * one reason that settled it. The summary text comes from the backend — the same sentence that lands in
 * the audit trail — rather than being reassembled here, which would be a second implementation of the
 * reasoning that could drift from the first.
 */
function DecisionSummary({ summary, onPick, isSel, t }) {
  if (!summary) return null;
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  const { technical, business } = summary;

  const Axis = ({ title, tone, garage, score, lines, vendorId }) => (
    <div className={`flex-1 rounded-lg p-2.5 ring-1 ring-inset ${tone}`}>
      <p className="text-[10px] font-bold uppercase tracking-wide opacity-70">{title}</p>
      {garage ? (
        <>
          <p className="mt-0.5 truncate text-[13px] font-bold text-slate-800">{garage}</p>
          {score != null && <p className="text-[12px] font-semibold tabular-nums text-slate-600">{score}/100</p>}
          {lines?.map((l, i) => <p key={i} className="text-[11px] leading-snug text-slate-600">{l}</p>)}
          {vendorId != null && (
            <button
              type="button"
              onClick={() => onPick(vendorId)}
              className="mt-1 text-[11px] font-semibold text-indigo-600 underline-offset-2 hover:underline"
            >
              {isSel(vendorId) ? t('workflow.garageRec.selected') : t('workflow.garageRec.use')}
            </button>
          )}
        </>
      ) : (
        <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{gr('summary.none')}</p>
      )}
    </div>
  );

  return (
    <div className="mb-2.5 rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-300">
      <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-500">{gr('summary.title')}</p>

      <div className="flex flex-col gap-2 sm:flex-row">
        <Axis
          title={gr('summary.technical')}
          tone="bg-indigo-50/70 ring-indigo-200"
          garage={technical?.garage}
          score={technical?.match_score}
          vendorId={technical?.vendor_id}
        />
        <Axis
          title={gr('summary.business')}
          tone="bg-amber-50/70 ring-amber-200"
          garage={business?.garage}
          lines={business?.advantages}
          vendorId={business?.vendor_id}
        />
      </div>

      <div className="mt-2 border-t border-slate-200 pt-2">
        <div className="flex items-baseline gap-2">
          <span className="text-[11px] font-bold uppercase tracking-wide text-emerald-700">{gr('summary.final')}</span>
          <span className="text-[14px] font-extrabold text-slate-900">{summary.final}</span>
          {summary.axes_agree && (
            <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
              {gr('summary.agree')}
            </span>
          )}
        </div>
        <p className="mt-0.5 text-[12px] leading-snug text-slate-600">
          <span className="font-semibold">{gr('summary.reason')}: </span>{summary.reason}
        </p>
      </div>
    </div>
  );
}

/** The business advantages a garage may honestly claim, each already carrying its number. */
function BusinessBadges({ business, t }) {
  if (!business?.badges?.length) return null;
  return (
    <div className="mt-1.5 flex flex-wrap gap-1">
      {business.badges.map((b) => (
        <span key={b} className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
          {t(`workflow.garageRec.business.badges.${b}`)}
        </span>
      ))}
    </div>
  );
}

/**
 * The multi-fault decision, shown ABOVE the hero card because when it says "split" it overrides the
 * single-garage recommendation the hero is making. It always states the reason and the trade-off — an
 * expert fleet manager's call, with the reasoning exposed so the supervisor can overrule it.
 */
function StrategyBanner({ strategy, onPick, isSel, t }) {
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  if (!strategy) return null;
  const split = strategy.mode === 'split';
  // A "single" verdict adds nothing the hero card doesn't already say — only surface it when the engine
  // actively considered splitting and decided against it, which is a real decision worth showing.
  if (!split && !strategy.rejected_reason) return null;

  return (
    <div className={`mb-2.5 rounded-xl p-3 ring-1 ring-inset ${split ? 'bg-violet-50 ring-violet-400/40' : 'bg-slate-50 ring-slate-300/60'}`}>
      <p className={`flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide ${split ? 'text-violet-700' : 'text-slate-500'}`}>
        <Icon.Wrench className="h-3.5 w-3.5" /> {gr(split ? 'strategy.splitTitle' : 'strategy.singleTitle')}
      </p>
      <p className="mt-1 text-[13px] font-semibold leading-snug text-slate-800">{strategy.headline}</p>

      {split && (
        <div className="mt-2 space-y-1.5">
          {strategy.legs.map((leg) => (
            <div key={leg.vendor_id} className="flex items-center justify-between gap-2 rounded-lg bg-white px-2.5 py-1.5 ring-1 ring-inset ring-violet-200">
              <div className="min-w-0">
                <div className="truncate text-[13px] font-semibold text-slate-800">{leg.garage}</div>
                <div className="text-[11px] text-slate-500">
                  {leg.faults.map((f) => f.label).join(' · ')} — {leg.match_score}/100
                </div>
              </div>
              <button
                type="button"
                onClick={() => onPick(leg.vendor_id)}
                className={`shrink-0 rounded-md px-2.5 py-1 text-[12px] font-semibold transition ${isSel(leg.vendor_id) ? 'bg-violet-600 text-white' : 'bg-violet-50 text-violet-700 hover:bg-violet-100'}`}
              >
                {isSel(leg.vendor_id) ? t('workflow.garageRec.selected') : t('workflow.garageRec.use')}
              </button>
            </div>
          ))}
        </div>
      )}

      <dl className="mt-2 space-y-1 text-[12px] leading-snug">
        <div><dt className="inline font-semibold text-slate-600">{gr('strategy.reason')}: </dt><dd className="inline text-slate-600">{strategy.reason}</dd></div>
        <div><dt className="inline font-semibold text-slate-600">{gr('strategy.tradeoff')}: </dt><dd className="inline text-slate-600">{strategy.tradeoff}</dd></div>
        {strategy.why_not && (
          <div><dt className="inline font-semibold text-slate-600">{gr('strategy.alternative')}: </dt><dd className="inline text-slate-600">{strategy.why_not}</dd></div>
        )}
        {strategy.actionable_note && (
          <div><dt className="inline font-semibold text-slate-600">{gr('strategy.dispatch')}: </dt><dd className="inline text-slate-600">{strategy.actionable_note}</dd></div>
        )}
      </dl>

      {/* The BUSINESS trade-off — a near-equal garage that is materially cheaper or faster. Surfaced,
          never auto-applied: the technical pick stands until a human decides otherwise. */}
      {strategy.business_tradeoff && (
        <div className="mt-2 rounded-lg bg-white p-2.5 ring-1 ring-inset ring-amber-400/40">
          <p className="text-[11px] font-bold uppercase tracking-wide text-amber-700">{gr('business.tradeoffTitle')}</p>
          <p className="mt-0.5 text-[12px] leading-snug text-slate-700">{strategy.business_tradeoff.summary}</p>
          <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{strategy.business_tradeoff.detail}</p>
          <button
            type="button"
            onClick={() => onPick(strategy.business_tradeoff.candidate_vendor_id)}
            className="mt-1.5 rounded-md bg-amber-50 px-2.5 py-1 text-[12px] font-semibold text-amber-800 transition hover:bg-amber-100"
          >
            {t('workflow.garageRec.use')}
          </button>
        </div>
      )}
    </div>
  );
}

function ConfBadge({ conf, t }) {
  if (!conf) return null;
  return (
    <span
      title={t(`workflow.garageRec.confidenceTip.${conf}`)}
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${CONF_STYLE[conf] || CONF_STYLE.low}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${CONF_DOT[conf] || CONF_DOT.low}`} />
      {t(`workflow.garageRec.confidence.${conf}`)}
    </span>
  );
}

/**
 * @param ticketId  score a real ticket (the assign step)
 * @param query     score a hypothetical: { model, brand, faults[], symptoms{}, severities{} } — the
 *                  fault-first finder, where no ticket exists yet. Exactly one of the two is used.
 * @param chrome    false strips the panel's own heading/background, for hosts that supply their own
 * @param payload   an ALREADY-FETCHED result. When a host renders this as its evidence layer it has the
 *                  payload in hand; refetching would mean two calls that can disagree with each other.
 */
export default function GarageRecommendations({ ticketId, query, payload, selectedVendorId, onPick, onResult, chrome = true }) {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: !payload, data: payload || null, error: false });
  const [showMore, setShowMore] = useState(false);
  const [showDetails, setShowDetails] = useState(false);
  const [showCalc, setShowCalc] = useState(false);
  // Expanded by default only when there is no fault-first view to lead with (single-fault tickets).
  const [showEngine, setShowEngine] = useState(false);

  // Serialised so a caller can rebuild the query object each render without re-fetching on every keystroke.
  const queryKey = query ? JSON.stringify(query) : null;

  useEffect(() => {
    if (payload) { setState({ loading: false, data: payload, error: false }); return undefined; }
    if (!ticketId && !queryKey) return undefined;
    let alive = true;
    setState({ loading: true, data: null, error: false });

    const request = ticketId
      ? api.get(`/maintenance-tickets/${ticketId}/garage-recommendations`)
      : api.get('/garage-recommendations', { params: JSON.parse(queryKey) });

    request
      .then((res) => {
        if (!alive) return;
        const data = res.data?.data || null;
        setState({ loading: false, data, error: false });
        if (data && onResult) onResult(data);
      })
      .catch(() => alive && setState({ loading: false, data: null, error: true }));
    return () => { alive = false; };
  }, [ticketId, queryKey, payload]); // eslint-disable-line react-hooks/exhaustive-deps

  const { loading, data, error } = state;

  const shell = (children) => (chrome ? (
    <div className="rounded-xl bg-gradient-to-br from-indigo-50/80 to-slate-50 p-3.5 ring-1 ring-inset ring-indigo-200/60">
      <p className="mb-2.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-indigo-700">
        <Icon.Wrench className="h-3.5 w-3.5" /> {t('workflow.garageRec.recTitle')}
      </p>
      {children}
    </div>
  ) : <div>{children}</div>);

  if (loading) return shell(<p className="text-xs text-slate-400">{t('workflow.garageRec.loading')}</p>);
  if (error) return null;

  const primary = data?.primary || [];
  const also = data?.also_consider || [];
  const faultLabels = data?.criteria?.fault_labels || [];
  const criticality = data?.criteria?.fault_criticality || [];
  const fleetOutcomes = data?.fleet_outcomes || null;
  const perFault = data?.per_fault || [];
  const metrics = data?.metrics || {};
  // With no fault-first view to lead with there is nothing to collapse behind, so the engine view is the
  // panel — it must stay visible, and there is no toggle to reveal it.
  const engineVisible = showEngine || perFault.length === 0;
  if (!primary.length && !also.length) return shell(<p className="text-xs text-slate-400">{t('workflow.garageRec.none')}</p>);

  const isSel = (id) => String(selectedVendorId || '') === String(id);
  const top = primary[0];
  const others = primary.slice(1);
  const topSelected = top && isSel(top.vendor_id);
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  // Per-fault coverage evidence for the top pick (tied to THIS repair's detected faults).
  // `ticket` on the assign step, `subject` on the ticket-free finder — same shape, different origin.
  const faultsDetail = data?.ticket?.faults_detail || data?.subject?.faults_detail || [];
  const modelLabel = data?.ticket?.model_label || data?.subject?.model_label || '';
  const covByCat = {};
  (top?.fault_coverage || []).forEach((fc) => { covByCat[fc.category_key] = fc; });
  const compareBtn = (others.length > 0 || also.length > 0) && (
    <button
      type="button"
      onClick={() => setShowMore((v) => !v)}
      className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-[13px] font-semibold text-slate-600 transition hover:bg-slate-50"
    >
      {showMore ? gr('hide') : gr('compare')}
    </button>
  );

  // THE ANALYST'S HALF — kept exactly as it was, moved behind one click. Every figure, every basis,
  // every fallback rung still renders; it simply stops being the first thing a supervisor meets.
  const technical = (
    <>
      <FaultWinnerTable perFault={perFault} metrics={metrics} t={t} />

      {perFault.length > 0 && (
        <div className="mb-2.5 space-y-2">
          {perFault.map((f) => (
            <FaultCard key={f.category_key} fault={f} metrics={metrics} onPick={onPick} isSel={isSel} t={t} />
          ))}
        </div>
      )}

      {/* THE OVERALL CALL — technical vs business vs final, after the per-fault detail. */}
      <DecisionSummary summary={data?.strategy?.summary} onPick={onPick} isSel={isSel} t={t} />

      {/* THE PLAN — one garage or a split. Sits above the hero because a split verdict overrides it. */}
      <StrategyBanner strategy={data?.strategy} onPick={onPick} isSel={isSel} t={t} />

      {/* The full engine view — score breakdown, forecasts, runner-ups — is now OPT-IN whenever the
          fault-first view is available. It is the evidence behind the answer, not the answer, and
          leading with it is what made this panel hard to scan. */}
      {perFault.length > 0 && (
        <button
          type="button"
          onClick={() => setShowEngine((v) => !v)}
          className="mb-2 text-[12px] font-semibold text-indigo-600 transition hover:text-indigo-700"
        >
          {gr(showEngine ? 'engineDetail.hide' : 'engineDetail.show')}
        </button>
      )}

      {/* HERO — the recommended decision */}
      {top && engineVisible && (
        <div className="rounded-xl border-2 border-indigo-500/80 bg-white p-3 shadow-sm">
          <div className="flex items-center gap-1.5">
            <span aria-hidden className="text-lg">🥇</span>
            <span className="truncate text-[15px] font-bold text-slate-900">{top.garage}</span>
          </div>

          {/* Score is the anchor; confidence is secondary. */}
          <div className="mt-1.5 flex items-end justify-between gap-3">
            <div className="flex items-baseline gap-1">
              <span className="text-[34px] font-extrabold leading-none tabular-nums text-indigo-700">{top.match_score}</span>
              <span className="text-sm font-semibold text-slate-400">/100</span>
            </div>
            <div className="flex items-center gap-1.5 pb-0.5">
              {top.warn && <Icon.Alert className="h-3.5 w-3.5 text-amber-500" title="Mixed re-inspection record" />}
              <ConfBadge conf={top.confidence} t={t} />
            </div>
          </div>
          {/* The score must never be an unexplained number — the calculation is always one tap away. */}
          <button
            type="button"
            onClick={() => setShowCalc((v) => !v)}
            className="mt-1 flex items-center gap-1 text-[12px] font-semibold text-indigo-600 transition hover:text-indigo-700"
          >
            {gr(showCalc ? 'breakdown.hide' : 'breakdown.show')}
          </button>
          {showCalc && <ScoreBreakdown breakdown={top.breakdown} t={t} />}
          <BusinessBadges business={top.business} t={t} />

          {/* The forward-looking half — what to expect if this pick is confirmed. */}
          <ExpectedOutcomes outcomes={top.outcomes} fleet={fleetOutcomes} t={t} />

          <p className="mt-1 text-[11px] leading-snug text-slate-400">{gr('basedOn')}</p>

          {top.confidence === 'low' && (
            <div className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[12px] text-amber-800 ring-1 ring-inset ring-amber-600/15">
              <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0" /> <span>{gr('lowWarning')}</span>
            </div>
          )}

          {top.reasons?.length > 0 && (
            <div className="mt-2.5">
              <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{gr('why.title')}</p>
              <ul className="space-y-1">
                {top.reasons.map((r, i) => (
                  <li key={i} className="flex items-start gap-1.5 text-[13px] text-slate-700">
                    <Icon.Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                    <span>{r.t}{r.s ? <span className="text-slate-400"> · {r.s}</span> : null}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Power-user evidence — collapsed by default */}
          <button type="button" onClick={() => setShowDetails((v) => !v)} className="mt-2 text-[12px] font-semibold text-indigo-600 hover:text-indigo-700">
            {showDetails ? gr('hideDetails') : gr('viewDetails')}
          </button>
          {/* Per-fault coverage — has this garage repaired THESE exact problems before? Always visible:
              on a multi-fault ticket this is the evidence that decides the assignment, not a detail. */}
          {faultsDetail.length > 0 && top.coverage && (
            <div className="mt-2.5 rounded-lg bg-white p-2.5 ring-1 ring-inset ring-slate-200">
              <div className="mb-1.5 flex items-center justify-between">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{gr('faultCoverageTitle')}</span>
                <b className="text-[12px] tabular-nums text-slate-800">{top.coverage.covered}/{top.coverage.total}</b>
              </div>
              <div className="space-y-1.5">
                {faultsDetail.map((f, i) => {
                  const c = covByCat[f.category_key] || { at_garage: 0, same_model: 0, label: f.label, tier: 'none', pct: 0 };
                  const style = TIER_STYLE[c.tier] || TIER_STYLE.none;
                  const Mark = style.icon === 'Check' ? Icon.Check : Icon.Alert;
                  return (
                    <div key={i} className="flex items-start gap-1.5 text-[12px]">
                      <Mark className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${style.text}`} />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-baseline justify-between gap-2">
                          <span className="font-medium text-slate-700">{f.symptom}</span>
                          <span className={`shrink-0 text-[11px] font-semibold tabular-nums ${style.text}`}>{gr('coveragePct', { pct: c.pct })}</span>
                        </div>
                        {/* The evidence sentence — same-model history when there is any, else the
                            same-fault-elsewhere fallback that earned the lower tier. */}
                        <div className="text-slate-500">
                          {c.same_model > 0
                            ? gr('repairsModel', { n: c.same_model, model: modelLabel, label: c.label })
                            : gr('repairsHere', { n: c.at_garage, label: c.label })}
                          <span className={`ms-1 ${style.text}`}>· {gr(`tier.${c.tier}`)}</span>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
              {top.coverage.missing?.length > 0 && (
                <div className="mt-1.5 text-[11px] font-medium text-amber-700">{gr('missingExperience')}: {top.coverage.missing.join(', ')}</div>
              )}
            </div>
          )}

          {showDetails && (
            <div className="mt-1.5 space-y-2 rounded-lg bg-slate-50 p-2.5 text-[12px] text-slate-600 ring-1 ring-inset ring-slate-200">
              <div className="flex justify-between"><span>{gr('details.matches')}</span><b className="tabular-nums text-slate-800">{top.matched}</b></div>
              <div className="flex justify-between"><span>{gr('details.specialization')}</span><b className="tabular-nums text-slate-800">{top.concentration}%</b></div>
              <div className="flex justify-between"><span>{gr('details.ranking')}</span><b className="text-slate-800">{gr('rankOf', { rank: 1, total: primary.length })}</b></div>
              <div><span className="font-medium text-slate-500">{gr('details.confidence')}:</span> {t(`workflow.garageRec.confidenceTip.${top.confidence}`)}</div>
              <div><span className="font-medium text-slate-500">{gr('details.alternatives')}:</span> {gr('altSummary', { proven: others.length, spec: also.length })}</div>
            </div>
          )}

          {/* CTA, or a richer confirmation once selected */}
          {topSelected ? (
            <>
              <div className="mt-3 rounded-lg bg-emerald-50 p-3 ring-1 ring-inset ring-emerald-600/20">
                <p className="flex items-center gap-1.5 text-[13px] font-bold text-emerald-800"><Icon.Check className="h-4 w-4" /> {gr('confirmSelected')}</p>
                <div className="mt-1.5 space-y-0.5 text-[13px] text-emerald-900">
                  <div>{gr('score')}: <b className="tabular-nums">{top.match_score}/100</b></div>
                  <div>{gr('details.confidence')}: <b>{gr(`confidence.${top.confidence}`)}</b></div>
                </div>
                <p className="mt-2 text-[11px] text-emerald-700/80">{gr('auditNote')}</p>
              </div>
              {compareBtn && <div className="mt-2">{compareBtn}</div>}
            </>
          ) : (
            <div className="mt-3 flex gap-2">
              <button
                type="button"
                onClick={() => onPick(top.vendor_id)}
                className="flex-1 rounded-lg bg-indigo-600 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-indigo-700"
              >
                {gr('selectRecommended')}
              </button>
              {compareBtn}
            </div>
          )}
        </div>
      )}

      {/* No PRIMARY pick — no garage has a proven record on this exact vehicle + fault. Rather than leave
          the panel blank under "Recommended for: …", explain why and surface the fallback specialists
          directly (they'd otherwise be stranded behind a Compare button that only lives inside the hero). */}
      {!top && engineVisible && (
        <div className="rounded-xl border border-amber-300 bg-amber-50/70 p-3">
          <p className="flex items-center gap-1.5 text-[13px] font-bold text-amber-800">
            <Icon.Alert className="h-4 w-4 shrink-0" /> {gr('noPrimaryTitle')}
          </p>
          <p className="mt-1 text-[12px] leading-snug text-amber-800/90">
            {also.length > 0 ? gr('noPrimary') : gr('noPrimaryNoAlso')}
          </p>
        </div>
      )}

      {/* Secondary — revealed on Compare (when there's a hero) or shown directly when there's no primary
          pick, so the specialist fallbacks are always reachable. */}
      {engineVisible && (showMore || !top) && (
        <div className="mt-3 space-y-3">
          {others.length > 0 && (
            <div>
              <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('workflow.garageRec.otherProven')}</p>
              <div className="space-y-1.5">
                {others.map((g) => (
                  <div key={g.vendor_id} className={`rounded-lg border bg-white px-2.5 py-1.5 ${isSel(g.vendor_id) ? 'border-indigo-400' : 'border-slate-200'}`}>
                    <div className="flex items-center justify-between gap-2">
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          <span className={`h-1.5 w-1.5 rounded-full ${CONF_DOT[g.confidence] || CONF_DOT.low}`} title={t(`workflow.garageRec.confidenceTip.${g.confidence}`)} />
                          <span className="truncate text-[13px] font-semibold text-slate-800">{g.garage}</span>
                        </div>
                        <span className="text-[11px] text-slate-400">
                          {t('workflow.garageRec.matches', { n: g.matched })} · {g.match_score}/100
                        </span>
                      </div>
                      <button
                        type="button"
                        onClick={() => onPick(g.vendor_id)}
                        className={`shrink-0 rounded-md px-2.5 py-1 text-[12px] font-semibold transition ${isSel(g.vendor_id) ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'}`}
                      >
                        {isSel(g.vendor_id) ? t('workflow.garageRec.selected') : t('workflow.garageRec.use')}
                      </button>
                    </div>
                    <BusinessBadges business={g.business} t={t} />

                    {/* Why this one LOST — stated in facts, with the strengths it still has. A
                        comparison that only lists failings is advocacy, not analysis. */}
                    {g.why_not && (
                      <div className="mt-1 space-y-1 border-t border-slate-100 pt-1">
                        <p className="text-[11px] leading-snug text-slate-500">
                          <span className="font-semibold text-slate-600">{t('workflow.garageRec.whyNot.title')} </span>
                          {g.why_not.summary}
                        </p>
                        {g.why_not.lost_because?.length > 0 && (
                          <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-rose-500">{t('workflow.garageRec.whyNot.lostBecause')}</p>
                            <ul className="mt-0.5 space-y-0.5">
                              {g.why_not.lost_because.map((f, i) => (
                                <li key={i} className="flex items-start gap-1 text-[11px] leading-snug text-slate-600">
                                  <span aria-hidden className="text-rose-400">•</span>{f}
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                        {g.why_not.strengths?.length > 0 && (
                          <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-emerald-600">{t('workflow.garageRec.whyNot.strengths')}</p>
                            <ul className="mt-0.5 space-y-0.5">
                              {g.why_not.strengths.map((s, i) => (
                                <li key={i} className="flex items-start gap-1 text-[11px] leading-snug text-slate-600">
                                  <span aria-hidden className="text-emerald-500">•</span>{s.detail}
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {also.length > 0 && (
            <div>
              <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('workflow.garageRec.specialists')}</p>
              <div className="flex flex-wrap gap-2">
                {also.map((a) => (
                  <button
                    key={`${a.dimension}-${a.vendor_id}`}
                    type="button"
                    onClick={() => onPick(a.vendor_id)}
                    title={t('workflow.garageRec.alsoHint')}
                    className={`inline-flex flex-col items-start rounded-lg border px-2.5 py-1.5 text-start transition hover:border-indigo-400 ${isSel(a.vendor_id) ? 'border-indigo-500 bg-indigo-50' : 'border-dashed border-slate-300 bg-white'}`}
                  >
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-indigo-600">{a.label}</span>
                    <span className="text-xs font-semibold text-slate-800">{a.garage}</span>
                    <span className="text-[11px] text-slate-400">{t('workflow.garageRec.specialistJobs', { jobs: a.jobs, conc: a.concentration })}</span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );

  // THE PLAN, in one line. A ticket goes to ONE garage, so once the faults have been read individually
  // the supervisor still needs to be told what to do with the car — and told plainly when the honest
  // answer is "no single garage is right for all of this".
  const split = data?.strategy?.mode === 'split';
  const footer = perFault.length > 0 && (
    split ? (
      <p className="mb-2.5 rounded-xl bg-amber-50 px-3 py-2.5 text-[13px] leading-snug text-amber-900 ring-1 ring-inset ring-amber-300">
        {gr('plain.splitNote')}
      </p>
    ) : top && (
      <div className="mb-2.5 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 ring-1 ring-inset ring-emerald-300">
        <p className="text-[13px] font-semibold text-emerald-900">{gr('plain.ticketGoesTo', { garage: top.garage })}</p>
        <button
          type="button"
          onClick={() => onPick(top.vendor_id)}
          className={`rounded-lg px-3 py-1.5 text-[13px] font-semibold transition ${
            topSelected ? 'bg-emerald-600 text-white' : 'bg-emerald-600 text-white hover:bg-emerald-700'}`}
        >
          {topSelected ? gr('plain.isSelected', { garage: top.garage }) : gr('plain.sendTo', { garage: top.garage })}
        </button>
      </div>
    )
  );

  return shell(
    <>
      {/* Faults, each tagged with the priority that decides how much it influenced the recommendation —
          a scratch and a brake failure must never look like equal inputs. */}
      {faultLabels.length > 0 && (
        <div className="mb-2.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
          <span className="font-medium">{gr('recommendedFor')}:</span>
          {(criticality.length ? criticality : faultLabels.map((f) => ({ label: f }))).map((c) => (
            <span
              key={c.category_key || c.label}
              title={c.tier ? `${t(`workflow.garageRec.criticality.${c.tier}`)} · ×${c.weight}${c.escalated ? ` · ${gr('criticality.escalated')}` : ''}` : undefined}
              className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${CRIT_STYLE[c.tier] || 'bg-white text-slate-700 ring-slate-200'}`}
            >
              {c.label}
              {c.tier && <span className="opacity-70">×{c.weight}</span>}
              {c.escalated && <span title={gr('criticality.escalated')}>↑</span>}
            </span>
          ))}
        </div>
      )}

      {/* If the money behind every cost figure stopped arriving, say so BEFORE the figures. */}
      <CostFreshness freshness={data?.cost_freshness} t={t} />

      {/* THE ANSWER — one card per fault, in words. This is the whole screen for most readers. */}
      {perFault.length > 0 && (
        <div className="mb-2.5 space-y-2">
          {perFault.map((f) => (
            <FaultDecision key={f.category_key} fault={f} model={modelLabel} onPick={onPick} isSel={isSel} t={t} faultCount={perFault.length} />
          ))}
        </div>
      )}

      {footer}

      {perFault.length > 0 && (
        <p className="mb-2.5 text-[11px] leading-snug text-slate-400">{gr('plain.sourceNote')}</p>
      )}

      {/* THE EVIDENCE — untouched, and deliberately not the default. Rendered inside <details> rather
          than behind a state flag so it stays in the page for search, print and screen readers. */}
      {perFault.length > 0 ? (
        <details className="rounded-xl bg-white/60 ring-1 ring-inset ring-slate-200">
          <summary className="cursor-pointer px-3 py-2 text-[12px] font-semibold text-indigo-700">
            {gr('plain.technical')}
            <span className="ms-1.5 font-normal text-slate-400">{gr('plain.technicalHint')}</span>
          </summary>
          <div className="border-t border-slate-200 p-3">{technical}</div>
        </details>
      ) : technical}
    </>
  );
}
