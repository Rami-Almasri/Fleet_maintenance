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

import { useCallback, useEffect, useState } from 'react';
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

// The FAULTS tab's date filter, which is a different question from the "again within" gap above and so
// gets its own control: that one asks "did this repeat inside N days of each other", this one asks
// "which returns happened in this period". 0 = all time. A returning fault is slow news — a car comes
// back over months, not hours — so the presets start at 30 days and reach a full year.
const FAULT_RANGES = [
  { days: 30,  label: '30d' },
  { days: 90,  label: '90d' },
  { days: 180, label: '6m' },
  { days: 365, label: '1y' },
  { days: 0,   label: 'All' },
];

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

// Where each record was read from. Named rather than tagged with a raw column value, because "sheet" is
// our word for it and the workshop's word is "the log".
const SOURCE_KEY = {
  sheet: 'Workshop log',
  ticket: 'Ticket',
  purchase: 'Purchase',
  review: 'Recurrence review',
  // A visit the imported log AND the ticket workflow both recorded. Named rather than resolved to one
  // of the two, because "both saw it" is the stronger evidence and hiding the overlap throws it away.
  both: 'Log + ticket',
};

// The third level: the records themselves. A count is only an argument-ender once you can see what it is
// made of — these are the visits/buys/recurrences, with the gap that preceded each and a mark on the ones
// the row above actually counted.
//
// Services list EVERY visit, not just the counted ones: four returns is five visits, and showing four of
// them would answer a different question than the row asked.
function RepeatEventList({ detail, t, num }) {
  if (!detail || detail.loading) {
    return (
      <div className="space-y-1 px-3 py-2">
        {[0, 1].map((i) => <Skeleton key={i} className="h-6 rounded" />)}
      </div>
    );
  }
  if (detail.error) {
    return <p className="px-3 py-2 text-[11px] text-rose-600">{t('Could not load this right now.')}</p>;
  }
  if (!detail.items.length) {
    return <p className="px-3 py-2 text-[11px] text-slate-400">{t('No records to show.')}</p>;
  }

  return (
    <ol className="space-y-1 border-t border-slate-100 bg-slate-50/70 px-3 py-2">
      {detail.items.map((e, i) => (
        <li key={`${e.at}-${i}`} className="flex items-baseline justify-between gap-2 text-[11px]">
          <span className="flex min-w-0 items-baseline gap-1.5">
            {/* The date IS the link to the record this row was counted from — the ticket for a
                workflow episode, the car's Timeline deep-linked to the exact log row for a sheet one.
                A count nobody can open is a claim, not evidence. */}
            {e.href ? (
              <Link
                to={e.href}
                title={e.link_kind === 'ticket' ? t('Open the ticket') : t('Open this record in the timeline')}
                className="shrink-0 font-mono tabular-nums text-indigo-600 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-700 hover:decoration-indigo-400"
              >
                {e.at || '—'}
              </Link>
            ) : (
              <span className="shrink-0 font-mono tabular-nums text-slate-500">{e.at || '—'}</span>
            )}
            {e.detail && <span className="truncate text-slate-600">{e.detail}</span>}
            {e.source && SOURCE_KEY[e.source] && (
              <span className="shrink-0 rounded bg-white px-1 py-px text-[10px] text-slate-400 ring-1 ring-slate-200">
                {t(SOURCE_KEY[e.source])}
              </span>
            )}
            {/* The maintenance contract this visit went out on, when one covers it. A second target on
                purpose: "show me the repair" and "show me the contract" are different questions. */}
            {e.contract_id && (
              <Link
                to={`/contracts/${e.contract_id}`}
                title={t('Open the maintenance contract')}
                className="shrink-0 rounded bg-white px-1 py-px text-[10px] text-slate-500 ring-1 ring-slate-200 hover:text-indigo-600 hover:ring-indigo-200"
              >
                #{e.contract_no || e.contract_id}
              </Link>
            )}
          </span>
          <span className="flex shrink-0 items-baseline gap-1.5 tabular-nums">
            <span className="text-slate-400">
              {e.gap_days == null ? t('first on record') : t('{n}d after', { n: num(e.gap_days) })}
            </span>
            {e.counted && (
              <span className="rounded-full bg-rose-50 px-1.5 py-px text-[10px] font-semibold text-rose-600 ring-1 ring-rose-200">
                {t('counted')}
              </span>
            )}
          </span>
        </li>
      ))}
    </ol>
  );
}

