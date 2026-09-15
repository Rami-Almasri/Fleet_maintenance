// Suggested Checks — "what should I actually look at on THIS car?"
//
// Replaces the fixed Battery / Fluids / Brakes row that used to appear on every idle car. Everything
// here is derived per vehicle by VehicleSuggestedChecksService from two evidence sources, rendered as
// two clearly separate groups because they answer different questions:
//
//   RECURRING HISTORY — the car keeps coming back for this ("returned 3×, last seen 52 days ago,
//                       usually returns every ~47 days"). Measured from its own repair episodes.
//   FORECAST          — scheduled upkeep it is already over (oil km/date, battery age, tyres).
//
// The old checklist still renders, at the bottom, under its own heading and deliberately NOT as
// tappable chips: it is an inspection agenda, never evidence that those faults exist.
//
// LANGUAGE: the backend sends reason CODES + params, never English sentences. Every line on screen is
// composed here through tp()/tf(), which is what lets the whole panel render in Arabic. The one thing
// that cannot come from labels.js is the finding keyword itself ("Brake noise") — that is catalog data,
// so the backend attaches `chip_ar` / `category_label_ar` alongside it.

import { useEffect, useRef, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import systemIcon from './systemIcons';

// One car's payload is reused across every card and page that asks for it in this session. The
// Inspection Review Queue can hold 140 cards over ~137 distinct vehicles; without this, scrolling
// back up would refetch what we already have.
const cache = new Map();

/**
 * Fetch a car's suggested checks the first time its card is actually scrolled into view.
 *
 * Deliberately NOT part of the ticket list response: computing this for every vehicle in the review
 * queue measured at 3.75s, about 4× the rest of that endpoint. Visible cards only, one request each,
 * cached per session — a reviewer working the top of the queue never pays for the other 130.
 */
function useSuggestedChecks(vehicleId, provided) {
  const [data, setData] = useState(() => provided ?? cache.get(vehicleId) ?? null);
  const ref = useRef(null);

  useEffect(() => {
    if (provided || !vehicleId || data) return undefined;

    const el = ref.current;
    if (!el) return undefined;

    let cancelled = false;

    const load = async () => {
      if (cache.has(vehicleId)) {
        setData(cache.get(vehicleId));
        return;
      }
      try {
        const res = await api.get(`/Vehicle/${vehicleId}/suggested-checks`);
        const payload = res?.data?.data ?? null;
        if (payload) cache.set(vehicleId, payload);
        if (!cancelled) setData(payload);
      } catch {
        // A car whose checks can't be computed simply shows no panel. This is advisory context on a
        // review card — it must never block the reviewer or surface an error where a suggestion goes.
        if (!cancelled) setData(null);
      }
    };

    // No IntersectionObserver (older browsers, jsdom in tests) → just load.
    if (typeof IntersectionObserver === 'undefined') {
      load();
      return () => { cancelled = true; };
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          observer.disconnect();
          load();
        }
      },
      { rootMargin: '200px' }, // start just before it reaches the viewport, so it's there on arrival
    );
    observer.observe(el);

    return () => {
      cancelled = true;
      observer.disconnect();
    };
  }, [vehicleId, provided, data]);

  return { data, ref };
}

// Condition keys the forecast group can emit, mapped to a readable name. Kept here (not in the
// engine) because it is presentation: the engine emits the key, the UI decides the wording.
const CONDITION_KEY = {
  oil_change: { key: 'suggestedChecks.condition.oilChange', en: 'Oil service' },
  battery: { key: 'suggestedChecks.condition.battery', en: 'Battery' },
  tire_rotation: { key: 'suggestedChecks.condition.tireRotation', en: 'Tyre rotation' },
  tire_change: { key: 'suggestedChecks.condition.tireChange', en: 'Tyre change' },
};

/**
 * One reason code + params → one human line. Returns null for a code we don't render, so an engine
 * that learns a new code degrades to showing fewer lines rather than printing a raw code at the user.
 */
