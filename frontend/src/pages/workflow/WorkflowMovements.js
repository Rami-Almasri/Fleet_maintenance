import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader } from '../../components/ui/Misc';
import { SectionCard } from '../../components/ui/Table';
import Icon from '../../components/ui/Icon';
import { num, fmtClock, fmtAgo } from '../../lib/format';

// A stage-driven timeline of every step a car takes through the workflow — inspections, tickets,
// dispatch to the garage, repairs, movements, readiness — newest first, grouped by day. Each row is
// headlined by its WORKFLOW STAGE NAME (Check-out, Garage Arrival, Ready for Rent…) exactly as the
// backend feed emits it, so the trail reads like the workflow itself rather than a table of raw events.
// Click any car to narrow the feed to that one car's followup record.

// The time window the feed covers. "All" reaches back far enough to mean everything the trail holds.
const WINDOWS = [
  { key: '24h', label: '24 hours', from: () => new Date(Date.now() - 864e5) },
  { key: '7d',  label: '7 days',   from: () => new Date(Date.now() - 7 * 864e5) },
  { key: '30d', label: '30 days',  from: () => new Date(Date.now() - 30 * 864e5) },
  { key: 'all', label: 'All time', from: () => new Date('2000-01-01') },
];

// The category buckets the backend feed exposes (ActivityFeedService::CATEGORIES). "All" clears the filter.
const CATEGORIES = [
  { key: '',           label: 'All' },
  { key: 'maintenance',label: 'Maintenance' },
  { key: 'movement',   label: 'Movement' },
  { key: 'inspection', label: 'Inspection' },
  { key: 'readiness',  label: 'Readiness' },
  { key: 'condition',  label: 'Condition' },
  { key: 'cleaning',   label: 'Cleaning' },
];

// The workflow STAGE NAMES the backend emits (ActivityFeedService::LOG_STAGE / LOGISTICS_STAGE +
// the inspection stages) → the icon + colour that headlines each timeline node. This is the single
// place the feed's look is tied to the workflow vocabulary: add a stage here and it themes itself.
const STAGE_META = {
  'Test Drive':          { icon: Icon.Gauge,  tone: 'cyan' },
  'Fault Reported':      { icon: Icon.Alert,  tone: 'amber' },
  'Garage Assigned':     { icon: Icon.Wrench, tone: 'indigo' },
  'Dispatch Requested':  { icon: Icon.Flag,   tone: 'indigo' },
  'Driver Assigned':     { icon: Icon.Users,  tone: 'blue' },
  'Check-out':           { icon: Icon.Truck,  tone: 'violet' },
  'Garage Arrival':      { icon: Icon.Route,  tone: 'violet' },
  'Repair Complete':     { icon: Icon.Check,  tone: 'emerald' },
  'Back in Service':     { icon: Icon.Car,    tone: 'emerald' },
  'Ready for Rent':      { icon: Icon.Shield, tone: 'emerald' },
  'Check-in':            { icon: Icon.Route,  tone: 'violet' },
  'Location Update':     { icon: Icon.Route,  tone: 'slate' },
  'Reassigned':          { icon: Icon.Refresh,tone: 'slate' },
  'Move Cancelled':      { icon: Icon.XCircle,tone: 'red' },
  'Re-inspection Failed':{ icon: Icon.XCircle,tone: 'red' },
  'Pre-rental Check':    { icon: Icon.Shield, tone: 'blue' },
  'Return Check':        { icon: Icon.Shield, tone: 'blue' },
};

// Fallback theming for a stage-less admin row (cost recorded, invoice, reclassify…) — keyed by the
// event's category so it still carries the right accent colour and a sensible icon.
const CATEGORY_META = {
  maintenance: { icon: Icon.Wrench,   tone: 'indigo' },
  movement:    { icon: Icon.Truck,    tone: 'violet' },
  inspection:  { icon: Icon.Shield,   tone: 'blue' },
  readiness:   { icon: Icon.Check,    tone: 'emerald' },
  condition:   { icon: Icon.Alert,    tone: 'amber' },
  cleaning:    { icon: Icon.Spark,    tone: 'cyan' },
};

