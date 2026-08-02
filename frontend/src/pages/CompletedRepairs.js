// Fixed & Completed Repairs (/completed-repairs) — the ledger of every car whose repair is DONE and
// signed off (workflow_status = closed), rendered as a dashboard-style feed of "this car has been
// fixed" cards instead of a bare table. Each card leads with the PROBLEM that was reported, the faults
// that were actually repaired, where it was fixed, how long it took and what it cost. Expand a card for
// the people chain (requested → inspected → dispatched → garage → closed), the odometer chain and each
// fault's root cause / resolution / parts. Filter by closing date (presets + custom range) and search.
// Backed by GET /maintenance-tickets/completed. Newest-closed first.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import CompletedRepairsAnalytics from '../components/analytics/CompletedRepairsAnalytics';
import { SHOW_FINANCIALS } from '../config/features';

const fmtAED = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const fmtKm = (n) => (n != null ? `${Number(n).toLocaleString()} km` : null);

// Human "how long ago" from an ISO date, coarse (the ledger only needs day-scale precision).
const ago = (iso) => {
  if (!iso) return '';
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  if (days <= 0) return 'today';
  if (days === 1) return '1 day ago';
  if (days < 30) return `${days} days ago`;
  const months = Math.floor(days / 30);
  return months === 1 ? '1 month ago' : `${months} months ago`;
};

// When the repair was finished — the one date every filter and every "closed" column keys off. The
// server fills `completed_at` for both origins; the fallbacks cover a legacy closed ticket whose
// wf_closed_at was never stamped, so it still lands on the timeline instead of reading "—".
const closedAt = (tk) => tk.completed_at || tk.handoffs?.closed?.at || tk.actual_in_date || tk.updated_at || null;

// How long the car was actually out of service, in SECONDS. Prefer the server-computed downtime
// (test drive → back in the fleet); fall back to reported → completed so an older row still reads.
const repairSeconds = (tk) => {
  const secs = tk.stage_timing?.durations?.total_downtime;
  if (secs != null) return Math.max(0, secs);
  const end = closedAt(tk);
  if (!tk.created_at || !end) return null;
  return Math.max(0, Math.round((new Date(end) - new Date(tk.created_at)) / 1000));
};

// Whole days, for averaging. Null when we have no span at all.
const repairDays = (tk) => {
  const s = repairSeconds(tk);
  return s == null ? null : Math.round(s / 86400);
};

// …and the human label. A same-day job reads as hours ("6h"), never a misleading "0d".
const repairSpan = (tk) => {
  const s = repairSeconds(tk);
  if (s == null) return null;
  if (s < 3600) return 'same day';
  if (s < 86400) return `${Math.round(s / 3600)}h`;
  return `${Math.round(s / 86400)}d`;
};

// The reported problem — what someone actually complained about, in priority order. This is the
// headline of a card: the story starts with the symptom, not with the paperwork.
const problemOf = (tk) =>
  tk.customer_complaint || tk.trigger_reason || tk.test_drive_report || tk.maintenance_type_label || null;

// The faults that were worked on. `tasks` (first-class fault rows) carry root cause / resolution /
// parts; the legacy `findings` array is the fallback for tickets predating the task model.
const faultsOf = (tk) => {
  if (Array.isArray(tk.tasks) && tk.tasks.length) return tk.tasks;
  return (tk.findings || []).map((f, i) => ({
    id: `f-${i}`,
    symptom: f.symptom || f.text || f.keyword || null,
    source: f.source || null,
  }));
};

const faultLabel = (f, t) => f.symptom || f.text || f.keyword || t('completedRepairs.fault');

// Which car a closed ticket belongs to. vehicle_id when we have it, plate as the legacy fallback —
// this is the key the repeat-visit counter groups on.
const carKey = (tk) => tk.vehicle_id || tk.plate || null;

