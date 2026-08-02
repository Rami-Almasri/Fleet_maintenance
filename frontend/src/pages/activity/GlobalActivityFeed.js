import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader, Card } from '../../components/ui/Misc';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import { SectionCard } from '../../components/ui/Table';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import FilterChips from '../../components/ui/FilterChips';
import Icon from '../../components/ui/Icon';
import { num } from '../../lib/format';
import ActivityTimeline, { CATEGORY_META, CATEGORY_KEYS } from '../../components/activity/ActivityTimeline';

// Rolling-window presets. 'all' reaches back far enough to mean "everything" (the API otherwise
// defaults an empty window to the last 7 days).
const WINDOWS = [
  { key: '24h', label: 'Last 24h', days: 1 },
  { key: '7d', label: '7 days', days: 7 },
  { key: '30d', label: '30 days', days: 30 },
  { key: 'all', label: 'All time', days: 3650 },
];

const isoFrom = (days) => {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString();
};

export default function GlobalActivityFeed() {
  const [category, setCategory] = useState('all');
  const [win, setWin] = useState('7d');
  const [q, setQ] = useState('');
  const [limit, setLimit] = useState(60);
  // 'story' rolls a car's burst of events into one Maintenance-Session card; 'detailed' is the raw,
  // one-row-per-event audit dump. Story is the default — the raw trail is always one click away.
  const [view, setView] = useState('story');

  const from = useMemo(() => isoFrom((WINDOWS.find((w) => w.key === win) || WINDOWS[1]).days), [win]);

  const fetcher = useCallback(async () => {
    const params = { from, limit };
    if (category !== 'all') params.category = category;
    if (q.trim()) params.q = q.trim();
    const { data } = await api.get('/Activity', { params });
    return data.data;
  }, [from, limit, category, q]);

  const { data, loading, error } = useFetch(fetcher, [from, limit, category, q], { refreshInterval: 30000 });

  const events = data?.events || [];
  const summary = useMemo(() => data?.summary || { total: 0, last_24h: 0, vehicles: 0, by_category: {} }, [data]);

  const busiest = useMemo(() => {
    const entries = Object.entries(summary.by_category || {});
    if (!entries.length) return null;
    const [key, count] = entries.sort((a, b) => b[1] - a[1])[0];
    return { key, count, label: CATEGORY_META[key]?.label || key };
  }, [summary]);

  const categoryOptions = useMemo(() => ([
    { key: 'all', label: 'All actions', count: summary.total },
    ...CATEGORY_KEYS.map((k) => ({
      key: k,
      label: CATEGORY_META[k].label,
      count: summary.by_category?.[k] || 0,
      tone: CATEGORY_META[k].tone,
    })),
  ]), [summary]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Activity Feed"
          subtitle="Every action on every car, told as a story — a car's burst of work rolls into one Maintenance Session, with movements (Check-in / Check-out) as the headline. Switch to Detailed for the raw, immutable trail."
        >
          <div className="flex flex-wrap items-center gap-2">
            {WINDOWS.map((w) => (
              <button
                key={w.key}
                type="button"
                onClick={() => setWin(w.key)}
                className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${win === w.key ? 'bg-indigo-600 text-white shadow-soft' : 'bg-white text-slate-500 ring-1 ring-inset ring-slate-200 hover:text-slate-700'}`}
              >
                {w.label}
              </button>
            ))}
          </div>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard label="Events in window" value={num(summary.total)} tone="indigo" icon={<Icon.Activity className="h-5 w-5" />} hint="Logged actions in the selected period" />
            <MetricCard label="Last 24 hours" value={num(summary.last_24h)} tone="emerald" icon={<Icon.Clock className="h-5 w-5" />} hint="Actions in the past day" />
            <MetricCard label="Vehicles touched" value={num(summary.vehicles)} tone="blue" icon={<Icon.Car className="h-5 w-5" />} hint="Distinct cars with activity" />
            <MetricCard label="Busiest category" value={busiest ? busiest.label : '—'} tone={busiest ? CATEGORY_META[busiest.key]?.tone : 'slate'} icon={<Icon.Chart className="h-5 w-5" />} hint={busiest ? `${num(busiest.count)} events` : 'No activity yet'} />
          </MetricGrid>
        )}

        <SectionCard
          title="Activity trail"
          subtitle="Filter by action type, then narrow with the time window or search."
          actions={
            <div className="flex items-center gap-3">
              <div className="inline-flex rounded-full bg-slate-100 p-0.5 text-xs font-semibold">
                {[{ key: 'story', label: 'Story' }, { key: 'detailed', label: 'Detailed' }].map((v) => (
                  <button
                    key={v.key}
                    type="button"
                    onClick={() => setView(v.key)}
                    className={`rounded-full px-3 py-1 transition ${view === v.key ? 'bg-white text-indigo-600 shadow-soft' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    {v.label}
                  </button>
                ))}
              </div>
              <span className="text-xs text-slate-400">{num(events.length)} shown{data?.has_more ? ' · more available' : ''}</span>
            </div>
          }
        >
          <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <FilterChips value={category} onChange={(k) => { setCategory(k); setLimit(60); }} options={categoryOptions} />
              <div className="relative sm:w-64">
                <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => { setQ(e.target.value); setLimit(60); }}
                  placeholder="Search action, note, user, plate…"
                  className="w-full rounded-full border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
              </div>
            </div>

            {loading && !events.length ? (
              <div className="flex justify-center py-16"><Icon.Refresh className="h-6 w-6 animate-spin text-slate-300" /></div>
            ) : (
              <ActivityTimeline
                events={events}
                showVehicle
                group={view === 'story'}
                emptyMessage={q || category !== 'all' ? 'No activity matches these filters in this window.' : 'No activity recorded in this window yet.'}
              />
            )}

            {data?.has_more && limit < 200 && (
              <div className="flex justify-center pt-2">
                <button
                  type="button"
                  onClick={() => setLimit((l) => Math.min(200, l + 60))}
                  className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm font-medium text-indigo-600 shadow-soft transition hover:border-slate-300 hover:text-indigo-700"
                >
                  Load more <Icon.ArrowRight className="h-4 w-4" />
                </button>
              </div>
            )}
          </div>
        </SectionCard>

        <Card className="!bg-slate-50/60">
          <p className="px-4 py-3 text-xs leading-relaxed text-slate-400">
            <span className="font-semibold text-slate-500">Data origin:</span> one unified read over three append-only trails —
            the vehicle event log (workflow, readiness, condition, cleaning), the logistics movement log, and inspection records.
            Every row carries who acted and when; nothing here can be edited. Open a car from any event to see its full{' '}
            <Link to="/vehicles" className="font-medium text-indigo-500 hover:text-indigo-600">vehicle timeline</Link>.
          </p>
        </Card>
      </div>
    </div>
  );
}
