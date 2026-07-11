import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { Select } from '../components/ui/Field';

// Relative "x ago" for a timestamp (kept tiny — no date lib). Mirrors LogisticsDispatch.
function ago(iso) {
  if (!iso) return '';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.round(hrs / 24)}d ago`;
}

const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

// The icon for what a busy person is doing.
function ActivityIcon({ kind, className }) {
  if (kind === 'maintenance') return <Icon.Wrench className={className} />;
  if (kind === 'inspection') return <Icon.Search className={className} />;
  return <Icon.Truck className={className} />;
}

// One person: avatar + name + role, an Available/Busy chip, and — when busy — exactly what they're on
// (the job, the car, how long, and their last "where is it" reply). When free, an explicit reason too.
function PersonCard({ p }) {
  const busy = p.status === 'busy';
  const a = p.activity;
  return (
    <div className={`flex items-start gap-3 rounded-xl border p-3 transition ${busy ? 'border-amber-200 bg-amber-50/40' : 'border-emerald-200 bg-emerald-50/40'}`}>
      <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold ${busy ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
        {initialsOf(p.name)}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <span className="truncate text-sm font-semibold text-slate-800">{p.name}</span>
          <Badge tone={busy ? 'amber' : 'emerald'}>{busy ? 'Busy' : 'Available'}</Badge>
        </div>
        <div className="mt-0.5">
          <Badge tone={p.role_tone || 'gray'}>{p.role}</Badge>
        </div>
        {busy && a ? (
          <p className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-slate-500">
            <ActivityIcon kind={a.kind} className="h-3 w-3 shrink-0 text-slate-400" />
            <span className="font-medium text-slate-600">{a.label}</span>
            {a.vehicle && <span className="font-mono text-slate-500">· {a.vehicle}</span>}
            {a.since && <span className="text-slate-400">· {ago(a.since)}</span>}
            {a.count > 1 && <span className="text-amber-600">· +{a.count - 1} more</span>}
            {a.last_status && <span className="w-full text-slate-400">↳ “{a.last_status}”</span>}
          </p>
        ) : (
          <p className="mt-1.5 text-xs font-medium text-emerald-600">Free — ready for the next job</p>
        )}
      </div>
    </div>
  );
}

export default function TeamPresence() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/team/presence');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const team = useMemo(() => data?.team || [], [data]);
  const summary = data?.summary || { total: 0, available: 0, busy: 0 };

  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');

  // Role filter options, derived from who's actually on the team.
  const roleOptions = useMemo(() => {
    const seen = new Map();
    team.forEach((p) => { if (!seen.has(p.role_key)) seen.set(p.role_key, p.role); });
    return [...seen.entries()].map(([key, label]) => ({ key, label }));
  }, [team]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return team.filter((p) => {
      const matchSearch = !q || [p.name, p.role, p.activity?.label, p.activity?.vehicle].some((f) => (f || '').toLowerCase().includes(q));
      const matchRole = !role || p.role_key === role;
      const matchStatus = !status || p.status === status;
      return matchSearch && matchRole && matchStatus;
    });
  }, [team, search, role, status]);

  // Show the busy people first — that's who a coordinator is reasoning about.
  const available = filtered.filter((p) => p.status === 'available');
  const busy = filtered.filter((p) => p.status === 'busy');

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Who's Where"
          subtitle={loading ? 'Loading…' : `${summary.total} on the team · live availability`}
        >
          <button
            onClick={reload}
            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-600 shadow-sm transition hover:bg-gray-50"
          >
            <Icon.Clock className="h-4 w-4" />
            Refresh
          </button>
        </PageHeader>

        {loading ? (
          <MetricGridSkeleton count={3} />
        ) : (
          <MetricGrid cols={3}>
            <MetricCard label="On the team" value={summary.total} tone="slate" icon={<Icon.Users className="h-5 w-5" />} />
            <MetricCard
              label="Available now"
              value={summary.available}
              tone="emerald"
              hint="Free to take the next job"
            />
            <MetricCard
              label="Busy"
              value={summary.busy}
              tone="amber"
              hint="On a move, pickup or inspection"
            />
          </MetricGrid>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={setSearch} placeholder="Search name, role, car or activity…" />
          <Select className="sm:w-48" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="">All roles</option>
            {roleOptions.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
          </Select>
          <Select className="sm:w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Everyone</option>
            <option value="available">Available</option>
            <option value="busy">Busy</option>
          </Select>
        </div>

        {!loading && filtered.length === 0 && (
          <SectionCard title="Team">
            <div className="px-6 py-12 text-center text-sm text-gray-500">No one matches this filter.</div>
          </SectionCard>
        )}

        {busy.length > 0 && (
          <SectionCard title="Busy" subtitle={`${busy.length} ${busy.length === 1 ? 'person is' : 'people are'} on a job right now`}>
            <div className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
              {busy.map((p) => <PersonCard key={p.id} p={p} />)}
            </div>
          </SectionCard>
        )}

        {available.length > 0 && (
          <SectionCard title="Available" subtitle={`${available.length} free`}>
            <div className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
              {available.map((p) => <PersonCard key={p.id} p={p} />)}
            </div>
          </SectionCard>
        )}
      </div>
    </div>
  );
}
