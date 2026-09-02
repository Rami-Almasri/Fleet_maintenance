// Maintenance History (/maintenance-history) — the full "Most in Maintenance" list behind the
// dashboard column's "All →" link. Every in-fleet car that saw the workshop over the chosen window,
// with how OFTEN it went in (visits) and how LONG it spent there (total days in the shop, from the
// canonical type-U maintenance contracts). Sortable + searchable. Backed by GET /Dashboard/maintenance-history.
//
// The page answers two different questions and keeps them apart, because one table cannot do both:
//
//   OVER A PERIOD — how often and how long, summed per car across a window. The figures come from the
//                   one canonical maintenance-day calculation Fleet Utilization uses, so a car reading
//                   31 days here reads 31 days there.
//   ON ONE DAY    — the morning question: pick a date, get the cars that were at a garage ON it, how
//                   many days each had been in BY that date, the date it was promised back, and how far
//                   past that promise the day already was. A snapshot, measured at the chosen day and
//                   never at today — otherwise a report on a past date does not read the way it read.

import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';
import DateRangePicker from '../components/ui/DateRangePicker';
import MaintenanceHistoryAnalytics from '../components/analytics/MaintenanceHistoryAnalytics';
import WorkshopEvents from '../components/WorkshopEvents';
import { useI18n } from '../i18n/I18nContext';

// Arabic must render Gregorian dates with Latin digits — a bare toLocale*() would emit Hijri +
// Arabic-Indic numerals and break the tabular columns. `lang` is threaded in from the component.
const fmtDate = (lang, iso) => (iso
  ? new Date(iso).toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, { day: '2-digit', month: 'short', year: 'numeric' })
  : '—');
const nfmt = (lang, n) => Number(n).toLocaleString(lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined);
// Count sentences branch in ENGLISH and emit two separate phrase keys, so Arabic gets its own wording
// rather than an English-shaped "1 / not 1" split.
const visitCount = (t, lang, n) => (Number(n) === 1 ? t('1 visit') : t('{n} visits', { n: nfmt(lang, n) }));
const dayCount = (t, lang, n) => (Number(n) === 1 ? t('1 day') : t('{n} days', { n: nfmt(lang, n) }));

const todayIso = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// WHO OPENED THE VISIT. Both record sources are offered even though one of them is currently four
// trips fleet-wide: a filter that hides an empty side would hide the fact that the workflow has barely
// been used yet, and that fact is worth seeing.
const SOURCES = [
  { key: 'all', label: 'All sources' },
  { key: 'officemanager', label: 'OfficeManager' },
  { key: 'system', label: 'This system' },
];

