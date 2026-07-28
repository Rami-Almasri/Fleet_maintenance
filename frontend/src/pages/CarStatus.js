import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { SearchInput, ErrorState, EmptyState } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { num } from '../lib/format';
import { stageAge } from '../components/workflow/meta';
import { useI18n } from '../i18n/I18nContext';

// ─────────────────────────────────────────────────────────────────────────────────────────────
// Car Status — the live stage board. Every car in the maintenance workflow, laid out by the exact
// stage it sits in right now, and — the whole point of this page — WHO is responsible for it at
// that stage: the inspector on a test drive, the supervisor who must dispatch, the driver who has
// the car, or the garage doing the work. When nobody has taken the stage yet it reads "Waiting";
// the moment someone picks it up their name replaces the placeholder.
//
// It reads the same live pipeline the Maintenance Cycle board does (/maintenance-tickets/board),
// so counts and holders never disagree between the two surfaces.
// ─────────────────────────────────────────────────────────────────────────────────────────────

// The canonical journey, left → right. `key` is the board column key; `role` is who owns the stage.
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

// Exception lanes — only rendered when they actually hold cars, so no ticket is ever hidden but the
// board stays focused on the eight-step happy path the rest of the time.
const EXCEPTION_STAGES = [
  { key: 'triage',                  name: 'Complaint Triage',      role: 'inspector',  tone: '#f43f5e' },
  { key: 'on_site',                 name: 'On-Site Service',       role: 'inspector',  tone: '#0d9488' },
  { key: 'repair_review',           name: 'Video Review',          role: 'supervisor', tone: '#7c3aed' },
  { key: 'reinspection_failed',     name: 'Sent Back — QA Failed', role: 'supervisor', tone: '#dc2626' },
  { key: 'paused',                  name: 'Paused',                role: 'none',       tone: '#64748b' },
  { key: 'returned_waiting_resume', name: 'Returned — Resume Due', role: 'none',       tone: '#f97316' },
];

// Role → how the responsible chip is drawn. `waiting` is the placeholder label shown until a real
// person/garage is on the hook for the stage.
const ROLES = {
  inspector:  { label: 'Inspector',  Icon: Icon.Shield, cls: 'bg-amber-50 text-amber-700 ring-amber-200',   waiting: 'Waiting for inspector' },
  supervisor: { label: 'Supervisor', Icon: Icon.Users,  cls: 'bg-violet-50 text-violet-700 ring-violet-200', waiting: 'Waiting for supervisor' },
  driver:     { label: 'Driver',     Icon: Icon.Truck,  cls: 'bg-blue-50 text-blue-700 ring-blue-200',       waiting: 'Waiting for driver' },
  garage:     { label: 'Garage',     Icon: Icon.Wrench, cls: 'bg-orange-50 text-orange-700 ring-orange-200', waiting: 'Not dispatched' },
  none:       { label: 'On hold',    Icon: Icon.Clock,  cls: 'bg-slate-100 text-slate-500 ring-slate-200',   waiting: 'On hold' },
};

// WHO is responsible for this car right now, given the stage. Returns the resolved name (or null →
// the stage's "Waiting" placeholder). Names come straight off the live ticket — never invented:
//   • Inspector stages → the inspector stamped on the test drive (Abu Maroof once he's on it)
//   • Driver stages    → the assigned / dispatched / collecting driver, whichever leg applies
//   • Garage stage     → the garage the car is being repaired at
//   • Supervisor / QA  → no one is "assigned" until they act, so these read Waiting until done
function holderName(stageKey, tk) {
  switch (stageKey) {
    case 'requested':
    case 'diagnostic':
    case 'triage':
    case 'on_site':
      return tk.handoffs?.inspected?.name || null;
    case 'awaiting_pickup':
      return tk.assigned_driver_name || tk.delegation?.driver_name || null;
    case 'in_transit':
      return tk.dispatched_by_name || tk.assigned_driver_name || null;
    case 'under_repair':
      return tk.garage || null;
    case 'ready_for_pickup':
      return tk.picked_up_from_garage_by_name || tk.assigned_driver_name || tk.dispatched_by_name || null;
    default:
      // pending (supervisor), qa_reinspection, reinspection_failed, repair_review, paused, returned
      return null;
  }
}

