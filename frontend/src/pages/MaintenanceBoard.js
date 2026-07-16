import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import SearchSelect from '../components/ui/SearchSelect';
import { Select } from '../components/ui/Field';
import WorkshopEvents from '../components/WorkshopEvents';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { LifecycleTimeline } from '../components/ui/Progress';
import { useToast } from '../components/ui/Toast';
import { usePageStat } from '../components/PageStat';
import { aed, aed2, fmtDate, fmtAgo, num } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

// ---- Command-center navigation --------------------------------------------
// The board is the hub of the maintenance ecosystem; this strip jumps to the
// rest of it so the page never feels like an island. `end` keeps the Board tab
// from staying active on the deeper routes.
const NAV = [
  { to: '/maintenance', label: 'Board', icon: Icon.Wrench, end: true },
  { to: '/maintenance-workflow', label: 'Workflow', icon: Icon.Route },
  { to: '/my-maintenance-queue', label: 'My Queue', icon: Icon.Users },
  { to: '/maintenance-analytics', label: 'Analytics', icon: Icon.Chart },
];

function NavStrip() {
  return (
    <nav className="flex flex-wrap items-center gap-1.5 rounded-2xl border border-slate-200/70 bg-white p-1.5 shadow-soft ring-1 ring-slate-900/5">
      {NAV.map(({ to, label, icon: Ic, end }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) =>
            `inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-medium transition ${
              isActive ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100'
            }`
          }
        >
          <Ic className="h-4 w-4" />
          {label}
        </NavLink>
      ))}
    </nav>
  );
}

// How many days a still-open car is running past the fleet's average turnaround.
// null when we can't compare (no avg, or the car is still under average).
function overAvg(daysOut, avg) {
  if (daysOut == null || avg == null || avg <= 0 || daysOut <= avg) return null;
  return Math.round(daysOut - avg);
}

// Traffic-light styling per SLA status (timeliness).
const LIGHT = {
  on_track: { dot: 'bg-emerald-500', text: 'On track', tone: 'green' },
  at_risk: { dot: 'bg-amber-500', text: 'At risk', tone: 'amber' },
  // SLA breached → Alert Orange (corporate palette).
  breached: { dot: 'bg-orange-500', text: 'Overdue', tone: 'orange' },
  // The linked visit already came back (latest sheet event = IN) — closed, never overdue.
  returned: { dot: 'bg-sky-500', text: 'Returned', tone: 'sky' },
  // No maintenance record is strictly linked to this contract (no stale visit borrowed).
  no_log: { dot: 'bg-slate-300', text: 'No log', tone: 'gray' },
  unknown: { dot: 'bg-slate-300', text: '—', tone: 'gray' },
};

// Severity classification per maintenance situation (reason -> status, from the sheet).
const PRIORITY = {
  critical: { label: 'Critical', tone: 'red', emoji: '🔴' },
  special: { label: 'Special', tone: 'violet', emoji: '🟣' },
  minor: { label: 'Minor', tone: 'amber', emoji: '🟡' },
  routine: { label: 'Routine', tone: 'green', emoji: '🟢' },
};

// Live workshop stage (sheet "OUT/IN" event log): where the car is in the repair flow.
// Labels mirror the raw sheet event_status (OUT / IN / Test / Follow up …); only the
// colour is added. 'unknown' is the synthetic "no linked event" case.
const STAGE = {
  OUT: { label: 'OUT', tone: 'blue' },
  IN: { label: 'IN', tone: 'green' },
  'Follow up': { label: 'Follow up', tone: 'amber' },
  Change: { label: 'Change', tone: 'violet' },
  Delay: { label: 'Delay', tone: 'red' },
  Test: { label: 'Test', tone: 'cyan' },
  'Under Test': { label: 'Under Test', tone: 'cyan' },
  unknown: { label: 'No log', tone: 'gray' },
};
const stageInfo = (s) => STAGE[s] || { label: s, tone: 'slate' };

// Short codes for the compact garage-history timeline (the "ping-pong" sequence).
const STAGE_ABBR = { OUT: 'OUT', IN: 'IN', 'Follow up': 'FU', Change: 'CHG', Delay: 'DLY', Test: 'TST', 'Under Test': 'TST' };
const abbr = (s) => STAGE_ABBR[s] || s;

// Map a board car onto the operational lifecycle (Inspection → Transit → Repair → Ready).
// A car already in the garage has cleared Inspection & Transit; its live workshop stage
// places it in Repair, or in Ready once it's back / under test for re-inspection.
const LIFE_STEPS = ['Inspection', 'Transit', 'Repair', 'Ready'];
function lifecycleOf(c) {
  const st = c.stage;
  const ready = c.status === 'returned' || st === 'IN' || st === 'Test' || st === 'Under Test';
  const current = ready ? 3 : 2;
  const breached = c.status === 'breached';
  const dur = c.days_out != null ? `${c.days_out}d` : null;
  const caption = [LIFE_STEPS[current], dur].filter(Boolean).join(' · ') + (breached ? ' · over SLA' : '');
  return { current, breached, caption };
}

