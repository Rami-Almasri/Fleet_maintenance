// Shared workflow metadata + formatters used by BOTH the board cards and the detail drawer,
// so the primary action, reason tones and time formatting stay identical across surfaces.

// The single primary action available on a row given its exact workflow_status. The button label is
// resolved from workflow.cardAction.<action>. The final re-inspection (sign off / send back) may be
// done by the INSPECTOR or a SUPERVISOR — drivers can see a ready ticket but not act on it (mirrors
// routes/api.php).
export const ACTION = {
  // Customer-complaint triage (Abu Maroof): talk / resolve on-site / send the car in.
  complaint_triage:       { action: 'triage',    perm: 'maintenance.initiate',  variant: 'primary' },
  inspection_requested:   { action: 'start',     perm: 'maintenance.initiate',  variant: 'primary' },
  inspection_diagnostic:  { action: 'decide',    perm: 'maintenance.initiate',  variant: 'primary' },
  // Phase 2 — the Supervisor (dispatcher) picks the garage + assigns a driver.
  inspection_pending:     { action: 'assign',    perm: 'maintenance.delegate',  variant: 'primary' },
  // Repair Location "On-Site" lane — a minor job done where the car is parked (no garage). The single
  // primary action collapses the whole back-half of the workflow into one step: no dispatch, no
  // re-inspection, no QA. Same authority as closing a ticket (inspector who logged it, or a supervisor).
  on_site_pending:         { action: 'serviced', perm: ['maintenance.initiate', 'maintenance.delegate'], variant: 'success' },
  // Deferred maintenance — a recorded fault whose repair was postponed. The ONE action on it is to send
  // it in, and it is the dispatch authority's call (the same pair that resumes a paused ticket): whether
  // the moment has come is a scheduling judgement, not a diagnostic one. The inspector already made his.
  maintenance_deferred:   { action: 'activateDeferred', perm: ['maintenance.delegate', 'maintenance.manage'], variant: 'primary' },
  // Phase 3 — the assigned Driver executes the physical pickup.
  awaiting_dispatch:      { action: 'dispatch',  perm: 'maintenance.logistics', variant: 'primary' },
  in_transit:             { action: 'receive',   perm: 'maintenance.logistics', variant: 'primary' },
  under_repair:           { action: 'ready',     perm: 'maintenance.logistics', variant: 'success' },
  // UC-5a — Supervisor Video-Review gate (Waleed/Abdullah): the primary action is APPROVE (→ Ready for
  // Pickup); the secondary "request a re-fix" is a separate button in the drawer. Both are supervisor authority.
  repair_review:          { action: 'approveRepair', perm: 'maintenance.delegate', variant: 'success' },
  // ready_for_pickup is DELIBERATELY not keyed here — it covers BOTH legs of the driver's return trip
  // (collect from the garage, then arrive at our park) without a workflow_status change in between, so
  // its action is resolved dynamically by resolveAction() below.
  // Final QA re-inspection (major repairs only, entered from in_our_park): Inspector (initiate) OR
  // Supervisor (delegate) — any-of via `allows`.
  ready_for_reinspection: { action: 'reinspect', perm: ['maintenance.initiate', 'maintenance.delegate'], variant: 'primary' },
  // Re-inspection failed → the Supervisor re-dispatches (reuses the assign-garage action, from this state).
  reinspection_failed:    { action: 'assign',    perm: 'maintenance.delegate',  variant: 'danger' },
  // Paused — Returned to Service — the car is back (or being sent in); Resume continues from the exact
  // paused stage. A controller (manage) or supervisor (delegate) may resume.
  paused_returned_to_service: { action: 'resume', perm: ['maintenance.manage', 'maintenance.delegate'], variant: 'primary' },
};

// Is this ticket currently paused — released back into service, repair on hold? Mirrors
// App\Models\Maintenance::isPausedReturnedToService().
export const isPaused = (tk) => tk?.workflow_status === 'paused_returned_to_service';

// Paused AND the vehicle hasn't been marked physically returned yet — still out with the customer/
// operation. Mirrors App\Models\Maintenance::isPausedOut(). Drives the "Mark Returned" secondary button.
export const isPausedOut = (tk) => isPaused(tk) && !tk?.vehicle_returned_at;

