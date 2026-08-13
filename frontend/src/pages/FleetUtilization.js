import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useI18n } from '../i18n/I18nContext';
import { Card, Spinner } from '../components/ui/Misc';
import DataTable, { SectionCard } from '../components/ui/Table';
import FleetUtilizationAnalytics from '../components/analytics/FleetUtilizationAnalytics';
import Icon from '../components/ui/Icon';
import { aed2, num, fmtDate } from '../lib/format';

// English stays beside the key here; the visible text is resolved with t(o.label) at render.
const PERIODS = [
  { key: 'last_month', label: 'Last month' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_3m', label: 'Last 3 mo' },
  { key: 'last_12m', label: 'Last 12 mo' },
  { key: 'all', label: 'All time' },
];

const SORTS = [
  { key: 'days_maintenance', label: 'Most maintenance days' },
  { key: 'downtime_pct', label: 'Highest downtime %' },
  { key: 'utilization_asc', label: 'Lowest utilization' },
  { key: 'days_idle', label: 'Most idle days' },
  { key: 'days_rented', label: 'Most rented days' },
  { key: 'revenue_lost_downtime', label: 'Most rent lost to downtime' },
];

// Split of a car's owned days into rented / in-maintenance / idle. Denominator is the segment sum
// (not owned days) so the bar always fills exactly — rare rent/maintenance overlap can otherwise
// push the three past 100% of owned. The numeric columns keep the true owned-based percentages.
function SplitBar({ rented, maintenance, idle }) {
  const { t } = useI18n();
  const total = rented + maintenance + idle || 1;
  // Static bars (no grow animation) — each segment sits at its final share and
  // its exact day count is available on hover, so the Idle column can be dropped.
  const seg = (v, cls, label) =>
    v > 0 ? (
      <div
        className={cls}
        style={{ width: `${((v / total) * 100).toFixed(1)}%` }}
        title={t('{label}: {n} days', { label, n: num(v) })}
      />
    ) : null;
  return (
    <div className="flex h-2.5 w-full min-w-[7rem] overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200">
      {seg(rented, 'bg-emerald-500', t('Rented'))}
      {seg(maintenance, 'bg-red-500', t('In maintenance'))}
      {seg(idle, 'bg-slate-300', t('Idle'))}
    </div>
  );
}

const pct = (v) => (v == null ? '—' : `${v}%`);
const days = (v, t) => (v == null ? '—' : t('{n}d', { n: num(v) }));

// Local "today" as YYYY-MM-DD (timezone-correct, unlike toISOString which is UTC).
const todayISO = () => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

// Visual identity per point-in-time status returned by the /status-on endpoint.
const TM_STATE = {
  rented:      { head: 'bg-emerald-50/70', iconWrap: 'bg-emerald-100 text-emerald-600', icon: <Icon.Car className="h-5 w-5" /> },
  maintenance: { head: 'bg-red-50/70',     iconWrap: 'bg-red-100 text-red-600',         icon: <Icon.Wrench className="h-5 w-5" /> },
  idle:        { head: 'bg-amber-50/70',   iconWrap: 'bg-amber-100 text-amber-600',     icon: <Icon.Clock className="h-5 w-5" /> },
  onboarding:  { head: 'bg-indigo-50/70',  iconWrap: 'bg-indigo-100 text-indigo-600',   icon: <Icon.Activity className="h-5 w-5" /> },
  not_owned:   { head: 'bg-slate-50',      iconWrap: 'bg-slate-100 text-slate-500',     icon: <Icon.Calendar className="h-5 w-5" /> },
  future:      { head: 'bg-slate-50',      iconWrap: 'bg-slate-100 text-slate-500',     icon: <Icon.Calendar className="h-5 w-5" /> },
};

// Time machine — pick a car + a day (or a date range) and see exactly what it was doing.
// One date → the rich "what was it doing then" card. Two dates → a rented/workshop/available
// day breakdown for the whole range.
function TimeMachine({ cars }) {
  const { t } = useI18n();
  const [carId, setCarId] = useState('');
  const [from, setFrom] = useState(todayISO());
  const [to, setTo] = useState('');               // blank = single-day lookup
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState(null);

  const isRange = !!to && to !== from;

  const run = async () => {
    if (!carId || !from) { setErr(t('Pick a car and a day first.')); return; }
    setLoading(true);
    setErr(null);
    try {
      const params = isRange ? { from, to } : { date: from };
      const { data } = await api.get(`/Vehicle/${carId}/status-on`, { params });
      setResult(data.data);
    } catch (e) {
      setErr(e?.response?.data?.message || t('Could not look that up.'));
      setResult(null);
    } finally {
      setLoading(false);
    }
  };

  return (
    <SectionCard
      title={t('Time machine')}
      subtitle={t('Pick a car and a day to see what it was doing then — or add a “to” date to count how many days it was rented, in the workshop, or available.')}
      bodyClass="p-5"
    >
      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          {t('Car')}
          <select
            value={carId}
            onChange={(e) => setCarId(e.target.value)}
            className="w-60 rounded-lg border-slate-200 bg-white py-2 ps-3 pe-8 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
          >
            <option value="">{t('Select a car…')}</option>
            {cars.map((c) => (
              <option key={c.vehicle_id} value={c.vehicle_id}>
                {c.plate || c.code || `#${c.vehicle_id}`}{c.car ? ` — ${c.car}` : ''}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          {t('From')}
          <input
            type="date"
            value={from}
            max={to || todayISO()}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
          />
        </label>
        <label className="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          {t('To')} <span className="text-slate-300">{t('· optional')}</span>
          <input
            type="date"
            value={to}
            min={from || undefined}
            max={todayISO()}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
          />
        </label>
        <button
          onClick={run}
          disabled={loading || !carId}
          className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {loading ? <Spinner className="h-4 w-4" /> : <Icon.Clock className="h-4 w-4" />}
          {isRange ? t('How was it used?') : t('What was it doing?')}
        </button>
      </div>

      {err && <p className="mt-3 text-sm text-red-600">{err}</p>}
      {result && (result.mode === 'range' ? <RangeResult r={result} /> : <TimeMachineResult r={result} />)}
    </SectionCard>
  );
}

// Range breakdown — how many days the car was rented / in the workshop / available over a window.
function RangeStat({ tone, label, value, sub }) {
  const tones = {
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    red:     'bg-red-50 text-red-700 ring-red-200',
    amber:   'bg-amber-50 text-amber-700 ring-amber-200',
  };
  return (
    <div className={`rounded-xl px-3 py-3 text-center ring-1 ring-inset ${tones[tone] || tones.amber}`}>
      <p className="text-2xl font-extrabold tabular-nums">{num(Math.round(value || 0))}</p>
      <p className="text-[11px] font-semibold uppercase tracking-wide opacity-80">{label}</p>
      {sub != null && <p className="text-[11px] opacity-70">{sub}</p>}
    </div>
  );
}

function RangeResult({ r }) {
  const { t } = useI18n();
  const total = r.total_days || 0;
  const share = (d) => (total ? t('{p}% of range', { p: Math.round((d / total) * 100) }) : '—');
  return (
    <div className="mt-4 animate-fade-in-up overflow-hidden rounded-2xl border border-slate-200/70 shadow-soft">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/70 px-5 py-3">
        <div className="min-w-0">
          <p className="text-sm font-bold text-slate-900">
            {r.plate || r.code || `#${r.vehicle_id}`}
            {r.car && <span className="font-normal text-slate-500"> · {[r.car, r.year].filter(Boolean).join(' · ')}</span>}
          </p>
          {/* Show the window actually counted — start at counted_from when the requested range was
              clipped to when the car joined the fleet, so the dates match the {total} day count. */}
          <p className="text-xs text-slate-400">{fmtDate(r.counted_from || r.from)} → {fmtDate(r.to)} · {t('{n} days', { n: num(total) })}</p>
        </div>
        {r.utilization_pct != null && (
          <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
            {t('{p}% utilized', { p: r.utilization_pct })}
          </span>
        )}
      </div>

      <div className="p-5">
        <div className="grid grid-cols-3 gap-3">
          <RangeStat tone="emerald" label={t('Rented')} value={r.rented_days} sub={share(r.rented_days)} />
          <RangeStat tone="red" label={t('In workshop')} value={r.maintenance_days} sub={share(r.maintenance_days)} />
          <RangeStat tone="amber" label={t('Available')} value={r.idle_days} sub={share(r.idle_days)} />
        </div>

        <div className="mt-4">
          <SplitBar rented={r.rented_days} maintenance={r.maintenance_days} idle={r.idle_days} />
        </div>

        {r.counted_from && r.counted_from !== r.from && (
          <p className="mt-2 text-[11px] text-slate-400">
            {t('You picked {picked}, but this car only joined the fleet on {joined} — so counting starts there.', {
              picked: fmtDate(r.from),
              joined: fmtDate(r.counted_from),
            })}
          </p>
        )}

        {r.rentals?.length > 0 && (
          <div className="mt-4">
            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('Rentals in this range')}</p>
            <ul className="space-y-1">
              {r.rentals.map((c) => (
                <li key={c.id} className="flex flex-wrap items-center gap-x-2 text-sm">
                  <Link to={`/contracts/${c.id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">#{c.no}</Link>
                  {c.customer && <span className="text-slate-600">{c.customer}</span>}
                  <span className="text-xs text-slate-400">{fmtDate(c.out_date)} → {c.open ? t('still out') : fmtDate(c.in_date)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {r.maintenance?.length > 0 && (
          <div className="mt-3">
            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('Workshop visits in this range')}</p>
            <ul className="space-y-1">
              {r.maintenance.map((m, i) => (
                <li key={i} className="flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                  <span className="font-medium text-slate-700">#{m.no}</span>
                  <span className="text-xs text-slate-400">{fmtDate(m.out_date)} → {m.open ? t('still in') : fmtDate(m.in_date)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}

function TimeMachineResult({ r }) {
  const { t } = useI18n();
  const st = TM_STATE[r.state] || TM_STATE.idle;
  return (
    <div className="mt-4 animate-fade-in-up overflow-hidden rounded-2xl border border-slate-200/70 shadow-soft">
      <div className={`flex items-start gap-3 px-5 py-4 ${st.head}`}>
        <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ${st.iconWrap} ring-1 ring-inset ring-black/5`}>
          {st.icon}
        </span>
        <div className="min-w-0">
          <p className="text-lg font-bold tracking-tight text-slate-900">{r.label}</p>
          <p className="text-sm text-slate-600">{r.detail}</p>
        </div>
        <span className="ms-auto shrink-0 text-end text-xs text-slate-400">
          <span className="block font-semibold text-slate-700">{r.plate || r.code || `#${r.vehicle_id}`}</span>
          {r.car && <span className="block">{[r.car, r.year].filter(Boolean).join(' · ')}</span>}
          <span className="block">{t('on {date}', { date: fmtDate(r.date) })}</span>
        </span>
      </div>

      {r.contract && (
        <div className="border-t border-slate-100 bg-white px-5 py-3 text-sm">
          <Link to={`/contracts/${r.contract.id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
            {t('Contract #{no}', { no: r.contract.no })}
          </Link>
          {r.contract.customer && <span className="text-slate-600"> · {r.contract.customer}</span>}
          <span className="block text-xs text-slate-400">
            {fmtDate(r.contract.out_date)} → {r.contract.open ? t('still out') : fmtDate(r.contract.in_date)}
          </span>
        </div>
      )}

      {r.maintenance && (
        <div className="border-t border-slate-100 bg-white px-5 py-3 text-sm">
          <Link to={`/vehicles/${r.vehicle_id}?focus=maintenance`} className="font-semibold capitalize text-indigo-600 hover:text-indigo-700">
            {r.maintenance.issue || t('Workshop visit')}
          </Link>
          {r.maintenance.garage && <span className="text-slate-600"> · {r.maintenance.garage}</span>}
          <span className="block text-xs text-slate-400">
            {fmtDate(r.maintenance.out_date)} → {fmtDate(r.maintenance.in_date)}
            {r.maintenance.cost != null && <span> · {aed2(r.maintenance.cost)}</span>}
          </span>
        </div>
      )}
    </div>
  );
}

// The operational fleet: cars that can actually be rented or sent for maintenance.
const DEFAULT_STATUSES = ['rented', 'ready', 'out_of_order', 'returned'];
// The status VALUES are API enums; only their display text is translated. Anything the backend
// adds later still falls back to the prettified enum rather than disappearing.
const STATUS_TEXT = {
  rented: 'Rented',
  ready: 'Ready',
  out_of_order: 'Out of order',
  returned: 'Returned',
  maintenance: 'In maintenance',
  sold: 'Sold',
  reserved: 'Reserved',
  idle: 'Idle',
};
const statusLabel = (s, t) =>
  (STATUS_TEXT[s] ? t(STATUS_TEXT[s]) : s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()));

export default function FleetUtilization() {
  const { t } = useI18n();
  const [period, setPeriod] = useState('last_12m');
  const [range, setRange] = useState({ from: '', to: '' });   // custom date window (overrides period)
  const [showCustom, setShowCustom] = useState(false);
  const [statuses, setStatuses] = useState(DEFAULT_STATUSES);
  const [sort, setSort] = useState('days_maintenance');
  const [q, setQ] = useState('');

  // A custom range (either side filled) takes precedence over the preset period — same rule the
  // backend uses: any from/to wins over `period`.
  const custom = !!(range.from || range.to);

  // Empty selection → send a sentinel so the API returns nothing (instead of falling back to default).
  const statusParam = statuses.length ? statuses.join(',') : '__none__';
  const fetcher = useCallback(async () => {
    const params = { statuses: statusParam };
    if (range.from || range.to) {
      if (range.from) params.from = range.from;
      if (range.to) params.to = range.to;
    } else {
      params.period = period;
    }
    const { data } = await api.get('/Vehicle/utilization', { params });
    return data.data;
  }, [period, statusParam, range.from, range.to]);
  const { data, loading, error } = useFetch(fetcher, [period, statusParam, range.from, range.to]);

  // Switch to a preset window — clears any custom range and collapses the date panel.
  const pickPeriod = (key) => { setPeriod(key); setRange({ from: '', to: '' }); setShowCustom(false); };
  const clearRange = () => { setRange({ from: '', to: '' }); setShowCustom(false); };

  const toggleStatus = (st) =>
    setStatuses((prev) => (prev.includes(st) ? prev.filter((x) => x !== st) : [...prev, st]));
  const statusOptions = data?.status_options || [];

  const rows = useMemo(() => {
    let list = data?.cars || [];
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter((c) =>
        [c.plate, c.code, c.car].filter(Boolean).some((s) => String(s).toLowerCase().includes(needle)),
      );
    }
    const cmp = {
      utilization_asc: (a, b) => (a.utilization_pct ?? 1e9) - (b.utilization_pct ?? 1e9),
      downtime_pct: (a, b) => (b.downtime_pct ?? -1) - (a.downtime_pct ?? -1),
      days_maintenance: (a, b) => (b.days_maintenance ?? -1) - (a.days_maintenance ?? -1),
      days_idle: (a, b) => (b.days_idle ?? -1) - (a.days_idle ?? -1),
      days_rented: (a, b) => (b.days_rented ?? -1) - (a.days_rented ?? -1),
      revenue_lost_downtime: (a, b) => (b.revenue_lost_downtime ?? -1) - (a.revenue_lost_downtime ?? -1),
    }[sort];
    return [...list].sort(cmp);
  }, [data, q, sort]);

  // Car picker for the Time machine — every loaded car, ordered by plate.
  const carOptions = useMemo(
    () => [...(data?.cars || [])].sort((a, b) =>
      String(a.plate || a.code || '').localeCompare(String(b.plate || b.code || ''), undefined, { numeric: true })),
    [data],
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Hero header — title and a tight colour-coded subtitle. */}
        <div className="animate-fade-in-up space-y-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2.5">
              <span className="h-7 w-1 rounded-full bg-indigo-500" />
              <h1 className="text-2xl font-bold tracking-tight text-slate-900">{t('Fleet Utilization')}</h1>
            </div>
            <p className="mt-2 max-w-2xl text-sm leading-relaxed text-slate-500 sm:ps-4">
              {t("How every car's time splits between earning on rent, in the workshop, and sitting idle — measured from each car's first rental, so new cars aren't branded as downtime.")}
            </p>
          </div>
        </div>

        {/* Controls */}
        <Card className="p-4 animate-fade-in-up">
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex flex-wrap gap-1.5">
              {PERIODS.map((p) => (
                <button
                  key={p.key}
                  onClick={() => pickPeriod(p.key)}
                  className={`rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition ${
                    period === p.key && !custom ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                  }`}
                >
                  {t(p.label)}
                </button>
              ))}
              {/* Custom date window — toggles the From/To panel below. */}
              <button
                onClick={() => setShowCustom((v) => !v)}
                aria-expanded={showCustom || custom}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition ${
                  custom ? 'bg-indigo-600 text-white ring-indigo-600' : showCustom ? 'bg-indigo-50 text-indigo-700 ring-indigo-300' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                }`}
              >
                <Icon.Calendar className="h-4 w-4" />
                {custom ? `${range.from || '…'} → ${range.to || '…'}` : t('Custom dates')}
              </button>
            </div>
            <div className="ms-auto flex flex-wrap items-center gap-2">
              <select
                value={sort}
                onChange={(e) => setSort(e.target.value)}
                className="rounded-lg border-slate-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
              >
                {SORTS.map((o) => (
                  <option key={o.key} value={o.key}>{t(o.label)}</option>
                ))}
              </select>
              <div className="relative">
                <Icon.Search className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder={t('Search plate / model…')}
                  className="w-44 rounded-lg border-slate-200 bg-white py-1.5 ps-8 pe-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
                />
              </div>
            </div>
          </div>

          {/* Custom date panel — pick a From/To window. Either side can be left blank for an
              open-ended range; any value here overrides the preset period above. */}
          {(showCustom || custom) && (
            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 animate-fade-in-up">
              <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                <Icon.Calendar className="h-4 w-4 text-indigo-500" /> {t('Date range')}
              </span>
              <label className="inline-flex items-center gap-1.5 text-sm text-slate-500">
                {t('From')}
                <input
                  type="date"
                  value={range.from}
                  max={range.to || undefined}
                  onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))}
                  className="rounded-lg border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
                />
              </label>
              <span className="text-slate-300 rtl:-scale-x-100">→</span>
              <label className="inline-flex items-center gap-1.5 text-sm text-slate-500">
                {t('To')}
                <input
                  type="date"
                  value={range.to}
                  min={range.from || undefined}
                  onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))}
                  className="rounded-lg border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-indigo-400"
                />
              </label>
              {custom ? (
                <button onClick={clearRange} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 hover:text-slate-700">
                  {t('Clear')}
                </button>
              ) : (
                <span className="text-xs text-slate-400">{t('Leave one side blank for an open-ended range.')}</span>
              )}
            </div>
          )}

          {/* Status checkboxes — tick which vehicle statuses to include (default = operational fleet) */}
          {statusOptions.length > 0 && (
            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Show statuses')}</span>
              {statusOptions.map((o) => {
                const on = statuses.includes(o.status);
                return (
                  <button
                    key={o.status}
                    onClick={() => toggleStatus(o.status)}
                    className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition ${
                      on ? 'bg-indigo-50 text-indigo-700 ring-indigo-300' : 'bg-white text-slate-500 ring-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <span className={`flex h-3.5 w-3.5 items-center justify-center rounded-[4px] ring-1 ring-inset ${on ? 'bg-indigo-600 ring-indigo-600' : 'bg-white ring-slate-300'}`}>
                      {on && (
                        <svg className="h-2.5 w-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round"><path d="M5 13l4 4L19 7" /></svg>
                      )}
                    </span>
                    {statusLabel(o.status, t)}
                    <span className={on ? 'text-indigo-400' : 'text-slate-400'}>{o.count}</span>
                  </button>
                );
              })}
              <span className="ms-auto flex items-center gap-2 text-xs">
                <button onClick={() => setStatuses(DEFAULT_STATUSES)} className="font-medium text-indigo-600 hover:text-indigo-700">{t('Operational')}</button>
                <span className="text-slate-300">·</span>
                <button onClick={() => setStatuses(statusOptions.map((o) => o.status))} className="font-medium text-slate-500 hover:text-slate-700">{t('All')}</button>
              </span>
            </div>
          )}
          <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-400">
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" /> {t('Rented')}</span>
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-red-500" /> {t('In maintenance')}</span>
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-slate-300" /> {t('Idle')}</span>
            <span className="ms-auto italic">{t('Maintenance = true off-road shop days — workshop days with no active rental, from the OfficeManager maintenance contracts. Rental is King: a day a car is both on rent and in the shop counts as rental time, never shop time. The three always add up to days in service.')}</span>
          </p>
        </Card>

        {/* Analytics — same filtered rows as the table below. */}
        {!loading && !error && rows.length > 0 && <FleetUtilizationAnalytics rows={rows} />}

        {/* Table */}
        {error ? (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        ) : (
          <SectionCard
            className="animate-fade-in-up"
            title={t('Per-car breakdown')}
            actions={!loading && <span className="text-xs text-slate-400">{t('{n} cars', { n: num(rows.length) })}</span>}
          >
            <DataTable
              className="stagger-rows"
              rows={rows}
              rowKey={(c) => c.vehicle_id}
              loading={loading}
              empty={t('No vehicles match this filter.')}
              highlightRow={(c) => c.downtime_pct >= 15 || (c.utilization_pct != null && c.utilization_pct < 20)}
              columns={[
                {
                  key: 'car',
                  header: t('Car'),
                  render: (c) => (
                    <>
                      <Link to={`/vehicles/${c.vehicle_id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
                        {c.plate || c.code || `#${c.vehicle_id}`}
                      </Link>
                      {c.pending_service && (
                        <span className="ms-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200" title={t('Purchased but not yet rented — no performance metrics until its first rental contract.')}>
                          {t('Pending service')}
                        </span>
                      )}
                      {c.car && <span className="block text-xs text-slate-400">{[c.car, c.year].filter(Boolean).join(' · ')}</span>}
                      {/* In-service days folded in here (its own column was removed to declutter). */}
                      <span
                        className="block text-[11px] text-slate-400"
                        title={[
                          t('In service {d}', { d: days(c.days_in_service, t) }),
                          c.owned_since ? t('owned {d} (since {date})', { d: days(c.days_owned, t), date: c.owned_since }) : null,
                          c.in_service_date ? t('first rental {date}', { date: c.in_service_date }) : null,
                        ].filter(Boolean).join(' · ')}
                      >
                        {t('{d} in service', { d: days(c.days_in_service, t) })}
                      </span>
                    </>
                  ),
                },
                {
                  key: 'rented',
                  header: t('Rented'),
                  align: 'right',
                  tooltip: t('Days on a paid rental, and the resulting Utilization % (rented ÷ in-service days).'),
                  cellClass: 'tabular-nums',
                  render: (c) => (
                    <>
                      <span className="font-medium text-emerald-600">{days(c.days_rented, t)}</span>
                      <span className="block text-[11px] text-slate-400">{pct(c.utilization_pct)}</span>
                    </>
                  ),
                },
                {
                  key: 'maintenance',
                  header: t('Maintenance'),
                  align: 'right',
                  tooltip: t('Workshop days with no active rental (true downtime), plus visit count and any onboarding visits excluded from this window.'),
                  cellClass: 'tabular-nums',
                  render: (c) => (
                    <>
                      <span className={`font-medium ${c.downtime_pct >= 15 ? 'text-red-600' : 'text-slate-700'}`}>{days(c.days_maintenance, t)}</span>
                      <span className="block text-[11px] text-slate-400">
                        {pct(c.downtime_pct)}
                        {c.maintenance_visits > 0 ? (
                          <>
                            {' · '}
                            <Link
                              to={`/vehicles/${c.vehicle_id}?focus=maintenance`}
                              title={t("See this car's workshop visits")}
                              className="font-medium text-indigo-600 underline decoration-dotted underline-offset-2 hover:text-indigo-700"
                            >
                              {t('{n} visits', { n: num(c.maintenance_visits) })}
                            </Link>
                          </>
                        ) : (
                          ` · ${t('{n} visits', { n: num(c.maintenance_visits) })}`
                        )}
                        {c.onboarding_visits > 0 && (
                          <span className="text-slate-400" title={t("{n} workshop visit(s) happened during onboarding, before the first rental — excluded here but shown in the car's lifetime total on its profile ({lifetime} lifetime).", { n: num(c.onboarding_visits), lifetime: num((c.maintenance_visits || 0) + c.onboarding_visits) })}>
                            {' '}{t('(+{n} onboarding)', { n: num(c.onboarding_visits) })}
                          </span>
                        )}
                      </span>
                    </>
                  ),
                },
                {
                  key: 'split',
                  header: t('Split'),
                  headerClass: 'w-40',
                  tooltip: t('Rented / in-maintenance / idle split of in-service days. Hover a segment for its exact day count (including idle days).'),
                  render: (c) => <SplitBar rented={c.days_rented} maintenance={c.days_maintenance} idle={c.days_idle || 0} />,
                },
                {
                  key: 'rent_lost',
                  header: t('Rent lost'),
                  align: 'right',
                  tooltip: t("Estimated rent foregone to downtime: downtime days × the car's daily rate."),
                  cellClass: 'tabular-nums text-slate-600',
                  render: (c) => (c.revenue_lost_downtime != null ? aed2(c.revenue_lost_downtime) : '—'),
                },
              ]}
            />
          </SectionCard>
        )}

        {/* Time machine — a secondary power-user tool, kept below the main breakdown. */}
        {!loading && carOptions.length > 0 && <TimeMachine cars={carOptions} />}
      </div>
    </div>
  );
}