// One opened row: the cars behind a single fault / part / service, ranked by how many times it came back
// on that car. Same shape for all three tabs, because the question is the same one — the difference
// between "38 cars once each" and "one car eight times" is the whole reason the row opens.
//
// Each car opens again into its own records, and the arrow at the end goes to the car itself: "show me
// the four" and "take me to this car" are different intentions, so they get different targets.
function RepeatCarPanel({ detail, tone, t, num, section, label, windowDays, faultParams, faultSig }) {
  // Which car is opened to its records, and the records fetched so far. Scoped to this panel, so closing
  // the parent row and reopening it starts clean rather than restoring a stale sub-expansion.
  const [openCar, setOpenCar] = useState(null);
  const [events, setEvents] = useState({});

  // A record list must not survive a change to the date filter: the "counted" badges are
  // window-dependent, so a stale list would carry badges the bar above no longer agrees with.
  useEffect(() => { setOpenCar(null); setEvents({}); }, [faultSig]);

  const toggleCar = (vehicleId) => {
    setOpenCar((cur) => (cur === vehicleId ? null : vehicleId));
    if (!events[vehicleId]) {
      setEvents((e) => ({ ...e, [vehicleId]: { loading: true, items: [], error: false } }));
      api.get('/Dashboard/repeat-events', {
        params: { section, label, vehicle_id: vehicleId, window_days: windowDays, ...faultParams },
      })
        .then((res) => {
          const d = res.data.data || {};
          setEvents((e) => ({ ...e, [vehicleId]: { loading: false, items: d.items || [], error: false } }));
        })
        .catch(() => setEvents((e) => ({ ...e, [vehicleId]: { loading: false, items: [], error: true } })));
    }
  };

  if (!detail || detail.loading) {
    return (
      <div className="mt-2 space-y-1.5 rounded-xl bg-white p-2 ring-1 ring-slate-200/70">
        {[0, 1, 2].map((i) => <Skeleton key={i} className="h-8 rounded-lg" />)}
      </div>
    );
  }
  if (detail.error) {
    return (
      <p className="mt-2 rounded-xl bg-white px-3 py-2.5 text-xs text-rose-600 ring-1 ring-slate-200/70">
        {t('Could not load this right now.')}
      </p>
    );
  }
  if (!detail.items.length) {
    return (
      <p className="mt-2 rounded-xl bg-white px-3 py-2.5 text-xs text-slate-400 ring-1 ring-slate-200/70">
        {t('No cars to show for this one.')}
      </p>
    );
  }

  const top = detail.items[0]?.count || 1;
  // The panel lists the top N; the header still reports the full car count, so a truncated list never
  // reads as the whole set.
  const hidden = Math.max(0, detail.cars - detail.items.length);

  return (
    <div className="mt-2 overflow-hidden rounded-xl bg-white ring-1 ring-slate-200/70">
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/70 px-3 py-1.5">
        <span className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          <Icon.Car className="h-3.5 w-3.5 text-slate-400" />
          {t('The cars behind it')}
        </span>
        <span className="text-[11px] font-medium tabular-nums text-slate-400">
          {t('{n} cars', { n: num(detail.cars) })} · {t('{n} returns', { n: num(detail.total) })}
        </span>
      </div>

      <ol className="divide-y divide-slate-50">
        {detail.items.map((c, i) => {
          const open = openCar === c.id;
          return (
          <li key={c.id}>
            <div className="group/car flex items-center gap-2.5 px-3 py-1.5 transition-colors hover:bg-indigo-50/50">
              <span className="w-4 shrink-0 text-end text-[11px] font-bold tabular-nums text-slate-400">{i + 1}</span>

              {/* The row opens the records; the arrow at the end still goes to the car. Two targets on
                  purpose — "show me the four" and "take me to this car" are different intentions. */}
              <button
                type="button"
                onClick={() => toggleCar(c.id)}
                aria-expanded={open}
                className="min-w-0 flex-1 text-start"
              >
                <div className="flex items-baseline justify-between gap-2">
                  <span className="flex min-w-0 items-baseline gap-1.5">
                    <span className="truncate font-mono text-[13px] font-semibold text-slate-900">{c.plate}</span>
                    {c.car && <span className="truncate text-[11px] text-slate-400">{c.car}</span>}
                  </span>
                  <span className="flex shrink-0 items-baseline gap-1.5 tabular-nums">
                    {c.fastest_days != null && (
                      <span className="text-[10px] font-medium text-slate-400">
                        {t('fastest {n}d', { n: c.fastest_days })}
                      </span>
                    )}
                    <span className="text-sm font-extrabold text-slate-900">{num(c.count)}×</span>
                    <Icon.ChevronDown
                      className={`h-3 w-3 shrink-0 text-slate-300 transition-transform ${open ? 'rotate-180' : ''}`}
                    />
                  </span>
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={`h-full rounded-full bg-gradient-to-r ${tone.bar}`}
                    style={{ width: `${Math.max(8, Math.round((c.count / top) * 100))}%` }}
                  />
                </div>
              </button>

              <Link
                to={`/vehicles/${c.id}`}
                title={t('Open the car')}
                className="shrink-0 rounded p-0.5 text-slate-300 transition hover:text-indigo-500"
              >
                <Icon.ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </div>

            {open && <RepeatEventList detail={events[c.id]} t={t} num={num} />}
          </li>
          );
        })}
      </ol>

      {hidden > 0 && (
        <div className="border-t border-slate-100 bg-slate-50/60 px-3 py-1.5 text-[11px] text-slate-400">
          {t('and {n} more cars', { n: num(hidden) })}
        </div>
      )}
    </div>
  );
}

