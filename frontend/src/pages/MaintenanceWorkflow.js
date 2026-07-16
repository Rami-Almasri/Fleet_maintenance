import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import TicketActionModal from '../components/workflow/TicketActionModal';
import TicketDetailDrawer from '../components/workflow/TicketDetailDrawer';
import TicketCommandView from '../components/workflow/TicketCommandView';
import TaskRoutingModal from '../components/workflow/TaskRoutingModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import TestIntakeModal from '../components/workflow/TestIntakeModal';
import CreateMoveModal from './logistics/CreateMoveModal';
import {
  resolveAction, allows, ctaLabel, TASK_STATUS, stageAge, stageSeconds,
  isAtGarage, custodyBlocked, custodyHolderName, fmtDuration,
} from '../components/workflow/meta';
import { SHOW_VIDEO_REVIEW } from '../config/features';
import {
  KpiTile, CommandPanel, ScoreRing, OpsClock,
} from '../components/ops';
import '../components/ops/ops.css';
import './maintenance-workflow.css';

// The Cockpit board lanes. Titles here are the operator-facing names used in the redesigned
// control surface; each maps to the API board.columns key(s) it draws from. The first eight are
// the canonical pipeline (always shown, left→right = the ticket journey); the rest are EXCEPTION
// lanes appended only when they actually hold tickets, so no live ticket is ever hidden.
const PRIMARY_LANES = [
  { key: 'requested',        name: 'Needs Test Drive',   tone: '#d946ef', hint: 'Vehicles need a test drive to confirm the issue' },
  { key: 'diagnostic',       name: 'Being Inspected',    tone: '#8b5cf6', hint: 'Currently under inspection or diagnostic' },
  { key: 'pending',          name: 'Needs Dispatch',     tone: '#a855f7', hint: 'Ready to be dispatched to a garage' },
  { key: 'awaiting_pickup',  name: 'Awaiting Pickup',    tone: '#f59e0b', hint: 'Garage + driver assigned — awaiting pickup' },
  { key: 'in_transit',       name: 'En Route to Garage', tone: '#f59e0b', hint: 'On the way to the garage' },
  { key: 'under_repair',     name: 'In Workshop',        tone: '#f97316', hint: 'Being worked on at the garage' },
  { key: 'ready_for_pickup', name: 'Ready for Pickup',   tone: '#10b981', hint: 'Work complete — awaiting collection' },
  { key: 'qa_reinspection',  name: 'Final QA',           tone: '#9333ea', hint: 'Back at our park, awaiting re-inspection sign-off' },
];
const EXCEPTION_LANES = [
  ...(SHOW_VIDEO_REVIEW ? [{ key: 'repair_review', name: 'Video Review', tone: '#7c3aed', hint: 'Awaiting supervisor video sign-off' }] : []),
  { key: 'reinspection_failed',     name: 'Sent Back — QA Failed', tone: '#dc2626', hint: 'Came back still broken — supervisor re-dispatches' },
  { key: 'paused',                  name: 'Paused',                tone: '#64748b', hint: 'Repair on hold — car released to service' },
  { key: 'returned_waiting_resume', name: 'Returned — Resume Due', tone: '#f97316', hint: 'Physically back — return handover pending' },
  { key: 'on_site',                 name: 'On-Site Service',       tone: '#0d9488', hint: 'Minor job done where the car is parked' },
];

// Cards shown per lane before the "+N more" toggle. Keeps every collapsed column short and roughly
// even (no scroll); expanding a lane reveals all its cards on demand.
const LANE_PAGE_SIZE = 3;

// Board column keys whose tickets are "waiting on us" for the next step — feeds the Workshop-Control
// "Waiting action" tile. Module-scoped so it's a stable useMemo dependency.
const WAITING_STAGES = ['requested', 'pending', 'awaiting_pickup', 'ready_for_pickup', 'qa_reinspection', 'returned_waiting_resume'];

