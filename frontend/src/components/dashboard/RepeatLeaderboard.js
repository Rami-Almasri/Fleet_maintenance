// What Keeps Coming Back — the dashboard's repeat leaderboard.
//
// Most Frequent Faults, further down the page, answers HOW OFTEN a thing happens. This card answers the
// question the workshop actually argues about on a Sunday morning: WHICH THING CAME BACK. Three tabs,
// three ledgers:
//
//   • Faults   — the fault that returned after it was repaired
//   • Parts    — the part that went on the same car twice
//   • Services — the service that was done again too soon
//
// Each bar is one thing, ranked by how many times it came back, with the number of cars behind it — the
// difference between "ten cars once each" (a fleet problem) and "one car ten times" (a car problem) is
// the whole point, so the car count is never hidden behind a hover.
//
// ── The three tabs do NOT share one rule, and the card says so ──────────────────────────────────────
// A tab shows its rule and its Data Origin in the footer, because they genuinely differ: a fault "comes
// back" when the recurrence engine rules that it returned after a verified repair (its own review
// window), while a part or a service "comes back" when the same one lands on the same car again inside
// the window picked below. Printing one blanket sentence over all three would be the lie.
//
// Everything here reads GET /Dashboard/repeats, which gates each section on the permission that owns its
// ledger. A tab the user cannot read is never sent, so it is simply not offered.

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { SectionCard } from '../ui/Table';
import { InfoTip } from '../ui/Tooltip';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// The "again within" gap for the parts and services tabs. 30 days is the operational default; the rest
// are there because a supervisor chasing a specific suspicion needs to tighten or widen it. The faults
// tab ignores this — its window belongs to the recurrence engine, and the footer says which.
const WINDOWS = [7, 30, 60, 90];

// Tab order is deliberate: faults first (a car that broke again is the worst news on the card), then the
// parts behind those repairs, then the planned work that did not stay done.
const TABS = [
  { key: 'faults',   icon: Icon.Refresh,  tone: 'rose' },
  { key: 'parts',    icon: Icon.Wrench,   tone: 'amber' },
  { key: 'services', icon: Icon.Calendar, tone: 'sky' },
];

const TONE = {
  rose:  { bar: 'from-rose-400 to-rose-600',   chip: 'bg-rose-50 text-rose-700 ring-rose-200',   on: 'bg-white text-rose-700' },
  amber: { bar: 'from-amber-400 to-amber-600', chip: 'bg-amber-50 text-amber-700 ring-amber-200', on: 'bg-white text-amber-700' },
  sky:   { bar: 'from-sky-400 to-sky-600',     chip: 'bg-sky-50 text-sky-700 ring-sky-200',       on: 'bg-white text-sky-700' },
};

