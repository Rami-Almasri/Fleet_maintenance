import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import { Tooltip } from '../components/ui/Tooltip';
import TicketActionModal from '../components/workflow/TicketActionModal';
import TicketDetailDrawer from '../components/workflow/TicketDetailDrawer';
import TicketCommandView from '../components/workflow/TicketCommandView';
import TaskRoutingModal from '../components/workflow/TaskRoutingModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import TestIntakeModal from '../components/workflow/TestIntakeModal';
import CreateMoveModal from './logistics/CreateMoveModal';
import CycleGuide from '../components/workflow/CycleGuide';
import {
  resolveAction, allows, ctaLabel, TASK_STATUS, stageAge,
  isAtGarage, custodyBlocked, custodyHolderName,
} from '../components/workflow/meta';
import { PRIMARY_LANES, EXCEPTION_LANES, PIPELINE_KEYS } from '../config/maintenanceLanes';
import {
  CommandPanel, OpsClock,
} from '../components/ops';
import '../components/ops/ops.css';
import './maintenance-workflow.css';

// The Cockpit board lanes (PRIMARY_LANES / EXCEPTION_LANES) and the canonical PIPELINE_KEYS now live
// in ../config/maintenanceLanes so the Dashboard analytics panel draws the exact same stages.

// Cards shown per lane before the "+N more" toggle. Keeps every collapsed column short and roughly
// even (no scroll); expanding a lane reveals all its cards on demand.
const LANE_PAGE_SIZE = 3;

// The pipeline "journey" spine — a segmented bar showing how far a ticket has moved through the 8
// canonical stages. Filled segments glow in the stage tone; the current one pulses. Off-pipeline
// (exception) lanes have no linear position, so they render a single tone strip instead of segments.
function PipelineBar({ laneKey, laneName, tone }) {
  const idx = PIPELINE_KEYS.indexOf(laneKey);
  const total = PIPELINE_KEYS.length;
  if (idx < 0) {
    return (
      <div className="mwf-stage">
        <div className="mwf-stage-hd">
          <span className="mwf-stage-nm">{laneName}</span>
          <span className="mwf-stage-step exc" style={{ color: tone }}>OFF-PIPELINE</span>
        </div>
        <div className="mwf-pipe">
          <span className="mwf-pipe-seg exc" style={{ background: tone, boxShadow: `0 0 10px ${tone}` }} />
        </div>
      </div>
    );
  }
  return (
    <div className="mwf-stage">
      <div className="mwf-stage-hd">
        <span className="mwf-stage-nm">{laneName}</span>
        <span className="mwf-stage-step">Step {idx + 1}/{total}</span>
      </div>
      <div className="mwf-pipe" role="progressbar" aria-valuenow={idx + 1} aria-valuemin={1} aria-valuemax={total} aria-label={`${laneName} — step ${idx + 1} of ${total}`}>
        {PIPELINE_KEYS.map((k, i) => (
          <span
            key={k}
            className={`mwf-pipe-seg ${i === idx ? 'now' : ''} ${i <= idx ? 'on' : ''}`}
            style={i <= idx ? { background: tone, ...(i === idx ? { boxShadow: `0 0 9px ${tone}` } : null) } : undefined}
          />
        ))}
      </div>
    </div>
  );
}

// Expected-return date (captured at dispatch / garage check-in) → a short "DD Mon" label + an overdue
// flag when the promised day has already passed. Returns null for a missing/unparseable date.
function expectedReturn(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return {
    label: d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' }),
    over: d < today,
  };
}

// Fault-severity tone (resource's fault_severity_tone) → dark opx chip class.
const SEV_OPX = { red: 'crit', orange: 'paused', amber: 'paused', green: 'avail' };
// Position chip tone → opx chip class.
const POS_OPX = { blue: 'rented', red: 'crit', amber: 'paused', violet: 'reserved', green: 'avail', cyan: 'rented', teal: 'avail', slate: 'blocked' };
const POSITION_ICON = { in_transit: '🚚', in_workshop: '🔧', repair_review: '🎬', awaiting_pickup: '📦', awaiting_dispatch: '📋', awaiting_reinspection: '✅', under_diagnosis: '🔍', inspection_requested: '🚩', reinspection_failed: '⛔', complaint_triage: '📣', ready_for_pickup: '🧳', in_our_park: '🏁', paused: '⏸️', temporarily_released: '🚗' };

