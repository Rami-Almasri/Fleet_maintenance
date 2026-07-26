// Maintenance Checkpoint — the progress-tracking client. Responsible users (Waleed/Abdullah, or a
// ticket's assigned owners) file dated workshop updates with evidence; the dashboard + vehicle profile
// read the resulting timeline and live monitoring state. Backend: MaintenanceCheckpointController.
//
// CONTRACT with App\Models\MaintenanceCheckpoint (OUTCOMES / STATUSES / DELAY_REASONS) — keep the option
// vocabularies below in lock-step with the model constants.

import api from '../api/client';

const base = (ticketId) => `/maintenance-tickets/${ticketId}`;

// The management verdict that drives dashboard colour + reporting.
export const OUTCOMES = [
  { value: 'on_track', label: 'On Track', tone: 'emerald' },
  { value: 'delayed', label: 'Delayed', tone: 'amber' },
  { value: 'critical', label: 'Critical', tone: 'red' },
];

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

// Structured delay reasons (required when outcome = Delayed).
export const DELAY_REASONS = [
  { value: 'waiting_parts', label: 'Waiting Parts' },
  { value: 'workshop_busy', label: 'Workshop Busy' },
  { value: 'additional_damage', label: 'Additional Damage Found' },
  { value: 'customer_approval', label: 'Customer Approval Pending' },
  { value: 'insurance_approval', label: 'Insurance Approval' },
  { value: 'vendor_delay', label: 'Vendor Delay' },
  { value: 'other', label: 'Other' },
];

// Single mutually-exclusive dashboard progress status → visual theme + label.
export const PROGRESS_STATUS = {
  overdue:      { label: 'Overdue',           tone: 'red',     dot: 'bg-red-500',     chip: 'bg-red-50 text-red-700 ring-red-200' },
  critical:     { label: 'Critical',          tone: 'red',     dot: 'bg-rose-500',    chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  needs_update: { label: 'Checkpoint due',    tone: 'amber',   dot: 'bg-amber-500',   chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
  delayed:      { label: 'Delayed',           tone: 'amber',   dot: 'bg-orange-500',  chip: 'bg-orange-50 text-orange-700 ring-orange-200' },
  on_track:     { label: 'On Track',          tone: 'emerald', dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
};

export const outcomeLabel = (v) => OUTCOMES.find((o) => o.value === v)?.label || v || '—';
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

// ── Writes ───────────────────────────────────────────────────────────────────

/**
 * File a checkpoint (multipart, one save). `files` is an array of File objects (photos/videos).
 * Returns { checkpoint, monitor }.
 */
export async function submitCheckpoint(ticketId, { outcome, status, delayReason, delayReasonOther, summary, nextExpectedDate, files = [] }) {
  const fd = new FormData();
  fd.append('outcome', outcome);
  if (status) fd.append('status', status);
  if (delayReason) fd.append('delay_reason', delayReason);
  if (delayReasonOther) fd.append('delay_reason_other', delayReasonOther);
  if (summary) fd.append('summary', summary);
  if (nextExpectedDate) fd.append('next_expected_date', nextExpectedDate);
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