// Fault-severity tone (resource's fault_severity_tone) → dark opx chip class.
const SEV_OPX = { red: 'crit', orange: 'paused', amber: 'paused', green: 'avail' };
// Position chip tone → opx chip class.
const POS_OPX = { blue: 'rented', red: 'crit', amber: 'paused', violet: 'reserved', green: 'avail', cyan: 'rented', teal: 'avail', slate: 'blocked' };
const POSITION_ICON = { in_transit: '🚚', in_workshop: '🔧', repair_review: '🎬', awaiting_pickup: '📦', awaiting_dispatch: '📋', awaiting_reinspection: '✅', under_diagnosis: '🔍', inspection_requested: '🚩', reinspection_failed: '⛔', complaint_triage: '📣', ready_for_pickup: '🧳', in_our_park: '🏁', paused: '⏸️', temporarily_released: '🚗' };

const SEV_FILTERS = [
  { value: '', label: 'All' },
  { value: 'critical', label: '🔴 Critical' },
  { value: 'moderate', label: '🟡 Moderate' },
  { value: 'routine', label: '🟢 Routine' },
];

const payload = (r) => (r && r.data && 'data' in r.data ? r.data.data : r?.data);

// One dark board card — the Cockpit restyle of the classic ticket card. It keeps EVERY operator
// affordance (severity, complaint/breakdown flags, live position, single-garage fault routing,
// custody gate, delegation, the one primary stage action) — only the skin changed to the .opx tokens.
function TicketCard({ tk, tone, can, userId, active, onSelect, onAct }) {
  const { t } = useI18n();
  const cardRef = useRef(null);
  useEffect(() => {
    if (active && cardRef.current) cardRef.current.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, [active]);

  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  const openFaults = tk.tasks_progress?.open ?? 0;
  const readyBlocked = act?.action === 'ready' && openFaults > 0;
  const readyHint = `Fix all ${openFaults} open fault${openFaults > 1 ? 's' : ''} first`;
  const custodyLocked = custodyBlocked(tk, userId);
  const custodyHolder = custodyHolderName(tk, userId);
  const custodyHint = act?.action === 'arriveAtPark'
    ? `Only ${custodyHolder || 'the driver who collected the car from the garage'} can complete the arrival at our park`
    : `Only ${custodyHolder || 'the driver who picked up the car'} can check it in`;
  const tasks = tk.tasks || [];
  const canRoute = can('maintenance.delegate');
  const canFollowUp = can('maintenance.delegate') && tk.workflow_status === 'under_repair';
  const driverName = tk.dispatched_by_name || tk.assigned_driver_name;
  const delegated = tk.delegation?.status === 'driver_assigned' && tk.delegation.driver_name;
  const critical = tk.fault_severity === 'critical';
  const isComplaint = tk.trigger_reason === 'customer_reported';
  const isDisabled = tk.maintenance_type === 'breakdown' || tk.is_recovery;
  const age = stageAge(tk, t);
  const sevCls = SEV_OPX[tk.fault_severity_tone] || 'paused';

  const railCls = active ? 'sel' : critical ? 'sev-crit' : isComplaint ? 'sev-paused' : '';

  return (
    <div
      ref={cardRef}
      onClick={() => onSelect(tk)}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onSelect(tk))}
      className={`mwf-card ${railCls}`}
    >
      <span className="mwf-rail" style={{ background: tone }} />
      <div className="mwf-card-bd">
        {/* Plate + vehicle + time-in-stage + jump-to-command-view */}
        <div className="mwf-card-top">
          <div className="mwf-id">
            <span className="mwf-plate"><Icon.Car className="h-3.5 w-3.5" strokeWidth={2} />{tk.plate || `#${tk.id}`}</span>
            {tk.car && <span className="mwf-car" title={tk.car}>{tk.car}</span>}
          </div>
          <div className="mwf-id-right">
            {age && (
              <span className={`mwf-age ${age.over ? 'over' : ''}`} title={t('queue.timeInStage')}>
                <Icon.Clock className="h-3 w-3" />{age.label}
              </span>
            )}
            <Link
              to={`/maintenance-workflow/${tk.id}`}
              onClick={(e) => e.stopPropagation()}
              className="mwf-jump"
              title={t('workflow.detail.eyebrow', { id: tk.id })}
              aria-label={t('workflow.detail.eyebrow', { id: tk.id })}
            >
              <Icon.ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          </div>
        </div>

        {/* Flags */}
        <div className="mwf-flags">
          {isComplaint && (
            <span className="mwf-pill crit" title={tk.customer_complaint || t('workflow.complaint.badge')}>📣 {t('workflow.complaint.badge')}</span>
          )}
          {isDisabled && (
            <>
              <span className="mwf-pill crit">⛔ {t('workflow.status.disabled')}</span>
              {tk.is_recovery && <span className="mwf-pill paused" title={tk.recovery_unit_phone || ''}>🛻 {tk.recovery_unit_name}</span>}
            </>
          )}
          {tk.fault_severity && (
            <span className={`opx-chip ${sevCls}`}><span className="cd" />{tk.fault_severity_emoji} {t(`workflow.faultSeverity.${tk.fault_severity}`)}</span>
          )}
          {tk.sent_back && (
            <span className="mwf-pill crit">⛔ {t('workflow.board.sentBack')}{tk.sent_back.count > 1 ? ` ×${tk.sent_back.count}` : ''}</span>
          )}
        </div>

        {/* System / driver-raised agenda (a complaint has its own pill above) */}
        {tk.customer_complaint && tk.trigger_reason !== 'customer_reported' && (
          <p className="mwf-agenda" title={tk.customer_complaint}>🕗 {tk.customer_complaint}</p>
        )}

        {/* Live position — where the car actually is */}
        {tk.position?.label && (
          <div className="mwf-flags">
            <span className={`opx-chip mwf-pos ${POS_OPX[tk.position.tone] || 'blocked'} ${tk.position.moving ? 'moving' : ''}`} title={tk.position.detail || tk.position.label}>
              <span aria-hidden="true">{POSITION_ICON[tk.position.phase] || '•'}</span>
              {tk.position.label}{tk.position.garage ? ` · ${tk.position.garage}` : ''}
              {tk.position.transfer && tk.position.destination ? ` → ${tk.position.destination}` : ''}
            </span>
          </div>
        )}

        {/* Single-garage fault routing */}
        {tasks.length > 0 && (
          <div className="mwf-tasks">
            <div className="mwf-tasks-hd">
              <Icon.Wrench className="h-3 w-3" />
              <span className="gn" title={tk.garage || ''}>{tk.garage || <em>{t('workflow.task.unassigned')}</em>}</span>
              <span className="ct">{tasks.length}</span>
            </div>
            {tasks.map((task) => {
              const st = TASK_STATUS[task.status] || TASK_STATUS.pending;
              const failCount = Number(task.reinspection_failures) || 0;
              return (
                <div key={task.id} className="mwf-task" title={task.symptom}>
                  <span className={`mwf-dot ${st.dot}`} />
                  <span>{task.symptom}</span>
                  {failCount > 0 && <span className="mwf-fail" title={t('workflow.reinspect.unresolvedBadge', { n: failCount, garage: task.last_failed_garage || t('workflow.task.unassigned') })}>⛔{failCount > 1 ? `×${failCount}` : ''}</span>}
                </div>
              );
            })}
            {canRoute && isAtGarage(tk) && tk.workflow_status !== 'ready_for_pickup' && (
              <button type="button" className="mwf-ghost" onClick={(e) => { e.stopPropagation(); onAct('route', tk); }}>
                <Icon.Wrench className="h-3 w-3" /> {t('workflow.task.route')}
              </button>
            )}
          </div>
        )}

        {/* Custody / delegation */}
        {(driverName || delegated) && (
          <div className="mwf-who">
            {delegated ? (
              <><Icon.Car className="h-3 w-3" />{t('workflow.delegation.assigned')} · {tk.delegation.driver_name}</>
            ) : (
              <><Icon.Truck className="h-3 w-3" /><span>{driverName}</span></>
            )}
          </div>
        )}

        {canFollowUp && (
          <button type="button" className="mwf-ghost" onClick={(e) => { e.stopPropagation(); onAct('followup', tk); }}>
            <Icon.Plus className="h-3 w-3" /> {t('workflow.cardAction.followup')}
          </button>
        )}

        {/* Primary stage action */}
        {allowed && custodyLocked && <p className="mwf-warn">{custodyHint}</p>}
        {allowed && !custodyLocked && (
          <div onClick={(e) => e.stopPropagation()}>
            <button
              type="button"
              className={`opx-btn ${act.variant === 'danger' ? 'danger' : 'primary'} mwf-cta`}
              disabled={readyBlocked}
              title={readyBlocked ? readyHint : undefined}
              onClick={() => onAct(act.action, tk)}
            >
              {ctaLabel(t, tk)}
            </button>
            {readyBlocked && <p className="mwf-warn">{readyHint}</p>}
          </div>
        )}
      </div>
    </div>
  );
}

