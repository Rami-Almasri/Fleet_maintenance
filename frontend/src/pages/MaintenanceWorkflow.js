import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import ActionMenu from '../components/ui/ActionMenu';
import { Tooltip } from '../components/ui/Tooltip';
// The car's own photograph — the same registry the Fleet hub and My Queue read. A miss is silent and
// falls back to the marque logo, then to a neutral silhouette; it NEVER guesses a different car.
import { carPhoto, brandLogo, vehicleName } from '../lib/carAssets';
import TicketActionModal from '../components/workflow/TicketActionModal';
import SendCarInModal from '../components/workflow/SendCarInModal';
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
  resolveAction, allows, ctaLabel, TASK_STATUS, stageAge, stageSeconds,
  isAtGarage, custodyBlocked, custodyHolderName, heldFindings, findingHoldBlocks,
} from '../components/workflow/meta';
import { useLanes, PIPELINE_KEYS } from '../config/maintenanceLanes';
import { CommandPanel } from '../components/ops';
import '../components/ops/ops.css';
import './maintenance-workflow.css';

// The Cockpit board lanes (PRIMARY_LANES / EXCEPTION_LANES) and the canonical PIPELINE_KEYS now live
// in ../config/maintenanceLanes so the Dashboard analytics panel draws the exact same stages.

// Cards shown per lane before the "+N more" toggle. Keeps every collapsed column short and roughly
// even (no scroll); expanding a lane reveals all its cards on demand.
const LANE_PAGE_SIZE = 3;

/**
 * The car's photograph, or the honest fallbacks behind it. A picture is a claim about which vehicle
 * this ticket is — we hold no photo for roughly a third of the fleet, and those cards show the marque
 * logo or a silhouette rather than a car we do not own. Same rule as the My Queue board.
 */
function CarShot({ car }) {
  const photo = carPhoto(car, '');
  const logo = photo ? null : brandLogo(car, '');
  if (photo) return <img className="mwf-shot-img" src={photo} alt="" loading="lazy" />;
  if (logo) return <img className="mwf-shot-logo" src={logo} alt="" loading="lazy" />;
  return <Icon.Car className="mwf-shot-silhouette" />;
}

// The masthead's date + running clock. Arabic stays Gregorian with Latin digits, or the calendar
// switches under the reader and the tabular alignment breaks.
function BoardClock() {
  const { lang } = useI18n();
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);
  const timeLoc = lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined;
  const dateLoc = lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined;
  return (
    <>
      <span className="d">{now.toLocaleDateString(dateLoc, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}</span>
      <strong className="tm tnum">{now.toLocaleTimeString(timeLoc, { hour: '2-digit', minute: '2-digit' })}</strong>
    </>
  );
}

// A glyph per stage, for the lane header. Purely presentational — it says what KIND of waiting a lane
// is (a drive, a look, a trip, a workshop), nothing the data doesn't already.
const LANE_ICON = {
  requested: Icon.Car,
  diagnostic: Icon.Search,
  pending: Icon.Truck,
  awaiting_pickup: Icon.Wrench,
  in_transit: Icon.Route,
  under_repair: Icon.Wrench,
  ready_for_pickup: Icon.Check,
  qa_reinspection: Icon.Shield,
  accident: Icon.Alert,
  triage: Icon.Flag,
  repair_review: Icon.Video,
  reinspection_failed: Icon.XCircle,
  paused: Icon.Clock,
  returned_waiting_resume: Icon.Refresh,
  on_site: Icon.Box,
  deferred: Icon.Calendar,
};
// NO HEADLINE STRIP. A row of stage totals above the board restated the lane headers directly beneath
// it — the same four numbers, twice, in the same glance. The lanes are the count.

// The marque word this ticket's car is filed under — the first word of the fleet's own "make model"
// string, read as typed and never repaired. Drives the Brands filter; an unnamed car is excluded.
function brandOf(tk) {
  const first = String(tk.car || '').trim().split(/[\s/]+/)[0] || '';
  return first.length > 1 ? first.toUpperCase() : '';
}

