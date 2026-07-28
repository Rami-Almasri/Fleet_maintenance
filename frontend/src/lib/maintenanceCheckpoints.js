// Maintenance Checkpoint — the progress-tracking client. Responsible users (Waleed/Abdullah, or a
// ticket's assigned owners) file dated workshop updates with evidence; the dashboard + vehicle profile
// read the resulting timeline and live monitoring state. Backend: MaintenanceCheckpointController.
//
// CONTRACT with App\Models\MaintenanceCheckpoint (STATUSES / DELAY_REASONS) — keep the option
// vocabularies below in lock-step with the model constants.
//
// There is NO manual "Progress outcome" (On Track / Delayed / Critical): a checkpoint captures the ETA
// change, and the dashboard PROGRESS_STATUS below is DERIVED from the promised date + workflow stage.

import api from '../api/client';

const base = (ticketId) => `/maintenance-tickets/${ticketId}`;

// The workshop's current stage.
export const STATUS_OPTIONS = [
  { value: 'waiting_parts', label: 'Waiting Parts' },
  { value: 'under_repair', label: 'Under Repair' },
  { value: 'painting', label: 'Painting' },
  { value: 'testing', label: 'Testing' },
  { value: 'ready_today', label: 'Ready Today' },
  { value: 'delayed', label: 'Delayed' },
  { value: 'other', label: 'Other' },
];

// Structured reasons the ETA moved (required only when the new completion date differs from the old one).
export const DELAY_REASONS = [
  { value: 'waiting_parts', label: 'Waiting Parts' },
  { value: 'workshop_busy', label: 'Workshop Busy' },
  { value: 'additional_damage', label: 'Additional Damage Found' },
  { value: 'customer_approval', label: 'Customer Approval Pending' },
  { value: 'insurance_approval', label: 'Insurance Approval' },
  { value: 'vendor_delay', label: 'Vendor Delay' },
  { value: 'other', label: 'Other' },
];

// Single mutually-exclusive dashboard progress status → visual theme + label. DERIVED, never chosen:
// the backend computes it from the promised completion date (today ≤ ETA → On Schedule, today > ETA →
// Overdue), the reminder window (Checkpoint due), and the workflow stage (Ready for Pickup / Completed).
export const PROGRESS_STATUS = {
  overdue:          { label: 'Overdue',          tone: 'red',     dot: 'bg-red-500',     chip: 'bg-red-50 text-red-700 ring-red-200' },
  needs_update:     { label: 'Checkpoint due',   tone: 'amber',   dot: 'bg-amber-500',   chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
  on_schedule:      { label: 'On Schedule',      tone: 'emerald', dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  ready_for_pickup: { label: 'Ready for Pickup', tone: 'sky',     dot: 'bg-sky-500',     chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  completed:        { label: 'Completed',        tone: 'slate',   dot: 'bg-slate-400',   chip: 'bg-slate-50 text-slate-600 ring-slate-200' },
};

// The workflow stages a car is "in the shop" for — the ONLY stages a checkpoint may be filed against.
// Mirrors App\Models\Maintenance::CHECKPOINT_TRACKED_STATES (keep in lock-step). Used to decide when the
// "Log Checkpoint" action shows on the maintenance-workflow board, so a checkpoint is always stored on a
// ticket that's actually at a workshop stage.
export const CHECKPOINT_STAGES = [
  'inspection_pending', 'awaiting_dispatch', 'in_transit', 'under_repair',
  'repair_review', 'ready_for_reinspection', 'reinspection_failed', 'ready_for_pickup',
];

export const isCheckpointStage = (workflowStatus) => CHECKPOINT_STAGES.includes(workflowStatus);

export const statusLabel = (v) => STATUS_OPTIONS.find((o) => o.value === v)?.label || v || null;
export const delayReasonLabel = (v) => DELAY_REASONS.find((o) => o.value === v)?.label || v || null;

// ── Reads ────────────────────────────────────────────────────────────────────
export async function getTicketCheckpoints(ticketId) {
  const res = await api.get(`${base(ticketId)}/checkpoints`);
  return res.data.data; // { monitor, responsibles, assigned, checkpoints, can_submit, can_manage }
}

export async function getVehicleCheckpoints(vehicleId) {
  const res = await api.get(`/maintenance-tickets/vehicle/${vehicleId}/checkpoints`);
  return res.data.data; // { active_ticket_id, monitor, checkpoints }
}

export async function getCheckpointCandidates() {
  const res = await api.get('/maintenance-tickets/checkpoint-candidates');
  return res.data.data; // [{ id, name }]
}

export async function getMaintenanceProgress() {
  const res = await api.get('/Dashboard/maintenance-progress');
  return res.data.data; // { summary, items }
}

// A Maintenance-Progress row's provenance → a small badge the queue shows so a car in the shop is
// distinguishable by which record we hold for it. Keep in lock-step with DashboardService::progressRow().
export const SOURCE_META = {
  contract: { label: 'Contract',  tip: 'Open type-U maintenance contract (OM / sheet — the fleet source of truth).', chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  workshop: { label: 'Workshop',  tip: 'App maintenance-workflow ticket.', chip: 'bg-violet-50 text-violet-700 ring-violet-200' },
};

// Resolve the ticket id a row files checkpoints against. Contract-sourced rows have no ticket until the
// first checkpoint is filed — this lazily links one (idempotent) and returns its id. Workflow rows already
// carry a ticket_id, so it's returned as-is.
export async function resolveCheckpointTicket(row) {
  if (row?.ticket_id) return row.ticket_id;
  if (row?.source === 'contract' && row?.contract_id) {
    const res = await api.post(`/maintenance-tickets/contract/${row.contract_id}/ensure-ticket`);
    return res.data.data.ticket_id;
  }
  return row?.ticket_id ?? null;
}

// ── Writes ───────────────────────────────────────────────────────────────────

/**
 * File a progress update (multipart, one save). `nextExpectedDate` (the new ETA) is required; a
 * `delayReason` is required when it differs from the current ETA. `files` is an array of File objects
 * (photos/videos). Returns { checkpoint, monitor }.
 */
export async function submitCheckpoint(ticketId, { status, delayReason, delayReasonOther, summary, nextExpectedDate, files = [] }) {
  const fd = new FormData();
  fd.append('next_expected_date', nextExpectedDate);
  if (status) fd.append('status', status);
  if (delayReason) fd.append('delay_reason', delayReason);
  if (delayReasonOther) fd.append('delay_reason_other', delayReasonOther);
  if (summary) fd.append('summary', summary);
  (files || []).forEach((f) => fd.append('files[]', f, f.name || 'checkpoint-media'));

  const res = await api.post(`${base(ticketId)}/checkpoints`, fd);
  return res.data.data;
}

export async function deleteCheckpoint(ticketId, checkpointId) {
  await api.delete(`${base(ticketId)}/checkpoints/${checkpointId}`);
  return true;
}

export async function setExpectedCompletion(ticketId, { durationDays, completionDate } = {}) {
  const payload = {};
  if (durationDays) payload.expected_duration_days = Number(durationDays);
  if (completionDate) payload.expected_completion_date = completionDate;
  const res = await api.post(`${base(ticketId)}/expected-completion`, payload);
  return res.data.data;
}

export async function setResponsibles(ticketId, userIds) {
  const res = await api.put(`${base(ticketId)}/responsibles`, { user_ids: userIds });
  return res.data.data;
}