// A single board column (lane) — colored top accent, header with count, cards or an empty state.
function Lane({ lane, loading, expanded, onToggle, cardProps }) {
  const tickets = lane.tickets;
  const shown = expanded ? tickets : tickets.slice(0, LANE_PAGE_SIZE);
  const hidden = tickets.length - shown.length;
  return (
    <div className="mwf-lane">
      <span className="mwf-lane-accent" style={{ background: lane.tone }} />
      <div className="mwf-lane-hd">
        <span className="dot" style={{ background: lane.tone, boxShadow: `0 0 8px ${lane.tone}` }} />
        <span className="nm">{lane.name}</span>
        <span className="ct" style={{ color: lane.tone, background: `${lane.tone}22` }}>{tickets.length}</span>
      </div>
      <div className="mwf-lane-bd">
        {loading ? (
          <><div className="opx-skel" style={{ height: 88 }} /><div className="opx-skel" style={{ height: 88 }} /></>
        ) : tickets.length === 0 ? (
          <div className="mwf-empty">
            <span className="ic"><Icon.Check className="h-4 w-4" /></span>
            <p className="t">No tickets</p>
            <p className="h">{lane.hint}</p>
          </div>
        ) : (
          <>
            {shown.map((tk) => <TicketCard key={tk.id} tk={tk} tone={lane.tone} active={tk.id === cardProps.selectedId} {...cardProps} />)}
            {hidden > 0 && <button type="button" className="opx-lane-more" onClick={onToggle}>+{hidden} more</button>}
            {expanded && tickets.length > LANE_PAGE_SIZE && <button type="button" className="opx-lane-more" onClick={onToggle}>Show less</button>}
          </>
        )}
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
  const focusId = id ? Number(id) : null;

  const [modal, setModal] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailReload, setDetailReload] = useState(0);
  const [vehicles, setVehicles] = useState([]);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);
  const [maintTypes, setMaintTypes] = useState([]);
  const [keywordMeta, setKeywordMeta] = useState({});
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({});
  const [drivers, setDrivers] = useState([]);
  const [sevFilter, setSevFilter] = useState('');
  const [query, setQuery] = useState('');
  const [view, setView] = useState('board');        // board | list
  const [expandedLanes, setExpandedLanes] = useState({});
  const [fleet, setFleet] = useState(null);         // /Dashboard fleet_status

  // Real-time board (silent revalidation every 6s), paused while a modal/drawer is open.
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/board')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 6000,
    paused: () => !!modal || !!detail,
  });

  const canDelegate = can('maintenance.delegate');

  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vehicle'), api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([v, g, f]) => {
        if (!alive) return;
        const vlist = v.data?.data;
        const allVehicles = Array.isArray(vlist) ? vlist : vlist?.items || [];
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
      .catch(() => { /* pickers fall back to empty */ });
    return () => { alive = false; };
  }, []);

  // Fleet-status donut for the bottom command row (existing endpoint only).
  useEffect(() => {
    let alive = true;
    const load = () => {
      api.get('/Dashboard', { params: { expiring_days: 7 } }).then((r) => { if (alive) setFleet(payload(r)?.fleet_status || null); }).catch(() => {});
    };
    load();
    const iv = setInterval(() => { if (document.visibilityState === 'visible') load(); }, 30000);
    return () => { alive = false; clearInterval(iv); };
  }, []);

  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => {});
    return () => { alive = false; };
  }, [canDelegate]);

  const columns = useMemo(() => data?.columns || {}, [data]);
  const counts = data?.counts || {};

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });
    setDetailReload((n) => n + 1);
  };

  const canInitiate = can('maintenance.initiate');
  const canLogistics = can('maintenance.logistics');
  const canManage = can('maintenance.manage');

  // Client-side filters shared by the board + list.
  const matchesFilters = useCallback((tk) => {
    if (sevFilter && tk.fault_severity !== sevFilter) return false;
    if (query) {
      const q = query.toLowerCase();
      const hay = [tk.plate, tk.car, tk.garage, tk.customer_complaint, ...(tk.tasks || []).map((x) => x.symptom)]
        .filter(Boolean).join(' ').toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  }, [sevFilter, query]);

  const buildLanes = useCallback((defs) => defs.map((l) => ({
    ...l,
    tickets: (columns[l.key] || []).filter(matchesFilters),
  })), [columns, matchesFilters]);

  const primaryLanes = useMemo(() => buildLanes(PRIMARY_LANES), [buildLanes]);
  // Always show every stage lane (empty ones render their "No tickets" state) so the board keeps a
  // stable shape and no stage is ever hidden.
  const exceptionLanes = useMemo(() => buildLanes(EXCEPTION_LANES), [buildLanes]);
  const allLanes = useMemo(() => [...primaryLanes, ...exceptionLanes], [primaryLanes, exceptionLanes]);
  const allTickets = useMemo(() => allLanes.flatMap((l) => l.tickets), [allLanes]);

  // ---- Today's Workshop Control — action-oriented, not reporting ----
  // A true workshop snapshot: computed over ALL open tickets (independent of the search/severity
  // filter above), so the strip always answers "what needs action now" for the whole shop.
  const openTotal = counts.open_total ?? allTickets.length;
  const allOpen = useMemo(
    () => [...PRIMARY_LANES, ...EXCEPTION_LANES].flatMap((l) => columns[l.key] || []),
    [columns],
  );
  // "Stale" = SLA breached in the current stage, or parked > 3 days — the same rule the board reddens by.
  const isStale = useCallback((tk) => {
    const a = stageAge(tk, t); const s = stageSeconds(tk);
    return (a && a.over) || (s != null && s > 3 * 86400);
  }, [t]);
  // 🔴 Needs attention — blocked (came back QA-failed) or overdue; a human is needed now. Deduped by id.
  const attentionIds = useMemo(() => {
    const ids = new Set((columns.reinspection_failed || []).map((x) => x.id));
    for (const tk of allOpen) if (isStale(tk)) ids.add(tk.id);
    return ids;
  }, [columns, allOpen, isStale]);
  const needsAttention = attentionIds.size;
  // 🟠 Waiting action — sitting in a "waiting on us" stage (inspection / dispatch-approval / collection),
  // and not already flagged red above.
  const waitingAction = useMemo(() => {
    let n = 0;
    for (const key of WAITING_STAGES) for (const tk of (columns[key] || [])) if (!attentionIds.has(tk.id)) n += 1;
    return n;
  }, [columns, attentionIds]);
  // 🟢 Completed today — closed since midnight (from the board count; back in the fleet).
  const completedToday = counts.completed_today ?? 0;
  // ⏱ Oldest open ticket — the single job that's been open the longest.
  const oldestOpen = useMemo(() => {
    let best = null;
    for (const tk of allOpen) {
      if (!tk.created_at) continue;
      if (!best || tk.created_at < best.created_at) best = tk;
    }
    return best;
  }, [allOpen]);
  const oldestAge = oldestOpen ? fmtDuration(Math.max(0, Math.floor((Date.now() - new Date(oldestOpen.created_at).getTime()) / 1000))) : '—';

  const fs = fleet || {};
  const fleetTotal = (fs.available ?? 0) + (fs.rented ?? 0) + (fs.maintenance ?? 0);
  const availPct = fleetTotal ? Math.round(((fs.available ?? 0) / fleetTotal) * 100) : 0;

  // Clicking a ticket opens the full detail drawer directly (it seeds from the board summary, then
  // lazy-loads the full ticket) — no intermediate "select → View Details" step.
  const openDetail = (tk) => { setDetail({ id: tk.id, summary: tk }); };

  // Deep-linked single ticket → the full-page command view (unchanged wiring).
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
          <CreateMoveModal open lockedVehicle={modal.ticket ? { id: modal.ticket.vehicle_id, plate: modal.ticket.plate, label: modal.ticket.car } : null} maintenanceId={modal.ticket?.id || null} onClose={() => setModal(null)} onCreated={() => onDone()} />
        )}
        {modal?.action === 'route' && (
          <TaskRoutingModal ticket={modal.ticket} garages={garages} onClose={() => setModal(null)} onDone={() => { reload({ silent: true }); setDetailReload((n) => n + 1); }} />
        )}
        {modal?.action === 'triage' && (
          <ComplaintTriageModal ticket={modal.ticket} vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
        )}
        {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test'].includes(modal.action) && (
          <TicketActionModal action={modal.action} ticket={modal.ticket || null} vehicles={vehicles} garages={garages} findingsCatalog={findingsCatalog} keywordMeta={keywordMeta} faultCausesCatalog={faultCausesCatalog} assignableDrivers={drivers} allowedTypes={maintTypes} onClose={() => setModal(null)} onDone={onDone} />
        )}
      </>
    );
  }

  const cardProps = {
    can, userId: user?.id,
    selectedId: detail?.id,
    onSelect: openDetail,
    onAct: (action, ticket) => setModal({ action, ticket }),
  };

  return (
    <div className="opx mwf">
      {/* Command header */}
      <header className="opx-head">
        <h1>
          Maintenance Workflow
          <span className="live"><span className="d" />{t('workflow.board.live')}</span>
        </h1>
        <OpsClock />
        <div className="seg" style={{ marginLeft: 12 }}>
          <button className={view === 'board' ? 'on' : ''} onClick={() => setView('board')}>Board</button>
          <button className={view === 'list' ? 'on' : ''} onClick={() => setView('list')}>List</button>
        </div>
        <div className="mwf-head-actions">
          {canManage && <button className="opx-btn primary" onClick={() => setModal({ action: 'test' })}><Icon.Plus className="h-4 w-4" /> {t('workflow.testIntake.newTest')}</button>}
          {canManage && <button className="opx-btn" onClick={() => setModal({ action: 'complaint' })}>📣 {t('workflow.board.newComplaint')}</button>}
          {canLogistics && <button className="opx-btn" onClick={() => setModal({ action: 'request' })}><Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}</button>}
        </div>
      </header>

      <div className="opx-body">
        {/* TODAY'S WORKSHOP CONTROL — action-oriented: what needs a decision right now, not reporting. */}
        <div className="opx-grid opx-c12 mwf-kpis">
          <div className="opx-span-3"><KpiTile tone={needsAttention > 0 ? 'hot' : ''} label="🔴 Needs<br>Attention" value={loading ? '—' : needsAttention} foot="blocked or overdue" footTone={needsAttention > 0 ? 'down' : 'flat'} /></div>
          <div className="opx-span-3"><KpiTile tone="warm" label="🟠 Waiting<br>Action" value={loading ? '—' : waitingAction} foot="inspection · approval · pickup" /></div>
          <div className="opx-span-3"><KpiTile tone="good" label="🟢 Completed<br>Today" value={loading ? '—' : completedToday} foot="back in fleet" /></div>
          <div className="opx-span-3"><KpiTile label="⏱ Oldest<br>Open" value={loading ? '—' : oldestAge} foot={oldestOpen ? (oldestOpen.plate || `#${oldestOpen.id}`) : 'no open tickets'} /></div>
        </div>

        {error && <div className="mwf-error">{error}</div>}

        {/* Toolbar */}
        <div className="mwf-toolbar">
          <div className="mwf-search">
            <Icon.Search className="h-4 w-4" />
            <input className="opx-input" placeholder="Search tickets, vehicles, faults…" value={query} onChange={(e) => setQuery(e.target.value)} style={{ paddingLeft: 34 }} />
          </div>
          <select className="opx-select" value={sevFilter} onChange={(e) => setSevFilter(e.target.value)}>
            {SEV_FILTERS.map((f) => <option key={f.value || 'all'} value={f.value}>{f.label}</option>)}
          </select>
          {(query || sevFilter) && (
            <button className="opx-btn" onClick={() => { setQuery(''); setSevFilter(''); }}>Clear</button>
          )}
          <span className="mwf-toolbar-meta">{allTickets.length} shown</span>
        </div>

        {/* BOARD */}
        {view === 'board' && (
          <div className="mwf-board">
            {allLanes.map((lane) => (
              <Lane
                key={lane.key}
                lane={lane}
                loading={loading}
                expanded={!!expandedLanes[lane.key]}
                onToggle={() => setExpandedLanes((p) => ({ ...p, [lane.key]: !p[lane.key] }))}
                cardProps={cardProps}
              />
            ))}
          </div>
        )}

        {/* LIST */}
        {view === 'list' && (
          <CommandPanel title="All open tickets" label={`${allTickets.length}`} bodyFlush>
            <div className="opx-tblwrap">
              <table className="opx-tbl">
                <thead>
                  <tr><th>Vehicle</th><th>Stage</th><th>Severity</th><th>Garage</th><th>Faults</th><th>In stage</th><th className="r">Action</th></tr>
                </thead>
                <tbody>
                  {allTickets.map((tk) => {
                    const a = stageAge(tk, t);
                    const laneName = [...PRIMARY_LANES, ...EXCEPTION_LANES].find((l) => l.key === tk.workflow_status)?.name || tk.status_label || tk.workflow_status;
                    return (
                      <tr key={tk.id} className={tk.fault_severity === 'critical' ? 'rt-crit' : ''} onClick={() => openDetail(tk)} style={{ cursor: 'pointer' }}>
                        <td><Link to={`/maintenance-workflow/${tk.id}`} className="opx-plate2" onClick={(e) => e.stopPropagation()}>{tk.plate || `#${tk.id}`}</Link><div className="opx-sub">{tk.car || ''}</div></td>
                        <td className="opx-mono2">{laneName}</td>
                        <td>{tk.fault_severity ? <span className={`opx-chip ${SEV_OPX[tk.fault_severity_tone] || 'paused'}`}><span className="cd" />{t(`workflow.faultSeverity.${tk.fault_severity}`)}</span> : '—'}</td>
                        <td className="opx-mono2">{tk.garage || '—'}</td>
                        <td className="opx-mono2">{(tk.tasks || []).length || '—'}</td>
                        <td className={`opx-mono2 ${a?.over ? 'mwf-over' : ''}`}>{a?.label || '—'}</td>
                        <td className="r"><Link to={`/maintenance-workflow/${tk.id}`} className="opx-ibtn go" onClick={(e) => e.stopPropagation()}>Open</Link></td>
                      </tr>
                    );
                  })}
                  {allTickets.length === 0 && !loading && <tr><td colSpan={7}><div className="opx-empty">No open tickets match the filters</div></td></tr>}
                </tbody>
              </table>
            </div>
          </CommandPanel>
        )}

        {/* BOTTOM COMMAND ROW — fleet status only. (Per-ticket detail lives in the click-to-open drawer;
            the Recent Activity feed was removed at the operator's request.) */}
        <div className="opx-grid opx-c12 mwf-bottom">
          <div className="opx-span-12">
            <CommandPanel title="Fleet Status" label="live">
              {!fleet ? <div className="opx-skel" style={{ height: 170 }} /> : (
                <div className="mwf-fleet">
                  <ScoreRing value={availPct} label="Available" size={116} />
                  <div className="mwf-fleet-legend">
                    <div><span className="d" style={{ background: '#34d399' }} />Available<b>{fs.available ?? 0}</b></div>
                    <div><span className="d" style={{ background: '#60a5fa' }} />Rented<b>{fs.rented ?? 0}</b></div>
                    <div><span className="d" style={{ background: '#fb7185' }} />Maintenance<b>{fs.maintenance ?? 0}</b></div>
                    <div className="tot"><span>Total fleet</span><b>{fleetTotal}</b></div>
                  </div>
                </div>
              )}
            </CommandPanel>
          </div>
        </div>

        {!loading && openTotal === 0 && (
          <div className="opx-empty" style={{ marginTop: 20 }}>
            <div className="big">🔧</div>
            {t('workflow.board.noOpenTitle')}
            <div className="opx-hint" style={{ marginTop: 8 }}>
              {canLogistics ? t('workflow.board.emptyLogistics') : canInitiate ? t('workflow.board.emptyInspector') : t('workflow.board.emptyOther')}
            </div>
          </div>
        )}
      </div>

      {/* Detail slide-over — the full ticket: history, findings, odometer photos, every action. */}
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

      {modal?.action === 'logistics' && (
        <CreateMoveModal open lockedVehicle={modal.ticket ? { id: modal.ticket.vehicle_id, plate: modal.ticket.plate, label: modal.ticket.car } : null} maintenanceId={modal.ticket?.id || null} onClose={() => setModal(null)} onCreated={() => onDone()} />
      )}
      {modal?.action === 'route' && (
        <TaskRoutingModal ticket={modal.ticket} garages={garages} onClose={() => setModal(null)} onDone={() => { reload(); setDetailReload((n) => n + 1); }} />
      )}
      {modal?.action === 'complaint' && (
        <ComplaintIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'breakdown' && (
        <BreakdownIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'test' && (
        <TestIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal ticket={modal.ticket} vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
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
