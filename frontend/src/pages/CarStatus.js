import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { SearchInput, ErrorState, EmptyState } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import Segmented from '../components/ui/Segmented';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import CarStatusAnalytics from '../components/analytics/CarStatusAnalytics';
import OpsCard, { ProgressSpine } from '../components/workflow/OpsCard';
import VehicleOpsDrawer from '../components/workflow/VehicleOpsDrawer';
import { ATTENTION_FILTERS, tone, ROLE, urgency, days } from '../components/workflow/opsMeta';
import { num } from '../lib/format';

// ─────────────────────────────────────────────────────────────────────────────────────────────
// Car Status — the maintenance OPERATIONS DASHBOARD.
//
// This page used to answer "where is this vehicle in the workflow?". It now answers the question an
// Operations or Maintenance Manager actually has: "WHAT IS HAPPENING TO THIS VEHICLE RIGHT NOW?"
//
// Every card carries, without opening the vehicle:
//   why it came in · what's happening now · what's blocking it · the checkpoint reached ·
//   who is responsible · how long it's been here vs. its promised date · whether it needs escalating
//
// All of that is computed server-side (MaintenanceOpsCardService → the ticket's `ops` block), so this
// page renders one agreed truth rather than deriving a second opinion. Two views share that data:
//
//   Operations (default) — every car as a full operations card, sorted by how loudly it needs attention
//   Stage board          — the classic pipeline lanes, for "where is everything" at a glance
//
// See [[car-status-command-center]] and [[traceability-visibility-requirement]].
// ─────────────────────────────────────────────────────────────────────────────────────────────

// The stage lanes, for the board view. `role` is who owns the stage; the ops block owns everything else.
const STAGES = [
  { key: 'requested',        name: 'Needs Test Drive',   role: 'inspector',  tone: '#d946ef' },
  { key: 'diagnostic',       name: 'Being Inspected',    role: 'inspector',  tone: '#8b5cf6' },
  { key: 'pending',          name: 'Needs Dispatch',     role: 'supervisor', tone: '#a855f7' },
  { key: 'awaiting_pickup',  name: 'Awaiting Pickup',    role: 'driver',     tone: '#f59e0b' },
  { key: 'in_transit',       name: 'En Route to Garage', role: 'driver',     tone: '#f59e0b' },
  { key: 'under_repair',     name: 'In Workshop',        role: 'garage',     tone: '#f97316' },
  { key: 'ready_for_pickup', name: 'Ready for Pickup',   role: 'driver',     tone: '#10b981' },
  { key: 'qa_reinspection',  name: 'Final QA',           role: 'inspector',  tone: '#9333ea' },
];

// Exception lanes — rendered only when they hold cars, so nothing is ever hidden but the board stays
// focused on the eight-step happy path the rest of the time.
const EXCEPTION_STAGES = [
  { key: 'triage',                  name: 'Complaint Triage',      role: 'inspector',  tone: '#f43f5e' },
  { key: 'on_site',                 name: 'On-Site Service',       role: 'inspector',  tone: '#0d9488' },
  { key: 'repair_review',           name: 'Video Review',          role: 'supervisor', tone: '#7c3aed' },
  { key: 'reinspection_failed',     name: 'Sent Back — QA Failed', role: 'supervisor', tone: '#dc2626' },
  { key: 'paused',                  name: 'Paused',                role: 'none',       tone: '#64748b' },
  { key: 'returned_waiting_resume', name: 'Returned — Resume Due', role: 'none',       tone: '#f97316' },
];