// Part-request lifecycle → the marker shown next to a part listed under its fault on the board card.
// Mirrors the drawer's FindingsList map (same vocabulary, dark-board tones): a fitted part reads as a
// green ✓, the in-flight stages get a coloured dot, and the off-ramps read muted + struck-through.
const PART_STATUS = {
  requested:    { dot: 'var(--ink-3)',  label: 'Requested' },
  under_review: { dot: 'var(--rented)', label: 'Under review' },
  approved:     { dot: 'var(--rented)', label: 'Approved' },
  purchased:    { dot: 'var(--reserved)', label: 'Purchased' },
  // Not a request status — synthesised when the purchase carries delivered_at. The part is on site and
  // the car has stopped waiting, even though the request stays `purchased` until someone fits it.
  delivered:    { check: true,          label: 'Delivered' },
  installed:    { check: true,          label: 'Installed' },
  completed:    { check: true,          label: 'Installed' },
  rejected:     { dot: 'var(--ink-3)',  label: 'Rejected',  muted: true },
  cancelled:    { dot: 'var(--ink-3)',  label: 'Cancelled', muted: true },
};

// Statuses where the part is no longer owed — fitted (installed/completed) or dropped
// (rejected/cancelled). Mirrors PartRequest::SETTLED.
const PART_SETTLED = new Set(['installed', 'completed', 'rejected', 'cancelled']);

// Is the car still WAITING on this part? The wait is for DELIVERY, not for the fitting — a part that
// has LANDED stops counting even though its request is still `purchased` (delivery is recorded on the
// purchase's delivered_at, not as a request status). The backend computes this as `outstanding` via
// PartRequest::isOutstanding(); we trust it when present and fall back to the same rule locally so an
// older/lighter payload still behaves. Purely DERIVED — nothing is written because of it.
function isOutstandingPart(p) {
  if (typeof p.outstanding === 'boolean') return p.outstanding;
  return !PART_SETTLED.has(p.status) && !p.delivered;
}

// Every part request on the ticket that is still owed — fault-linked and ticket-level alike, since
// `tk.parts` is the ticket's own partRequests relation (the board eager-loads it, so this is free).
function outstandingParts(tk) {
  return (tk.parts || []).filter(isOutstandingPart);
}

// One part line — the status marker, the name (+ qty) and where it is in its lifecycle.
function PartRow({ part }) {
  // A delivered-but-not-yet-fitted part reads "Delivered", not a stale "Purchased".
  const key = part.delivered && !PART_SETTLED.has(part.status) ? 'delivered' : part.status;
  const ps = PART_STATUS[key] || { dot: 'var(--ink-3)', label: part.status };
  return (
    <div
      className={`mwf-part ${ps.muted ? 'muted' : ''}`}
      title={`${part.part_name}${part.part_number ? ` · ${part.part_number}` : ''} — ${ps.label}`}
    >
      {ps.check
        ? <span className="ok" aria-hidden="true">✓</span>
        : <span className="mwf-dot" style={{ background: ps.dot }} />}
      <span className="nm">{part.part_name}</span>
      {part.quantity > 1 && <span className="qt">×{Math.round(part.quantity)}</span>}
      <span className="st">{ps.label}</span>
    </div>
  );
}

const SEV_FILTERS = [
  { value: '', label: 'All' },
  { value: 'critical', label: '🔴 Critical' },
  { value: 'moderate', label: '🟡 Moderate' },
  { value: 'routine', label: '🟢 Routine' },
];