// How the cards inside every lane are ordered. All four read fields the board already carries, so no
// sort invents a ranking the data cannot support.
const SEV_RANK = { critical: 3, moderate: 2, routine: 1 };
const SORTS = {
  recent:   (a, b) => (stageSeconds(a) ?? Infinity) - (stageSeconds(b) ?? Infinity),
  longest:  (a, b) => (stageSeconds(b) ?? -1) - (stageSeconds(a) ?? -1),
  severity: (a, b) => (SEV_RANK[b.fault_severity] || 0) - (SEV_RANK[a.fault_severity] || 0),
  plate:    (a, b) => String(a.plate || '').localeCompare(String(b.plate || '')),
};

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

// The severity narrowing. The words themselves come from the catalog (workflow.faultSeverity.*), so
// the filter reads in the same language as the chip it filters on.
const SEV_FILTERS = [
  { value: '' },
  { value: 'critical', emoji: '🔴' },
  { value: 'moderate', emoji: '🟡' },
  { value: 'routine', emoji: '🟢' },
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
  // THE HOLD. The card keeps its lane — a frozen ticket is still a ticket at Needs Dispatch, and moving
  // it to a holding column would hide where the car actually is. What changes is what the card CLAIMS:
  // without this it went on saying "Assign Garage" in primary blue, which is now the one thing that
  // cannot happen, and the supervisor only found out after filling the form.
  const held = heldFindings(tk);
  const holdBlocked = findingHoldBlocks(tk, act?.action);
  const holdHint = tf('workflow.card.findingHeld', '{list} — waiting on a manager’s approval before this ticket can move', {
    list: held.map((h) => h.finding).join(', '),
  });
  const custodyLocked = custodyBlocked(tk, userId, can);
  const custodyHolder = custodyHolderName(tk, userId, can);
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
        {/* The car itself — photograph, plate, model — then time-in-stage + jump-to-command-view.
            A card on this board is a CAR before it is a ticket: the picture is what a dispatcher
            recognises from across the room, so it leads. */}
        <div className="mwf-card-top">
          <div className="mwf-shot"><CarShot car={tk.car} /></div>
          <div className="mwf-id">
            <span className="mwf-plate"><Icon.Car className="h-3.5 w-3.5" strokeWidth={2} />{tk.plate || `#${tk.id}`}</span>
            {tk.car && <span className="mwf-car" title={tk.car}>{vehicleName(tk.car, '')}</span>}
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
          {/* First flag on the card, ahead of the complaint and severity chips: while this stands nothing
              else about this ticket can happen, so it must not be the badge you notice third. */}
          {held.length > 0 && (
            <span className="mwf-pill crit" title={holdHint}>
              ⏸ {tp('workflow.card.findingHeldBadge', held.length)}
            </span>
          )}
          {/* THE CRASH BEHIND THE CARD. Shown on every ticket that came out of one — not only the fenced
              ones — because "why is this car here?" is the first question anybody asks of a card, and a
              repair that started as an accident answers it differently from one that started as a fault.
              The stage is the ACCIDENT CASE's answer, repeated here; the board never forms its own.
              The link leaves for the case, where the actions live — there is one place to advance an
              accident and it is not this card. */}
          {tk.accident && (
            <Link
              to={tk.accident.url}
              onClick={(e) => e.stopPropagation()}
              className="mwf-pill crit"
              title={t('workflow.accident.badgeTip', {
                ref: tk.accident.reference,
                stage: tk.accident.stage_label,
              })}
            >
              🚨 {tk.accident.reference} · {tk.accident.stage_label}
            </Link>
          )}
          {/* Nobody has authorised any work yet — said plainly, because an accident card in a maintenance
              board otherwise reads as a job somebody is failing to dispatch. */}
          {tk.is_accident_cycle && (
            <span className="mwf-pill paused" title={t('workflow.accident.fencedTip')}>
              ⏳ {t('workflow.accident.fenced')}
            </span>
          )}
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
          {/* WHERE this repair happens — the decision taken at Decide, and until now invisible on the
              board. Without it an on-site job read "Not dispatched" like any car waiting for a garage,
              so a dispatcher could not tell a car that is WAITING for a workshop from one that was never
              going to a workshop at all. Shown on every ticket, not just the on-site ones: "in garage" is
              the answer to the same question, and a chip that appears only sometimes teaches nobody. */}
          {tk.repair_location && (
            <span
              className={`mwf-pill ${tk.is_on_site ? 'onsite' : 'shop'}`}
              title={t(tk.is_on_site ? 'workflow.board.onSiteTip' : 'workflow.board.inShopTip')}
            >
              {tk.is_on_site ? '🧰' : '🏭'} {t(tk.is_on_site ? 'workflow.board.onSite' : 'workflow.board.inShop')}
            </span>
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
              {/* "Not dispatched" is only true of a car that is SUPPOSED to go to a garage. An on-site
                  job never is, so it says where the work actually happens instead of reading as a car
                  nobody has sent anywhere. A mobile vendor, when one is recorded, still shows by name. */}
              <span className="gn" title={tk.garage || t(tk.is_on_site ? 'workflow.board.onSiteTip' : 'workflow.task.noGarage')}>
                {tk.garage || <em>{t(tk.is_on_site ? 'workflow.board.onSiteNoGarage' : 'workflow.task.unassigned')}</em>}
              </span>
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
                disabled={readyBlocked || holdBlocked}
                title={holdBlocked ? holdHint : readyBlocked ? readyHint : undefined}
                onClick={() => onAct(act.action, tk)}
              >
                {ctaLabel(t, tk)}
              </button>
            )}
          </div>
        )}
        {/* Blocking reasons — sit under the footer so the CTA stays visually primary */}
        {/* The hold is named before the softer gates: it outranks them, and unlike them it is answered by
            someone else entirely, so the card has to say WHO the ticket is waiting on. */}
        {holdBlocked && <p className="mwf-warn">{holdHint}</p>}
        {allowed && custodyLocked && <p className="mwf-warn">{custodyHint}</p>}
        {allowed && !custodyLocked && !holdBlocked && readyBlocked && <p className="mwf-warn">{readyHint}</p>}
      </div>
    </div>
  );
}