export default function RepeatLeaderboard({ limit = 6 }) {
  const { t } = useI18n();
  const [tab, setTab] = useState('faults');
  const [windowDays, setWindowDays] = useState(30);
  // The faults tab's own date filter. Defaults to all time: the card's job is "what does not stay
  // fixed", and most of this fleet's repeat history is older than any short preset — opening on 30 days
  // would hide 280 of 354 returns behind a control the reader never touched.
  const [faultDays, setFaultDays] = useState(0);
  const [faultFrom, setFaultFrom] = useState('');
  const [faultTo, setFaultTo] = useState('');
  const [customOpen, setCustomOpen] = useState(false);

  // An explicit range wins over the preset — the backend applies the same rule, so the control and the
  // number can never disagree about which filter is live.
  const ranged = !!(faultFrom || faultTo);
  const faultParams = {
    fault_days: ranged ? 0 : faultDays,
    fault_from: faultFrom || undefined,
    fault_to: faultTo || undefined,
  };
  // Part of every cache key below: a list fetched under one window must never be reused under another.
  const faultSig = `${faultParams.fault_days}:${faultFrom}:${faultTo}`;
  // Which row is open, and the fetched cars behind each one. Keyed by tab+label+window because all three
  // change what the answer is — reusing a cached parts list after the window moved would be a lie.
  const [openLabel, setOpenLabel] = useState(null);
  const [cars, setCars] = useState({});

  const fetcher = useCallback(async () => {
    const res = await api.get('/Dashboard/repeats', {
      params: { window_days: windowDays, limit, ...faultParams },
    });
    return res.data.data || null;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [windowDays, limit, faultSig]);

  const { data, loading, error } = useFetch(fetcher, [windowDays, limit, faultSig]);

  // Switching tab, window or date filter closes the open row: the label belongs to the ledger it was
  // opened from, and leaving it open would show a panel counted under a filter no longer applied.
  useEffect(() => { setOpenLabel(null); }, [tab, windowDays, faultSig]);

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
    REPEAT_FAULT_AFTER_REPAIR: t('A car back for the same fault in a separate workshop visit, counted across the workshop log and this system'),
    REPEAT_PART_SAME_CAR: t('The same part fitted to the same car again within {days} days', { days: section?.window_days }),
    REPEAT_SERVICE_SAME_CAR: t('The same service done on the same car again within {days} days', { days: section?.window_days }),
  };
  const ORIGINS = {
    recurring_fault_reviews: t('Recurring-fault reviews'),
    part_purchases: t('The parts purchase ledger'),
    workshop_log_and_tickets: t('The workshop log and the ticket workflow'),
  };

  // Which ledger each fault's evidence came from. Shown per row, because a fault whose whole history is
  // the imported log reads very differently from one this system watched happen — and the card used to
  // be unable to say either, having only ever read the review table.
  const SOURCE_BADGE = {
    SHEET: t('Sheet'),
    SYSTEM: t('System'),
    BOTH: t('Both'),
  };

  // Open a row and fetch the cars behind it once; afterwards the cache answers instantly.
  const toggleRow = (label) => {
    const key = `${active?.key}:${label}:${windowDays}:${faultSig}`;
    setOpenLabel((cur) => (cur === label ? null : label));
    if (!cars[key]) {
      setCars((c) => ({ ...c, [key]: { loading: true, items: [], error: false } }));
      api.get('/Dashboard/repeat-cars', {
        params: { section: active?.key, label, window_days: windowDays, limit: 10, ...faultParams },
      })
        .then((res) => {
          const d = res.data.data || {};
          setCars((c) => ({
            ...c,
            [key]: { loading: false, items: d.items || [], total: d.total || 0, cars: d.cars || 0, error: false },
          }));
        })
        .catch(() => setCars((c) => ({ ...c, [key]: { loading: false, items: [], error: true } })));
    }
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
        ) : active ? (
          // The faults tab's own filter: WHEN the car came back, not how close two visits were.
          <div className="flex items-center gap-1.5">
            <div className="inline-flex rounded-lg bg-slate-100 p-0.5" title={t('When the car came back')}>
              {FAULT_RANGES.map((r) => (
                <button
                  key={r.days}
                  type="button"
                  onClick={() => { setFaultDays(r.days); setFaultFrom(''); setFaultTo(''); setCustomOpen(false); }}
                  className={`rounded-md px-2 py-1 text-xs font-semibold transition ${
                    !ranged && faultDays === r.days
                      ? 'bg-white text-slate-900 shadow-soft'
                      : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {t(r.label)}
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={() => setCustomOpen((v) => !v)}
              title={t('Pick an exact date range')}
              className={`rounded-lg p-1.5 text-xs font-semibold transition ${
                ranged ? 'bg-indigo-50 text-indigo-600 ring-1 ring-indigo-200' : 'bg-slate-100 text-slate-500 hover:text-slate-700'
              }`}
            >
              <Icon.Calendar className="h-3.5 w-3.5" />
            </button>
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

      {/* The exact-range picker, revealed by the calendar button. Kept out of the header so the common
          case (a preset) stays one click and the card keeps its height. */}
      {active?.key === 'faults' && customOpen && (
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200/70">
          <label className="flex items-center gap-1.5 text-[11px] font-medium text-slate-500">
            {t('From')}
            <input
              type="date"
              value={faultFrom}
              max={faultTo || undefined}
              onChange={(e) => setFaultFrom(e.target.value)}
              className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700"
            />
          </label>
          <label className="flex items-center gap-1.5 text-[11px] font-medium text-slate-500">
            {t('To')}
            <input
              type="date"
              value={faultTo}
              min={faultFrom || undefined}
              onChange={(e) => setFaultTo(e.target.value)}
              className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700"
            />
          </label>
          {ranged && (
            <button
              type="button"
              onClick={() => { setFaultFrom(''); setFaultTo(''); }}
              className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-700"
            >
              {t('Clear')}
            </button>
          )}
        </div>
      )}

      {/* A filtered count must never read as the whole fleet. Shown only when a filter is actually
          narrowing something — on "All" there is nothing to disclose. */}
      {active?.key === 'faults' && section?.window && section.window.total_returns > section.total && (
        <p className="mb-2 text-[11px] text-slate-400">
          {t('Showing {n} of {total} returns', {
            n: num(section.total),
            total: num(section.window.total_returns),
          })}
          {section.window.from && (
            <> · {section.window.to
              ? t('{from} to {to}', { from: section.window.from, to: section.window.to })
              : t('since {from}', { from: section.window.from })}</>
          )}
        </p>
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
          {items.map((it, i) => {
            const open = openLabel === it.label;
            return (
            <li key={it.label}>
              {/* The whole row is the control: the car count is the thing being asked about, so making
                  only that number clickable would hide the affordance on the smallest target. */}
              <button
                type="button"
                onClick={() => toggleRow(it.label)}
                aria-expanded={open}
                className="w-full rounded-lg px-1 py-0.5 text-start transition-colors hover:bg-slate-50"
              >
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="flex min-w-0 items-center gap-2 font-medium text-slate-800">
                    <span className="w-4 shrink-0 text-end text-xs font-bold tabular-nums text-slate-400">{i + 1}</span>
                    <span className="truncate">{it.label}</span>
                  </span>
                  <span className="flex shrink-0 items-center gap-2">
                    {/* Which ledger proved it. Faults only — the parts and services tabs each read a
                        single ledger and have nothing to disambiguate. */}
                    {it.source_code && SOURCE_BADGE[it.source_code] && (
                      <span
                        className="rounded px-1.5 py-px text-[10px] font-medium text-slate-500 ring-1 ring-slate-200"
                        title={t('Sheet history: {s} · System records: {y}', {
                          s: num(it.sheet_cars || 0),
                          y: num(it.system_cars || 0),
                        })}
                      >
                        {SOURCE_BADGE[it.source_code]}
                      </span>
                    )}
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
                    <span className="w-10 text-end text-base font-extrabold tabular-nums text-slate-900">
                      {num(it.value)}×
                    </span>
                    <Icon.ChevronDown
                      className={`h-3.5 w-3.5 shrink-0 text-slate-300 transition-transform ${open ? 'rotate-180' : ''}`}
                    />
                  </span>
                </div>
                <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={`h-full rounded-full bg-gradient-to-r ${tone.bar} transition-[width] duration-700 ease-out`}
                    style={{ width: `${Math.max(4, Math.round((it.value / max) * 100))}%` }}
                  />
                </div>
              </button>

              {open && (
                <RepeatCarPanel
                  detail={cars[`${active?.key}:${it.label}:${windowDays}:${faultSig}`]}
                  tone={tone}
                  t={t}
                  num={num}
                  section={active?.key}
                  label={it.label}
                  windowDays={windowDays}
                  faultParams={faultParams}
                  faultSig={faultSig}
                />
              )}
            </li>
            );
          })}
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