// The committed-ticket stages a live repair can be PAUSED from (Pause Maintenance & Return to Service).
// Mirrors App\Models\Maintenance::PAUSABLE_STATES — the drawer offers a secondary "Pause" button on these.
export const PAUSABLE_STATES = [
  'inspection_pending', 'awaiting_dispatch', 'in_transit', 'under_repair',
  'repair_review', 'ready_for_reinspection', 'reinspection_failed', 'ready_for_pickup',
];
export const isPausable = (tk) => PAUSABLE_STATES.includes(tk?.workflow_status);

// Temporary Vehicle Release — the car is taken OUT of the workshop mid-repair (road test / customer
// test / external inspection / storage) WITHOUT pausing: the ticket stays at its stage. Mirrors
// App\Models\Maintenance::isTemporarilyReleased() / isTempReleasable() (TEMP_RELEASABLE_STATES ==
// PAUSABLE_STATES). The drawer offers a secondary "Temporarily Release" / "Return to Workshop" button.
export const isTemporarilyReleased = (tk) => !!tk?.temporarily_released;
export const isTempReleasable = (tk) =>
  PAUSABLE_STATES.includes(tk?.workflow_status) && !isTemporarilyReleased(tk) && !isPaused(tk);

// A release is a ROUND TRIP, and while it runs, the release's own stage — not the ticket's frozen
// workflow_status — decides what the card asks for next. CONTRACT with
// App\Models\MaintenanceTemporaryRelease::STAGES. The lanes these map onto live on the backend
// (STAGE_LANES); the board just renders where the server put the card.
//
//   out_dispatch     supervisor: where does it go, and who drives it?
//   out_assigned     driver: collect it from the garage (captures the odometer OUT)
//   out_transit      driver: confirm it arrived at the destination
//   at_destination   controller/supervisor: call it back when the repair wants it
//   return_dispatch  supervisor: confirm/change the garage + assign a driver
//   return_assigned  driver: collect it from where it's parked
//   return_transit   driver: confirm it's back in the workshop (captures the odometer IN)
export const RELEASE_ACTION = {
  out_dispatch:    { action: 'assignReleaseMove',   perm: ['maintenance.delegate', 'maintenance.manage'],   variant: 'primary' },
  out_assigned:    { action: 'startReleaseMove',    perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'primary' },
  out_transit:     { action: 'arriveAtDestination', perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'success' },
  at_destination:  { action: 'requestReleaseReturn', perm: ['maintenance.manage', 'maintenance.delegate'],   variant: 'primary' },
  return_dispatch: { action: 'assignReleaseReturn', perm: ['maintenance.delegate', 'maintenance.manage'],    variant: 'primary' },
  return_assigned: { action: 'startReleaseReturn',  perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'primary' },
  return_transit:  { action: 'returnFromRelease',   perm: ['maintenance.logistics', 'maintenance.delegate', 'maintenance.manage'], variant: 'success' },
};

// Where the open release stands (null when the car isn't out).
export const releaseStage = (tk) =>
  (isTemporarilyReleased(tk) ? (tk?.release_stage || tk?.active_temporary_release?.stage || null) : null);

// The car is physically away from the workshop right now (in transit or parked somewhere else) — as
// opposed to released-on-paper but still sitting in the garage waiting to be collected.
export const AWAY_RELEASE_STAGES = ['out_transit', 'at_destination', 'return_dispatch', 'return_assigned', 'return_transit'];
export const isReleaseAway = (tk) => AWAY_RELEASE_STAGES.includes(releaseStage(tk));

// The release can still be called off — the car hasn't physically moved yet, it's sitting in the garage
// waiting to be collected. Once a driver has the keys there is no cancelling: the car is out, and the
// only way back is the return leg. Mirrors MaintenanceWorkflowService::cancelTemporaryRelease.
export const isReleaseCancellable = (tk) => ['out_dispatch', 'out_assigned'].includes(releaseStage(tk));

