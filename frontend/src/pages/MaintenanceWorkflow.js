import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import { EmptyState } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import TicketActionModal from '../components/workflow/TicketActionModal';
import TicketDetailDrawer from '../components/workflow/TicketDetailDrawer';
import TicketCommandView from '../components/workflow/TicketCommandView';
import TaskRoutingModal from '../components/workflow/TaskRoutingModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import TestIntakeModal from '../components/workflow/TestIntakeModal';
import CreateMoveModal from './logistics/CreateMoveModal';
import { resolveAction, allows, ctaLabel, SEVERITY_CHIP, TASK_STATUS, stageAge, isAtGarage, custodyBlocked } from '../components/workflow/meta';
import { SHOW_VIDEO_REVIEW } from '../config/features';

// The Controller-dashboard lanes (match the API's board.columns keys). Titles + role
// captions are resolved from the i18n catalog (workflow.lane.<key>) at render time.
// The "repair_review" lane is the Supervisor Video-Review gate — hidden while that gate
// is parked (SHOW_VIDEO_REVIEW = false); "Mark Ready" then flows straight to the final gate.
const LANES = [
  { key: 'requested',       tone: '#d946ef' },
  { key: 'diagnostic',      tone: '#8b5cf6' },
  { key: 'pending',         tone: '#a855f7' }, // ticket open — awaiting the Supervisor's dispatch
  { key: 'awaiting_pickup', tone: '#f59e0b' }, // garage + driver assigned — awaiting pickup
  { key: 'in_transit',      tone: '#f59e0b' }, // Now at Garage — arrival checkpoint: confirm arrival + log the mandatory odometer (no repairs here)
  { key: 'under_repair',    tone: '#f97316' }, // In Workshop — the repair itself: cost, faults & timeline
  ...(SHOW_VIDEO_REVIEW ? [{ key: 'repair_review', tone: '#7c3aed' }] : []), // Supervisor Video-Review: garage finished — Waleed/Abdullah review the video
  { key: 'ready_for_pickup', tone: '#10b981' }, // signed off — awaiting the driver's RETURN leg (collect + arrive)
  { key: 'qa_reinspection',  tone: '#9333ea' }, // Final QA — major repair, back at our park, awaiting the Inspector's sign-off (replaces the always-empty in_our_park transient lane)
  { key: 'reinspection_failed', tone: '#dc2626' }, // QC: came back but still broken — supervisor re-dispatches
  { key: 'on_site',         tone: '#0d9488' }, // On-Site (mobile) — separate side-lane; car stays available, one "Mark as Serviced" step closes it
];

// How many cards a lane shows before collapsing the rest behind a "Show more" button.
const LANE_PAGE_SIZE = 3;

// Fault-severity filter chips for the board toolbar ('' = show all). Mirrors the inspector's grades.
const SEV_FILTERS = [
  { value: '', key: 'filterAll' },
  { value: 'critical', key: 'critical', emoji: '🔴' },
  { value: 'moderate', key: 'moderate', emoji: '🟡' },
  { value: 'high', key: 'high', emoji: '🟠' },
  { value: 'routine', key: 'routine', emoji: '🟢' },
];

// Live-position chip tones (from the backend's unified position.tone) + the icon per phase, so the
// card shows WHERE the car is — In Transit / In Workshop — as a moving part of this same ticket.
const POSITION_TONE = {
  blue:   'bg-sky-50 text-sky-700 ring-sky-200',
  red:    'bg-red-50 text-red-700 ring-red-200',
  amber:  'bg-amber-50 text-amber-700 ring-amber-200',
  violet: 'bg-violet-50 text-violet-700 ring-violet-200',
  green:  'bg-emerald-50 text-emerald-700 ring-emerald-200',
  cyan:   'bg-cyan-50 text-cyan-700 ring-cyan-200',
  teal:   'bg-teal-50 text-teal-700 ring-teal-200',
  slate:  'bg-slate-50 text-slate-600 ring-slate-200',
};
const POSITION_ICON = { in_transit: '🚚', in_workshop: '🔧', repair_review: '🎬', awaiting_pickup: '📦', awaiting_dispatch: '📋', awaiting_reinspection: '✅', under_diagnosis: '🔍', inspection_requested: '🚩', reinspection_failed: '⛔', complaint_triage: '📣', ready_for_pickup: '🧳', in_our_park: '🏁' };