function useReasonLine() {
  const { tf, tp, lang } = useI18n();

  return ({ code, params = {} }) => {
    const n = Number(params.days ?? params.episodes ?? params.km ?? 0);

    switch (code) {
      case 'repeat.returned':
        return tp('suggestedChecks.reason.returned', Number(params.episodes || 0), { n: params.episodes });
      case 'repeat.last_seen':
        return tp('suggestedChecks.reason.lastSeen', n, { n });
      case 'repeat.usual_gap':
        return tp('suggestedChecks.reason.usualGap', n, { n });
      // One observed gap only — the engine refuses to call it an interval, and so does this line.
      case 'repeat.single_gap':
        return tp('suggestedChecks.reason.singleGap', n, { n });
      case 'repeat.pattern_lapsed':
        return tp('suggestedChecks.reason.patternLapsed', n, { n });
      case 'repeat.past_usual_gap':
        return tp('suggestedChecks.reason.pastUsualGap', n, { n });
      case 'repeat.approaching_usual_gap':
        return tf('suggestedChecks.reason.approachingGap', 'Coming up on its usual return window');
      case 'repeat.stalling':
        // The counter-signal: this is the engine saying "blame the workshop, not the car".
        return tf(
          'suggestedChecks.reason.stalling',
          'Same garage took it {visits}× within {days} days — likely a slow repair, not a failing car',
          { visits: params.visits, days: params.days, garage: params.garage || '' },
        );
      case 'forecast.condition_due': {
        const c = CONDITION_KEY[params.condition];
        const name = c ? tf(c.key, c.en) : params.condition;
        return tf('suggestedChecks.reason.conditionDue', '{name} is due', { name });
      }
      case 'forecast.overdue_km':
        return tf('suggestedChecks.reason.overdueKm', 'Overdue by {km} km', {
          km: Number(params.km || 0).toLocaleString(lang === 'ar' ? 'ar-EG' : undefined),
        });
      case 'forecast.remaining_km':
        return tf('suggestedChecks.reason.remainingKm', '{km} km to go', {
          km: Number(params.km || 0).toLocaleString(lang === 'ar' ? 'ar-EG' : undefined),
        });
      case 'forecast.days_to_due':
        return tp('suggestedChecks.reason.daysToDue', n, { n });
      case 'checklist.post_idle':
        return tp('suggestedChecks.reason.postIdle', n, { n });
      default:
        return null; // unknown code → render nothing rather than leak an engine token
    }
  };
}

// Severity → the colour the row's system icon is drawn in. Same scale the service ranks on; the dot
// it replaces said the same thing in less space than the icon now occupies for free.
const LEVEL_ICON = {
  critical: 'text-rose-500',
  moderate: 'text-amber-500',
  minor: 'text-sky-500',
  routine: 'text-emerald-500',
};