// Some statuses need a DYNAMIC action beyond a pure workflow_status lookup. ready_for_pickup covers BOTH
// legs of the driver's return trip (collect the car from the garage, then arrive at our park) — the
// ticket's workflow_status doesn't change in between, so this keys off picked_up_from_garage_at instead.
// Every ACTION[...] lookup across the board/drawer/queue should go through this, not the raw map.
export function resolveAction(tk) {
  if (!tk) return undefined;
  // Temporary Vehicle Release — while the car is out, the round trip OUTRANKS the repair stage: the
  // repair is frozen and can't advance until the car is back, so the only thing anyone can do to this
  // ticket is move the car along its trip. See RELEASE_ACTION.
  const rel = releaseStage(tk);
  if (rel) return RELEASE_ACTION[rel];
  if (tk.workflow_status === 'ready_for_pickup') {
    return tk.picked_up_from_garage_at
      ? { action: 'arriveAtPark', perm: 'maintenance.logistics', variant: 'success' }
      : { action: 'collectFromGarage', perm: 'maintenance.logistics', variant: 'primary' };
  }
  // A Breakdown can't be driven in — Recovery (towing) is the only sensible dispatch path, so it
  // OUTRANKS the classic "Assign Garage" screen the moment the ticket is born (inspection_pending), not
  // just after a garage happens to already be picked. dispatchRecovery() accepts the garage inline, so
  // there is no separate gate to clear first. At inspection_pending ("Needs Dispatch") no garage is
  // assigned yet, so this is still the Supervisor's call — a Driver can't act on it here. Still offered
  // (as a secondary button, now also to the Driver) at awaiting_dispatch, once a garage is already
  // picked and the car assigned the classic way turns out to need towing after all.
  if (tk.workflow_status === 'inspection_pending' && tk.maintenance_type === 'breakdown') {
    return { action: 'recovery', perm: 'maintenance.delegate', variant: 'danger' };
  }
  if (tk.workflow_status === 'awaiting_dispatch' && tk.maintenance_type === 'breakdown') {
    return { action: 'recovery', perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'danger' };
  }
  // A planned garage-to-garage TRANSFER where the Supervisor chose "Recovery Truck" at the Transfer
  // dialog (transfer_transport_method) — the pickup leg runs through the same Recovery dispatch form
  // as a breakdown tow, not the driver dispatch() form, even though maintenance_type isn't 'breakdown'.
  if (tk.workflow_status === 'awaiting_dispatch' && tk.transfer_transport_method === 'recovery') {
    return { action: 'recovery', perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'danger' };
  }
  return ACTION[tk.workflow_status];
}

// Does the user hold the permission(s) a card action needs (array = any-of)?
export const allows = (can, perm) => (Array.isArray(perm) ? perm.some(can) : can(perm));

// ── THE FINDING HOLD — A FREEZE, NOT A PLACE ────────────────────────────────────────────────────────
//
// A finding the car's own data disagrees with stops the ticket WHERE IT STANDS: the server refuses every
// staged transition (MaintenanceWorkflowService::assertTransition → FindingApprovalService::
// assertNothingPending) until an approver decides. The ticket does NOT move to a holding stage — it keeps
// its real one, because "Needs Dispatch, frozen" and "Under Repair, frozen" are different situations and
// collapsing them would lose where the car physically is, and because a stage you are moved INTO has to
// remember where to put you back. Nothing here is ever stranded: rejecting is always the way out.
//
// So the hold is expressed the way every other blocker on this board is — a badge and a dead CTA on the
// card that still sits in its own lane, exactly like custodyBlocked() and the open-faults gate below.
//
// Listed as the actions the hold does NOT stop, mirroring the server: these are the endpoints that never
// route through assertTransition, so blocking them here would refuse what the backend allows.
export const FINDING_HOLD_EXEMPT = new Set([
  // Writing to the ticket rather than moving it. `finding` is also how a held finding gets corrected,
  // so it must stay open — otherwise the hold could only ever be resolved by an approver.
  'finding', 'followup', 'lineitems', 'delegate', 'typechange',
  // Custody checkpoints — they guard on workflow_status directly, not through the transition table.
  'pause', 'resume', 'markReturned',
  // Temporary release and its six legs: the car leaves and comes back, the ticket keeps its stage.
  'temporarilyRelease', 'cancelRelease', 'assignReleaseMove', 'startReleaseMove',
  'arriveAtDestination', 'requestReleaseReturn', 'assignReleaseReturn', 'startReleaseReturn',
  'returnFromRelease',
]);

/** Findings on this ticket still waiting on a decision. The same list the drawer and the panel read. */
export const heldFindings = (tk) => tk?.pending_finding_approvals || [];