// Compact "out → back → out again" history shown under the current stage. Collapses
// long chains (first 2 … last 2) and exposes the full dated sequence on hover.
function PingPong({ events }) {
  if (!events || events.length < 2) return null;
  const codes = events.map((e) => abbr(e.stage));
  const shown = codes.length > 4 ? [...codes.slice(0, 2), '…', ...codes.slice(-2)] : codes;
  const full = events
    .map((e) => `${e.stage} · out ${e.out_date || '—'}${e.actual_in_date ? ` · in ${e.actual_in_date}` : ''}`)
    .join('\n');
  return (
    <div className="mt-1 max-w-[150px] text-[10px] leading-tight text-slate-400" title={full}>
      {shown.join(' → ')} <span className="text-slate-300">({codes.length})</span>
    </div>
  );
}

// Full "what happened in the sheet" log for one car — the whole linked event sequence
// (oldest→newest) with each event's garage, services and progress note. Opened by
// clicking the Stage cell on the board.
function GarageLog({ car, onClose }) {
  return (
    <Modal
      open={!!car}
      onClose={onClose}
      size="xl"
      title={car ? `Garage log · ${car.plate || `#${car.contract_no || car.id}`}` : ''}
      subtitle={car ? [car.car, car.contract_no && `contract #${car.contract_no}`].filter(Boolean).join(' · ') : ''}
    >
      {car && (car.events && car.events.length > 0 ? (
        <ol className="space-y-3">
          {car.events.map((e, i) => {
            const si = stageInfo(e.stage);
            return (
              <li key={i} className="rounded-xl border border-slate-100 p-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                  <span className="font-medium text-slate-400">{i + 1}.</span>
                  <Badge tone={si.tone}>{si.label}</Badge>
                  {e.out_date && <span className="text-slate-500">out {fmtDate(e.out_date)}</span>}
                  {e.expected_return_date && <span className="text-slate-400">· due {fmtDate(e.expected_return_date)}</span>}
                  {e.actual_in_date && <span className="text-emerald-600">· back {fmtDate(e.actual_in_date)}</span>}
                  {e.garage && <span className="text-slate-400">· {e.garage}</span>}
                  {SHOW_FINANCIALS && e.cost ? <span className="ml-auto font-medium text-slate-600">{aed2(e.cost)}</span> : null}
                </div>
                {e.services && e.services.length > 0 && (
                  <div className="mt-1.5 flex flex-wrap gap-1">
                    {e.services.map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                  </div>
                )}
                {e.notes && <p className="mt-1.5 whitespace-pre-wrap text-xs leading-relaxed text-slate-600">{e.notes}</p>}
              </li>
            );
          })}
        </ol>
      ) : (
        <EmptyState title="No sheet log" message="No maintenance-sheet events are linked to this contract." />
      ))}
    </Modal>
  );
}

// Full per-car workshop-events CRUD (add / edit / delete), reusing the same
// <WorkshopEvents> timeline used on the contract detail page. In "car" mode (a
// row's Manage button) it opens straight onto that row's CONTRACT; with no car
// (the page's "+ Add event" button) it asks which car AND which contract/visit
// to log against, so each event is tied to a specific maintenance contract.
function ManageModal({ open, car, vehicles, onClose }) {
  const [vehicleId, setVehicleId] = useState('');
  const [contractId, setContractId] = useState('');     // '' = whole car (all visits)
  const [contracts, setContracts] = useState([]);
  const [loadingContracts, setLoadingContracts] = useState(false);

  // Reset selections each time the modal opens / switches target. A board row IS a
  // contract, so scope straight to it; the page-level add starts with nothing chosen.
  useEffect(() => {
    if (!open) return;
    setVehicleId(car?.vehicle_id ? String(car.vehicle_id) : '');
    // A contract-less garage card has a synthetic id ('m<vid>'), not a real contract — scope the
    // manage view to the whole car so it doesn't pass a bogus contract id to the events API.
    setContractId(car?.is_contract === false ? '' : (car?.id ? String(car.id) : ''));
    setContracts([]);
  }, [open, car]);

  // Page-level add: once a car is chosen, load its maintenance contracts so the user
  // can say which visit the event belongs to.
  useEffect(() => {
    if (!open || car || !vehicleId) { setContracts([]); return; }
    let alive = true;
    setLoadingContracts(true);
    setContractId('');
    api.get('/Contract', { params: { vehicle_id: vehicleId, contract_type: 'U' } })
      .then(({ data }) => { if (alive) setContracts(data.data?.items || []); })
      .catch(() => { if (alive) setContracts([]); })
      .finally(() => { if (alive) setLoadingContracts(false); });
    return () => { alive = false; };
  }, [open, car, vehicleId]);

  const carOptions = useMemo(
    () => vehicles.map((v) => ({
      id: v.id,
      label: v.plate_no || `#${v.id}`,
      sub: [v.make, v.model].filter(Boolean).join(' '),
    })),
    [vehicles],
  );

  // The contract whose window scopes the log + seeds the new-event dates: the board row
  // in row mode, or the picked one in add mode.
  const chosenContract = car || contracts.find((c) => String(c.id) === String(contractId)) || null;

  const title = car
    ? `Manage maintenance · ${car.plate || `#${car.contract_no || car.id}`}`
    : 'Add maintenance event';
  const subtitle = car
    ? [car.car, car.contract_no && `contract #${car.contract_no}`].filter(Boolean).join(' · ')
    : 'Pick a car and the contract/visit, then log or edit its workshop events';

  return (
    <Modal open={open} onClose={onClose} size="xl" title={title} subtitle={subtitle}>
      {!car && (
        <div className="mb-4 grid gap-4 sm:grid-cols-2">
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">Car</span>
            <SearchSelect value={vehicleId} onChange={setVehicleId} options={carOptions} placeholder="Search plate / make / model…" />
          </div>
          {vehicleId && (
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">Contract / visit</span>
              <Select value={contractId} onChange={(e) => setContractId(e.target.value)} disabled={loadingContracts}>
                <option value="">{loadingContracts ? 'Loading contracts…' : 'Whole car — all visits'}</option>
                {contracts.map((co) => (
                  <option key={co.id} value={co.id}>
                    #{co.contract_no || co.id} · {co.out_date ? fmtDate(co.out_date) : '—'}{co.in_date ? ` → ${fmtDate(co.in_date)}` : ' → open'} · {co.state}
                  </option>
                ))}
              </Select>
              {!loadingContracts && contracts.length === 0 && (
                <p className="mt-1 text-xs text-slate-400">No maintenance contracts for this car — logging against the whole car.</p>
              )}
            </div>
          )}
        </div>
      )}
      {vehicleId ? (
        <WorkshopEvents
          key={`${vehicleId}:${contractId}`}
          vehicleId={Number(vehicleId)}
          contractId={contractId ? Number(contractId) : undefined}
          defaultDate={chosenContract?.out_date}
          expectedReturn={chosenContract?.expected_return_date}
        />
      ) : (
        <p className="py-8 text-center text-sm text-slate-400">Choose a car above to see and manage its workshop events.</p>
      )}
    </Modal>
  );
}

// One labelled cell in a card's facts grid.
function Fact({ label, hint, children }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
        {label}
        {hint && <span className="ml-1 font-normal normal-case text-slate-300">· {hint}</span>}
      </dt>
      <dd className="mt-0.5 truncate text-xs font-medium text-slate-700">{children}</dd>
    </div>
  );
}

// ---- Workflow phases (Kanban columns) -------------------------------------
// Four pipeline stages that mirror the natural repair lifecycle left→right.
// Derived purely from the live sheet stage + SLA — no new backend needed.
const PHASES = [
  {
    key: 'diagnostic',
    label: 'Needs a Log',
    subLabel: 'Just sent — no garage update yet',
    hint: 'Car arrived at the garage but no workshop log entry yet',
    accent: '#8b5cf6',
  },
  {
    key: 'in_transit',
    label: 'On the Way',
    subLabel: 'Car driving to the garage',
    hint: 'Car has been dispatched and is in transit to the garage',
    accent: '#3b82f6',
  },
  {
    key: 'repair',
    label: 'At the Garage',
    subLabel: 'Being repaired / follow-ups',
    hint: 'Car is actively being worked on at the garage',
    accent: '#f59e0b',
  },
  {
    key: 'ready',
    label: 'Fixed – Coming Back',
    subLabel: 'Returned or under final check',
    hint: 'Repair done — car is back or under final test before return',
    accent: '#10b981',
  },
];
const PHASE_BY_KEY = Object.fromEntries(PHASES.map((p) => [p.key, p]));

// Map a board car onto a workflow phase from its live sheet stage + SLA status.
function phaseOf(c) {
  const st = c.stage;
  if (c.status === 'returned' || st === 'IN' || st === 'Test' || st === 'Under Test') return 'ready';
  if (st === 'OUT' || st === 'Follow up' || st === 'Change' || st === 'Delay') return 'repair';
  if (!st || st === 'unknown') return 'diagnostic';
  return 'in_transit';
}

// A small drag affordance — the universal "grab handle" dots.
const GripIcon = ({ className = 'h-4 w-4' }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <circle cx="9" cy="6" r="1.4" /><circle cx="15" cy="6" r="1.4" />
    <circle cx="9" cy="12" r="1.4" /><circle cx="15" cy="12" r="1.4" />
    <circle cx="9" cy="18" r="1.4" /><circle cx="15" cy="18" r="1.4" />
  </svg>
);

// A tiny "garage" glyph for the attribution meta-chip.
const GarageIcon = ({ className = 'h-3 w-3' }) => (
  <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M3 21h18M4 21V10l5 3V10l5 3V8l5 3v10" /><path d="M9 21v-4h3v4" />
  </svg>
);

// A compact "meta-chip": a pill with an icon + one fact. The Dashboard's design
// language, reused so the board reads consistently.
function MetaChip({ icon, children, title }) {
  return (
    <span title={title} className="inline-flex max-w-full items-center gap-1 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
      {icon && <span className="shrink-0 text-slate-400">{icon}</span>}
      <span className="truncate">{children}</span>
    </span>
  );
}

// Compact, draggable card for the pipeline Kanban. Mirrors the TicketCard
// language on the Workflow board: thin top accent, plate chip, meta-chips,
// slim footer — so both boards feel like one system.
function PhaseCard({ c, accent, avgDays, canManage, onLog, onManage, onDragStart, onDragEnd, dragging }) {
  const light = LIGHT[c.status] || LIGHT.unknown;
  const prio = PRIORITY[c.priority] || PRIORITY.routine;
  const issues = c.issues || [];
  const topIssue = issues[0];
  const moreIssues = Math.max(0, issues.length - 1);
  const hasEvents = c.events && c.events.length > 0;
  const over = overAvg(c.days_out, avgDays);
  const updated = fmtAgo(c.last_update);

  return (
    <div
      draggable
      onDragStart={(e) => onDragStart(e, c)}
      onDragEnd={onDragEnd}
      className={`group cursor-grab overflow-hidden rounded-xl border border-slate-100 bg-white shadow-soft transition hover:shadow-card hover:border-slate-200 active:cursor-grabbing ${dragging ? 'opacity-40 ring-2 ring-indigo-300' : ''}`}
    >
      {/* Phase colour accent — top strip */}
      <div className="h-0.5 w-full" style={{ background: accent }} />

      {/* Car identity row */}
      <div className="flex items-center gap-2 px-3 py-2.5">
        <span className="shrink-0 text-slate-200 group-hover:text-slate-300" title="Drag to move">
          <GripIcon className="h-3.5 w-3.5" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1">
            <Link
              to={c.is_contract === false ? `/vehicles/${c.vehicle_id}` : `/contracts/${c.id}`}
              className="inline-flex items-center gap-1 font-mono text-xs font-bold tracking-wider text-slate-800 hover:text-indigo-600"
            >
              <Icon.Car className="h-3 w-3 shrink-0 text-slate-400" strokeWidth={2} />
              {c.plate || (c.is_contract === false ? `#${c.vehicle_id}` : `#${c.contract_no || c.id}`)}
            </Link>
            {c.is_contract === false && (
              <Badge tone="amber" title="No maintenance contract — log-only entry">log</Badge>
            )}
          </div>
          <p className="truncate text-[10px] text-slate-400">{c.car || '—'}</p>
        </div>
        <span
          className="shrink-0"
          title={c.priority_matched ? `Matched keyword: "${c.priority_matched}"` : 'Routine'}
        >
          <Badge tone={prio.tone} dot>{prio.label}</Badge>
        </span>
      </div>

      {/* Meta-chips: the three that matter at a glance — top issue · days out · garage.
          (Responsible now lives on the accountability line; cost stays in the full card.) */}
      <div className="flex flex-wrap gap-1 border-t border-slate-50 px-3 py-1.5">
        {topIssue ? (
          <MetaChip icon={<Icon.Wrench className="h-3 w-3" />} title={issues.join(', ')}>
            {topIssue}{moreIssues ? ` +${moreIssues}` : ''}
          </MetaChip>
        ) : (
          <MetaChip icon={<Icon.Wrench className="h-3 w-3" />}>No issues</MetaChip>
        )}
        {c.days_out != null && (
          <MetaChip icon={<Icon.Clock className="h-3 w-3" />}>{c.days_out}d out</MetaChip>
        )}
        {c.garage && <MetaChip icon={<GarageIcon />}>{c.garage}</MetaChip>}
      </div>

      {/* Accountability + freshness line: who owns it, when it last moved, over-average flag */}
      {(c.responsible || updated || over != null) && (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-t border-slate-50 px-3 py-1 text-[10px] text-slate-400">
          {c.responsible && (
            <span className="inline-flex items-center gap-1 font-medium text-slate-500" title="Accountable person">
              <Icon.Users className="h-2.5 w-2.5" /> {c.responsible}
            </span>
          )}
          {updated && (
            <span className="inline-flex items-center gap-1" title={`Last updated ${fmtDate(c.last_update)}`}>
              <Icon.Clock className="h-2.5 w-2.5" /> {updated}
            </span>
          )}
          {over != null && (
            <span className="ml-auto inline-flex items-center gap-1 font-semibold text-orange-600" title={`Fleet average is ${avgDays}d — this car is ${over}d over`}>
              <Icon.Alert className="h-2.5 w-2.5" /> {over}d over avg
            </span>
          )}
        </div>
      )}

      {/* Footer: SLA status + manage */}
      <div className="flex items-center justify-between gap-2 border-t border-slate-50 px-3 py-2">
        <button
          type="button"
          onClick={() => onLog(c)}
          className="inline-flex items-center gap-1.5"
          title="See full garage log"
        >
          <span className={`h-2 w-2 rounded-full ${light.dot}`} />
          <span className="text-[10px] font-medium text-slate-500">{light.text}</span>
          {c.overdue_days > 0 && <span className="text-[10px] font-semibold text-orange-500">+{c.overdue_days}d</span>}
          {hasEvents && <span className="text-[10px] text-indigo-400">· log →</span>}
        </button>
        {canManage && (
          <button
            type="button"
            onClick={() => onManage(c)}
            className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-medium text-indigo-600 transition hover:bg-indigo-100"
          >
            Manage
          </button>
        )}
      </div>
    </div>
  );
}

// One Kanban column = one pipeline stage. Drop target with its own tinted
// background so each stage is visually distinct at a glance.
function PhaseColumn({ phase, cars, avgDays, isOver, canManage, onLog, onManage, onDragStart, onDragEnd, dragId, onDragOver, onDragLeave, onDrop }) {
  return (
    <div
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
      className={`flex flex-col overflow-hidden rounded-2xl border bg-white transition ${isOver ? 'border-indigo-400 ring-2 ring-indigo-300' : 'border-slate-200/70'}`}
    >
      {/* Column header: thin top accent in the phase colour + sub-label + neutral count */}
      <div className="border-b border-slate-200/60 px-3.5 pb-3 pt-3.5" style={{ borderTopWidth: 3, borderTopColor: phase.accent, borderTopStyle: 'solid' }}>
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            <h3 className="text-sm font-bold text-slate-800">{phase.label}</h3>
            <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{phase.subLabel}</p>
          </div>
          <div className="flex items-center gap-1.5">
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold tabular-nums text-slate-700">
              {cars.length}
            </span>
            <InfoTip content={phase.hint} />
          </div>
        </div>
      </div>

      {/* Cards */}
      <div className="flex min-h-[140px] flex-1 flex-col gap-2.5 p-3">
        {cars.length > 0 ? (
          cars.map((c) => (
            <PhaseCard
              key={c.id}
              c={c}
              accent={phase.accent}
              avgDays={avgDays}
              canManage={canManage}
              onLog={onLog}
              onManage={onManage}
              onDragStart={onDragStart}
              onDragEnd={onDragEnd}
              dragging={dragId === c.id}
            />
          ))
        ) : (
          <div className={`flex flex-1 items-center justify-center rounded-xl border border-dashed py-10 text-center text-xs ${isOver ? 'border-indigo-300 text-indigo-400' : 'border-slate-200 text-slate-300'}`}>
            {isOver ? 'Drop to move here' : 'No cars here'}
          </div>
        )}
      </div>
    </div>
  );
}

// One car in the garage, as a card. Replaces a single dense table row — same data,
// same interactions (click Stage → garage log, Manage → events CRUD), laid out for
// scanning instead of side-scrolling. `onLog`/`onManage` mirror the old row buttons.
function MaintenanceCard({ c, avgDays, canManage, onLog, onManage }) {
  const light = LIGHT[c.status] || LIGHT.unknown;
  const prio = PRIORITY[c.priority] || PRIORITY.routine;
  const stg = stageInfo(c.stage || 'unknown');
  const hasEvents = c.events && c.events.length > 0;
  const over = overAvg(c.days_out, avgDays);
  const updated = fmtAgo(c.last_update);

  return (
    <div className="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft ring-1 ring-slate-900/5 transition hover:-translate-y-0.5 hover:shadow-card">
      {/* Left accent bar = SLA status traffic light */}
      <span className={`absolute inset-y-0 left-0 w-1.5 ${light.dot}`} />

      <div className="flex flex-col gap-3 p-4 pl-5">
        {/* Header: plate + car, with priority on the right */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-1.5">
              <Link
                to={c.is_contract === false ? `/vehicles/${c.vehicle_id}` : `/contracts/${c.id}`}
                className="text-base font-semibold text-indigo-600 hover:text-indigo-700"
              >
                {c.plate || (c.is_contract === false ? `#${c.vehicle_id}` : `#${c.contract_no || c.id}`)}
              </Link>
              {c.is_contract === false && (
                <Badge tone="amber" title="In the garage on a logged workshop event — no maintenance contract">log</Badge>
              )}
              {c.type && <Badge tone="slate">{c.type}</Badge>}
            </div>
            <p className="mt-0.5 truncate text-xs text-slate-400">{c.car || '—'}</p>
          </div>
          <span
            className="shrink-0"
            title={c.priority_matched ? `Matched keyword: "${c.priority_matched}"` : 'No critical/minor keywords — treated as routine'}
          >
            <Badge tone={prio.tone} dot>{prio.label}</Badge>
          </span>
        </div>

        {/* Status + live stage + days out */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-2.5 py-1 ring-1 ring-inset ring-slate-200">
            <span className={`h-2 w-2 rounded-full ${light.dot}`} />
            <span className="text-xs font-medium text-slate-600">{light.text}</span>
            {c.overdue_days > 0 && <span className="text-xs font-semibold text-orange-500">+{c.overdue_days}d</span>}
          </span>
          <button
            type="button"
            onClick={() => onLog(c)}
            className="group/stage inline-flex items-center gap-1.5"
            title="Click to see the full garage log from the sheet"
          >
            <Badge tone={stg.tone}>{stg.label}</Badge>
            {hasEvents && (
              <span className="text-[10px] font-medium text-indigo-500 opacity-0 transition group-hover/stage:opacity-100">
                log →
              </span>
            )}
          </button>
          {c.days_out != null && (
            <span className="ml-auto text-xs font-medium text-slate-500">{c.days_out}d out</span>
          )}
        </div>

        {/* Accountability & freshness: who owns the ticket, when it last moved, and
            whether it's dragging past the fleet's average turnaround. */}
        {(c.responsible || updated || over != null) && (
          <div className="-mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
            {c.responsible && (
              <span className="inline-flex items-center gap-1 font-medium text-slate-500" title="Accountable person — who owns this ticket">
                <Icon.Users className="h-3 w-3" /> {c.responsible}
              </span>
            )}
            {updated && (
              <span className="inline-flex items-center gap-1" title={`Last updated ${fmtDate(c.last_update)}`}>
                <Icon.Clock className="h-3 w-3" /> Updated {updated}
              </span>
            )}
            {over != null && (
              <span className="inline-flex items-center gap-1 font-semibold text-orange-600" title={`Fleet average turnaround is ${avgDays}d — this car is ${over}d over`}>
                <Icon.Alert className="h-3 w-3" /> {over}d over avg
              </span>
            )}
          </div>
        )}

        {/* Operational lifecycle timeline — where the car is, and how long it's been there.
            Green = active/cleared step; Alert Orange = current step is over its SLA. */}
        {(() => {
          const lc = lifecycleOf(c);
          return (
            <div className="rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2.5">
              <LifecycleTimeline steps={LIFE_STEPS} current={lc.current} breached={lc.breached} caption={lc.caption} />
            </div>
          );
        })()}

        <PingPong events={c.events} />

        {/* Issues + note */}
        <div>
          <div className="flex flex-wrap items-center gap-1">
            {(c.issues || []).slice(0, 4).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
            {c.issues && c.issues.length > 4 && (
              <span className="text-[11px] font-medium text-slate-400" title={c.issues.join(', ')}>+{c.issues.length - 4} more</span>
            )}
            {(!c.issues || c.issues.length === 0) && <span className="text-xs text-slate-300">No issues logged</span>}
          </div>
          {c.notes && (
            <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed text-slate-400" title={c.notes}>{c.notes}</p>
          )}
        </div>

        {/* Facts grid */}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-slate-100 pt-3">
          <Fact label="Garage">{c.garage || <span className="text-slate-300">—</span>}</Fact>
          {SHOW_FINANCIALS && <Fact label="Cost">{c.cost ? aed2(c.cost) : <span className="text-slate-300">—</span>}</Fact>}
          <Fact label="Out" hint="API">{c.out_date ? fmtDate(c.out_date) : <span className="text-slate-300">—</span>}</Fact>
          <Fact label="In" hint="API">{c.in_date ? fmtDate(c.in_date) : <span className="text-slate-300">—</span>}</Fact>
          <Fact label="Due" hint="sheet">{c.due_sheet ? fmtDate(c.due_sheet) : <span className="text-slate-300">—</span>}</Fact>
          <Fact label="Back" hint="sheet">{c.sheet_back ? fmtDate(c.sheet_back) : <span className="text-slate-300">—</span>}</Fact>
          {SHOW_FINANCIALS && (
            <div className="col-span-2 min-w-0">
              <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                Net margin <span className="font-normal normal-case text-slate-300">· car lifetime</span>
              </dt>
              <dd className="mt-0.5">
                {c.net_margin != null ? (
                  <span
                    className={`text-sm font-semibold ${c.net_margin >= 0 ? 'text-emerald-600' : 'text-red-600'}`}
                    title={`Income ${aed2(c.vehicle_income || 0)}  −  maintenance ${aed2(c.vehicle_maintenance_cost || 0)}`}
                  >
                    {c.net_margin >= 0 ? '+' : '−'}{aed2(Math.abs(c.net_margin))}
                  </span>
                ) : <span className="text-xs text-slate-300">—</span>}
              </dd>
            </div>
          )}
        </dl>

        {canManage && (
          <div className="flex justify-end border-t border-slate-100 pt-3">
            <button
              type="button"
              onClick={() => onManage(c)}
              className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-600 transition hover:bg-indigo-100"
            >
              Manage events
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// The board body (summary KPIs + priority filter + table), without the page
// chrome — reused both on its own page and embedded on the Dashboard so the
// two never drift apart.
export function MaintenanceBoardPanel({ publishStat = false, manageable = false, grouped = false, showPulse = false }) {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/board');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = manageable && can('maintenance.manage');

  const [priority, setPriority] = useState(''); // '', 'critical', 'special', 'minor', 'routine'
  const [stage, setStage] = useState('');       // '', 'OUT', 'IN', 'Follow up', …
  const [logCar, setLogCar] = useState(null);   // car whose full garage log is open
  const [manage, setManage] = useState(null);   // { car } (row) | { pick: true } (page-level add) | null

  // Kanban drag state. `phaseOverride` holds optimistic, local-only moves so a
  // drag feels instantly responsive; it is NOT persisted (the board reloads from
  // the live sheet/SLA), so a drop is a preview the team can act on, not a write.
  const [phaseOverride, setPhaseOverride] = useState({}); // id -> phase key
  const [dragId, setDragId] = useState(null);
  const [overPhase, setOverPhase] = useState(null);

  const handleDragStart = useCallback((e, c) => {
    setDragId(c.id);
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', String(c.id)); } catch { /* some browsers */ }
    }
  }, []);
  const handleDragEnd = useCallback(() => { setDragId(null); setOverPhase(null); }, []);

  // Vehicles for the "add against any car" picker — fetched lazily on first add.
  const [vehicles, setVehicles] = useState([]);
  const [vehiclesLoaded, setVehiclesLoaded] = useState(false);
  const ensureVehicles = useCallback(async () => {
    if (vehiclesLoaded) return;
    try {
      const { data: v } = await api.get('/Vehicle');
      setVehicles(v.data || []);
    } catch { /* picker just stays empty */ }
    setVehiclesLoaded(true);
  }, [vehiclesLoaded]);

  // Closing the manage modal re-pulls the board — an edit may have changed a car's
  // latest stage, priority, garage or cost.
  const closeManage = useCallback(() => { setManage(null); reload(); }, [reload]);
  const openAdd = () => { ensureVehicles(); setManage({ pick: true }); };

  // Floating page gauge: share of in-garage cars that are critical priority.
  // Only the standalone page publishes (publishStat); the Dashboard embed stays
  // silent so it doesn't fight the Dashboard's own utilization gauge.
  const totalCars = data?.cars?.length || 0;
  const criticalCars = data?.summary?.critical || 0;
  usePageStat(publishStat ? {
    percent: totalCars ? (criticalCars / totalCars) * 100 : null,
    label: 'Critical',
    color: 'red',
    hint: `${criticalCars} of ${totalCars} cars in the garage are critical priority`,
  } : {});

  if (loading) {
    return (
      <div className="space-y-6">
        <MetricGridSkeleton count={4} />
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-72 rounded-2xl" />
          ))}
        </div>
      </div>
    );
  }

  const cars = data?.cars || [];
  const s = data?.summary || {};
  const pulse = data?.pulse || null;
  const avgDays = pulse?.avg_repair_days ?? null;
  const stages = s.stages || {};
  const shown = cars.filter(
    (c) => (!priority || c.priority === priority) && (!stage || (c.stage || 'unknown') === stage)
  );

  // Kanban grouping: each card's phase = its optimistic override, else derived.
  const phaseKey = (c) => phaseOverride[c.id] || phaseOf(c);
  const columns = PHASES.map((p) => ({ ...p, cars: shown.filter((c) => phaseKey(c) === p.key) }));
  const dropOn = (target) => {
    const id = dragId;
    setOverPhase(null);
    setDragId(null);
    if (id == null) return;
    const car = cars.find((c) => c.id === id);
    if (!car || phaseKey(car) === target) return;
    setPhaseOverride((m) => ({ ...m, [id]: target }));
    toast.info(`Moved ${car.plate || `#${car.contract_no || car.id}`} → “${PHASE_BY_KEY[target].label}”. Saving workflow stage changes is coming soon.`);
  };

  const priorityFilters = [
    { key: '', label: 'All', n: cars.length, dot: null },
    { key: 'critical', label: 'Critical', n: s.critical, dot: 'bg-red-500' },
    { key: 'special', label: 'Special', n: s.special, dot: 'bg-violet-500' },
    { key: 'minor', label: 'Minor', n: s.minor, dot: 'bg-amber-500' },
    { key: 'routine', label: 'Routine', n: s.routine, dot: 'bg-emerald-500' },
  ];

  // Workshop-stage filter chips. We always list the four operational stages actually
  // used — OUT / IN / Follow up / Test — plus the "No log" bucket, even when a stage has
  // 0 cars right now, so nothing looks "missing". (A stage like IN = "car came back" is
  // naturally 0 here: this board only holds cars still in the garage, so a returned car
  // has already left it.) Empty chips render greyed & disabled. Any other raw stage that
  // shows up in the data (Change / Delay / Under Test …) is still appended by the safety
  // net below so a car is never hidden — it just isn't a top-level chip.
  const STAGE_ORDER = ['OUT', 'IN', 'Follow up', 'Test', 'unknown'];
  const stageFilters = [
    { key: '', label: 'All', n: cars.length },
    ...STAGE_ORDER.map((key) => ({ key, label: stageInfo(key).label, n: stages[key] || 0 })),
    // Safety net: any stage present in the data but not in the canonical list above.
    ...Object.keys(stages)
      .filter((key) => !STAGE_ORDER.includes(key))
      .map((key) => ({ key, label: stageInfo(key).label, n: stages[key] })),
  ];

  return (
    <div className="space-y-6">
        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {canManage && (
          <div className="flex items-center justify-end">
            <Button onClick={openAdd}>+ Add event</Button>
          </div>
        )}

        {/* SLA timeliness summary (timeliness = is each car back on time?) */}
        <MetricGrid cols={4}>
          <MetricCard
            label="In maintenance"
            value={num(s.total)}
            tone="slate"
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint="Cars currently in the garage"
            tooltip="Total cars with an open maintenance contract or a live workshop event."
          />
          <MetricCard
            label="On track"
            value={num(s.on_track)}
            tone="emerald"
            icon={<Icon.Check className="h-5 w-5" />}
            hint="Within expected return window"
            tooltip="Cars whose repair is still inside its expected return window (SLA on track)."
          />
          <MetricCard
            label="At risk"
            value={num(s.at_risk)}
            tone="amber"
            icon={<Icon.Clock className="h-5 w-5" />}
            hint="Nearing the return deadline"
            tooltip="Cars approaching their expected return date — at risk of breaching the SLA."
          />
          <MetricCard
            label="Overdue"
            value={num(s.breached)}
            tone="red"
            icon={<Icon.Alert className="h-5 w-5" />}
            hint="Past expected return"
            tooltip="Cars past their expected return date — the maintenance SLA is breached."
          />
          {/* Live pulse metrics — merged into the one metric row (was a separate gradient
              "Maintenance Pulse" panel). Only when the panel opts into the pulse + data exists. */}
          {showPulse && pulse && (
            <>
              <MetricCard
                label="Avg repair time"
                value={pulse.avg_repair_days != null ? `${pulse.avg_repair_days}d` : '—'}
                tone="slate"
                icon={<Icon.Clock className="h-5 w-5" />}
                hint="Last 90 days turnaround"
              />
              <MetricCard
                label="Back this week"
                value={num(pulse.completed_week)}
                tone="emerald"
                icon={<Icon.Check className="h-5 w-5" />}
                hint="Visits completed"
              />
              {pulse.service_due_soon != null && (
                <MetricCard
                  label="Service due soon"
                  value={num(pulse.service_due_soon)}
                  tone={pulse.service_due_soon > 0 ? 'amber' : 'slate'}
                  icon={<Icon.Gauge className="h-5 w-5" />}
                  hint={pulse.service_due_soon > 0 ? 'Approaching the interval' : 'All serviced'}
                  to="/maintenance-foresight"
                />
              )}
              {SHOW_FINANCIALS && (
                <MetricCard
                  label="Spend this week"
                  value={aed(pulse.spend_week)}
                  tone="slate"
                  icon={<Icon.Coins className="h-5 w-5" />}
                  hint="Repairs started this week"
                />
              )}
            </>
          )}
        </MetricGrid>

        <SectionCard
          title="In the garage"
          subtitle={`${num(shown.length)} of ${num(cars.length)} cars shown`}
          actions={
            <span className="hidden items-center gap-1.5 text-xs text-slate-400 sm:inline-flex">
              <Icon.Filter className="h-4 w-4" />
              Filter by priority &amp; stage
            </span>
          }
          bodyClass="p-4 sm:p-5 space-y-4"
        >
          {/* Priority filter (severity of the maintenance situation) */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="mr-1 inline-flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              Priority
              <InfoTip content="Severity of the maintenance situation, derived from the sheet reason keywords (critical / special / minor / routine)." />
            </span>
            {priorityFilters.map((f) => (
              <button
                key={f.key || 'all'}
                onClick={() => setPriority(f.key)}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                  priority === f.key
                    ? 'bg-indigo-600 text-white ring-indigo-600'
                    : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                }`}
              >
                {f.dot && <span className={`h-1.5 w-1.5 rounded-full ${f.dot}`} />}
                {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
              </button>
            ))}
          </div>

          {/* Workshop-stage filter (live OUT / IN / Follow-up flow from the maintenance log) */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="mr-1 inline-flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              Stage
              <InfoTip content="Live workshop stage from the maintenance-sheet event log — OUT / IN / Follow up / Test (plus No log). The full set is always shown; a stage with 0 cars (e.g. IN = already returned) is greyed out." />
            </span>
            {stageFilters.map((f) => {
              const empty = f.key !== '' && f.n === 0;
              return (
                <button
                  key={f.key || 'all'}
                  onClick={() => { if (!empty) setStage(f.key); }}
                  disabled={empty}
                  title={empty ? 'No cars at this stage right now' : undefined}
                  className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                    stage === f.key
                      ? 'bg-indigo-600 text-white ring-indigo-600'
                      : empty
                        ? 'cursor-not-allowed bg-slate-50 text-slate-300 ring-slate-200'
                        : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                  }`}
                >
                  {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
                </button>
              );
            })}
          </div>

          {grouped ? (
            cars.length > 0 ? (
              <div className="space-y-3">
                <p className="flex items-center gap-1.5 text-xs text-slate-400">
                  <GripIcon className="h-3.5 w-3.5" />
                  Cards are workflow steps — drag a card between columns to move it across stages.
                </p>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                  {columns.map((col) => (
                    <PhaseColumn
                      key={col.key}
                      phase={col}
                      cars={col.cars}
                      avgDays={avgDays}
                      isOver={overPhase === col.key}
                      canManage={canManage}
                      onLog={setLogCar}
                      onManage={(car) => setManage({ car })}
                      onDragStart={handleDragStart}
                      onDragEnd={handleDragEnd}
                      dragId={dragId}
                      onDragOver={(e) => { if (dragId != null) { e.preventDefault(); if (overPhase !== col.key) setOverPhase(col.key); } }}
                      onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setOverPhase((p) => (p === col.key ? null : p)); }}
                      onDrop={() => dropOn(col.key)}
                    />
                  ))}
                </div>
              </div>
            ) : (
              <EmptyState
                icon={<Icon.Wrench className="h-7 w-7" />}
                title="No cars in maintenance"
                message="Nothing is currently in the garage."
              />
            )
          ) : shown.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {shown.map((c) => (
                <MaintenanceCard
                  key={c.id}
                  c={c}
                  avgDays={avgDays}
                  canManage={canManage}
                  onLog={setLogCar}
                  onManage={(car) => setManage({ car })}
                />
              ))}
            </div>
          ) : (
            <EmptyState
              icon={<Icon.Wrench className="h-7 w-7" />}
              title={cars.length === 0 ? 'No cars in maintenance' : `No ${priority || 'matching'} maintenance`}
              message={cars.length === 0 ? 'Nothing is currently in the garage.' : 'No cars match the selected filters.'}
            />
          )}
        </SectionCard>

        <GarageLog car={logCar} onClose={() => setLogCar(null)} />

        {canManage && (
          <ManageModal
            open={!!manage}
            car={manage?.car || null}
            vehicles={vehicles}
            onClose={closeManage}
          />
        )}
    </div>
  );
}

export default function MaintenanceBoard() {
  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1700px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Fleet Maintenance Command Center" subtitle={`The health of your fleet at a glance — vital signs, cars in the garage, due dates, issues${SHOW_FINANCIALS ? ', cost' : ''} and live status.`}>
          {SHOW_FINANCIALS && (
            <Link to="/maintenance-analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Cost Analytics →</Link>
          )}
        </PageHeader>
        <NavStrip />
        <MaintenanceBoardPanel publishStat manageable grouped showPulse />
      </div>
    </div>
  );
}