export default function MaintenanceHistory() {
  const { t, lang } = useI18n();
  const [days, setDays] = useState(0); // default: all-time — total days each car has ever spent in the shop
  const [from, setFrom] = useState(''); // explicit date range (YYYY-MM-DD); either bound overrides `days`
  const [to, setTo] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');
  const [sort, setSort] = useState({ key: 'days_in_shop', dir: 'desc' }); // rank by most days in the shop
  // Per-car "see N visits" drill-down: which row is open + its fetched visit list (cached by id).
  const [openId, setOpenId] = useState(null);
  const [visits, setVisits] = useState({}); // { [vehicleId]: { loading, items, error } }
  // The point-in-time view: one chosen day, optionally narrowed to one record source.
  const [mode, setMode] = useState('period'); // 'period' | 'day'
  const [day, setDay] = useState(todayIso());
  const [source, setSource] = useState('all');
  const [dayData, setDayData] = useState(null);
  const [dayLoading, setDayLoading] = useState(false);

  // A date range (either bound) takes precedence over the preset trailing window.
  const usingRange = Boolean(from || to);
  // The exact params both the list and drill-down requests send, so they always share one window.
  const params = useMemo(() => (usingRange ? { from: from || undefined, to: to || undefined } : { days }), [usingRange, from, to, days]);

  // The day snapshot is its own request — a different question over a different shape, so it is not
  // folded into the window fetch above. Only fired while the day view is the one on screen.
  useEffect(() => {
    if (mode !== 'day') return undefined;
    let alive = true;
    setDayLoading(true);
    api.get('/Dashboard/maintenance-on-day', { params: { date: day, source: source === 'all' ? undefined : source } })
      .then((res) => { if (alive) setDayData(res.data.data || null); })
      .catch(() => { if (alive) setDayData(null); })
      .finally(() => { if (alive) setDayLoading(false); });
    return () => { alive = false; };
  }, [mode, day, source]);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    setOpenId(null);
    setVisits({}); // window changed → drop any cached drill-downs (they're window-scoped)
    api.get('/Dashboard/maintenance-history', { params })
      .then((res) => { if (alive) setData(res.data.data || { count: 0, items: [] }); })
      .catch(() => { if (alive) setData({ count: 0, items: [] }); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [params]);

  const toggleVisits = (id) => {
    setOpenId((cur) => (cur === id ? null : id));
    // Fetch once per car (per window); cached in state afterwards.
    if (!visits[id]) {
      setVisits((v) => ({ ...v, [id]: { loading: true, items: [], error: false } }));
      api.get(`/Dashboard/maintenance-history/${id}/visits`, { params })
        .then((res) => setVisits((v) => ({ ...v, [id]: { loading: false, items: res.data.data?.items || [], error: false } })))
        .catch(() => setVisits((v) => ({ ...v, [id]: { loading: false, items: [], error: true } })));
    }
  };

  const items = useMemo(() => data?.items || [], [data]);

  // Summary KPIs — derived from the same list so they always reconcile with the table.
  const summary = useMemo(() => {
    const totalVisits = items.reduce((s, r) => s + (r.visits || 0), 0);
    const inShop = items.filter((r) => r.currently_in_shop).length;
    const withDur = items.filter((r) => r.days_in_shop != null);
    const avgDays = withDur.length ? Math.round(withDur.reduce((s, r) => s + r.days_in_shop, 0) / withDur.length) : 0;
    return { cars: items.length, totalVisits, inShop, avgDays };
  }, [items]);

  const rows = useMemo(() => {
    let r = items;
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate} ${x.car}`.toLowerCase().includes(term));
    const { key, dir } = sort;
    const mul = dir === 'asc' ? 1 : -1;
    return [...r].sort((a, b) => {
      let av = a[key], bv = b[key];
      if (key === 'last_visit' || key === 'first_visit') { av = av || ''; bv = bv || ''; return mul * String(av).localeCompare(String(bv)); }
      av = av ?? -1; bv = bv ?? -1; // null durations sort last
      return mul * (av - bv);
    });
  }, [items, q, sort]);

  const toggleSort = (key) => setSort((s) => (s.key === key ? { key, dir: s.dir === 'desc' ? 'asc' : 'desc' } : { key, dir: 'desc' }));
  const SortHead = ({ label, sortKey, align = 'left' }) => (
    <th className={`whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 ${align === 'right' ? 'text-end' : ''}`}>
      <button onClick={() => toggleSort(sortKey)} className={`inline-flex items-center gap-1 hover:text-slate-700 ${align === 'right' ? 'flex-row-reverse' : ''}`}>
        {label}
        <Icon.ChevronDown className={`h-3 w-3 transition ${sort.key === sortKey ? (sort.dir === 'asc' ? 'rotate-180 text-indigo-600' : 'text-indigo-600') : 'text-slate-300'}`} />
      </button>
    </th>
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1100px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180 rtl:-scale-x-100" /> {t('Dashboard')}
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('Maintenance History')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('Every car that went to the workshop — how often it went in, and how long it spent there.')}</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-soft" role="group" aria-label={t('Which question to ask')}>
              {[{ key: 'period', label: t('Over a period') }, { key: 'day', label: t('On one day') }].map((m) => (
                <button
                  key={m.key}
                  onClick={() => setMode(m.key)}
                  aria-pressed={mode === m.key}
                  className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${mode === m.key ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-indigo-600'}`}
                >
                  {m.label}
                </button>
              ))}
            </div>
            {mode === 'period' ? (
              <DateRangePicker
                days={days}
                from={from}
                to={to}
                onChange={({ days: d, from: f, to: t }) => { setDays(d); setFrom(f); setTo(t); }}
              />
            ) : (
              <label className="inline-flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 shadow-soft">
                <span className="grid h-7 w-7 place-items-center rounded-lg bg-indigo-600 text-white">
                  <Icon.Calendar className="h-4 w-4" />
                </span>
                <span className="flex flex-col leading-tight">
                  <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Report date')}</span>
                  <input
                    type="date"
                    value={day}
                    max={todayIso()}
                    onChange={(e) => setDay(e.target.value || todayIso())}
                    className="bg-transparent text-sm font-medium tabular-nums text-slate-700 outline-none"
                  />
                </span>
              </label>
            )}
          </div>
        </div>

        {/* Record source — a real filter on the day view, where visits are listed one by one. */}
        {mode === 'day' && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Recorded by')}</span>
            {SOURCES.map((s) => (
              <button
                key={s.key}
                onClick={() => setSource(s.key)}
                aria-pressed={source === s.key}
                className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${source === s.key ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:border-indigo-200 hover:text-indigo-600'}`}
              >
                {t(s.label)}
                {dayData?.summary && s.key !== 'all' && source === 'all'
                  ? <span className="ms-1.5 text-slate-400">{nfmt(lang, dayData.summary[s.key] || 0)}</span>
                  : null}
              </button>
            ))}
          </div>
        )}

        {mode === 'day' ? <DaySnapshot data={dayData} loading={dayLoading} day={day} /> : (
        <>
        {/* Summary KPIs */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Kpi icon="Car" label={t('Cars maintained')} value={loading ? null : nfmt(lang, summary.cars)} />
          <Kpi icon="Wrench" label={t('Total visits')} value={loading ? null : nfmt(lang, summary.totalVisits)} />
          <Kpi icon="Clock" label={t('Avg days in shop')} value={loading ? null : t('{n}d', { n: nfmt(lang, summary.avgDays) })} />
          <Kpi icon="Activity" label={t('Currently in shop')} value={loading ? null : nfmt(lang, summary.inShop)} tone={summary.inShop ? 'amber' : 'slate'} />
        </div>

        {/* Analytics — the whole window, before the search narrows the table below. */}
        {!loading && items.length > 0 && <MaintenanceHistoryAnalytics items={items} />}

        {/* Search */}
        <div className="relative max-w-xs">
          <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={t('Search plate or model…')}
            className="w-full rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        {loading ? (
          <Skeleton className="h-96 rounded-2xl" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('No workshop visits in this window')}</p>
            <p className="text-xs text-slate-400">{t('Try a wider time range.')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <table className="w-full min-w-[760px] border-separate border-spacing-0 text-sm">
              <thead>
                <tr className="text-start">
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Vehicle')}</th>
                  <SortHead label={t('Visits')} sortKey="visits" align="right" />
                  <SortHead label={t('Time in shop')} sortKey="days_in_shop" align="right" />
                  <SortHead label={t('First visit')} sortKey="first_visit" />
                  <SortHead label={t('Last visit')} sortKey="last_visit" />
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Status')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => {
                  const isOpen = openId === r.id;
                  const vd = visits[r.id];
                  return (
                  <Fragment key={r.id}>
                  <tr className={`transition-colors hover:bg-indigo-50/40 ${isOpen ? 'bg-indigo-50/40' : i % 2 ? 'bg-slate-50/40' : 'bg-white'}`}>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <Link to={`/vehicles/${r.id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{r.plate || `#${r.id}`}</Link>
                      {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-end">
                      <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${r.visits >= 10 ? 'bg-red-100 text-red-700' : r.visits >= 5 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>
                        {visitCount(t, lang, r.visits)}
                      </span>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-end font-semibold tabular-nums text-slate-700">
                      {r.days_in_shop == null ? <span className="text-slate-300">—</span> : dayCount(t, lang, r.days_in_shop)}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(lang, r.first_visit)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(lang, r.last_visit)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        {r.currently_in_shop
                          ? <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"><span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> {t('In shop')}</span>
                          : <span className="text-xs text-slate-400">{t('Returned')}</span>}
                        <button
                          onClick={() => toggleVisits(r.id)}
                          className={`ms-auto inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${isOpen ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-600'}`}
                          aria-expanded={isOpen}
                        >
                          {Number(r.visits) === 1 ? t('See 1 visit') : t('See {n} visits', { n: nfmt(lang, r.visits) })}
                          <Icon.ChevronDown className={`h-3 w-3 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                        </button>
                      </div>
                    </td>
                  </tr>
                  {isOpen && (
                    <tr>
                      <td colSpan={6} className="border-b border-slate-200 bg-slate-50/60 px-5 py-4">
                        <VisitList detail={vd} vehicleId={r.id} />
                      </td>
                    </tr>
                  )}
                  </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        </>
        )}
      </div>
    </div>
  );
}

/**
 * THE DAY SNAPSHOT — every car that was at a garage on the chosen date.
 *
 * The lateness column is the point of the view, and it is only as good as the date it measures
 * against. `maintenances.expected_return_date` is the fleet's only ready-by date at scale, and on a
 * trip that has already ended it is usually rewritten to the day the car actually came back. Those
 * rows are labelled "logged on return" and counted as neither late nor on time, because a date
 * written after the fact cannot tell you whether anyone was late. The panel says so on the page
 * rather than in a comment, so a reader never mistakes a small "late" count for a punctual fleet.
 */
function DaySnapshot({ data, loading, day }) {
  const { t, lang } = useI18n();
  const s = data?.summary || {};
  const items = data?.items || [];
  const promised = (s.late || 0) + (s.due_today || 0) + (s.on_track || 0);

  if (loading) return <Skeleton className="h-96 rounded-2xl" />;

  return (
    <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Kpi icon="Car" label={t('Cars in a garage')} value={nfmt(lang, data?.count ?? 0)} />
        <Kpi icon="ArrowRight" label={t('Went in that day')} value={nfmt(lang, s.went_in_on_day || 0)} />
        <Kpi icon="Check" label={t('Came back that day')} value={nfmt(lang, s.came_back_on_day || 0)} />
        <Kpi icon="Flag" label={t('Past their promised date')} value={nfmt(lang, s.late || 0)} tone={s.late ? 'amber' : 'slate'} />
      </div>

      {/* What the "late" count is worth — stated before the table, not after it. */}
      <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
        <div className="flex gap-3">
          <Icon.Info className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
          <div className="space-y-1 text-sm text-amber-900">
            <p className="font-semibold">
              {t('{n} of {total} cars had a date they were promised back by.', {
                n: nfmt(lang, promised), total: nfmt(lang, data?.count ?? 0),
              })}
            </p>
            <p className="text-amber-800/90">
              {t('The expected-return date comes from the workshop sheet. On a visit that has already ended it is usually rewritten to the day the car actually came back — those {n} rows are shown as “logged on return” and counted as neither late nor on time. The flag is dependable for a car that was still in the shop on the day you picked.', { n: nfmt(lang, s.recorded_on_return || 0) })}
            </p>
            {s.no_promise > 0 && (
              <p className="text-amber-800/90">
                {t('{n} cars carry no promised date at all — nobody entered one, and the page does not invent it.', { n: nfmt(lang, s.no_promise) })}
              </p>
            )}
          </div>
        </div>
      </div>

      {items.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
          <Icon.Check className="h-10 w-10 text-emerald-500" />
          <p className="mt-3 text-sm font-medium text-slate-700">{t('No car was at a garage on {date}.', { date: fmtDate(lang, day) })}</p>
          <p className="text-xs text-slate-400">{t('Try another date, or widen the record source.')}</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200/60 bg-white shadow-soft">
          <table className="w-full min-w-[900px] border-separate border-spacing-0 text-sm">
            <thead>
              <tr className="text-start">
                {[t('Vehicle'), t('Recorded by'), t('Went in'), t('Days in by then'), t('Promised back'), t('Promised days'), t('Status'), t('Garage'), t('Reported issue')].map((h, i) => (
                  <th key={i} className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((r, i) => (
                <tr key={`${r.vehicle_id}-${r.out_date}`} className={`transition-colors hover:bg-indigo-50/40 ${i % 2 ? 'bg-slate-50/40' : 'bg-white'}`}>
                  <td className="border-b border-slate-100 px-4 py-3.5">
                    <Link to={`/vehicles/${r.vehicle_id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{r.plate || `#${r.vehicle_id}`}</Link>
                    {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                  </td>
                  <td className="border-b border-slate-100 px-4 py-3.5"><SourceChip source={r.recorded_by} /></td>
                  <td className="whitespace-nowrap border-b border-slate-100 px-4 py-3.5 text-slate-500">{fmtDate(lang, r.out_date)}</td>
                  <td className="border-b border-slate-100 px-4 py-3.5 font-semibold tabular-nums text-slate-700">{dayCount(t, lang, r.days_in_shop)}</td>
                  <td className="whitespace-nowrap border-b border-slate-100 px-4 py-3.5 text-slate-500">{r.expected_on ? fmtDate(lang, r.expected_on) : <span className="text-slate-300">—</span>}</td>
                  <td className="border-b border-slate-100 px-4 py-3.5 tabular-nums text-slate-600">{r.expected_days == null ? <span className="text-slate-300">—</span> : dayCount(t, lang, r.expected_days)}</td>
                  <td className="border-b border-slate-100 px-4 py-3.5"><StatusChip row={r} /></td>
                  <td className="border-b border-slate-100 px-4 py-3.5 text-slate-600">{r.garage || <span className="text-slate-300">—</span>}</td>
                  <td className="border-b border-slate-100 px-4 py-3.5 text-slate-600">{r.issue || <span className="text-slate-300">—</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Where every column on this table came from — per the fleet's traceability rule. */}
      {data?.provenance && (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 text-xs text-slate-500">
          <p className="mb-1.5 font-semibold uppercase tracking-wide text-slate-400">{t('Where this comes from')}</p>
          <p>{t('In a garage on the day')}: <span className="text-slate-600">{data.provenance.in_shop}</span></p>
          <p>{t('Promised back')}: <span className="text-slate-600">{data.provenance.expected}</span></p>
        </div>
      )}
    </>
  );
}

/** Late / on-track / not-really-a-promise — the four honest readings of one date. */
function StatusChip({ row }) {
  const { t, lang } = useI18n();
  const chip = (cls, text) => <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}>{text}</span>;

  if (row.status === 'late') {
    return chip('bg-red-100 text-red-700', row.late_days === 1 ? t('Late by 1 day') : t('Late by {n} days', { n: nfmt(lang, row.late_days) }));
  }
  if (row.status === 'due_today') return chip('bg-amber-100 text-amber-700', t('Due back that day'));
  if (row.status === 'on_track') return chip('bg-emerald-100 text-emerald-700', t('Inside the promise'));
  if (row.status === 'recorded_on_return') return chip('bg-slate-100 text-slate-500', t('Logged on return'));
  return chip('bg-slate-100 text-slate-500', t('No date promised'));
}

/** Which record source opened the visit — OfficeManager, or FleetView's own workflow. */
function SourceChip({ source }) {
  const { t } = useI18n();
  if (source === 'system') {
    return <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">{t('This system')}</span>;
  }
  if (source === 'officemanager') {
    return <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{t('OfficeManager')}</span>;
  }
  return <span className="text-xs text-slate-400">{t('Unknown')}</span>;
}

/**
 * THE DRILL-DOWN: WHAT HAPPENED, one maintenance contract at a time.
 *
 * Straight to the workshop events — the panel the contract page already uses, which renders each
 * entry as the event it is: the stage it was at, its priority, the garage, the dates it was expected
 * and returned, the problem somebody reported and the fix somebody wrote down.
 *
 * There is no summary table in front of it on purpose. A row of went-in / came-back / days / garage
 * columns is a description OF the log rather than the log, and a reader who has opened a car to find
 * out what happened to it has to get past the description before reaching the answer. The one thing
 * kept above each panel is the contract's own dates, because a car with three visits needs to know
 * which one it is reading.
 */
function VisitList({ detail, vehicleId }) {
  const { t, lang } = useI18n();

  if (!detail || detail.loading) {
    return <div className="space-y-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-9 rounded-lg" />)}</div>;
  }
  if (detail.error) {
    return <p className="text-sm text-rose-600">{t('Couldn’t load this car’s visits. Please try again.')}</p>;
  }
  if (!detail.items.length) {
    return <p className="text-sm text-slate-400">{t('No individual visits recorded in this window.')}</p>;
  }

  return (
    <div className="space-y-5">
      {detail.items.map((v, i) => (
        <div key={`${v.contract_id}-${i}`}>
          <div className="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <span className="font-semibold text-slate-700">{fmtDate(lang, v.out_date)}</span>
            <span className="text-slate-300">→</span>
            <span className="text-slate-600">
              {v.returned
                ? fmtDate(lang, v.in_date)
                : <span className="inline-flex items-center gap-1 font-medium text-amber-600"><span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> {t('Still in')}</span>}
            </span>
            {v.days != null && <span className="text-slate-400">· {dayCount(t, lang, v.days)}</span>}
            <SourceChip source={v.recorded_by} />
            {/* The promise, and what it is worth — a date the sheet wrote when the car came back says
                nothing about lateness, so it is labelled rather than turned into a verdict. */}
            {v.expected_on && (
              <span className="text-slate-400">
                · {t('Promised back')} {fmtDate(lang, v.expected_on)}
                {v.expected_recorded_on_return
                  ? <span className="ms-1 text-slate-400">{t('(logged on return)')}</span>
                  : v.late_days > 0
                    ? <span className="ms-1 font-semibold text-red-600">{v.late_days === 1 ? t('Late by 1 day') : t('Late by {n} days', { n: nfmt(lang, v.late_days) })}</span>
                    : null}
              </span>
            )}
          </div>
          <WorkshopEvents
            contractId={v.contract_id}
            vehicleId={vehicleId}
            defaultDate={v.out_date}
            expectedReturn={v.expected_on}
          />
        </div>
      ))}
    </div>
  );
}


function Kpi({ icon, label, value, tone = 'slate' }) {
  const Ico = Icon[icon] || Icon.Info;
  const toneCls = tone === 'amber' ? 'text-amber-600' : 'text-slate-900';
  return (
    <div className="rounded-2xl bg-white p-4 shadow-soft ring-1 ring-slate-200">
      <div className="mb-2 flex items-center gap-2">
        <Ico className="h-4 w-4 text-slate-400" />
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      </div>
      {value == null ? <Skeleton className="h-7 w-16 rounded" /> : <p className={`text-2xl font-bold tabular-nums ${toneCls}`}>{value}</p>}
    </div>
  );
}