/** Does the hold stop THIS action? `null` asks the blunt question "is this ticket frozen at all?". */
export const findingHoldBlocks = (tk, action = null) =>
  heldFindings(tk).length > 0 && (action === null || !FINDING_HOLD_EXEMPT.has(action));

// Custody gates — a return/arrival leg may only be completed by the SAME driver who took the car,
// enforced on the backend so the button reflects the rule instead of letting anyone else tap it and
// bounce off a 422. Two legs are gated:
//   • "Now at Garage" check-in (in_transit → under_repair): only the driver who picked the car up
//     (dispatched_by_id) — mirrors MaintenanceWorkflowService::markUnderRepair().
//   • "Arrive at our park" (ready_for_pickup, after collect): only the driver who collected it from the
//     garage (picked_up_from_garage_by) — mirrors MaintenanceWorkflowService::arriveAtPark().
//
// A SUPERVISOR is exempt from the arrival gate — they run these legs themselves (see
// MaintenanceWorkflowService::maySupersedeDriver), so pass `can` to let them through. The garage
// check-in gate has no override on the backend and keeps none here.
export function custodyBlocked(tk, userId, can = null) {
  if (!tk) return false;
  // A release leg in flight belongs to whoever is actually driving it — only they can confirm the
  // arrival. Mirrors MaintenanceWorkflowService::assertReleaseDriver (supervisors may step in).
  const rel = releaseStage(tk);
  if (rel === 'out_transit' || rel === 'return_transit') {
    const holder = rel === 'out_transit'
      ? tk.active_temporary_release?.out_driver_id
      : tk.active_temporary_release?.return_driver_id;
    if (!holder || canSupersedeDriver(can)) return false;
    return Number(holder) !== Number(userId);
  }
  if (tk.workflow_status === 'in_transit' && tk.dispatched_by_id) {
    return Number(tk.dispatched_by_id) !== Number(userId);
  }
  // The arrival leg only exists once the car has been collected (picked_up_from_garage_at set); before
  // that the action is "Collect from Garage", which any driver may run.
  if (tk.workflow_status === 'ready_for_pickup' && tk.picked_up_from_garage_at && tk.picked_up_from_garage_by) {
    if (canSupersedeDriver(can)) return false;
    return Number(tk.picked_up_from_garage_by) !== Number(userId);
  }
  return false;
}

// Does this user hold the dispatch authority that lets them take a leg on someone else's name?
// Mirrors MaintenanceWorkflowService::SUPERSEDE_DRIVER_PERMISSION.
const canSupersedeDriver = (can) => typeof can === 'function' && !!can('maintenance.delegate');

// Assigned-pickup gate — when the Supervisor named a driver for the pickup, that job is THAT driver's.
// Every other driver sees "Assigned to <name>" instead of a Pick up button they'd bounce off (the
// backend refuses them in MaintenanceWorkflowService::dispatch()). A pickup with NO driver named stays
// open to the pool — first driver to claim it takes it — which is how garage transfers are raised.
// A supervisor may take any pickup, assigned or not — pass `can` so their button stays live.
export function assignmentBlocked(tk, userId, can = null) {
  if (!tk) return false;
  // The same rule on a release pickup: a named driver owns that leg; an unnamed one is the pool's.
  const rel = releaseStage(tk);
  if (rel === 'out_assigned' || rel === 'return_assigned') {
    const named = rel === 'out_assigned'
      ? tk.active_temporary_release?.out_driver_id
      : tk.active_temporary_release?.return_driver_id;
    if (!named || canSupersedeDriver(can)) return false;
    return Number(named) !== Number(userId);
  }
  if (tk.workflow_status !== 'awaiting_dispatch') return false;
  const driverId = tk.delegation?.driver_id ?? tk.assigned_driver_id;
  if (!driverId) return false;
  if (canSupersedeDriver(can)) return false;
  return Number(driverId) !== Number(userId);
}

// The driver a pickup is assigned to, for the "Assigned to X" note. Null when it's the current user's
// job, the pickup is open to the pool, or the viewer may take it anyway.
export function assignedDriverName(tk, userId, can = null) {
  if (!assignmentBlocked(tk, userId, can)) return null;
  const rel = releaseStage(tk);
  if (rel === 'out_assigned') return tk.active_temporary_release?.out_driver_name || null;
  if (rel === 'return_assigned') return tk.active_temporary_release?.return_driver_name || null;
  return tk.delegation?.driver_name || tk.assigned_driver_name || null;
}