// Date presets → an inclusive [from, to] pair of yyyy-mm-dd strings (or nulls for "all time").
const isoDay = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const presetRange = (key) => {
  const now = new Date();
  const back = (days) => { const d = new Date(now); d.setDate(d.getDate() - days); return isoDay(d); };
  switch (key) {
    case '7':     return { from: back(6), to: isoDay(now) };
    case '30':    return { from: back(29), to: isoDay(now) };
    case '90':    return { from: back(89), to: isoDay(now) };
    case 'month': return { from: isoDay(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDay(now) };
    default:      return { from: '', to: '' };
  }
};

export default function CompletedRepairs() {
  const { t } = useI18n();

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [origin, setOrigin] = useState('all'); // all | system | sheet — the DATA ORIGIN filter
  const [preset, setPreset] = useState('all');
  const [repeatOnly, setRepeatOnly] = useState(false); // only cars that came back more than once
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [open, setOpen] = useState(() => new Set()); // expanded ticket ids

  const load = useCallback(async () => {
    try {
      const res = await api.get('/maintenance-tickets/completed');
      setData(res.data.data);
    } catch (_) {
      // keep last good state
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const tickets = useMemo(() => data?.tickets || [], [data]);

  const pickPreset = (key) => {
    setPreset(key);
    if (key !== 'custom') {
      const r = presetRange(key);
      setFrom(r.from);
      setTo(r.to);
    }
  };

  const resetFilters = () => { setPreset('all'); setFrom(''); setTo(''); setSearch(''); setOrigin('all'); setRepeatOnly(false); };

  // How the loaded ledger splits by data origin — drives the counts on the Sheet / System tabs.
  const originCounts = useMemo(() => ({
    all: tickets.length,
    system: tickets.filter((tk) => tk.source !== 'sheet').length,
    sheet: tickets.filter((tk) => tk.source === 'sheet').length,
  }), [tickets]);

  // Client-side filter over the loaded set (the endpoint also supports ?search=, but the fleet's
  // closed set is capped at 500 so filtering in-memory keeps the box instant). Date filtering is on
  // the CLOSING date — the day the repair was signed off — inclusive on both ends.
  const scoped = useMemo(() => {
    const q = search.trim().toLowerCase();
    return tickets.filter((tk) => {
      if (origin === 'sheet' && tk.source !== 'sheet') return false;
      if (origin === 'system' && tk.source === 'sheet') return false;
      if (from || to) {
        const iso = closedAt(tk);
        if (!iso) return false;
        const day = isoDay(new Date(iso));
        if (from && day < from) return false;
        if (to && day > to) return false;
      }
      if (!q) return true;
      const haystack = [
        tk.plate, tk.car, tk.garage, tk.requested_by_name, tk.assigned_driver_name, tk.dispatched_by_name,
        problemOf(tk),
        ...faultsOf(tk).map((f) => faultLabel(f, t)),
      ].filter(Boolean);
      return haystack.some((v) => String(v).toLowerCase().includes(q));
    });
  }, [tickets, search, from, to, origin, t]);

  // How many closed repairs each car racked up inside the current scope. This is what turns a flat
  // ledger into a signal: a car with 3 closed tickets in 90 days is a problem car, not 3 successes.
  const carVisits = useMemo(() => {
    const map = new Map();
    scoped.forEach((tk) => {
      const key = carKey(tk);
      if (key == null) return;
      map.set(key, (map.get(key) || 0) + 1);
    });
    return map;
  }, [scoped]);

  const isRepeat = useCallback((tk) => (carVisits.get(carKey(tk)) || 0) > 1, [carVisits]);

  // The repeat filter narrows the FEED but never the counters — otherwise clicking the tile would
  // rewrite the very number you just clicked.
  const filtered = useMemo(
    () => (repeatOnly ? scoped.filter(isRepeat) : scoped),
    [scoped, repeatOnly, isRepeat],
  );

  const stats = useMemo(() => {
    const totalCost = scoped.reduce((sum, tk) => sum + Number(tk.cost || 0), 0);
    const cars = carVisits.size;
    const spans = scoped.map(repairDays).filter((d) => d != null);
    const avgDays = spans.length ? Math.round(spans.reduce((a, b) => a + b, 0) / spans.length) : null;
    const faults = scoped.reduce((sum, tk) => sum + faultsOf(tk).length, 0);
    // Cars that came back — repaired, signed off, and in the ledger again.
    let repeatCars = 0;
    carVisits.forEach((n) => { if (n > 1) repeatCars += 1; });
    return { totalCost, cars, avgDays, faults, repeatCars };
  }, [scoped, carVisits]);

  const toggle = (id) => setOpen((prev) => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  const filtersActive = Boolean(search || from || to || origin !== 'all' || repeatOnly);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1280px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('completedRepairs.title')} subtitle={t('completedRepairs.subtitle')} />

        {/* KPI strip — the dashboard read of the filtered ledger. */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {/* Cars fixed — how many CARS were repaired, not how many tickets were written. */}
          <StatTile
            icon={<Icon.Check className="h-5 w-5" />}
            tone="emerald"
            value={stats.cars}
            label={t('completedRepairs.carsFixed')}
          />
          {/* Cars returned — of those, the ones that came back for another repair. Click to see them. */}
          <StatTile
            icon={<Icon.Refresh className="h-5 w-5" />}
            tone={stats.repeatCars ? 'rose' : 'indigo'}
            value={stats.repeatCars}
            label={t('completedRepairs.repeatCars')}
            active={repeatOnly}
            onClick={stats.repeatCars ? () => setRepeatOnly((v) => !v) : undefined}
          />
          <StatTile
            icon={<Icon.Clock className="h-5 w-5" />}
            tone="amber"
            value={stats.avgDays != null ? `${stats.avgDays}d` : '—'}
            label={t('completedRepairs.avgDays')}
          />
          {SHOW_FINANCIALS ? (
            <StatTile
              icon={<Icon.Cash className="h-5 w-5" />}
              tone="slate"
              value={fmtAED(stats.totalCost)}
              label={t('completedRepairs.totalSpend')}
              small
            />
          ) : (
            <StatTile
              icon={<Icon.Wrench className="h-5 w-5" />}
              tone="slate"
              value={stats.faults}
              label={t('completedRepairs.faultsCount')}
            />
          )}
        </div>

        {/* Analytics — the whole closed ledger, before the filters narrow the feed. */}
        {!loading && tickets.length > 0 && (
          <CompletedRepairsAnalytics tickets={tickets} showFinancials={SHOW_FINANCIALS} />
        )}

        {/* Filter bar — data origin, date range (presets + custom) and free-text search. */}
        <div className="rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
          {/* DATA ORIGIN — where each repair record came from. Sheet = the imported historical workshop
              log; System = a ticket this app ran end-to-end. No black boxes: the source is always shown. */}
          <div className="mb-3 flex flex-wrap items-center gap-3 border-b border-slate-100 pb-3">
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Info className="h-3.5 w-3.5" />
              {t('completedRepairs.dataOrigin')}
            </span>
            <div className="inline-flex rounded-xl bg-slate-100 p-1">
              {[
                ['all', t('completedRepairs.originAll')],
                ['system', t('completedRepairs.originSystem')],
                ['sheet', t('completedRepairs.originSheet')],
              ].map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setOrigin(key)}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                    origin === key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {label}
                  <span className="tabular-nums text-slate-400">{originCounts[key]}</span>
                </button>
              ))}
            </div>
            <p className="text-[11px] text-slate-400">{t('completedRepairs.originHint')}</p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Calendar className="h-3.5 w-3.5" />
              {t('completedRepairs.period')}
            </span>
            <div className="flex flex-wrap gap-1.5">
              {[
                ['all', t('completedRepairs.allTime')],
                ['7', t('completedRepairs.last7')],
                ['30', t('completedRepairs.last30')],
                ['90', t('completedRepairs.last90')],
                ['month', t('completedRepairs.thisMonth')],
                ['custom', t('completedRepairs.custom')],
              ].map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => pickPreset(key)}
                  className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                    preset === key
                      ? 'bg-indigo-600 text-white shadow-sm'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>

            {preset === 'custom' && (
              <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-1.5 text-xs text-slate-500">
                  {t('completedRepairs.from')}
                  <input
                    type="date"
                    value={from}
                    max={to || undefined}
                    onChange={(e) => setFrom(e.target.value)}
                    className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-700 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                  />
                </label>
                <label className="flex items-center gap-1.5 text-xs text-slate-500">
                  {t('completedRepairs.to')}
                  <input
                    type="date"
                    value={to}
                    min={from || undefined}
                    onChange={(e) => setTo(e.target.value)}
                    className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-700 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                  />
                </label>
              </div>
            )}

            <div className="relative ms-auto min-w-[240px] flex-1 sm:max-w-xs">
              <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('completedRepairs.searchPlaceholder')}
                className="w-full rounded-xl border border-slate-200 bg-white py-2 ps-10 pe-4 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
              />
            </div>

            {filtersActive && (
              <button
                type="button"
                onClick={resetFilters}
                className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-500 hover:bg-slate-100"
              >
                <Icon.X className="h-3.5 w-3.5" />
                {t('completedRepairs.reset')}
              </button>
            )}
          </div>

          {!loading && (
            <p className="mt-3 text-xs text-slate-400">
              {t('completedRepairs.showing', { n: filtered.length, total: tickets.length })}
            </p>
          )}
        </div>

        {loading ? (
          <div className="space-y-4">
            <Skeleton className="h-40 rounded-2xl" />
            <Skeleton className="h-40 rounded-2xl" />
          </div>
        ) : filtered.length === 0 ? (
          <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <EmptyState
              icon={<Icon.Check className="h-6 w-6 text-emerald-500" />}
              title={tickets.length === 0 ? t('completedRepairs.emptyTitle') : t('completedRepairs.noDateMatch')}
              message={tickets.length === 0 ? t('completedRepairs.emptyBody') : t('completedRepairs.noDateMatchBody')}
            />
          </div>
        ) : (
          <div className="space-y-4">
            {filtered.map((tk) => (
              <RepairCard
                key={tk.id}
                tk={tk}
                t={t}
                visits={isRepeat(tk) ? carVisits.get(carKey(tk)) : null}
                isOpen={open.has(tk.id)}
                onToggle={() => toggle(tk.id)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */

const TONES = {
  emerald: 'bg-emerald-50 text-emerald-600',
  indigo:  'bg-indigo-50 text-indigo-600',
  amber:   'bg-amber-50 text-amber-600',
  rose:    'bg-rose-50 text-rose-600',
  slate:   'bg-slate-100 text-slate-600',
};

// A KPI tile. Pass `onClick` and the tile becomes a filter toggle for the feed.
function StatTile({ icon, tone = 'slate', value, label, small, active, onClick }) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      className={`flex items-center gap-3 rounded-2xl border bg-white px-4 py-3.5 text-start shadow-soft transition ${
        active ? 'border-rose-300 ring-2 ring-rose-100' : 'border-slate-200/60'
      } ${onClick ? 'cursor-pointer hover:border-slate-300 hover:shadow-md' : ''}`}
    >
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${TONES[tone]}`}>{icon}</span>
      <div className="min-w-0">
        <p className={`truncate font-bold tabular-nums text-slate-900 ${small ? 'text-lg' : 'text-2xl'}`}>{value}</p>
        <p className="truncate text-[11px] uppercase tracking-wide text-slate-400">{label}</p>
      </div>
    </Tag>
  );
}

// One finished repair, told as a card: what broke, what was fixed, where, how long, what it cost.
function RepairCard({ tk, t, visits, isOpen, onToggle }) {
  const faults = faultsOf(tk);
  const problem = problemOf(tk);
  const span = repairSpan(tk);
  const isSheet = tk.source === 'sheet';
  const kmIn = tk.receive_odometer;
  const kmOut = tk.return_odometer;
  const kmDriven = (kmIn != null && kmOut != null && kmOut >= kmIn) ? kmOut - kmIn : null;
  const closed = closedAt(tk);
  const driver = tk.assigned_driver_name || tk.dispatched_by_name || null;

  return (
    <article className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft transition-shadow hover:shadow-md">
      {/* Emerald rail = signed off. The card reads "this car has been fixed" at a glance. */}
      <div className="flex">
        <span className="w-1 shrink-0 bg-emerald-500" />
        <div className="min-w-0 flex-1">
          <button type="button" onClick={onToggle} className="w-full px-5 py-4 text-start">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="flex min-w-0 gap-3">
                <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                  <Icon.Check className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-base font-bold text-slate-900">{tk.plate || `#${tk.id}`}</span>
                    {tk.car && <span className="text-sm text-slate-400">{tk.car}</span>}
                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
                      <Icon.Check className="h-3 w-3" />
                      {t('completedRepairs.fixedBadge')}
                    </span>
                    {/* This car is in the ledger more than once — the repair was "done", and it came
                        back anyway. Surfaced right next to the plate so the pattern can't hide. */}
                    {visits > 1 && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200">
                        <Icon.Refresh className="h-3 w-3" />
                        {t('completedRepairs.repeatBadge', { n: visits })}
                      </span>
                    )}
                    {tk.fault_severity_label && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
                        {tk.fault_severity_emoji} {tk.fault_severity_label}
                      </span>
                    )}
                    {tk.is_on_site && (
                      <span className="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200">
                        {t('completedRepairs.onSite')}
                      </span>
                    )}
                    <OriginBadge isSheet={isSheet} t={t} />
                  </div>

                  {/* THE PROBLEM — the reason this repair ever happened. Skipped entirely when there is
                      no description AND the faults below already say what was wrong. */}
                  {(problem || faults.length === 0) && (
                    <p className="mt-2 max-w-2xl text-sm text-slate-600">
                      <span className="font-semibold text-slate-400">{t('completedRepairs.problem')}: </span>
                      {problem || <span className="text-slate-300">{t('completedRepairs.noProblem')}</span>}
                    </p>
                  )}

                  {/* WHAT WAS FIXED — the faults themselves. */}
                  {faults.length > 0 && (
                    <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        {t('completedRepairs.whatWasFixed')}:
                      </span>
                      {faults.slice(0, 4).map((f) => (
                        <span key={f.id} className="inline-flex items-center rounded-full bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-200">
                          {faultLabel(f, t)}
                        </span>
                      ))}
                      {faults.length > 4 && (
                        <span className="text-xs font-medium text-slate-400">+{faults.length - 4}</span>
                      )}
                    </div>
                  )}
                </div>
              </div>

              <div className="flex shrink-0 items-start gap-3">
                <div className="text-end">
                  <p className="text-sm font-semibold text-slate-800">{fmtDate(closed)}</p>
                  <p className="text-xs text-slate-400">{ago(closed)}</p>
                  {SHOW_FINANCIALS && tk.cost != null && (
                    <p className="mt-1 text-sm font-bold tabular-nums text-slate-900">{fmtAED(tk.cost)}</p>
                  )}
                </div>
                <Icon.ChevronDown className={`mt-1 h-4 w-4 text-slate-400 transition-transform ${isOpen ? '' : '-rotate-90'}`} />
              </div>
            </div>

            {/* Fact strip */}
            <div className="mt-4 grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-4">
              <MiniFact icon={<Icon.Wrench className="h-3.5 w-3.5" />} label={t('completedRepairs.garageLabel')} value={tk.garage || t('completedRepairs.onSite')} />
              <MiniFact icon={<Icon.Clock className="h-3.5 w-3.5" />} label={t('completedRepairs.daysInShop')} value={span} />
              {isSheet ? (
                <MiniFact icon={<Icon.Invoice className="h-3.5 w-3.5" />} label={t('completedRepairs.contract')} value={tk.contract_no} />
              ) : (
                <MiniFact icon={<Icon.Users className="h-3.5 w-3.5" />} label={t('completedRepairs.requestedBy')} value={tk.requested_by_name || tk.handoffs?.inspected?.name} />
              )}
              {isSheet ? (
                <MiniFact icon={<Icon.Wrench className="h-3.5 w-3.5" />} label={t('completedRepairs.sparePart')} value={tk.spare_part} />
              ) : (
                <MiniFact icon={<Icon.Gauge className="h-3.5 w-3.5" />} label={t('completedRepairs.kmDriven')} value={kmDriven != null ? fmtKm(kmDriven) : fmtKm(kmOut)} />
              )}
            </div>
          </button>

          {isOpen && (
            <div className="border-t border-slate-100 bg-slate-50/60 px-5 py-5">
              <div className="grid gap-6 lg:grid-cols-3">
                {/* People chain — the full custody story. The sheet never recorded one, so a sheet row
                    says so plainly instead of showing five empty steps. */}
                <div>
                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('completedRepairs.journey')}</h4>
                  {isSheet ? (
                    <div className="rounded-xl bg-white p-3 text-xs text-slate-500 ring-1 ring-slate-200">
                      <p className="mb-1.5 font-semibold text-slate-600">{t('completedRepairs.sheetRecord')}</p>
                      <p>{t('completedRepairs.sheetNoJourney')}</p>
                      <dl className="mt-3 space-y-1.5 text-sm">
                        <FactRow label={t('completedRepairs.contract')} value={tk.contract_no} />
                        <FactRow label={t('completedRepairs.outDate')} value={tk.out_date ? fmtDate(tk.out_date) : null} />
                        <FactRow label={t('completedRepairs.backDate')} value={tk.contract_in ? fmtDate(tk.contract_in) : null} />
                        <FactRow label={t('completedRepairs.garageTrips')} value={tk.event_count ? String(tk.event_count) : null} />
                        <FactRow label={t('completedRepairs.driver')} value={tk.driver} />
                        <FactRow label={t('completedRepairs.invoiceNo')} value={tk.invoice_no} />
                      </dl>
                      {/* The contract proves the visit happened and closed — but nothing in the workshop
                          log matched its window, so we say so rather than implying a silent gap. */}
                      {tk.has_sheet_log === false && (
                        <p className="mt-3 flex items-start gap-1.5 rounded-lg bg-amber-50 p-2 text-[11px] text-amber-700 ring-1 ring-amber-200">
                          <Icon.Alert className="mt-px h-3.5 w-3.5 shrink-0" />
                          {t('completedRepairs.noSheetLog')}
                        </p>
                      )}
                    </div>
                  ) : (
                  <ol className="space-y-2.5 text-sm">
                    <ChainStep label={t('completedRepairs.requested')} name={tk.handoffs?.requested?.name || tk.requested_by_name} at={tk.handoffs?.requested?.at} />
                    <ChainStep label={t('completedRepairs.inspected')} name={tk.handoffs?.inspected?.name} at={tk.handoffs?.inspected?.at} />
                    <ChainStep label={t('completedRepairs.dispatched')} name={tk.handoffs?.dispatched?.name} at={tk.handoffs?.dispatched?.at} sub={tk.handoffs?.dispatched?.destination} />
                    <ChainStep label={t('completedRepairs.atGarage')} name={tk.garage} at={tk.handoffs?.repair_started?.at} />
                    <ChainStep label={t('completedRepairs.closedStep')} name={tk.handoffs?.closed?.name} at={tk.handoffs?.closed?.at} last />
                  </ol>
                  )}
                </div>

                {/* Faults fixed — with the diagnosis and the fix, when the fault rows carry them. */}
                <div>
                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {t('completedRepairs.faultsFixed')} ({faults.length})
                  </h4>
                  {faults.length === 0 ? (
                    <p className="text-sm text-slate-400">{t('completedRepairs.noFaults')}</p>
                  ) : (
                    <ul className="space-y-2">
                      {faults.map((f) => <FaultDetail key={f.id} f={f} t={t} />)}
                    </ul>
                  )}
                  {tk.has_video && (
                    <p className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600">
                      <Icon.Video className="h-3.5 w-3.5" />
                      {t('completedRepairs.hasVideo', { n: tk.video_count ?? 0 })}
                    </p>
                  )}
                </div>

                {/* Facts */}
                <div>
                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('completedRepairs.details')}</h4>
                  <dl className="space-y-1.5 text-sm">
                    <FactRow label={t('completedRepairs.dataOrigin')} value={isSheet ? t('completedRepairs.originSheet') : t('completedRepairs.originSystem')} />
                    <FactRow label={t('completedRepairs.garageLabel')} value={tk.garage} />
                    <FactRow label={t('completedRepairs.driver')} value={driver} />
                    <FactRow label={t('completedRepairs.severity')} value={tk.fault_severity_label ? `${tk.fault_severity_emoji || ''} ${tk.fault_severity_label}` : tk.severity} />
                    {!isSheet && <FactRow label={t('completedRepairs.odometerIn')} value={fmtKm(kmIn)} />}
                    {!isSheet && <FactRow label={t('completedRepairs.odometerOut')} value={fmtKm(kmOut)} />}
                    {!isSheet && <FactRow label={t('completedRepairs.contract')} value={tk.linked_contract_no} />}
                    <FactRow label={t('completedRepairs.reported')} value={fmtDateTime(tk.created_at)} />
                    <FactRow label={t('completedRepairs.closed')} value={fmtDateTime(closed)} />
                    {isSheet && tk.maintenance_notes && <FactRow label={t('completedRepairs.notes')} value={tk.maintenance_notes} />}
                    {SHOW_FINANCIALS && <FactRow label={t('completedRepairs.cost')} value={tk.cost != null ? fmtAED(tk.cost) : null} />}
                  </dl>
                  {/* A sheet row has no ticket to open — it links to the car's own history instead. */}
                  {isSheet ? (
                    tk.vehicle_id ? (
                      <Link
                        to={`/vehicles/${tk.vehicle_id}`}
                        className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-indigo-600 ring-1 ring-slate-200 hover:bg-indigo-50"
                      >
                        {t('completedRepairs.viewVehicle')}
                        <Icon.ArrowRight className="h-3.5 w-3.5" />
                      </Link>
                    ) : null
                  ) : (
                    <Link
                      to={`/maintenance-workflow/${tk.id}`}
                      className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-indigo-600 ring-1 ring-slate-200 hover:bg-indigo-50"
                    >
                      {t('completedRepairs.viewTicket')}
                      <Icon.ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </article>
  );
}

// A single fault, expanded: what it was, what caused it, what was done, which parts went in.
function FaultDetail({ f, t }) {
  const parts = Array.isArray(f.parts) ? f.parts : [];
  return (
    <li className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm font-medium text-slate-800">
          {f.severity_emoji ? `${f.severity_emoji} ` : ''}{faultLabel(f, t)}
        </p>
        {f.resolved_at && <span className="shrink-0 text-[11px] text-slate-400">{fmtDate(f.resolved_at)}</span>}
      </div>
      {f.root_cause && (
        <p className="mt-1.5 text-xs text-slate-500">
          <span className="font-semibold text-slate-400">{t('completedRepairs.rootCause')}: </span>{f.root_cause}
        </p>
      )}
      {f.resolution_note && (
        <p className="mt-1 text-xs text-slate-500">
          <span className="font-semibold text-slate-400">{t('completedRepairs.resolution')}: </span>{f.resolution_note}
        </p>
      )}
      {f.current_garage && (
        <p className="mt-1 inline-flex items-center gap-1 text-xs text-slate-400">
          <Icon.Wrench className="h-3 w-3" />{f.current_garage}
        </p>
      )}
      {parts.length > 0 && (
        <p className="mt-1.5 text-xs text-slate-500">
          <span className="font-semibold text-slate-400">{t('completedRepairs.partsUsed')}: </span>
          {parts.map((p) => `${p.part_name}${p.quantity > 1 ? ` ×${p.quantity}` : ''}`).join(', ')}
        </p>
      )}
    </li>
  );
}

// Data Origin chip — every row says where it came from: the imported workshop sheet, or a ticket this
// app ran end-to-end. Standing rule: no black boxes.
function OriginBadge({ isSheet, t }) {
  return isSheet ? (
    <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200" title={t('completedRepairs.originSheetHint')}>
      <Icon.Download className="h-3 w-3" />
      {t('completedRepairs.originSheet')}
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200" title={t('completedRepairs.originSystemHint')}>
      <Icon.Spark className="h-3 w-3" />
      {t('completedRepairs.originSystem')}
    </span>
  );
}

function MiniFact({ icon, label, value }) {
  return (
    <div className="flex items-center gap-2 text-sm">
      <span className="text-slate-300">{icon}</span>
      <span className="text-xs text-slate-400">{label}</span>
      <span className="min-w-0 truncate font-medium text-slate-700">{value || '—'}</span>
    </div>
  );
}

// One step in the custody chain — a labelled who + when, with a connecting rail.
function ChainStep({ label, name, at, sub, last }) {
  const done = Boolean(name || at);
  return (
    <li className="relative flex gap-3 ps-1">
      <span className="mt-1 flex flex-col items-center">
        <span className={`h-2.5 w-2.5 rounded-full ${done ? 'bg-emerald-500' : 'bg-slate-300'}`} />
        {!last && <span className="mt-0.5 h-6 w-px bg-slate-200" />}
      </span>
      <span className="min-w-0">
        <span className="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
        <span className="block truncate text-slate-800">{name || '—'}</span>
        {sub && <span className="block truncate text-xs text-slate-400">→ {sub}</span>}
        {at && <span className="block text-xs text-slate-400">{fmtDateTime(at)}</span>}
      </span>
    </li>
  );
}

function FactRow({ label, value }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className="text-slate-400">{label}</dt>
      <dd className="text-end font-medium text-slate-700">{value || <span className="text-slate-300">—</span>}</dd>
    </div>
  );
}