// A single board column (lane) — colored top accent, header with count, cards or an empty state.
function Lane({ lane, loading, expanded, onToggle, cardProps }) {
  const { t } = useI18n();
  const tickets = lane.tickets;
  const shown = expanded ? tickets : tickets.slice(0, LANE_PAGE_SIZE);
  const hidden = tickets.length - shown.length;
  const Ic = LANE_ICON[lane.key] || Icon.Car;
  return (
    <div className="mwf-lane" style={{ '--tone': lane.tone }}>
      <span className="mwf-lane-accent" style={{ background: lane.tone }} />
      <div className="mwf-lane-hd">
        <span className="ic" aria-hidden="true"><Ic /></span>
        <span className="nm" title={lane.hint}>{lane.name}</span>
        <span className="ct">{tickets.length}</span>
      </div>
      <div className="mwf-lane-bd">
        {loading ? (
          <><div className="opx-skel" style={{ height: 88 }} /><div className="opx-skel" style={{ height: 88 }} /></>
        ) : tickets.length === 0 ? (
          <div className="mwf-empty">
            <span className="ic"><Icon.Check className="h-4 w-4" /></span>
            <p className="t">{t('workflow.board.noTickets')}</p>
            <p className="h">{lane.hint}</p>
          </div>
        ) : (
          <>
            {shown.map((tk) => <TicketCard key={tk.id} tk={tk} tone={lane.tone} laneKey={lane.key} laneName={lane.name} active={tk.id === cardProps.selectedId} {...cardProps} />)}
            {hidden > 0 && <button type="button" className="opx-lane-more" onClick={onToggle}>+{hidden} more</button>}
            {expanded && tickets.length > LANE_PAGE_SIZE && <button type="button" className="opx-lane-more" onClick={onToggle}>{t('workflow.board.showLess')}</button>}
          </>
        )}
      </div>
    </div>
  );
}