// The name of the driver who holds custody on a gated leg (for the "in X's custody" note shown when the
// action button is hidden). Returns null when the current user IS the custodian or the leg isn't gated.
export function custodyHolderName(tk, userId, can = null) {
  if (!custodyBlocked(tk, userId, can)) return null;
  const rel = releaseStage(tk);
  if (rel === 'out_transit') return tk.active_temporary_release?.out_driver_name || null;
  if (rel === 'return_transit') return tk.active_temporary_release?.return_driver_name || null;
  if (tk.workflow_status === 'in_transit') return tk.dispatched_by_name || null;
  if (tk.workflow_status === 'ready_for_pickup') return tk.picked_up_from_garage_by_name || null;
  return null;
}

// The primary CTA's label for a ticket. Normally `workflow.cardAction.<action>`, but the arrival
// check-in ('receive') reads "Confirm Handover" when the car is riding on a custodian's transport task
// (position.driver present) — a garage transfer — so the custodian's button matches their duty.
export function ctaLabel(t, tk) {
  const act = resolveAction(tk);
  if (!act) return '';
  if (act.action === 'receive' && tk?.position?.driver) return t('workflow.cardAction.confirmHandover');
  return t(`workflow.cardAction.${act.action}`);
}

// Reason → badge tone. The visible label comes from workflow.reasonShort.<value>.
export const REASON_TONE = { test_drive: 'violet', customer_reported: 'amber', periodic: 'blue', driver_reported: 'emerald' };

// request_origin → human label + tone. WHERE the ticket came from, a separate axis from the reason
// above. CONTRACT with Maintenance::REQUEST_ORIGIN_LABELS.
export const ORIGIN_LABEL = {
  driver_observation: 'Driver Observation',
  driver_request: 'Driver Request',
  controller: 'Controller',
  inspector: 'Inspector',
  system_schedule: 'System Schedule',
  workshop: 'Workshop',
  customer: 'Customer Report',
};
export const ORIGIN_TONE = {
  driver_observation: 'emerald',
  driver_request: 'cyan',
  controller: 'blue',
  inspector: 'violet',
  system_schedule: 'indigo',
  workshop: 'red',
  customer: 'amber',
};