export default function RepeatLeaderboard({ limit = 6 }) {
  const { t } = useI18n();
  const [tab, setTab] = useState('faults');
  const [windowDays, setWindowDays] = useState(30);

  const fetcher = useCallback(async () => {
    const res = await api.get('/Dashboard/repeats', { params: { window_days: windowDays, limit } });
    return res.data.data || null;
  }, [windowDays, limit]);

  const { data, loading, error } = useFetch(fetcher, [windowDays, limit]);

  const sections = data?.sections || {};
  // Only offer a tab the API actually returned — that is how permission gating reaches the UI.
  const available = TABS.filter((x) => sections[x.key]);
  // The selected tab can vanish between reads (a permission change, or the very first load); fall back
  // to the first one we were given rather than rendering an empty card over live data.
  const active = available.find((x) => x.key === tab) || available[0];
  const section = active ? sections[active.key] : null;
  const items = section?.items || [];
  const max = items.reduce((m, it) => Math.max(m, it.value), 0) || 1;
  const tone = TONE[active?.tone] || TONE.rose;

  // The rule line under the bars. One per tab, because the rules really are different.
  const RULES = {
    REPEAT_FAULT_AFTER_REPAIR: t('A fault that came back after it was repaired, ruled by the recurrence review'),
    REPEAT_PART_SAME_CAR: t('The same part fitted to the same car again within {days} days', { days: section?.window_days }),
    REPEAT_SERVICE_SAME_CAR: t('The same service done on the same car again within {days} days', { days: section?.window_days }),
  };
  const ORIGINS = {
    recurring_fault_reviews: t('Recurring-fault reviews'),
    part_purchases: t('The parts purchase ledger'),
    workshop_log_and_tickets: t('The workshop log and the ticket workflow'),
  };

  if (!loading && !error && available.length === 0) return null;

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          {t('What Keeps Coming Back')}
          <InfoTip content={t('The fault that returned after its repair, the part that went on the same car twice, and the service that was done again too soon. Ranked by how many times each one came back; the car count beside it separates a fleet-wide problem from one bad car.')} />
        </span>
      }
      subtitle={t('Not what happens most — what does not stay fixed')}
      bodyClass="px-4 pb-4 pt-1 sm:px-5"
      actions={
        active && active.key !== 'faults' ? (
          <div className="inline-flex rounded-lg bg-slate-100 p-0.5" title={t('Maximum days between the two')}>
            {WINDOWS.map((w) => (
              <button
                key={w}
                type="button"
                onClick={() => setWindowDays(w)}
                className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                  windowDays === w ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {t('{n}d', { n: w })}
              </button>
            ))}
          </div>
        ) : null
      }
    >
      {/* Tabs. Rendered from what came back, so a user without the parts ledger never sees a parts tab. */}
      {available.length > 1 && (
        <div className="mb-4 inline-flex flex-wrap gap-0.5 rounded-xl bg-slate-100 p-1">
          {available.map((x) => {
            const on = x.key === active?.key;
            const TabIcon = x.icon;
            return (
              <button
                key={x.key}
                type="button"
                onClick={() => setTab(x.key)}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition ${
                  on ? `${TONE[x.tone].on} shadow-soft` : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {TabIcon ? <TabIcon className="h-4 w-4" /> : null}
                {x.key === 'faults' ? t('Faults') : x.key === 'parts' ? t('Parts') : t('Services')}
                <span className="tabular-nums text-xs font-bold opacity-60">{num(sections[x.key]?.total || 0)}</span>
              </button>
            );
          })}
        </div>
      )}

      {loading ? (
        <ul className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => <li key={i}><Skeleton className="h-11 rounded-xl" /></li>)}
        </ul>
      ) : error ? (
        <p className="py-8 text-center text-sm text-slate-400">{t('Could not load this right now.')}</p>
      ) : items.length === 0 ? (
        // An empty leaderboard is good news, and should read as good news rather than as a broken card.
        <p className="py-8 text-center text-sm text-slate-500">
          {active?.key === 'faults'
            ? t('Nothing has come back after a repair.')
            : t('Nothing repeated inside {days} days.', { days: section?.window_days })}
        </p>
      ) : (
        <ul className="space-y-2.5">
          {items.map((it, i) => (
            <li key={it.label}>
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="flex min-w-0 items-center gap-2 font-medium text-slate-800">
                  <span className="w-4 shrink-0 text-right text-xs font-bold tabular-nums text-slate-400">{i + 1}</span>
                  <span className="truncate">{it.label}</span>
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  {/* How fast it came back, when we know — a part back in 2 days is a different story
                      from one back in 29, and the rank alone cannot tell them apart. */}
                  {it.fastest_days != null && (
                    <span className="text-[11px] font-medium tabular-nums text-slate-400">
                      {t('fastest {n}d', { n: it.fastest_days })}
                    </span>
                  )}
                  <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ring-1 ${tone.chip}`}>
                    {t('{n} cars', { n: num(it.cars) })}
                  </span>
                  <span className="w-10 text-right text-base font-extrabold tabular-nums text-slate-900">
                    {num(it.value)}×
                  </span>
                </span>
              </div>
              <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                  className={`h-full rounded-full bg-gradient-to-r ${tone.bar} transition-[width] duration-700 ease-out`}
                  style={{ width: `${Math.max(4, Math.round((it.value / max) * 100))}%` }}
                />
              </div>
            </li>
          ))}
        </ul>
      )}

      {/* Data Origin — what this tab counted and where it read it. Different per tab on purpose. */}
      {section && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-[11px] text-slate-400">
          <span>
            {RULES[section.rule]}
            {' · '}
            {t('Source: {origin}', { origin: ORIGINS[section.origin] || section.origin })}
            {/* A ranking built off a capped sweep must say it was capped, or it reads as the whole fleet. */}
            {section.truncated ? ` · ${t('Showing the most recent repeats only — there are more.')}` : ''}
          </span>
          {section.route && (
            <Link to={section.route} className="font-semibold text-indigo-600 hover:text-indigo-700">
              {t('Open the full list')} →
            </Link>
          )}
        </div>
      )}
    </SectionCard>
  );
}