export default function MaintenanceWorkflow() {
  const { t } = useI18n();
  // Lane names/hints in the active language — never read them off the raw constants.
  const laneDefs = useLanes();
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
  // WHERE ON THE CAR — the shared location vocabulary + the per-fault-type policy that says which
  // findings take a place at all. Ships inside the findings catalog (one request), so the detail
  // editor can render the instant a fault chip is tapped. See lib/faultLocations.
  const [locationCatalog, setLocationCatalog] = useState({ groups: [], policy: {}, maxQuantity: 40 });
  const [drivers, setDrivers] = useState([]);
  const [sevFilter, setSevFilter] = useState('');
  // Two more narrowings on the same board: the marque the car is filed under, and the garage the
  // ticket is routed to. Both are read off the tickets themselves, so the dropdowns only ever offer
  // values that are actually on the board right now.
  const [brandFilter, setBrandFilter] = useState('');
  const [garageFilter, setGarageFilter] = useState('');
  const [sortKey, setSortKey] = useState('recent');
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
        setLocationCatalog({
          groups: f.data?.data?.locations || [],
          policy: f.data?.data?.location_policy || {},
          maxQuantity: f.data?.data?.max_quantity || 40,
        });
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
    if (brandFilter && brandOf(tk) !== brandFilter) return false;
    if (garageFilter && (tk.garage || '') !== garageFilter) return false;
    if (query) {
      const q = query.toLowerCase();
      const hay = [tk.plate, tk.car, tk.garage, tk.customer_complaint, ...(tk.tasks || []).map((x) => x.symptom)]
        .filter(Boolean).join(' ').toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  }, [sevFilter, brandFilter, garageFilter, query]);

  const buildLanes = useCallback((defs) => defs.map((l) => ({
    ...l,
    tickets: (columns[l.key] || []).filter(matchesFilters).sort(SORTS[sortKey] || SORTS.recent),
  })), [columns, matchesFilters, sortKey]);

  // The dropdown options — built from every ticket the board holds, NOT from the filtered view, so
  // picking one brand never empties the list you would use to pick a different one.
  const allBoardTickets = useMemo(() => Object.values(columns).flat(), [columns]);
  const brandOptions = useMemo(
    () => [...new Set(allBoardTickets.map(brandOf).filter(Boolean))].sort(),
    [allBoardTickets],
  );
  const garageOptions = useMemo(
    () => [...new Set(allBoardTickets.map((tk) => tk.garage).filter(Boolean))].sort(),
    [allBoardTickets],
  );

  const primaryLanes = useMemo(() => buildLanes(laneDefs.primary), [buildLanes, laneDefs]);
  // Always show every stage lane (empty ones render their "No tickets" state) so the board keeps a
  // stable shape and no stage is ever hidden.
  const exceptionLanes = useMemo(() => buildLanes(laneDefs.exception), [buildLanes, laneDefs]);
  const allLanes = useMemo(() => [...primaryLanes, ...exceptionLanes], [primaryLanes, exceptionLanes]);
  const allTickets = useMemo(() => allLanes.flatMap((l) => l.tickets), [allLanes]);

  // Guard: if the focused stage isn't a real lane key (e.g. a stale/bad ?stage= value), fall back to
  // the full board. Empty stages are fine — they render their own "No tickets" state.
  useEffect(() => {
    const known = laneDefs.all.some((l) => l.key === stageTab);
    if (stageTab !== 'all' && !known) setStageTab('all');
  }, [stageTab, laneDefs]);

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
        {/* Send a car in — its own two-door form, so it is excluded from the generic modal below. */}
        {modal?.action === 'request' && (
          <SendCarInModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
        )}
        {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test', 'request'].includes(modal.action) && (
          <TicketActionModal action={modal.action} ticket={modal.ticket || null} vehicles={vehicles} garages={garages} findingsCatalog={findingsCatalog} keywordMeta={keywordMeta} faultCausesCatalog={faultCausesCatalog} locationCatalog={locationCatalog} assignableDrivers={drivers} allowedTypes={maintTypes} onClose={() => setModal(null)} onDone={onDone} />
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
        {/* ---- Masthead — what this page is, what time it is, and the one button that adds work ---- */}
        <header className="mwf-hdr">
          <div className="mwf-hdr-main">
            <nav className="mwf-crumbs" aria-label={t('workflow.board.breadcrumb')}>
              <span>{t('workflow.board.breadcrumb')}</span>
              <Icon.ArrowRight className="sep" aria-hidden="true" />
              <b>{t('workflow.board.title')}</b>
            </nav>
            <h1>{t('workflow.board.title')}</h1>
            <p>{t('workflow.board.tagline')}</p>
          </div>

          <div className="mwf-hdr-when">
            <span className="ic"><Icon.Calendar /></span>
            <span className="txt"><BoardClock /></span>
            <span className="mwf-live" title={t('workflow.board.live')}><span className="d" />{t('workflow.board.liveNow')}</span>
          </div>

          {/* Either door qualifies: a driver asks for a look, an inspector or supervisor can also send
              a car straight to a garage. SendCarInModal shows only the doors the caller may use. The
              caret carries the intake that isn't a car being sent in — a customer's complaint. */}
          {(canLogistics || canInitiate || canManage) && (
            <div className="mwf-hdr-acts">
              <button type="button" className="mwf-newbtn" onClick={() => setModal({ action: 'request' })}>
                <Icon.Plus className="h-4 w-4" /> {t('workflow.board.newTicket')}
              </button>
              {canManage && (
                <span className="mwf-newbtn-more">
                  <ActionMenu
                    glyph="⌄"
                    label={t('workflow.board.newTicket')}
                    items={[
                      { key: 'request', label: t('workflow.board.requestInspection'), onSelect: () => setModal({ action: 'request' }) },
                      { key: 'complaint', label: t('workflow.board.newComplaint'), onSelect: () => setModal({ action: 'complaint' }) },
                    ]}
                  />
                </span>
              )}
            </div>
          )}
        </header>

        {error && <div className="mwf-error">{error}</div>}

        {/* One control strip — search + filters on the left, view toggle + primary actions on the right. */}
        <div className="mwf-toolbar">
          <div className="mwf-search">
            <Icon.Search className="h-4 w-4" />
            <input className="opx-input" placeholder={t('workflow.board.searchPlaceholder')} value={query} onChange={(e) => setQuery(e.target.value)} style={{ paddingLeft: 34 }} />
          </div>
          <select className="opx-select" value={sevFilter} onChange={(e) => setSevFilter(e.target.value)}>
            {SEV_FILTERS.map((f) => (
              <option key={f.value || 'all'} value={f.value}>
                {f.value ? `${f.emoji} ${t(`workflow.faultSeverity.${f.value}`)}` : t('workflow.board.allStatus')}
              </option>
            ))}
          </select>
          <select className="opx-select" value={brandFilter} onChange={(e) => setBrandFilter(e.target.value)}>
            <option value="">{t('workflow.board.allBrands')}</option>
            {brandOptions.map((b) => <option key={b} value={b}>{b}</option>)}
          </select>
          <select className="opx-select" value={garageFilter} onChange={(e) => setGarageFilter(e.target.value)}>
            <option value="">{t('workflow.board.allGarages')}</option>
            {garageOptions.map((g) => <option key={g} value={g}>{g}</option>)}
          </select>
          {(query || sevFilter || brandFilter || garageFilter) && (
            <button className="opx-btn" onClick={() => { setQuery(''); setSevFilter(''); setBrandFilter(''); setGarageFilter(''); }}>{t('workflow.board.clear')}</button>
          )}

          <div className="mwf-toolbar-right">
            {/* The filters narrow what is DRAWN and never what is counted above — so whenever they hide
                a card, the strip says how many are left rather than letting the headline numbers lie. */}
            <span className="mwf-toolbar-meta">{t('workflow.board.shown', { n: visibleTickets.length })}</span>
            <button
              type="button"
              className={`opx-btn ${showCycleGuide ? 'primary' : ''}`}
              onClick={() => setShowCycleGuide((v) => !v)}
              title={t('workflow.cycle.title')}
            >
              <Icon.Activity className="h-4 w-4" /> {t('workflow.cycle.toggle')}
            </button>
            <div className="seg">
              <button className={view === 'board' ? 'on' : ''} onClick={() => setView('board')}>
                <Icon.Chart className="h-3.5 w-3.5" /> {t('workflow.board.viewBoard')}
              </button>
              <button className={view === 'list' ? 'on' : ''} onClick={() => setView('list')}>
                <Icon.Filter className="h-3.5 w-3.5" /> {t('workflow.board.viewList')}
              </button>
            </div>
            <select className="opx-select mwf-sort" value={sortKey} onChange={(e) => setSortKey(e.target.value)} aria-label={t('workflow.board.sortBy')}>
              <option value="recent">{t('workflow.board.sort.recent')}</option>
              <option value="longest">{t('workflow.board.sort.longest')}</option>
              <option value="severity">{t('workflow.board.sort.severity')}</option>
              <option value="plate">{t('workflow.board.sort.plate')}</option>
            </select>
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
          <CommandPanel title={t('workflow.board.allOpen')} label={`${visibleTickets.length}`} bodyFlush>
            <div className="opx-tblwrap">
              <table className="opx-tbl">
                <thead>
                  <tr>
                    <th>{t('workflow.board.cols.vehicle')}</th>
                    <th>{t('workflow.board.cols.stage')}</th>
                    <th>{t('workflow.board.cols.severity')}</th>
                    <th>{t('workflow.board.cols.garage')}</th>
                    <th>{t('workflow.board.cols.faults')}</th>
                    <th>{t('workflow.board.cols.inStage')}</th>
                    <th className="r">{t('workflow.board.cols.action')}</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleTickets.map((tk) => {
                    const a = stageAge(tk, t);
                    const laneName = laneDefs.all.find((l) => l.key === tk.workflow_status)?.name || tk.status_label || tk.workflow_status;
                    return (
                      <tr key={tk.id} className={tk.fault_severity === 'critical' ? 'rt-crit' : ''} onClick={() => openDetail(tk)} style={{ cursor: 'pointer' }}>
                        <td><Link to={`/maintenance-workflow/${tk.id}`} className="opx-plate2" onClick={(e) => e.stopPropagation()}>{tk.plate || `#${tk.id}`}</Link><div className="opx-sub">{tk.car || ''}</div></td>
                        <td className="opx-mono2">{laneName}</td>
                        <td>{tk.fault_severity ? <span className={`opx-chip ${SEV_OPX[tk.fault_severity_tone] || 'paused'}`}><span className="cd" />{t(`workflow.faultSeverity.${tk.fault_severity}`)}</span> : '—'}</td>
                        <td className="opx-mono2">{tk.garage || '—'}</td>
                        <td className="opx-mono2">{(tk.tasks || []).length || '—'}</td>
                        <td className={`opx-mono2 ${a?.over ? 'mwf-over' : ''}`}>{a?.label || '—'}</td>
                        <td className="r"><Link to={`/maintenance-workflow/${tk.id}`} className="opx-ibtn go" onClick={(e) => e.stopPropagation()}>{t('workflow.board.open')}</Link></td>
                      </tr>
                    );
                  })}
                  {visibleTickets.length === 0 && !loading && <tr><td colSpan={7}><div className="opx-empty">{t('workflow.board.noMatch')}</div></td></tr>}
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
      {/* Send a car in — its own two-door form, so it is excluded from the generic modal below. */}
      {modal?.action === 'request' && (
        <SendCarInModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal && !['logistics', 'route', 'complaint', 'breakdown', 'triage', 'test', 'request'].includes(modal.action) && (
        <TicketActionModal
          action={modal.action}
          ticket={modal.ticket || null}
          vehicles={vehicles}
          garages={garages}
          findingsCatalog={findingsCatalog}
          keywordMeta={keywordMeta}
          faultCausesCatalog={faultCausesCatalog}
          locationCatalog={locationCatalog}
          assignableDrivers={drivers}
          allowedTypes={maintTypes}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}
    </div>
  );
}