// Fault Severity tone (from the resource's fault_severity_tone) → chip classes. The inspector's
// mandatory diagnostic grade is shown FIRST on every ticket surface, so a supervisor reads how
// urgent the garage dispatch is before anything else.
export const SEVERITY_CHIP = {
  red:    'bg-red-50 text-red-700 ring-red-200',
  orange: 'bg-orange-50 text-orange-700 ring-orange-200',
  amber:  'bg-amber-50 text-amber-700 ring-amber-200',
  green:  'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

// The "In Workshop" stage — the only point at which faults can be managed (worked / marked fixed).
// It begins once the car has ARRIVED and been checked in (under_repair) — NOT while it's still on the
// way ("Now at Garage" / in_transit), where only arrival + the odometer are logged and no repair
// happens — and runs up to and including Ready for Pickup (car still physically at the garage there).
// ready_for_reinspection is deliberately NOT here: by then the car has left the garage and is back at
// our park (major-repair QA sign-off, not a repair step). Mirrors Maintenance::WF_AT_GARAGE on the backend.
export const AT_GARAGE_STATUSES = ['under_repair', 'repair_review', 'ready_for_pickup', 'closed'];
export const isAtGarage = (tk) => AT_GARAGE_STATUSES.includes(tk?.workflow_status);

// Parts Ordering — a part can only be requested while the car is BEING INSPECTED (with the inspector for
// the diagnostic / test drive) or IN THE WORKSHOP (physically at the garage). Everywhere else — awaiting
// a dispatch decision, in transit, on-site, triage, back at our park, QA re-inspection, closed — the
// "Request Part" button is hidden. Gates that button on every ticket surface (drawer footer, command
// view, Parts card).
export const PART_ORDERABLE_STATES = [
  // Being inspected — the car is with the inspector (diagnostic / test drive).
  'inspection_requested', 'inspection_diagnostic',
  // In the workshop — the car is physically at the garage.
  'under_repair', 'repair_review', 'ready_for_pickup',
];
export const canOrderParts = (tk) => PART_ORDERABLE_STATES.includes(tk?.workflow_status);

// Per-fault (maintenance_task) status → presentation. `chip` styles the status pill (consistent with
// SEVERITY_CHIP), `dot` is the tiny status indicator on the dense board card. Keys are the backend
// MaintenanceTask::STATUSES contract; labels resolve from workflow.task.status.<key>.
export const TASK_STATUS = {
  pending:     { chip: 'bg-amber-50 text-amber-700 ring-amber-200',       dot: 'bg-amber-400' },
  in_progress: { chip: 'bg-blue-50 text-blue-700 ring-blue-200',          dot: 'bg-blue-500' },
  transferred: { chip: 'bg-violet-50 text-violet-700 ring-violet-200',    dot: 'bg-violet-500' },
  completed:   { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500' },
  cancelled:   { chip: 'bg-slate-100 text-slate-500 ring-slate-200',      dot: 'bg-slate-300' },
};

// A routine/reminder service that was PERFORMED inside a ticket carries a vehicle-sync confirmation
// state: 'pending_confirmation' until the ticket is closed (vehicle record NOT updated yet), then
// 'confirmed'. Labels resolve from workflow.task.confirm.<key>.
export const SERVICE_CONFIRM = {
  pending_confirmation: { chip: 'bg-amber-50 text-amber-800 ring-amber-300',    icon: '⏳' },
  confirmed:            { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', icon: '✓' },
};

// "3h ago" / "2d ago" / "45m ago" from a duration in seconds, localized via `t`.
export function fmtAgo(seconds, t) {
  if (seconds == null) return null;
  const s = Math.max(0, seconds);
  if (s < 90) return t('time.justNow');
  if (s < 3600) return t('time.minutesAgo', { n: Math.round(s / 60) });
  if (s < 86400) return t('time.hoursAgo', { n: Math.round(s / 3600) });
  return t('time.daysAgo', { n: Math.round(s / 86400) });
}

// "3h ago" / "2d ago" from an ISO timestamp, localized via `t`.
export function ago(iso, t) {
  if (!iso) return null;
  return fmtAgo(Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000), t);
}

// Stages that carry a "slacking" SLA — once a card overstays the threshold (seconds), its time-in-
// stage label turns red to nudge whoever owns the stage. Pending Dispatch is the Supervisor's call
// (pick the garage): a ticket parked there past 4h means they're sitting on it. Single source of
// truth so every surface (My Queue + the board) reddens at the same moment.
export const STAGE_SLA_SECONDS = { inspection_pending: 4 * 3600 };

// Seconds a ticket has sat in its CURRENT stage — server-computed (clock-skew-proof) when present,
// else derived from the entered-stage anchor (falling back to creation for legacy rows).
export function stageSeconds(tk) {
  if (typeof tk.seconds_in_stage === 'number') return tk.seconds_in_stage;
  const iso = tk.last_state_change_at || tk.created_at;
  return iso ? Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000) : null;
}

// The card's "time in stage": a localized "2h ago" label + whether the stage has breached its SLA
// (→ render red). Both derive from the SAME seconds value so the label and colour never disagree.
// Returns null when there's no timestamp to show.
export function stageAge(tk, t) {
  const secs = stageSeconds(tk);
  if (secs == null) return null;
  const sla = STAGE_SLA_SECONDS[tk.workflow_status];
  return { label: fmtAgo(secs, t), over: sla != null && secs > sla, seconds: secs };
}

// A compact "2h 10m" / "45m" / "1d 3h" from a duration in whole seconds. null/undefined → "—".
export function fmtDuration(seconds) {
  if (seconds == null) return '—';
  const s = Math.max(0, Math.round(seconds));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${m}m`;
  if (m) return `${m}m`;
  return `${s}s`;
}

// Absolute, locale-formatted timestamp ("28 Jun 2026, 14:05") from an ISO string.
export function fmtDateTime(iso) {
  if (!iso) return null;
  try {
    return new Date(iso).toLocaleString(undefined, {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  } catch {
    return iso;
  }
}