// tone → the timeline node's ring/background + text colour. Kept local so the rail nodes read boldly
// (a solid tinted disc) rather than reusing the softer inline Badge palette.
const NODE_TONE = {
  cyan:    { ring: 'bg-cyan-100 text-cyan-700',       rail: 'bg-cyan-300' },
  amber:   { ring: 'bg-amber-100 text-amber-700',     rail: 'bg-amber-300' },
  indigo:  { ring: 'bg-indigo-100 text-indigo-700',   rail: 'bg-indigo-300' },
  blue:    { ring: 'bg-blue-100 text-blue-700',       rail: 'bg-blue-300' },
  violet:  { ring: 'bg-violet-100 text-violet-700',   rail: 'bg-violet-300' },
  emerald: { ring: 'bg-emerald-100 text-emerald-700', rail: 'bg-emerald-300' },
  red:     { ring: 'bg-red-100 text-red-700',         rail: 'bg-red-300' },
  slate:   { ring: 'bg-slate-100 text-slate-500',     rail: 'bg-slate-300' },
};

const metaFor = (e) => STAGE_META[e.stage] || CATEGORY_META[e.category] || { icon: Icon.Activity, tone: 'slate' };

// "Today" / "Yesterday" / "Mon 8 Jul" heading for a day group.
function dayLabel(iso) {
  const d = new Date(iso);
  const today = new Date();
  const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((startOf(today) - startOf(d)) / 864e5);
  if (diff === 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}

function FilterChips({ options, value, onChange }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {options.map((o) => {
        const on = value === o.key;
        return (
          <button
            key={o.key || 'all'}
            type="button"
            onClick={() => onChange(o.key)}
            className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
              on ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500 hover:text-slate-700'
            }`}
          >
            {o.label}
          </button>
        );
      })}
    </div>
  );
}

// One little count tile for the summary strip.
function Stat({ label, value, icon: I }) {
  return (
    <div className="flex items-center gap-3 rounded-xl border border-slate-200/70 bg-white px-4 py-3">
      {I && (
        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
          <I className="h-5 w-5" />
        </span>
      )}
      <div>
        <div className="text-lg font-bold leading-none text-slate-900">{num(value)}</div>
        <div className="mt-1 text-xs font-medium text-slate-500">{label}</div>
      </div>
    </div>
  );
}

// A single timeline node: the stage-coloured icon disc on the rail + the event's headline and detail.
function TimelineRow({ e, onFollow, last }) {
  const { icon: I, tone } = metaFor(e);
  const nt = NODE_TONE[tone] || NODE_TONE.slate;
  // The headline is the WORKFLOW STAGE NAME whenever the event carries one; a stage-less admin row
  // falls back to its precise action label.
  const headline = e.stage || e.action;

  return (
    <div className="relative flex gap-4 pb-6 last:pb-0">
      {/* Rail + node */}
      <div className="relative flex flex-col items-center">
        <span className={`z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${nt.ring}`}>
          <I className="h-5 w-5" />
        </span>
        {!last && <span className={`absolute top-9 h-full w-px ${nt.rail}`} />}
      </div>

      {/* Body */}
      <div className="min-w-0 flex-1 pt-0.5">
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <span className="font-semibold text-slate-900">{headline}</span>
          {/* First-class stage transition, e.g. "Check-out → Garage Arrival". */}
          {e.transition ? (
            <span className="font-mono text-xs text-slate-500">{e.transition}</span>
          ) : (
            e.stage && e.action && e.action !== e.stage && (
              <span className="text-xs text-slate-500">{e.action}</span>
            )
          )}
          <span className="ml-auto whitespace-nowrap text-xs text-slate-400" title={fmtAgo(e.occurred_at)}>
            {fmtClock(e.occurred_at)}
          </span>
        </div>

        {/* Vehicle + actor line */}
        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
          {e.vehicle_id ? (
            <button
              type="button"
              onClick={() => onFollow(e)}
              className="font-semibold text-indigo-600 hover:text-indigo-700"
              title="Follow this car"
            >
              {e.plate || `#${e.vehicle_id}`}
            </button>
          ) : (
            <span className="text-slate-400">Fleet</span>
          )}
          {e.model && <span className="text-xs text-slate-400">{e.model}</span>}
          <span className="text-xs text-slate-400">· by {e.actor_name || 'System'}</span>
        </div>

        {/* Detail line */}
        {(e.description || e.odometer != null || e.contract_no) && (
          <div className="mt-1 text-sm text-slate-600">
            {e.description}
            <span className="text-xs text-slate-400">
              {e.odometer != null && <> · {num(e.odometer)} km</>}
              {e.contract_no && (
                <> · <Link to={`/contracts/${e.contract_id}`} className="text-indigo-500 hover:text-indigo-600">Contract {e.contract_no}</Link></>
              )}
            </span>
          </div>
        )}
      </div>
    </div>
  );
}

export default function WorkflowMovements() {
  const [windowKey, setWindowKey] = useState('7d');
  const [category, setCategory] = useState('');
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState(null); // { id, plate, model } — the followed car, or null for the whole fleet

  const win = WINDOWS.find((w) => w.key === windowKey) || WINDOWS[1];

  const fetcher = useCallback(async () => {
    const params = { from: win.from().toISOString(), limit: 200 };
    if (category) params.category = category;
    if (selected) params.vehicle_id = selected.id;
    const { data } = await api.get('/Activity', { params });
    return data.data;
  }, [win, category, selected]);

  const { data, loading, error } = useFetch(fetcher, [windowKey, category, selected?.id], { refreshInterval: 60000 });

  const events = useMemo(() => data?.events || [], [data]);
  const summary = data?.summary || {};

  // Free-text search runs client-side over the fetched window, so typing doesn't re-hit the server.
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return events;
    return events.filter((e) =>
      [e.plate, e.model, e.action, e.stage, e.description, e.actor_name]
        .filter(Boolean)
        .some((s) => String(s).toLowerCase().includes(needle)),
    );
  }, [events, q]);

  // Bucket the (already newest-first) rows into day groups for the timeline headings.
  const groups = useMemo(() => {
    const out = [];
    let cur = null;
    for (const e of rows) {
      const key = (e.occurred_at || '').slice(0, 10);
      if (!cur || cur.key !== key) {
        cur = { key, label: dayLabel(e.occurred_at), events: [] };
        out.push(cur);
      }
      cur.events.push(e);
    }
    return out;
  }, [rows]);

  const follow = (e) => {
    if (!e.vehicle_id) return;
    setSelected({ id: e.vehicle_id, plate: e.plate, model: e.model });
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Workflow Journey"
          subtitle="Every step each car takes through the workflow — inspection, dispatch, garage arrival, repair, movement and readiness — as one stage-by-stage timeline, newest first. Click a car to follow just its journey."
        />

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {/* Summary strip */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:max-w-2xl">
          <Stat label="Stage events" value={summary.total || 0} icon={Icon.Activity} />
          <Stat label="Last 24h" value={summary.last_24h || 0} icon={Icon.Clock} />
          <Stat label="Cars involved" value={summary.vehicles || 0} icon={Icon.Car} />
        </div>

        <SectionCard
          title={selected ? `${selected.plate || 'Vehicle'} · Journey` : 'Fleet workflow journey'}
          subtitle={selected ? (selected.model || 'Every stage this car passed through, newest first.') : 'Every car — each stage as it happened.'}
          actions={
            selected ? (
              <button
                type="button"
                onClick={() => setSelected(null)}
                className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
              >
                <Icon.ArrowRight className="h-3.5 w-3.5 rotate-180" /> Back to whole fleet
              </button>
            ) : null
          }
        >
          {/* Controls */}
          <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <FilterChips options={WINDOWS} value={windowKey} onChange={setWindowKey} />
              <div className="relative">
                <Icon.Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Search plate, stage, person…"
                  className="w-64 rounded-full border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
              </div>
            </div>
            <FilterChips options={CATEGORIES} value={category} onChange={setCategory} />
          </div>

          {/* Timeline */}
          <div className="px-5 py-5">
            {loading && !events.length ? (
              <div className="space-y-4">
                {[0, 1, 2, 3].map((i) => (
                  <div key={i} className="flex animate-pulse gap-4">
                    <span className="h-9 w-9 shrink-0 rounded-full bg-slate-100" />
                    <div className="flex-1 space-y-2 pt-1">
                      <div className="h-3 w-40 rounded bg-slate-100" />
                      <div className="h-3 w-64 rounded bg-slate-100" />
                    </div>
                  </div>
                ))}
              </div>
            ) : groups.length === 0 ? (
              <div className="py-10 text-center text-sm text-slate-400">
                {selected ? 'No workflow steps recorded for this car in this window.' : 'No workflow steps in this window.'}
              </div>
            ) : (
              <div className="space-y-6">
                {groups.map((g) => (
                  <div key={g.key}>
                    <div className="mb-3 flex items-center gap-3">
                      <span className="text-xs font-bold uppercase tracking-wide text-slate-400">{g.label}</span>
                      <span className="h-px flex-1 bg-slate-100" />
                      <span className="text-xs text-slate-300">{g.events.length}</span>
                    </div>
                    <div>
                      {g.events.map((e, i) => (
                        <TimelineRow key={e.id} e={e} onFollow={follow} last={i === g.events.length - 1} />
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </SectionCard>

        <p className="px-1 text-xs leading-relaxed text-slate-400">
          <span className="font-semibold text-slate-500">Data origin:</span> a single read over three append-only trails —
          the workflow/readiness log, vehicle movements and inspection captures — merged newest-first and headlined by the
          workflow stage each event reached. Nothing here can be edited.
        </p>
      </div>
    </div>
  );
}