// A single board card — deliberately dense and quiet. It shows only what a controller needs to
// scan the lane (plate, who has the car) plus the ONE primary stage action; everything
// else (full history, findings, photos, secondary actions) lives one click away in the drawer.
function TicketCard({ tk, tone, can, userId, active, onOpen, onAct }) {
  const { t } = useI18n();
  const cardRef = useRef(null);

  // When this card is the deep-link / active target, scroll it into view once it renders.
  useEffect(() => {
    if (active && cardRef.current) {
      cardRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, [active]);

  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  // "Mark Ready" is gated: blocked until every fault on the ticket is fixed (or cancelled).
  const openFaults = tk.tasks_progress?.open ?? 0;
  const readyBlocked = act?.action === 'ready' && openFaults > 0;
  const readyHint = `Fix all ${openFaults} open fault${openFaults > 1 ? 's' : ''} first`;
  // "Now at Garage" custody gate: only the driver who picked the car up may check it in.
  const custodyLocked = act?.action === 'receive' && custodyBlocked(tk, userId);
  const custodyHint = `Only ${tk.dispatched_by_name || 'the driver who picked up the car'} can check it in`;
  const tasks = tk.tasks || [];
  const canRoute = can('maintenance.delegate'); // the Supervisor's dispatch authority routes faults
  // Follow-up notes (Waleed/Abdullah) surfaced right on the card — only while the car is In Workshop
  // (under_repair), i.e. actually at the garage being worked on. Mirrors the drawer's canFollowUp.
  const canFollowUp = can('maintenance.delegate') && tk.workflow_status === 'under_repair';
  const driverName = tk.dispatched_by_name || tk.assigned_driver_name;
  const delegated = tk.delegation?.status === 'driver_assigned' && tk.delegation.driver_name;
  // Critical tickets get a card-level red accent so they pop out of the lane at a glance — the
  // active (deep-link) ring still wins so a focused card is never visually ambiguous.
  const critical = tk.fault_severity === 'critical';
  // Customer complaints jump the queue: management must prioritise them above all else, so the card
  // wears a bold "Customer Complaint" badge (and a rose accent when it isn't already critical-red).
  const isComplaint = tk.trigger_reason === 'customer_reported';
  // A breakdown grounds the car (condition RED / In-Maintenance) — surfaced as "Disabled" in this context,
  // and once it's on a recovery truck the same Disabled state holds. A hard, unmissable red pill.
  const isDisabled = tk.maintenance_type === 'breakdown' || tk.is_recovery;
  // Time in the CURRENT stage (not the misleading creation date). `age.over` reddens the label once a
  // stage overstays its SLA (e.g. a supervisor sitting on a Pending-Dispatch ticket past 4h).
  const age = stageAge(tk, t);

  return (
    <div
      ref={cardRef}
      onClick={() => onOpen(tk)}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen(tk))}
      className={`group cursor-pointer overflow-hidden rounded-lg border bg-white transition focus:outline-none ${
        active
          ? 'border-indigo-500 bg-indigo-50/40 shadow-md animate-pulse-ring'
          : critical
            ? 'border-red-200 ring-1 ring-red-100 shadow-sm hover:-translate-y-px hover:border-red-300 hover:shadow-md'
            : isComplaint
              ? 'border-rose-200 ring-1 ring-rose-100 shadow-sm hover:-translate-y-px hover:border-rose-300 hover:shadow-md'
              : 'border-slate-200 shadow-sm hover:-translate-y-px hover:border-slate-300 hover:shadow-md'
      }`}
    >
      <div className="flex">
        {/* Left status rail — the lane colour, doubling as a click affordance. */}
        <span className="w-1 shrink-0" style={{ background: tone }} />

        <div className="min-w-0 flex-1 px-2.5 py-2">
          {/* Vehicle identification — plate as the bold scan-key, vehicle name beneath it (mirrors the
              drawer's plate-title + car-subtitle), so a controller IDs the car without opening it. */}
          <div className="flex items-start justify-between gap-2">
            <span className="inline-flex min-w-0 flex-col">
              <span className="inline-flex min-w-0 items-center gap-1.5 font-mono text-sm font-bold tracking-wider text-slate-800">
                <Icon.Car className="h-3.5 w-3.5 shrink-0 text-slate-400" strokeWidth={2} />
                <span className="truncate">{tk.plate || `#${tk.id}`}</span>
              </span>
              {tk.car && (
                <span className="mt-0.5 truncate pl-5 text-[11px] font-medium text-slate-500" title={tk.car}>
                  {tk.car}
                </span>
              )}
            </span>
            <span className="flex shrink-0 items-center gap-1">
              {/* Time in this stage — reddens once the stage overstays its SLA (supervisor slacking). */}
              {age && (
                <span
                  className={`inline-flex items-center gap-0.5 text-[10px] font-semibold tabular-nums ${age.over ? 'text-red-600' : 'text-slate-400'}`}
                  title={t('queue.timeInStage')}
                >
                  <Icon.Clock className="h-3 w-3" />
                  {age.label}
                </span>
              )}
              {/* Open the full-page command view (the rich single-ticket page). */}
              <Link
                to={`/maintenance-workflow/${tk.id}`}
                onClick={(e) => e.stopPropagation()}
                className="rounded-md p-0.5 text-slate-300 opacity-0 transition hover:bg-slate-100 hover:text-indigo-600 group-hover:opacity-100"
                title={t('workflow.detail.eyebrow', { id: tk.id })}
              >
                <Icon.ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </span>
          </div>

          {/* Customer Complaint — the top-priority flag. Management dispatches these first, so it's the
              boldest chip on the card (filled rose, above even the severity grade). */}
          {isComplaint && (
            <div className="mt-1.5">
              <span
                className="inline-flex items-center gap-1 rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white shadow-sm ring-1 ring-inset ring-rose-700/40"
                title={tk.customer_complaint || t('workflow.complaint.badge')}
              >
                📣 {t('workflow.complaint.badge')}
              </span>
            </div>
          )}

          {/* Disabled — a breakdown-grounded car (or one on a recovery truck). Reuses the RED /
              In-Maintenance grounding, labelled "Disabled" for the breakdown/recovery context. */}
          {isDisabled && (
            <div className="mt-1.5 flex flex-wrap items-center gap-1">
              <span className="inline-flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white shadow-sm ring-1 ring-inset ring-red-700/40">
                ⛔ {t('workflow.status.disabled')}
              </span>
              {tk.is_recovery && (
                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20" title={tk.recovery_unit_phone || ''}>
                  🛻 {tk.recovery_unit_name}
                </span>
              )}
            </div>
          )}

          {/* Request agenda — the "why" behind a system/driver-raised inspection (a customer complaint
              already has its own rose badge above). For a system Post-Downtime request this is where the
              idle duration shows ("Vehicle idle for 25 days — go check: Battery, Fluids, and Brakes"), so
              the inspector sees how long the car has sat, and what to check, without opening the ticket. */}
          {tk.customer_complaint && tk.trigger_reason !== 'customer_reported' && (
            <div className="mt-1.5">
              <p
                className="line-clamp-2 rounded-md bg-amber-50 px-2 py-1 text-[10px] font-medium leading-snug text-amber-800 ring-1 ring-inset ring-amber-600/15"
                title={tk.customer_complaint}
              >
                🕗 {tk.customer_complaint}
              </p>
            </div>
          )}

          {/* Fault severity — the inspector's grade, surfaced up top as the card's headline urgency. */}
          {tk.fault_severity && (
            <div className="mt-1.5">
              <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${SEVERITY_CHIP[tk.fault_severity_tone] || SEVERITY_CHIP.amber}`}>
                {tk.fault_severity_emoji} {t(`workflow.faultSeverity.${tk.fault_severity}`)}
              </span>
            </div>
          )}

          {/* "Sent back" — this car already came back broken and was re-dispatched to a garage. Shown
              at a glance so nobody has to open the ticket to learn it's a repeat trip. */}
          {tk.sent_back && (
            <div className="mt-1.5">
              <span
                className="inline-flex max-w-full items-center gap-1 rounded-md bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-700 ring-1 ring-inset ring-rose-600/20"
                title={
                  tk.sent_back.changed
                    ? t('workflow.board.sentBackTipChanged', { from: tk.sent_back.from_garage || '—', to: tk.sent_back.to_garage || '—' })
                    : t('workflow.board.sentBackTipSame', { to: tk.sent_back.to_garage || '—' })
                }
              >
                <span>⛔</span>
                <span className="truncate">{t('workflow.board.sentBack')}{tk.sent_back.count > 1 ? ` ×${tk.sent_back.count}` : ''}</span>
              </span>
            </div>
          )}

          {/* Live position — the unified "where is the car" (In Transit / In Workshop / …), derived on
              the server from this ticket's status + active garage stint + active move. The logistics
              leg shows here, ON the ticket, not on a separate board. */}
          {tk.position?.label && (
            <div className="mt-1.5 flex flex-wrap items-center gap-1">
              <span
                className={`inline-flex max-w-full items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${POSITION_TONE[tk.position.tone] || POSITION_TONE.slate} ${tk.position.moving ? 'animate-pulse' : ''}`}
                title={tk.position.detail || tk.position.label}
              >
                <span>{POSITION_ICON[tk.position.phase] || '•'}</span>
                <span className="truncate">
                  {tk.position.label}{tk.position.garage ? ` · ${tk.position.garage}` : ''}
                  {tk.position.transfer && tk.position.destination ? ` → ${tk.position.destination}` : ''}
                </span>
              </span>
              {/* Transfer flag — an at-a-glance marker that this car is being moved between garages. */}
              {tk.position.transfer && (
                <span className="inline-flex items-center gap-0.5 rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 ring-1 ring-inset ring-violet-200">
                  🔀 {t('workflow.position.transfer')}
                </span>
              )}
            </div>
          )}

          {/* Single-garage routing — the faults grouped under the car's ONE current garage, so a
              controller sees every fault and where the car is, without opening the ticket. */}
          {tasks.length > 0 && (
            <div className="mt-2 space-y-1">
              {/* Group header — the single current garage all faults sit at (shown once). */}
              <div className="flex items-center gap-1 text-[10px] font-semibold text-slate-500">
                <Icon.Wrench className="h-3 w-3 shrink-0 text-slate-400" />
                <span className="truncate" title={tk.garage || ''}>
                  {tk.garage || <span className="italic font-normal text-slate-400">{t('workflow.task.unassigned')}</span>}
                </span>
                <span className="text-slate-300">·</span>
                <span className="shrink-0 text-slate-400">{tasks.length}</span>
              </div>
              {tasks.map((task) => {
                const st = TASK_STATUS[task.status] || TASK_STATUS.pending;
                const failCount = Number(task.reinspection_failures) || 0;
                return (
                  <div key={task.id} className="flex items-center gap-1.5 rounded-md bg-slate-50 px-1.5 py-1 ring-1 ring-inset ring-slate-100" title={task.symptom}>
                    <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${st.dot}`} />
                    <span className="truncate text-[10px] font-medium text-slate-700">{task.symptom}</span>
                    {/* QC blame — this fault came back unfixed before; shows which garage + how many times. */}
                    {failCount > 0 && (
                      <span
                        className="ms-auto inline-flex shrink-0 items-center gap-0.5 rounded bg-red-100 px-1 py-0.5 text-[9px] font-bold text-red-700"
                        title={t('workflow.reinspect.unresolvedBadge', { n: failCount, garage: task.last_failed_garage || t('workflow.task.unassigned') })}
                      >
                        ⛔{failCount > 1 ? `×${failCount}` : ''}
                      </span>
                    )}
                  </div>
                );
              })}
              {/* "Manage faults" only appears once the car is at the garage stage — before that
                  (awaiting dispatch) there's nothing to work on yet. It's hidden again at
                  ready_for_pickup: by then the faults are fixed and there's nothing left to route. */}
              {canRoute && isAtGarage(tk) && tk.workflow_status !== 'ready_for_pickup' && (
                <div onClick={(e) => e.stopPropagation()}>
                  <button
                    type="button"
                    onClick={() => onAct('route', tk)}
                    className="mt-0.5 inline-flex w-full items-center justify-center gap-1 rounded-md border border-dashed border-slate-300 px-2 py-1 text-[10px] font-semibold text-slate-500 transition hover:border-indigo-300 hover:bg-indigo-50/50 hover:text-indigo-600"
                  >
                    <Icon.Wrench className="h-3 w-3" /> {t('workflow.task.route')}
                  </button>
                </div>
              )}
            </div>
          )}

          {/* Who has the car / delegation — the only attribution kept on the card */}
          {(driverName || delegated) && (
            <div className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500">
              {delegated ? (
                <span className="inline-flex items-center gap-1 font-medium text-indigo-600">
                  <Icon.Car className="h-3 w-3 shrink-0" />
                  {t('workflow.delegation.assigned')} · {tk.delegation.driver_name}
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 truncate">
                  <Icon.Truck className="h-3 w-3 shrink-0 text-slate-400" />
                  <span className="truncate">{driverName}</span>
                </span>
              )}
            </div>
          )}

          {/* Add note — management follow-up (Waleed/Abdullah), on the card so it's one tap while the car
              is out, no drawer needed. */}
          {canFollowUp && (
            <div className="mt-1.5" onClick={(e) => e.stopPropagation()}>
              <button
                type="button"
                onClick={() => onAct('followup', tk)}
                className="inline-flex w-full items-center justify-center gap-1 rounded-md border border-dashed border-slate-300 px-2 py-1 text-[10px] font-semibold text-slate-500 transition hover:border-indigo-300 hover:bg-indigo-50/50 hover:text-indigo-600"
              >
                <Icon.Plus className="h-3 w-3" /> {t('workflow.cardAction.followup')}
              </button>
            </div>
          )}

          {/* Primary action only — secondary actions moved to the drawer */}
          {allowed && custodyLocked && (
            <div className="mt-2.5" onClick={(e) => e.stopPropagation()}>
              <p className="text-center text-[10px] font-medium text-amber-600">{custodyHint}</p>
            </div>
          )}
          {allowed && !custodyLocked && (
            <div className="mt-2.5" onClick={(e) => e.stopPropagation()}>
              <Button size="sm" variant={act.variant} disabled={readyBlocked} title={readyBlocked ? readyHint : undefined} onClick={() => onAct(act.action, tk)} className="w-full justify-center">
                {ctaLabel(t, tk)}
              </Button>
              {readyBlocked && <p className="mt-1 text-center text-[10px] font-medium text-amber-600">{readyHint}</p>}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export default function MaintenanceWorkflow() {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const { user } = useAuth();
  const { id } = useParams();
  const focusId = id ? Number(id) : null; // deep-link target from a notification

  const [modal, setModal] = useState(null);            // { action, ticket } | { action:'request' }
  // A deep-linked ticket (/maintenance-workflow/:id) opens the full-page command view;
  // a click on a board card opens the lighter slide-over drawer instead.
  const [detail, setDetail] = useState(null);          // open drawer { id, summary? }
  const [detailReload, setDetailReload] = useState(0); // bumped to refresh the drawer after an action
  const [vehicles, setVehicles] = useState([]);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);
  const [maintTypes, setMaintTypes] = useState([]); // permission-scoped classification values (technician → routine/breakdown)
  const [keywordMeta, setKeywordMeta] = useState({}); // keyword → { risk, tone, emoji, ar } (bilingual + risk chips)
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({}); // symptom → probable root causes (diagnostic step)
  const [drivers, setDrivers] = useState([]); // delegation picker — logistics users (supervisors only)
  const [sevFilter, setSevFilter] = useState(''); // board fault-severity filter ('' = all)
  const [expandedLanes, setExpandedLanes] = useState({}); // lane.key → true once "Show more" is clicked

  // Real-time board: silent background revalidation every 6s (no skeleton flash, scroll &
  // filters preserved), paused while a modal/drawer is open so the view never shifts mid-action.
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 6000,
    paused: () => !!modal || !!detail,
  });

  const canDelegate = can('maintenance.delegate');

  // Reference lists for the pickers: vehicles for "open", garages for dispatch/close, and the
  // central Findings keyword library (config-backed) for the test-drive & garage-findings steps.
  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vehicle'), api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([v, g, f]) => {
        if (!alive) return;
        const vlist = v.data?.data;
        const allVehicles = Array.isArray(vlist) ? vlist : vlist?.items || [];
        // Only Ready + Rented cars can be flagged for inspection
        setVehicles(allVehicles.filter((veh) => ['ready', 'rented'].includes(veh.status)));
        const glist = g.data?.data;
        const all = Array.isArray(glist) ? glist : glist?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
        setFindingsCatalog(f.data?.data?.categories || []);
        setKeywordMeta(f.data?.data?.keyword_risk || {});
        setFaultCausesCatalog(f.data?.data?.fault_causes || {});
        setMaintTypes((f.data?.data?.maintenance_types || []).map((x) => x.value));
      })
      .catch(() => { /* pickers just stay empty / fall back to the built-in list */ });
    return () => { alive = false; };
  }, []);

  // The delegation picker's driver list — only a supervisor (maintenance.delegate) may load it.
  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => { /* leave the picker empty on failure */ });
    return () => { alive = false; };
  }, [canDelegate]);

  // Memoised so its identity is stable while `data` is unchanged — otherwise the `lanes` useMemo below
  // (which depends on it) would recompute on every render.
  const columns = useMemo(() => data?.columns || {}, [data]);
  const counts = data?.counts || {};

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });      // swap in the new board state with no skeleton flash
    setDetailReload((n) => n + 1); // refresh the drawer if it's open
  };

  const canInitiate = can('maintenance.initiate');
  const canLogistics = can('maintenance.logistics');
  const canManage = can('maintenance.manage'); // Operations controllers (Marwa & Leen) — complaint intake

  const lanes = useMemo(() => LANES.map((l) => {
    const own = columns[l.key] || [];
    const merged = (l.mergeKeys || []).flatMap((k) => columns[k] || []);
    let tickets = [...merged, ...own];
    if (sevFilter) tickets = tickets.filter((tk) => tk.fault_severity === sevFilter);
    return { ...l, tickets };
  }), [columns, sevFilter]);

  const openDetail = (tk) => setDetail({ id: tk.id, summary: tk });

  // Deep-linked to a single ticket → render the full-page command view; the board's
  // action modals (rendered below) stay shared, and an action there bumps detailReload
  // so the command view refreshes itself.
  if (focusId) {
    return (
      <>
        <TicketCommandView
          ticketId={focusId}
          can={can}
          userId={user?.id}
          reloadKey={detailReload}
          onAct={(action, ticket) => setModal({ action, ticket })}
        />
        {modal?.action === 'logistics' && (
          <CreateMoveModal
            open
            lockedVehicle={modal.ticket ? { id: modal.ticket.vehicle_id, plate: modal.ticket.plate, label: modal.ticket.car } : null}
            maintenanceId={modal.ticket?.id || null}
            onClose={() => setModal(null)}
            onCreated={() => onDone()}
          />
        )}
        {modal?.action === 'route' && (
          <TaskRoutingModal
            ticket={modal.ticket}
            garages={garages}
            onClose={() => setModal(null)}
            onDone={() => { reload({ silent: true }); setDetailReload((n) => n + 1); }}
          />
        )}
        {modal?.action === 'triage' && (
          <ComplaintTriageModal
            ticket={modal.ticket}
            vehicles={vehicles}
            onClose={() => setModal(null)}
            onDone={onDone}
          />
        )}
        {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test'].includes(modal.action) && (
          <TicketActionModal
            action={modal.action}
            ticket={modal.ticket || null}
            vehicles={vehicles}
            garages={garages}
            findingsCatalog={findingsCatalog}
            keywordMeta={keywordMeta}
            faultCausesCatalog={faultCausesCatalog}
            assignableDrivers={drivers}
            allowedTypes={maintTypes}
            onClose={() => setModal(null)}
            onDone={onDone}
          />
        )}
      </>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1700px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Command deck — same language as the single-ticket command view, so the
            board and the focused page feel like one system. The pipeline strip mirrors
            the ticket journey: lanes flow left→right with live, glowing counts. */}
        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-900 via-navy-900 to-navy-950 px-6 py-7 shadow-xl ring-1 ring-white/10 sm:px-8">
          <div className="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-brand-500/25 blur-3xl" />
          <div className="pointer-events-none absolute -bottom-24 left-1/4 h-64 w-64 rounded-full bg-violet-500/15 blur-3xl" />

          <div className="relative space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-brand-300/90">
                  <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-70" /><span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" /></span>
                  Maintenance Control · {t('workflow.board.live')}
                </p>
                <h1 className="mt-2 font-display text-3xl font-bold tracking-tight text-white">{t('workflow.board.title')}</h1>
                <p className="mt-1 max-w-2xl text-sm text-slate-300">{t('workflow.board.subtitle')}</p>
              </div>
              <div className="flex shrink-0 items-center gap-5">
                <div className="text-right">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Open tickets</p>
                  <p className="font-display text-3xl font-bold text-white tabular-nums">
                    {loading ? '—' : (counts.open_total ?? lanes.reduce((a, l) => a + l.tickets.length, 0))}
                  </p>
                </div>
                {/* Complaint Intake — the Operations controllers (Marwa & Leen) log a customer
                    complaint straight into the dispatch queue. Gated to maintenance.manage. */}
                {canManage && (
                  <Button onClick={() => setModal({ action: 'test' })}>
                    <Icon.Plus className="h-4 w-4" /> {t('workflow.testIntake.newTest')}
                  </Button>
                )}
                {canManage && (
                  <Button variant="secondary" onClick={() => setModal({ action: 'complaint' })}>
                    <span aria-hidden>📣</span> {t('workflow.board.newComplaint')}
                  </Button>
                )}
                {canLogistics && (
                  <Button variant={canManage ? 'secondary' : 'primary'} onClick={() => setModal({ action: 'request' })}>
                    <Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}
                  </Button>
                )}
              </div>
            </div>

            {/* Pipeline overview */}
            <div className="-mx-1 overflow-x-auto pb-1">
              <div className="flex min-w-[720px] items-center gap-2">
                {lanes.map((lane, i) => (
                  <div key={lane.key} className="flex flex-1 items-center gap-2">
                    <div className="flex-1 rounded-2xl border border-white/10 bg-white/5 px-3.5 py-3 backdrop-blur transition hover:border-white/20 hover:bg-white/10">
                      <div className="flex items-center gap-1.5">
                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: lane.tone, boxShadow: `0 0 8px ${lane.tone}` }} />
                        <p className="truncate text-[11px] font-semibold text-white">{t(`workflow.lane.${lane.key}.title`)}</p>
                      </div>
                      <p className="mt-1 font-display text-2xl font-bold tabular-nums text-white">{loading ? '—' : lane.tickets.length}</p>
                      <p className="truncate text-[10px] font-medium text-slate-400">{t(`workflow.lane.${lane.key}.role`)}</p>
                    </div>
                    {i < lanes.length - 1 && <Icon.ArrowRight className="h-4 w-4 shrink-0 text-white/25" />}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {/* Fault-severity filter — supervisors narrow the board to the urgency they care about. */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">{t('workflow.faultSeverity.label')}</span>
          {SEV_FILTERS.map((f) => {
            const active = sevFilter === f.value;
            return (
              <button
                key={f.value || 'all'}
                type="button"
                onClick={() => setSevFilter(f.value)}
                className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition ${active ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'}`}
              >
                {f.emoji && <span>{f.emoji}</span>}
                {t(`workflow.faultSeverity.${f.key}`)}
              </button>
            );
          })}
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7">
          {lanes.map((lane) => (
            <div key={lane.key} className="flex flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">
              {/* lane-colour accent + header */}
              <div className="h-1 w-full" style={{ background: lane.tone }} />
              <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2.5">
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: lane.tone, boxShadow: `0 0 8px ${lane.tone}99` }} />
                <p className="min-w-0 flex-1 font-display text-[13px] font-bold leading-tight text-slate-900">{t(`workflow.lane.${lane.key}.title`)}</p>
                <span
                  className="shrink-0 rounded-full px-2 py-0.5 font-display text-xs font-bold tabular-nums"
                  style={{ background: `${lane.tone}1a`, color: lane.tone }}
                >
                  {lane.tickets.length}
                </span>
              </div>

              {/* cards — tinted body so the white ticket cards pop */}
              <div className="flex min-h-[200px] flex-1 flex-col gap-2.5 bg-slate-50/60 p-2.5">
                {loading ? (
                  <>
                    <Skeleton className="h-20 rounded-xl" />
                    <Skeleton className="h-20 rounded-xl" />
                  </>
                ) : lane.tickets.length === 0 ? (
                  <div className="flex flex-1 flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-200 py-8 text-center">
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-white text-slate-300 shadow-sm ring-1 ring-slate-100">
                      <Icon.Check className="h-4 w-4" />
                    </span>
                    <p className="text-[11px] font-medium text-slate-400">{t('workflow.board.noTicketsHere')}</p>
                  </div>
                ) : (
                  <>
                    {(expandedLanes[lane.key] ? lane.tickets : lane.tickets.slice(0, LANE_PAGE_SIZE)).map((tk) => (
                      <TicketCard
                        key={tk.id}
                        tk={tk}
                        tone={lane.tone}
                        can={can}
                        userId={user?.id}
                        active={tk.id === detail?.id}
                        onOpen={openDetail}
                        onAct={(action, ticket) => setModal({ action, ticket })}
                      />
                    ))}
                    {!expandedLanes[lane.key] && lane.tickets.length > LANE_PAGE_SIZE && (
                      <button
                        onClick={() => setExpandedLanes((prev) => ({ ...prev, [lane.key]: true }))}
                        className="rounded-lg py-2 text-center text-[11px] font-semibold text-indigo-600 hover:bg-white hover:text-indigo-700"
                      >
                        {t('workflow.board.showMore', { count: lane.tickets.length - LANE_PAGE_SIZE })}
                      </button>
                    )}
                  </>
                )}
              </div>
            </div>
          ))}
        </div>

        {!loading && (counts.open_total ?? 0) === 0 && (
          <EmptyState
            icon={<Icon.Wrench className="h-7 w-7" />}
            title={t('workflow.board.noOpenTitle')}
            message={canLogistics ? t('workflow.board.emptyLogistics') : canInitiate ? t('workflow.board.emptyInspector') : t('workflow.board.emptyOther')}
          />
        )}
      </div>

      {/* Detail slide-over — the full ticket: history, findings, odometer photos, and every action. */}
      {detail && (
        <TicketDetailDrawer
          ticketId={detail.id}
          summary={detail.summary}
          can={can}
          userId={user?.id}
          reloadKey={detailReload}
          garages={garages}
          findingsCatalog={findingsCatalog}
          onAct={(action, ticket) => setModal({ action, ticket })}
          onClose={() => setDetail(null)}
        />
      )}

      {/* Dispatch a car straight from its ticket — the move is linked to this maintenance ticket. */}
      {modal?.action === 'logistics' && (
        <CreateMoveModal
          open
          lockedVehicle={modal.ticket ? { id: modal.ticket.vehicle_id, plate: modal.ticket.plate, label: modal.ticket.car } : null}
          maintenanceId={modal.ticket?.id || null}
          onClose={() => setModal(null)}
          onCreated={() => onDone()}
        />
      )}

      {/* Multi-garage task routing — assign / transfer faults to garages independently. */}
      {modal?.action === 'route' && (
        <TaskRoutingModal
          ticket={modal.ticket}
          garages={garages}
          onClose={() => setModal(null)}
          onDone={() => { reload(); setDetailReload((n) => n + 1); }}
        />
      )}

      {/* Complaint Intake — Operations logs a customer complaint → ticket opens in the dispatch queue. */}
      {modal?.action === 'complaint' && (
        <ComplaintIntakeModal
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {/* Breakdown Intake — a technician reports a not-driveable car → ticket opens grounded in the
          dispatch queue, car set RED. */}
      {modal?.action === 'breakdown' && (
        <BreakdownIntakeModal
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {/* Test Intake — the tabbed front door: Routine (oil/battery/tyres) · Scheduled (park-time +
          Breakdown) · Accidents (→ Damage & Accidents log). */}
      {modal?.action === 'test' && (
        <TestIntakeModal
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {/* Complaint Triage — Abu Maroof handles a logged complaint (talk / resolve on-site / send in). */}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal
          ticket={modal.ticket}
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test'].includes(modal.action) && (
        <TicketActionModal
          action={modal.action}
          ticket={modal.ticket || null}
          vehicles={vehicles}
          garages={garages}
          findingsCatalog={findingsCatalog}
          keywordMeta={keywordMeta}
          faultCausesCatalog={faultCausesCatalog}
          assignableDrivers={drivers}
          allowedTypes={maintTypes}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}
    </div>
  );
}