export default function CarStatus() {
  const navigate = useNavigate();
  const { t } = useI18n();
  const [q, setQ] = useState('');

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  const { data, loading, error, reload, validating } = useFetch(fetcher, [], { refreshInterval: 15000 });

  const columns = useMemo(() => data?.columns || {}, [data]);
  const counts = data?.counts || {};

  const needle = q.trim().toLowerCase();
  const matches = useCallback((tk) => {
    if (!needle) return true;
    return [tk.plate, tk.car, tk.garage, tk.assigned_driver_name, tk.dispatched_by_name]
      .filter(Boolean).some((x) => String(x).toLowerCase().includes(needle));
  }, [needle]);

  // Build every lane; keep primary lanes always, and exception lanes only when they hold a match.
  const primaryLanes = useMemo(
    () => STAGES.map((s) => ({ ...s, tickets: (columns[s.key] || []).filter(matches) })),
    [columns, matches],
  );
  const exceptionLanes = useMemo(
    () => EXCEPTION_STAGES
      .map((s) => ({ ...s, tickets: (columns[s.key] || []).filter(matches) }))
      .filter((s) => s.tickets.length > 0),
    [columns, matches],
  );
  const lanes = useMemo(() => [...primaryLanes, ...exceptionLanes], [primaryLanes, exceptionLanes]);

  const totalShown = useMemo(() => lanes.reduce((n, l) => n + l.tickets.length, 0), [lanes]);
  const openTotal = counts.open_total ?? 0;

  return (
    <div className="pb-10">
      {/* Hero */}
      <div className="relative overflow-hidden bg-navy-900 text-white">
        <div className="pointer-events-none absolute inset-0" aria-hidden="true">
          <div className="absolute -right-24 -top-32 h-96 w-96 rounded-full bg-brand-500/25 blur-3xl" />
          <div className="absolute -bottom-40 left-10 h-80 w-80 rounded-full bg-violet-500/10 blur-3xl" />
        </div>
        <div className="relative mx-auto max-w-[1700px] px-4 pb-10 pt-8 sm:px-6 lg:px-8">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div className="min-w-0">
              <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-steel-300">
                <span className="relative flex h-2 w-2">
                  <span className={`absolute inline-flex h-full w-full rounded-full bg-emerald-400 ${validating ? 'animate-ping' : 'opacity-75'}`} />
                  <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
                </span>
                Live · Maintenance Pipeline
              </div>
              <h1 className="mt-2 font-display text-3xl font-bold tracking-tight sm:text-4xl">Car Status</h1>
              <p className="mt-2 max-w-2xl text-sm text-steel-200">
                Every car in maintenance, by the stage it's in right now — and who's responsible for it at that stage.
                A stage reads <span className="font-semibold text-white">Waiting</span> until someone takes it, then shows their name.
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-3">
              <div className="rounded-2xl bg-white/[0.06] px-4 py-3 text-center ring-1 ring-inset ring-white/15">
                <div className="font-display text-3xl font-bold tabular-nums leading-none">{num(openTotal)}</div>
                <div className="mt-1 text-[11px] font-medium uppercase tracking-wide text-steel-200">In the pipeline</div>
              </div>
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

        {/* Control bar */}
        <div className="rounded-2xl border border-slate-200/70 bg-white/80 p-3 shadow-soft backdrop-blur">
          <div className="flex flex-wrap items-center gap-3">
            <SearchInput value={q} onChange={setQ} placeholder="Plate, car, driver, garage…" className="w-full max-w-xs" />
            <div className="ml-auto text-xs font-medium text-slate-400">
              <span className="tabular-nums text-slate-600">{num(totalShown)}</span> shown
            </div>
          </div>
        </div>

        {/* Stage board — horizontal scroll of stage columns */}
        {!loading && openTotal === 0 ? (
          <SectionCard>
            <EmptyState
              icon={<Icon.Wrench className="h-6 w-6" />}
              title="Pipeline is clear"
              message="No car is in the maintenance workflow right now."
            />
          </SectionCard>
        ) : (
          <div className="flex gap-4 overflow-x-auto pb-4">
            {lanes.map((lane) => (
              <Lane key={lane.key} lane={lane} loading={loading} t={t} onOpen={(tk) => navigate(`/maintenance-workflow/${tk.id}`)} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// One stage column — accent header with count + role, then its cars.
function Lane({ lane, loading, onOpen, t }) {
  const role = ROLES[lane.role] || ROLES.none;
  const RIcon = role.Icon;
  return (
    <div className="flex w-[300px] shrink-0 flex-col rounded-2xl bg-slate-50/70 ring-1 ring-slate-200/70">
      <div className="rounded-t-2xl border-b border-slate-200/70 bg-white/70 px-3.5 py-3">
        <div className="flex items-center gap-2">
          <span className="h-2.5 w-2.5 rounded-full" style={{ background: lane.tone, boxShadow: `0 0 8px ${lane.tone}66` }} />
          <span className="font-display text-sm font-bold tracking-tight text-slate-800">{lane.name}</span>
          <span
            className="ml-auto rounded-full px-2 py-0.5 text-xs font-bold tabular-nums"
            style={{ color: lane.tone, background: `${lane.tone}1a` }}
          >
            {lane.tickets.length}
          </span>
        </div>
        <div className="mt-1.5 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
          <RIcon className="h-3 w-3" /> Owned by {role.label}
        </div>
      </div>

      <div className="flex flex-1 flex-col gap-2.5 p-2.5">
        {loading ? (
          <>
            <div className="h-24 animate-pulse rounded-xl bg-white" />
            <div className="h-24 animate-pulse rounded-xl bg-white" />
          </>
        ) : lane.tickets.length === 0 ? (
          <div className="flex flex-col items-center gap-1 rounded-xl border border-dashed border-slate-200 py-6 text-center">
            <Icon.Check className="h-4 w-4 text-slate-300" />
            <span className="text-[11px] font-medium text-slate-400">No cars here</span>
          </div>
        ) : (
          lane.tickets.map((tk) => <CarCard key={tk.id} tk={tk} lane={lane} role={role} onOpen={onOpen} t={t} />)
        )}
      </div>
    </div>
  );
}

// One car in a stage — plate + model, the responsible-party chip (the headline of this page),
// severity + how long it's sat in the stage. Click → the ticket on the Maintenance Cycle board.
// Why is this car in the shop — the primary open fault's symptom, else the customer complaint.
function reasonFor(tk) {
  const tasks = tk.tasks || [];
  const primary = tasks.find((x) => !x.is_terminal) || tasks[0];
  return primary?.symptom || tk.customer_complaint || null;
}

function CarCard({ tk, lane, role, onOpen, t }) {
  const RIcon = role.Icon;
  const name = holderName(lane.key, tk);
  const age = stageAge(tk, t);
  const reason = reasonFor(tk);
  const openFaults = tk.tasks_progress?.open ?? 0;
  const otherFaults = Math.max(0, openFaults - 1);
  const partsPending = tk.parts_pending || [];

  return (
    <button
      type="button"
      onClick={() => onOpen(tk)}
      className="group w-full rounded-xl bg-white p-3 text-left shadow-soft ring-1 ring-slate-200/70 transition hover:ring-indigo-300"
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

      {/* Why it's in the shop — the reason + how many other faults ride along */}
      {reason && (
        <div className="mt-2 flex items-start gap-1.5 text-xs leading-snug text-slate-600">
          <Icon.Wrench className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span className="min-w-0">
            <span className="font-medium text-slate-700">{reason}</span>
            {otherFaults > 0 && <span className="text-slate-400"> +{otherFaults} more</span>}
          </span>
        </div>
      )}

      {/* Waiting for parts — the distinct parts still owed on the ticket */}
      {partsPending.length > 0 && (
        <div className="mt-1.5 flex items-start gap-1.5 rounded-lg bg-violet-50 px-2 py-1.5 ring-1 ring-inset ring-violet-100">
          <Icon.Coins className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-500" />
          <span className="min-w-0 text-[11px] leading-snug text-violet-700">
            <span className="font-semibold">Waiting for parts</span>
            <span className="text-violet-600"> · {partsPending.slice(0, 3).join(', ')}{partsPending.length > 3 ? `, +${partsPending.length - 3}` : ''}</span>
          </span>
        </div>
      )}

      {/* Responsible party — the point of the board */}
      <div className={`mt-2.5 flex items-center gap-2 rounded-lg px-2.5 py-2 ring-1 ring-inset ${name ? role.cls : 'bg-slate-50 text-slate-400 ring-slate-200'}`}>
        <RIcon className="h-4 w-4 shrink-0" />
        <div className="min-w-0 flex-1">
          <div className="text-[9px] font-semibold uppercase tracking-wide opacity-70">{role.label}</div>
          {name ? (
            <div className="truncate text-sm font-bold leading-tight">{name}</div>
          ) : (
            <div className="truncate text-sm font-semibold italic leading-tight">{role.waiting}</div>
          )}
        </div>
      </div>

      {/* Time in stage */}
      {age && (
        <div className={`mt-2 flex items-center gap-1 text-[11px] font-medium ${age.over ? 'text-red-600' : 'text-slate-400'}`}>
          <Icon.Clock className="h-3 w-3" /> {age.label} in this stage
        </div>
      )}
    </button>
  );
}