// The evidence source, named on the row itself rather than as a heading above a block of them.
// A heading is only read once; on a four-row panel where three rows are history and one is forecast,
// the badge is what stops a reader carrying the first heading down the whole list.
const GROUP_BADGE = {
  recurring: { key: 'suggestedChecks.group.recurring', en: 'Recurring history', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
  forecast:  { key: 'suggestedChecks.group.forecast',  en: 'Forecast',          cls: 'bg-indigo-50 text-indigo-700 ring-indigo-200' },
};

// `showGroup` — the badge is printed on the FIRST row of each source only. Repeating "Recurring
// history" on four consecutive rows is noise; dropping it entirely would leave the reader unable to
// tell which claim came from which ledger. See [[traceability-visibility-requirement]].
function Suggestion({ s, onPick, showGroup }) {
  const { tf, lang } = useI18n();
  const reasonLine = useReasonLine();

  const isAr = lang === 'ar';
  // The noun. A chip is a real catalog keyword and can be tapped to pre-fill the finding; a
  // category-only suggestion names the system to look at and is not selectable (the catalog has no
  // keyword for the wording this car's history used).
  const name = s.chip
    ? (isAr && s.chip_ar) || s.chip
    : (isAr && s.category_label_ar) || s.category_label;
  // The system this row is about, which is the system the picker below draws with the same glyph.
  const systemName = (isAr && s.category_label_ar) || s.category_label || name;

  const lines = (s.reasons || []).map(reasonLine).filter(Boolean);
  const badge = showGroup ? GROUP_BADGE[s.group] : null;
  const SystemIcon = systemIcon(s.category_key);
  // Every row leads somewhere, but NOT by the same route. A catalog keyword is itself the tap target —
  // the word you are logging is the thing you press, and it goes straight in as a finding. A
  // category-only row has no word to press, so the action is to open that system in the picker and let
  // a PERSON choose one; the engine never invents it ([[findings-vocabulary-contract]]).
  const openCategory = !s.selectable && s.picker_category && onPick
    ? tf('suggestedChecks.openCategory', 'Open {category} checks', { category: systemName })
    : null;

  return (
    <li>
      <div className="flex items-start gap-3 py-2.5">
        <SystemIcon className={`mt-0.5 h-5 w-5 shrink-0 ${LEVEL_ICON[s.level] || 'text-slate-400'}`} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            {s.selectable && onPick ? (
              <button
                type="button"
                onClick={() => onPick(s)}
                title={tf('suggestedChecks.addFinding', 'Add {name}', { name })}
                className="rounded text-sm font-bold text-slate-900 underline decoration-slate-300 decoration-dashed underline-offset-4 transition hover:text-rose-700 hover:decoration-rose-400"
              >
                {name}
              </button>
            ) : (
              <span className="text-sm font-bold text-slate-900">{name}</span>
            )}
            {badge && (
              <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${badge.cls}`}>
                {tf(badge.key, badge.en)}
              </span>
            )}
            {s.promoted && (
              <span className="rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700 ring-1 ring-inset ring-rose-200">
                {tf('suggestedChecks.dueNow', 'Due now')}
              </span>
            )}
            {/* Both sources produced this row — the merge is visible, not silent. */}
            {s.also_group && (
              <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">
                {tf('suggestedChecks.bothSources', 'history + forecast')}
              </span>
            )}
          </div>

          {lines.length > 0 && (
            <p className="mt-0.5 text-[11px] leading-relaxed text-rose-600/90">{lines.join(' · ')}</p>
          )}
        </div>

        {openCategory && (
          <button
            type="button"
            onClick={() => onPick(s)}
            className="inline-flex shrink-0 items-center gap-1 self-center text-[11px] font-semibold text-rose-600 transition hover:text-rose-700"
          >
            {openCategory}
            <Icon.ChevronDown className="h-3.5 w-3.5 -rotate-90 rtl:rotate-90" aria-hidden />
          </button>
        )}
      </div>
    </li>
  );
}

/**
 * Drop this on any surface that shows a vehicle. It fetches its own data, so the Decide step, the
 * request card and the Vehicle Profile all get the identical ranked list with no per-page logic:
 *
 *   <SuggestedChecks vehicleId={id} />                    // read-only
 *   <SuggestedChecks vehicleId={id} onPick={addFinding} /> // tappable (Decide step)
 *
 * @param {number}   vehicleId the car to fetch checks for
 * @param {object}   [data]    a pre-fetched payload; skips the fetch entirely
 * @param {function} [onPick]  called with the suggestion when tapped. Omit on read-only surfaces —
 *                             without it, nothing in the panel is clickable.
 * @param {function} [onHasContent] told whether this car has anything to suggest, once the fetch
 *                             resolves. A caller that puts the panel in a column beside something
 *                             else needs to know, or it lays out an empty half-width gap for the
 *                             (common) car with a clean history.
 */
export default function SuggestedChecks({ vehicleId, data: provided, onPick, onHasContent, className = 'mt-3' }) {
  const { tf, tp } = useI18n();
  const { data, ref } = useSuggestedChecks(vehicleId, provided);

  const suggestions = data?.suggestions || [];
  const checklist = data?.checklist;
  const hasContent = !!data && (suggestions.length > 0 || !!checklist);

  useEffect(() => {
    if (onHasContent) onHasContent(hasContent);
  }, [hasContent, onHasContent]);

  // The sentinel must stay mounted while there is nothing to show: it is what the observer watches to
  // know the card arrived. Rendering null before the fetch would mean the fetch never fires.
  // Nothing recurring, nothing due, no idle checklist → the car has nothing to flag, and an empty
  // "suggested checks" box reads as a broken panel, so it collapses to the bare sentinel.
  if (!data || (suggestions.length === 0 && !checklist)) {
    return <div ref={ref} aria-hidden className="h-px" />;
  }

  const recurring = suggestions.filter((s) => s.group === 'recurring');
  const forecast = suggestions.filter((s) => s.group === 'forecast');
  const unplaceable = data.summary?.unplaceable || 0;

  return (
    <div ref={ref} className={`overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      <div className="px-3.5 pt-3">
        <p className="text-sm font-bold text-slate-900">
          {tf('suggestedChecks.title', 'Suggested checks for this car')}
        </p>
        {/* Data Origin — every panel says where its content came from. */}
        <p className="mt-0.5 text-[11px] text-slate-400">
          {tf('suggestedChecks.origin', "From this car's own repair history and service forecast")}
        </p>
      </div>

      {/* One list, not two stacked blocks. Both ledgers rank into the same order the service returned;
          each row carries its own source badge, so the merge is legible without splitting the panel
          into halves the reader has to compare. */}
      {(recurring.length > 0 || forecast.length > 0) && (
        <ul className="mt-1.5 divide-y divide-slate-100 px-3.5">
          {recurring.map((s, i) => (
            <Suggestion key={`r-${s.category_key}`} s={s} onPick={onPick} showGroup={i === 0} />
          ))}
          {forecast.map((s, i) => (
            <Suggestion key={`f-${s.category_key}`} s={s} onPick={onPick} showGroup={i === 0} />
          ))}
        </ul>
      )}

      {/* WHAT THIS PANEL IS. Four rows that name real faults, drawn from real history, sitting above a
          catalog — without this sentence they read as findings already logged. They are an agenda; the
          inspector logs what he actually found, here or by hand. See [[vehicle-suggested-checks]]. */}
      <p className="mt-2 flex items-start gap-1.5 bg-slate-50 px-3.5 py-2 text-[11px] text-slate-500 ring-1 ring-inset ring-slate-100">
        <Icon.Info className="mt-px h-3.5 w-3.5 shrink-0 text-sky-500" />
        {tf('suggestedChecks.agendaNote', 'These are suggested checks. You can add issues manually using the search above.')}
      </p>

      {(checklist || unplaceable > 0) && (
        <div className="px-3.5 pb-3">
          {/* The old fixed checklist, in its proper place: an agenda, clearly not findings. */}
          {checklist && (
            <div className="mt-2.5 rounded-md bg-slate-50 px-2.5 py-2 ring-1 ring-inset ring-slate-100">
              <p className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                <Icon.Check className="h-3 w-3" />
                {tf('suggestedChecks.checklist.title', 'Standard post-idle checklist')}
              </p>
              <p className="mt-0.5 text-[11px] text-slate-600">{checklist.items.join(' · ')}</p>
              <p className="mt-0.5 text-[11px] text-slate-400">
                {checklist.reason ? tp('suggestedChecks.reason.postIdle', checklist.days || 0, { n: checklist.days }) : null}
                {' — '}
                {tf('suggestedChecks.checklist.note', 'a look-at list, not findings to confirm')}
              </p>
            </div>
          )}

          {/* No silent truncation: if repeat faults exist that we cannot offer as checks, say so and
              point at the report that does show them. */}
          {unplaceable > 0 && (
            <p className="mt-2 border-t border-slate-100 pt-1.5 text-[11px] text-slate-400">
              {tp('suggestedChecks.unplaceable', unplaceable, { n: unplaceable })}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
