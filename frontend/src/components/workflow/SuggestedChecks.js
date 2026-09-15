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

// Severity → the dot beside a suggestion. Same scale the service ranks on.
const LEVEL_DOT = {
  critical: 'bg-rose-500',
  moderate: 'bg-amber-500',
  minor: 'bg-sky-500',
  routine: 'bg-emerald-500',
};

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

/** The group header — names the evidence source, so no line is a black box. */
function GroupHeader({ group }) {
  const { tf } = useI18n();
  const meta =
    group === 'recurring'
      ? { icon: <Icon.Refresh className="h-3 w-3" />, key: 'suggestedChecks.group.recurring', en: 'Recurring history', cls: 'text-rose-600' }
      : { icon: <Icon.TrendUp className="h-3 w-3" />, key: 'suggestedChecks.group.forecast', en: 'Forecast', cls: 'text-indigo-600' };

  return (
    <p className={`flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide ${meta.cls}`}>
      {meta.icon}
      {tf(meta.key, meta.en)}
    </p>
  );
}

function Suggestion({ s, onPick }) {
  const { tf, lang } = useI18n();
  const reasonLine = useReasonLine();

  const isAr = lang === 'ar';
  // The noun. A chip is a real catalog keyword and can be tapped to pre-fill the finding; a
  // category-only suggestion names the system to look at and is not selectable (the catalog has no
  // keyword for the wording this car's history used).
  const name = s.chip
    ? (isAr && s.chip_ar) || s.chip
    : (isAr && s.category_label_ar) || s.category_label;

  const lines = (s.reasons || []).map(reasonLine).filter(Boolean);

  return (
    <li className="flex items-start gap-2 py-1.5">
      <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${LEVEL_DOT[s.level] || 'bg-slate-400'}`} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-1.5">
          {s.selectable && onPick ? (
            <button
              type="button"
              onClick={() => onPick(s)}
              className="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200 transition hover:bg-indigo-50 hover:ring-indigo-300"
            >
              {name}
            </button>
          ) : (
            <span className="text-[11px] font-semibold text-slate-800">{name}</span>
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
          <p className="mt-0.5 text-[11px] leading-relaxed text-slate-500">{lines.join(' · ')}</p>
        )}

        {/* A category-only suggestion still leads somewhere useful: open the picker on the right
            group and let a person choose the keyword, rather than the engine inventing one. */}
        {!s.selectable && s.picker_category && onPick && (
          <button
            type="button"
            onClick={() => onPick(s)}
            className="mt-0.5 text-[11px] font-semibold text-indigo-600 hover:text-indigo-700"
          >
            {tf('suggestedChecks.openCategory', 'Open {category} checks', { category: name })}
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
    <div ref={ref} className={`rounded-xl border border-slate-200 bg-white px-3.5 py-3 shadow-sm ${className}`}>
      <div className="flex items-center gap-2 text-sm font-bold text-slate-900">
        <Icon.Search className="h-4 w-4 text-slate-400" />
        {tf('suggestedChecks.title', 'Suggested checks for this car')}
      </div>
      {/* Data Origin — every panel says where its content came from. */}
      <p className="mt-0.5 text-[11px] text-slate-400">
        {tf('suggestedChecks.origin', "From this car's own repair history and service forecast")}
      </p>

      {recurring.length > 0 && (
        <div className="mt-2">
          <GroupHeader group="recurring" />
          <ul className="mt-0.5 divide-y divide-slate-100">
            {recurring.map((s) => (
              <Suggestion key={`r-${s.category_key}`} s={s} onPick={onPick} />
            ))}
          </ul>
        </div>
      )}

      {forecast.length > 0 && (
        <div className="mt-2">
          <GroupHeader group="forecast" />
          <ul className="mt-0.5 divide-y divide-slate-100">
            {forecast.map((s) => (
              <Suggestion key={`f-${s.category_key}`} s={s} onPick={onPick} />
            ))}
          </ul>
        </div>
      )}

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
  );
}
