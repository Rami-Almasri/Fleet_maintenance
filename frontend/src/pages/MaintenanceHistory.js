// Maintenance History (/maintenance-history) — the full "Most in Maintenance" list behind the
// dashboard column's "All →" link. Every in-fleet car that saw the workshop over the chosen window,
// with how OFTEN it went in (visits) and how LONG it spent there (total days in the shop, from the
// canonical type-U maintenance contracts). Sortable + searchable. Backed by GET /Dashboard/maintenance-history.

import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';
import DateRangePicker from '../components/ui/DateRangePicker';
import MaintenanceHistoryAnalytics from '../components/analytics/MaintenanceHistoryAnalytics';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const plural = (n, w) => `${Number(n).toLocaleString()} ${w}${n === 1 ? '' : 's'}`;

export default function MaintenanceHistory() {
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

  // A date range (either bound) takes precedence over the preset trailing window.
  const usingRange = Boolean(from || to);
  // The exact params both the list and drill-down requests send, so they always share one window.
  const params = useMemo(() => (usingRange ? { from: from || undefined, to: to || undefined } : { days }), [usingRange, from, to, days]);

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
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> Dashboard
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">Maintenance History</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">Every car that went to the workshop — how often it went in, and how long it spent there.</p>
          </div>
          <DateRangePicker
            days={days}
            from={from}
            to={to}
            onChange={({ days: d, from: f, to: t }) => { setDays(d); setFrom(f); setTo(t); }}
          />
        </div>

        {/* Summary KPIs */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Kpi icon="Car" label="Cars maintained" value={loading ? null : summary.cars} />
          <Kpi icon="Wrench" label="Total visits" value={loading ? null : summary.totalVisits.toLocaleString()} />
          <Kpi icon="Clock" label="Avg days in shop" value={loading ? null : `${summary.avgDays}d`} />
          <Kpi icon="Activity" label="Currently in shop" value={loading ? null : summary.inShop} tone={summary.inShop ? 'amber' : 'slate'} />
        </div>

        {/* Analytics — the whole window, before the search narrows the table below. */}
        {!loading && items.length > 0 && <MaintenanceHistoryAnalytics items={items} />}

        {/* Search */}
        <div className="relative max-w-xs">
          <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search plate or model…"
            className="w-full rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        {loading ? (
          <Skeleton className="h-96 rounded-2xl" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">No workshop visits in this window</p>
            <p className="text-xs text-slate-400">Try a wider time range.</p>
          </div>
        ) : (
          <div className="overflow-x-auto rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <table className="w-full min-w-[760px] border-separate border-spacing-0 text-sm">
              <thead>
                <tr className="text-start">
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicle</th>
                  <SortHead label="Visits" sortKey="visits" align="right" />
                  <SortHead label="Time in shop" sortKey="days_in_shop" align="right" />
                  <SortHead label="First visit" sortKey="first_visit" />
                  <SortHead label="Last visit" sortKey="last_visit" />
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
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
                        {plural(r.visits, 'visit')}
                      </span>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-end font-semibold tabular-nums text-slate-700">
                      {r.days_in_shop == null ? <span className="text-slate-300">—</span> : plural(r.days_in_shop, 'day')}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(r.first_visit)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(r.last_visit)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        {r.currently_in_shop
                          ? <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"><span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> In shop</span>
                          : <span className="text-xs text-slate-400">Returned</span>}
                        <button
                          onClick={() => toggleVisits(r.id)}
                          className={`ms-auto inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${isOpen ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-600'}`}
                          aria-expanded={isOpen}
                        >
                          See {plural(r.visits, 'visit')}
                          <Icon.ChevronDown className={`h-3 w-3 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                        </button>
                      </div>
                    </td>
                  </tr>
                  {isOpen && (
                    <tr>
                      <td colSpan={6} className="border-b border-slate-200 bg-slate-50/60 px-5 py-4">
                        <VisitList detail={vd} />
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
      </div>
    </div>
  );
}

// The "see N visits" drill-down: each individual workshop trip for one car within the window.
function VisitList({ detail }) {
  if (!detail || detail.loading) {
    return <div className="space-y-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-9 rounded-lg" />)}</div>;
  }
  if (detail.error) {
    return <p className="text-sm text-rose-600">Couldn’t load this car’s visits. Please try again.</p>;
  }
  if (!detail.items.length) {
    return <p className="text-sm text-slate-400">No individual visits recorded in this window.</p>;
  }
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-start text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            <th className="px-4 py-2">Went in</th>
            <th className="px-4 py-2">Came back</th>
            <th className="px-4 py-2 text-end">Days</th>
            <th className="px-4 py-2">Garage</th>
            <th className="px-4 py-2">What was done</th>
          </tr>
        </thead>
        <tbody>
          {detail.items.map((v, i) => (
            <tr key={`${v.out_date}-${i}`} className="border-t border-slate-100">
              <td className="whitespace-nowrap px-4 py-2 font-medium text-slate-700">{fmtDate(v.out_date)}</td>
              <td className="whitespace-nowrap px-4 py-2 text-slate-500">
                {v.returned ? fmtDate(v.in_date) : <span className="inline-flex items-center gap-1 text-amber-600"><span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> Still in</span>}
              </td>
              <td className="whitespace-nowrap px-4 py-2 text-end tabular-nums text-slate-600">{v.days == null ? '—' : plural(v.days, 'day')}</td>
              <td className="px-4 py-2 text-slate-600">{v.garage || <span className="text-slate-300">—</span>}</td>
              <td className="px-4 py-2 text-slate-600">{v.issue || v.notes || <span className="text-slate-300">—</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
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