// One dark board card — the Cockpit restyle of the classic ticket card. It keeps EVERY operator
// affordance (severity, complaint/breakdown flags, live position, single-garage fault routing,
// custody gate, delegation, the one primary stage action) — only the skin changed to the .opx tokens.
function TicketCard({ tk, tone, laneKey, laneName, can, userId, active, onSelect, onAct }) {
  const { t, tf, tp } = useI18n();
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
  const showRoute = tasks.length > 0 && canRoute && isAtGarage(tk) && tk.workflow_status !== 'ready_for_pickup';
  const canFollowUp = can('maintenance.delegate') && tk.workflow_status === 'under_repair';
  const driverName = tk.dispatched_by_name || tk.assigned_driver_name;
  const delegated = tk.delegation?.status === 'driver_assigned' && tk.delegation.driver_name;
  const critical = tk.fault_severity === 'critical';
  const isComplaint = tk.trigger_reason === 'customer_reported';
  const isDisabled = tk.maintenance_type === 'breakdown' || tk.is_recovery;
  const age = stageAge(tk, t);
  const expected = expectedReturn(tk.expected_return_date);
  // Parts still owed on the ticket — a DERIVED read of its part requests, not a workflow state. The
  // ticket stays in whatever lane it is in; this only decides whether the header badge shows. Falls
  // back to the resource's `parts_pending` names when the full request list isn't serialized, so the
  // badge never silently vanishes on a lighter payload.
  const partsOwed = outstandingParts(tk);
  const partsPending = tk.parts_pending || [];
  const partsCount = tk.parts ? partsOwed.length : partsPending.length;
  // Requests raised against the TICKET with no fault attached — they never show up in tasks[].parts, so
  // they get their own block under the fault list (otherwise they'd be invisible on the board).
  const looseParts = (tk.parts || []).filter((p) => !p.task_id);
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
      style={{ '--tone': tone }}
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

        {/* Journey spine — how far this vehicle has moved through the pipeline */}
        <PipelineBar laneKey={laneKey} laneName={laneName} tone={tone} />

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
          {/* Parts blocker — the car is sitting on an outstanding part request. Purely visual: the ticket
              keeps its lane and the vehicle keeps its operational status. Hover/focus lists exactly what
              is owed (name ×qty — stage) so a dispatcher knows WHY without opening the ticket.
              The wrapper swallows click/Enter so reading the badge never opens the drawer behind it. */}
          {partsCount > 0 && (
            <span
              onClick={(e) => e.stopPropagation()}
              onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && e.stopPropagation()}
            >
              <Tooltip
                side="bottom"
                maxWidth={280}
                content={
                  <div className="mwf-parts-tip">
                    <div className="hd">{tp('workflow.board.partsRequested', partsCount)}</div>
                    {partsOwed.length > 0
                      ? partsOwed.map((p) => (
                          <div key={p.id} className="ln">
                            <span className="nm">
                              {p.part_name}
                              {p.quantity > 1 ? ` ×${Math.round(p.quantity)}` : ''}
                            </span>
                            <span className="st">
                              {tf(
                                `workflow.board.partStatus.${p.status}`,
                                (PART_STATUS[p.status] || {}).label || p.status,
                              )}
                            </span>
                          </div>
                        ))
                      : partsPending.map((n) => <div key={n} className="ln"><span className="nm">{n}</span></div>)}
                  </div>
                }
              >
                <span className="mwf-pill parts">
                  🔧 {tp('workflow.board.partsRequested', partsCount)}
                </span>
              </Tooltip>
            </span>
          )}
          {tk.sent_back && (
            <span className="mwf-pill crit">⛔ {t('workflow.board.sentBack')}{tk.sent_back.count > 1 ? ` ×${tk.sent_back.count}` : ''}</span>
          )}
          {/* Expected return day — the date the garage promised the car back (set at dispatch / check-in).
              Turns red once that day has passed and the car still isn't back. */}
          {expected && (
            <span className={`mwf-pill due ${expected.over ? 'over' : ''}`} title={t('workflow.board.expectedReturnTip')}>
              🗓️ {t(expected.over ? 'workflow.board.expectedOverdue' : 'workflow.board.expectedReturn', { date: expected.label })}
            </span>
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
              const parts = task.parts || [];
              return (
                <div key={task.id}>
                  <div className="mwf-task" title={task.symptom}>
                    <span className={`mwf-dot ${st.dot}`} />
                    <span>{task.symptom}</span>
                    {failCount > 0 && <span className="mwf-fail" title={t('workflow.reinspect.unresolvedBadge', { n: failCount, garage: task.last_failed_garage || t('workflow.task.unassigned') })}>⛔{failCount > 1 ? `×${failCount}` : ''}</span>}
                  </div>
                  {/* The parts raised against THIS fault — what was requested / bought / fitted for it. */}
                  {parts.length > 0 && (
                    <div className="mwf-parts">
                      <span className="hd">{t('workflow.board.partsHd')}</span>
                      {parts.map((p) => <PartRow key={p.id} part={p} />)}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {/* Parts raised on the ticket itself (no fault attached) — same block, its own header. */}
        {looseParts.length > 0 && (
          <div className="mwf-tasks">
            <div className="mwf-tasks-hd">
              <span aria-hidden="true">🧩</span>
              <span className="gn">{t('workflow.board.partsHd')}</span>
              <span className="ct">{looseParts.length}</span>
            </div>
            {looseParts.map((p) => <PartRow key={p.id} part={p} />)}
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

        {/* Action footer — secondary icon-tools on the left, the primary stage CTA on the right */}
        {(showRoute || canFollowUp || (allowed && !custodyLocked)) && (
          <div className="mwf-foot" onClick={(e) => e.stopPropagation()}>
            <div className="mwf-foot-tools">
              {showRoute && (
                <button type="button" className="mwf-tool" title={t('workflow.task.route')}
                  onClick={() => onAct('route', tk)}>
                  <Icon.Wrench className="h-3.5 w-3.5" /><span>{t('workflow.task.route')}</span>
                </button>
              )}
              {canFollowUp && (
                <button type="button" className="mwf-tool" title={t('workflow.cardAction.followup')}
                  onClick={() => onAct('followup', tk)}>
                  <Icon.Plus className="h-3.5 w-3.5" /><span>{t('workflow.cardAction.followup')}</span>
                </button>
              )}
            </div>
            {allowed && !custodyLocked && (
              <button
                type="button"
                className={`opx-btn ${act.variant === 'danger' ? 'danger' : 'primary'} mwf-cta`}
                disabled={readyBlocked}
                title={readyBlocked ? readyHint : undefined}
                onClick={() => onAct(act.action, tk)}
              >
                {ctaLabel(t, tk)}
              </button>
            )}
          </div>
        )}
        {/* Blocking reasons — sit under the footer so the CTA stays visually primary */}
        {allowed && custodyLocked && <p className="mwf-warn">{custodyHint}</p>}
        {allowed && !custodyLocked && readyBlocked && <p className="mwf-warn">{readyHint}</p>}
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
            {shown.map((tk) => <TicketCard key={tk.id} tk={tk} tone={lane.tone} laneKey={lane.key} laneName={lane.name} active={tk.id === cardProps.selectedId} {...cardProps} />)}
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
  const location = useLocation();

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
  // Focused stage — 'all' or a lane key. Seeded from the URL (?stage=…) so the "Maintenance Cycle"
  // nav dropdown can deep-link straight to a stage; kept in sync when that query param changes.
  const [stageTab, setStageTab] = useState(() => new URLSearchParams(window.location.search).get('stage') || 'all');
  const [expandedLanes, setExpandedLanes] = useState({});
  const [showCycleGuide, setShowCycleGuide] = useState(false);

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

  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => {});
    return () => { alive = false; };
  }, [canDelegate]);

  const columns = useMemo(() => data?.columns || {}, [data]);
  const counts = useMemo(() => data?.counts || {}, [data]);

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

  // Guard: if the focused stage isn't a real lane key (e.g. a stale/bad ?stage= value), fall back to
  // the full board. Empty stages are fine — they render their own "No tickets" state.
  useEffect(() => {
    const known = [...PRIMARY_LANES, ...EXCEPTION_LANES].some((l) => l.key === stageTab);
    if (stageTab !== 'all' && !known) setStageTab('all');
  }, [stageTab]);

  // Deep-link sync — follow ?stage=… as it changes (e.g. picking a stage from the nav dropdown while
  // already on the board). No param → back to the full board.
  useEffect(() => {
    setStageTab(new URLSearchParams(location.search).get('stage') || 'all');
  }, [location.search]);

  // Lanes actually rendered: every lane on the "All" tab, otherwise just the selected stage.
  const displayLanes = useMemo(
    () => (stageTab === 'all' ? allLanes : allLanes.filter((l) => l.key === stageTab)),
    [allLanes, stageTab],
  );
  const visibleTickets = useMemo(() => displayLanes.flatMap((l) => l.tickets), [displayLanes]);

  // Total open tickets across the whole shop — drives the "no open tickets" empty state below.
  const openTotal = counts.open_total ?? allTickets.length;

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
      <div className="opx-body">
        {/* ---- Command header — live pipeline title + running clock ---- */}
        <header className="mwf-hero">
          <div className="mwf-hero-id">
            <span className="mwf-hero-ic"><Icon.Wrench className="h-6 w-6" strokeWidth={2} /></span>
            <div className="mwf-hero-copy">
              <h1>
                Maintenance Command
                <span className="mwf-live"><span className="d" />LIVE</span>
              </h1>
              <p>{openTotal} {openTotal === 1 ? 'ticket' : 'tickets'} in the pipeline · auto-refreshing every 6s</p>
            </div>
          </div>
          <div className="mwf-hero-clock"><OpsClock /></div>
        </header>

        {error && <div className="mwf-error">{error}</div>}

        {/* One control strip — search + filters on the left, view toggle + primary actions on the right. */}
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

          <div className="mwf-toolbar-right">
            <span className="mwf-toolbar-meta">{visibleTickets.length} shown</span>
            <button
              type="button"
              className={`opx-btn ${showCycleGuide ? 'primary' : ''}`}
              onClick={() => setShowCycleGuide((v) => !v)}
              title={t('workflow.cycle.title')}
            >
              <Icon.Activity className="h-4 w-4" /> {t('workflow.cycle.toggle')}
            </button>
            <div className="seg">
              <button className={view === 'board' ? 'on' : ''} onClick={() => setView('board')}>Board</button>
              <button className={view === 'list' ? 'on' : ''} onClick={() => setView('list')}>List</button>
            </div>
            {canManage && <button className="opx-btn" onClick={() => setModal({ action: 'complaint' })}>📣 {t('workflow.board.newComplaint')}</button>}
            {canLogistics && <button className="opx-btn primary" onClick={() => setModal({ action: 'request' })}><Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}</button>}
          </div>
        </div>

        {/* Cycle guide — inline flow map of the whole pipeline (documentation from the board's own meta) */}
        {showCycleGuide && <CycleGuide lanes={primaryLanes} onClose={() => setShowCycleGuide(false)} />}

        {/* BOARD */}
        {view === 'board' && (
          <div className={`mwf-board ${stageTab !== 'all' ? 'single' : ''}`}>
            {displayLanes.map((lane) => (
              <Lane
                key={lane.key}
                lane={lane}
                loading={loading}
                expanded={stageTab !== 'all' || !!expandedLanes[lane.key]}
                onToggle={() => setExpandedLanes((p) => ({ ...p, [lane.key]: !p[lane.key] }))}
                cardProps={cardProps}
              />
            ))}
          </div>
        )}

        {/* LIST */}
        {view === 'list' && (
          <CommandPanel title="All open tickets" label={`${visibleTickets.length}`} bodyFlush>
            <div className="opx-tblwrap">
              <table className="opx-tbl">
                <thead>
                  <tr><th>Vehicle</th><th>Stage</th><th>Severity</th><th>Garage</th><th>Faults</th><th>In stage</th><th className="r">Action</th></tr>
                </thead>
                <tbody>
                  {visibleTickets.map((tk) => {
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
                  {visibleTickets.length === 0 && !loading && <tr><td colSpan={7}><div className="opx-empty">No open tickets match the filters</div></td></tr>}
                </tbody>
              </table>
            </div>
          </CommandPanel>
        )}

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