export default function CarStatus() {
  const [q, setQ] = useState('');
  const [view, setView] = useState('operations');
  const [filter, setFilter] = useState('all');
  const [openTicket, setOpenTicket] = useState(null);

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  // Polling pauses while the drawer is open so the page never shifts under a manager mid-read.
  const { data, loading, error, reload, validating } = useFetch(fetcher, [], {
    refreshInterval: 15000,
    paused: () => !!openTicket,
  });

  const columns = useMemo(() => data?.columns || {}, [data]);
  const counts = data?.counts || {};

  // Every open ticket, once — the operations view's own list, and what the KPI tiles count.
  const allTickets = useMemo(() => {
    const seen = new Set();
    return [...STAGES, ...EXCEPTION_STAGES].flatMap((s) =>
      (columns[s.key] || [])
        .filter((tk) => !seen.has(tk.id) && seen.add(tk.id))
        .map((tk) => ({ ...tk, lane: s })),
    );
  }, [columns]);

  const needle = q.trim().toLowerCase();
  const matches = useCallback((tk) => {
    if (!needle) return true;
    return [
      tk.plate, tk.car, tk.garage, tk.assigned_driver_name, tk.dispatched_by_name,
      tk.ops?.reason?.primary, tk.ops?.state?.label, tk.ops?.blocker?.label,
      ...(tk.ops?.parts || []).map((p) => p.name),
    ].filter(Boolean).some((x) => String(x).toLowerCase().includes(needle));
  }, [needle]);

  // Attention filter counts — computed over the search-matched set, so a chip's number always equals
  // the number of cards clicking it reveals.
  const searched = useMemo(() => allTickets.filter(matches), [allTickets, matches]);
  const filterCounts = useMemo(() => {
    const out = {};
    ATTENTION_FILTERS.forEach((f) => { out[f.key] = searched.filter((tk) => f.test(tk.ops)).length; });
    return out;
  }, [searched]);

  const activeFilter = ATTENTION_FILTERS.find((f) => f.key === filter) || ATTENTION_FILTERS[0];
  const opsTickets = useMemo(
    () => searched.filter((tk) => activeFilter.test(tk.ops)).sort((a, b) => urgency(b) - urgency(a)),
    [searched, activeFilter],
  );

  // Board lanes — the same tickets, bucketed by stage, honouring search + the attention filter.
  const laneFilter = useCallback((tk) => matches(tk) && activeFilter.test(tk.ops), [matches, activeFilter]);
  const primaryLanes = useMemo(
    () => STAGES.map((s) => ({ ...s, tickets: (columns[s.key] || []).filter(laneFilter) })),
    [columns, laneFilter],
  );
  const exceptionLanes = useMemo(
    () => EXCEPTION_STAGES
      .map((s) => ({ ...s, tickets: (columns[s.key] || []).filter(laneFilter) }))
      .filter((s) => s.tickets.length > 0),
    [columns, laneFilter],
  );
  const lanes = useMemo(() => [...primaryLanes, ...exceptionLanes], [primaryLanes, exceptionLanes]);

  const openTotal = counts.open_total ?? allTickets.length;

  return (
    <div className="pb-10">
      {/* Hero — the fleet's maintenance position in four numbers. */}
      <div className="relative overflow-hidden bg-navy-900 text-white">
        <div className="pointer-events-none absolute inset-0" aria-hidden="true">
          <div className="absolute -end-24 -top-32 h-96 w-96 rounded-full bg-brand-500/25 blur-3xl" />
          <div className="absolute -bottom-40 start-10 h-80 w-80 rounded-full bg-violet-500/10 blur-3xl" />
        </div>
        <div className="relative mx-auto max-w-[1700px] px-4 pb-10 pt-8 sm:px-6 lg:px-8">
          <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div className="min-w-0">
              <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-steel-300">
                <span className="relative flex h-2 w-2">
                  <span className={`absolute inline-flex h-full w-full rounded-full bg-emerald-400 ${validating ? 'animate-ping' : 'opacity-75'}`} />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
                </span>
                Live · Maintenance Operations
              </div>
              <h1 className="mt-2 font-display text-3xl font-bold tracking-tight sm:text-4xl">Car Status</h1>
              <p className="mt-2 max-w-2xl text-sm text-steel-200">
                What is happening to every car in maintenance right now — why it came in, what stage the
                repair has actually reached, what is blocking it, and who is on the hook.
              </p>
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-3">
              <HeroStat label="In the pipeline" value={openTotal} />
              <HeroStat label="ETA overdue" value={filterCounts.overdue} tone="text-red-300" />
              <HeroStat label="No updates" value={filterCounts.no_update} tone="text-amber-300" />
              <button
                type="button"
                onClick={() => reload()}
                className="focus-ring-self inline-flex items-center gap-2 rounded-xl bg-white/10 px-3.5 py-2 text-sm font-semibold text-white ring-1 ring-inset ring-white/15 transition hover:bg-white/15"
              >
                <Icon.Refresh className={`h-4 w-4 ${validating ? 'animate-spin' : ''}`} /> Refresh
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="mx-auto -mt-5 max-w-[1700px] space-y-5 px-4 sm:px-6 lg:px-8">
        {error && <ErrorState onRetry={reload} message={error} />}

        {/* Control bar — search, the attention filters, and the view switch. */}
        <div className="rounded-2xl border border-slate-200/70 bg-white/80 p-3 shadow-soft backdrop-blur">
          <div className="flex flex-wrap items-center gap-3">
            <SearchInput
              value={q}
              onChange={setQ}
              placeholder="Plate, car, reason, garage, part…"
              className="w-full max-w-xs"
            />
            <div className="flex flex-wrap items-center gap-1.5">
              {ATTENTION_FILTERS.map((f) => {
                const on = f.key === filter;
                const n = filterCounts[f.key] ?? 0;
                return (
                  <button
                    key={f.key}
                    type="button"
                    onClick={() => setFilter(f.key)}
                    disabled={n === 0 && f.key !== 'all'}
                    className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-40 ${
                      on ? `${tone(f.tone).chip} shadow-sm` : 'bg-white text-slate-500 ring-slate-200 hover:text-slate-700'
                    }`}
                  >
                    {f.label}
                    <span className="tabular-nums opacity-70">{n}</span>
                  </button>
                );
              })}
            </div>
            <div className="ms-auto flex items-center gap-3">
              <span className="text-xs font-medium text-slate-400">
                <span className="tabular-nums text-slate-600">{num(opsTickets.length)}</span> shown
              </span>
              <Segmented
                value={view}
                onChange={setView}
                options={[{ key: 'operations', label: 'Operations' }, { key: 'board', label: 'Stage board' }]}
              />
            </div>
          </div>
        </div>

        {/* Pipeline shape — kept above both views so the numbers frame whatever is below. */}
        {!loading && !error && allTickets.length > 0 && <CarStatusAnalytics lanes={lanes} />}

        {loading ? (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            {[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="h-96 animate-pulse rounded-2xl bg-white" />)}
          </div>
        ) : openTotal === 0 ? (
          <SectionCard>
            <EmptyState
              icon={<Icon.Wrench className="h-6 w-6" />}
              title="Pipeline is clear"
              message="No car is in the maintenance workflow right now."
            />
          </SectionCard>
        ) : view === 'operations' ? (
          opsTickets.length === 0 ? (
            <SectionCard>
              <EmptyState
                icon={<Icon.Check className="h-6 w-6" />}
                title="Nothing matches"
                message="No car in the pipeline matches this search and filter."
              />
            </SectionCard>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {opsTickets.map((tk) => (
                <OpsCard key={tk.id} tk={tk} onOpen={() => setOpenTicket(tk.id)} />
              ))}
            </div>
          )
        ) : (
          <div className="flex gap-4 overflow-x-auto pb-4">
            {lanes.map((lane) => (
              <Lane key={lane.key} lane={lane} onOpen={(tk) => setOpenTicket(tk.id)} />
            ))}
          </div>
        )}
      </div>

      {/* The full operational summary for one car — item 10 of the dashboard brief. */}
      {openTicket && (
        <VehicleOpsDrawer ticketId={openTicket} onClose={() => setOpenTicket(null)} />
      )}
    </div>
  );
}

/** One number in the hero strip. */
function HeroStat({ label, value, tone: toneCls }) {
  return (
    <div className="rounded-2xl bg-white/[0.06] px-4 py-3 text-center ring-1 ring-inset ring-white/15">
      <div className={`font-display text-2xl font-bold tabular-nums leading-none ${toneCls || ''}`}>
        {num(value ?? 0)}
      </div>
      <div className="mt-1 text-[11px] font-medium uppercase tracking-wide text-steel-200">{label}</div>
    </div>
  );
}

// ── Stage board view ─────────────────────────────────────────────────────────

/** One stage column — accent header with count + role, then its cars as compact ops cards. */
function Lane({ lane, onOpen }) {
  const role = ROLE[lane.role] || ROLE.none;
  return (
    <div className="flex w-[300px] shrink-0 flex-col rounded-2xl bg-slate-50/70 ring-1 ring-slate-200/70">
      <div className="rounded-t-2xl border-b border-slate-200/70 bg-white/70 px-3.5 py-3">
        <div className="flex items-center gap-2">
          <span className="h-2.5 w-2.5 rounded-full" style={{ background: lane.tone, boxShadow: `0 0 8px ${lane.tone}66` }} />
          <span className="font-display text-sm font-bold tracking-tight text-slate-800">{lane.name}</span>
          <span
            className="ms-auto rounded-full px-2 py-0.5 text-xs font-bold tabular-nums"
            style={{ color: lane.tone, background: `${lane.tone}1a` }}
          >
            {lane.tickets.length}
          </span>
        </div>
        <div className="mt-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
          Owned by {role.label}
        </div>
      </div>

      <div className="flex flex-1 flex-col gap-2.5 p-2.5">
        {lane.tickets.length === 0 ? (
          <div className="flex flex-col items-center gap-1 rounded-xl border border-dashed border-slate-200 py-6 text-center">
            <Icon.Check className="h-4 w-4 text-slate-300" />
            <span className="text-[11px] font-medium text-slate-400">No cars here</span>
          </div>
        ) : (
          lane.tickets.map((tk) => <LaneCard key={tk.id} tk={tk} role={role} onOpen={onOpen} />)
        )}
      </div>
    </div>
  );
}

/**
 * The lane-width card: the same operational truth as the full card, compressed to what fits a 300px
 * column — reason, state, blocker, the spine, the clock and who holds it.
 */
function LaneCard({ tk, role, onOpen }) {
  const o = tk.ops || {};
  const st = tone(o.state?.tone);
  const timing = o.timing || {};
  const owner = o.responsibility?.owner_name;

  return (
    <button
      type="button"
      onClick={() => onOpen(tk)}
      className="group w-full rounded-xl bg-white p-3 text-start shadow-soft ring-1 ring-slate-200/70 transition hover:ring-indigo-300"
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="font-display text-base font-bold leading-none tracking-tight text-slate-900">
            {tk.plate || `#${tk.id}`}
          </div>
          {tk.car && <div className="mt-1 truncate text-xs text-slate-500">{tk.car}</div>}
        </div>
        {tk.fault_severity && (
          <Badge tone={tk.fault_severity_tone || 'slate'} dot>{tk.fault_severity_label || tk.fault_severity}</Badge>
        )}
      </div>

      {/* Why it's here */}
      <div className="mt-2 flex items-start gap-1.5 text-xs leading-snug">
        <Icon.Wrench className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
        <span className="min-w-0">
          <span className="font-semibold text-slate-800">{o.reason?.primary || 'Reason not recorded'}</span>
          {o.reason?.extra > 0 && <span className="text-slate-400"> +{o.reason.extra} more</span>}
        </span>
      </div>

      {/* What's happening now */}
      {o.state && (
        <div className={`mt-2 inline-flex rounded-lg px-2 py-1 text-[11px] font-bold ring-1 ring-inset ${st.chip}`}>
          {o.state.label}
        </div>
      )}

      {/* What's blocking it */}
      {o.blocker && (
        <div className={`mt-1.5 rounded-lg px-2 py-1.5 text-[11px] leading-snug ring-1 ring-inset ${tone(o.blocker.tone).chip}`}>
          <span className="font-bold">{o.blocker.label}</span>
          {o.blocker.detail && <span className="opacity-80"> · {o.blocker.detail}</span>}
        </div>
      )}

      {/* How far the repair has got */}
      {o.progress?.length > 0 && (
        <div className="mt-2">
          <ProgressSpine steps={o.progress} compact />
        </div>
      )}

      {/* Who holds it */}
      <div className="mt-2 flex items-center gap-1.5 text-[11px] text-slate-500">
        <Icon.Users className="h-3 w-3 shrink-0 text-slate-400" />
        <span className="truncate">
          {role.label}:{' '}
          {owner ? <span className="font-semibold text-slate-700">{owner}</span> : <span className="italic">{role.waiting}</span>}
        </span>
      </div>

      {/* The clock */}
      <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] font-medium">
        <span className="inline-flex items-center gap-1 text-slate-400">
          <Icon.Clock className="h-3 w-3" /> {days(timing.days_in_maintenance) ?? '—'} in maintenance
        </span>
        {timing.days_over > 0 && <span className="text-red-600">{timing.days_over}d overdue</span>}
        {(timing.days_since_checkpoint ?? 0) >= 3 && (
          <span className="text-amber-600">{timing.days_since_checkpoint}d no update</span>
        )}
      </div>
    </button>
  );
}
