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
};

// Some statuses need a DYNAMIC action beyond a pure workflow_status lookup. ready_for_pickup covers BOTH
// legs of the driver's return trip (collect the car from the garage, then arrive at our park) — the
// ticket's workflow_status doesn't change in between, so this keys off picked_up_from_garage_at instead.
// Every ACTION[...] lookup across the board/drawer/queue should go through this, not the raw map.
export function resolveAction(tk) {
  if (!tk) return undefined;
  if (tk.workflow_status === 'ready_for_pickup') {
    return tk.picked_up_from_garage_at
      ? { action: 'arriveAtPark', perm: 'maintenance.logistics', variant: 'success' }
      : { action: 'collectFromGarage', perm: 'maintenance.logistics', variant: 'primary' };
  }
  // A Breakdown can't be driven in — Recovery (towing) is the only sensible dispatch path, so it
  // OUTRANKS the classic "Assign Garage" screen the moment the ticket is born (inspection_pending), not
  // just after a garage happens to already be picked. dispatchRecovery() accepts the garage inline, so
  // there is no separate gate to clear first. Still offered (as a secondary button) at awaiting_dispatch
  // for the rarer case a car assigned the classic way turns out to need towing after all.
  if ((tk.workflow_status === 'inspection_pending' || tk.workflow_status === 'awaiting_dispatch') && tk.maintenance_type === 'breakdown') {
    return { action: 'recovery', perm: ['maintenance.logistics', 'maintenance.delegate'], variant: 'danger' };
  }
  return ACTION[tk.workflow_status];
}

// Does the user hold the permission(s) a card action needs (array = any-of)?
export const allows = (can, perm) => (Array.isArray(perm) ? perm.some(can) : can(perm));

// The "Now at Garage" check-in (in_transit → under_repair) is custody-gated on the backend: ONLY the
// driver who physically picked the car up (dispatched_by_id) may confirm arrival — no supervisor
// override, no exceptions. Mirrors MaintenanceWorkflowService::markUnderRepair() so the button reflects
// the same rule instead of letting anyone else tap it and bounce off a 422.
export function custodyBlocked(tk, userId) {
  if (!tk || tk.workflow_status !== 'in_transit' || !tk.dispatched_by_id) return false;
  return Number(tk.dispatched_by_id) !== Number(userId);
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
export const REASON_TONE = { test_drive: 'violet', customer_reported: 'amber', periodic: 'blue' };

// Fault Severity tone (from the resource's fault_severity_tone) → chip classes. The inspector's
// mandatory diagnostic grade is shown FIRST on every ticket surface, so a supervisor reads how
// urgent the garage dispatch is before anything else.
export const SEVERITY_CHIP = {
  red:   'bg-red-50 text-red-700 ring-red-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

// The "In Workshop" stage — the only point at which faults can be managed (worked / marked fixed).
// It begins once the car has ARRIVED and been checked in (under_repair) — NOT while it's still on the
// way ("Now at Garage" / in_transit), where only arrival + the odometer are logged and no repair
// happens — and runs up to and including Ready for Pickup (car still physically at the garage there).
// ready_for_reinspection is deliberately NOT here: by then the car has left the garage and is back at
// our park (major-repair QA sign-off, not a repair step). Mirrors Maintenance::WF_AT_GARAGE on the backend.
export const AT_GARAGE_STATUSES = ['under_repair', 'repair_review', 'ready_for_pickup', 'closed'];
export const isAtGarage = (tk) => AT_GARAGE_STATUSES.includes(tk?.workflow_status);

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
