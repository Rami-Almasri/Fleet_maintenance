// Repair capture — the client for Tier 1 structured capture (what was done, did it work, how do we
// know). Backend: MaintenanceWorkflowController@captureOptions / @captureRepair, RepairCaptureService.
//
// CONTRACT with App\Services\RepairCaptureService — the OUTCOMES and VERIFICATION_METHODS constants
// below must stay in lock-step with the PHP ones. The server also returns its own vocabularies from
// captureOptions(); these local lists exist only to give each value a human label and an order, and
// the server list is what decides which are actually offered.

import api from '../api/client';

const base = (taskId) => `/maintenance-tasks/${taskId}/capture`;

// Step 3. Phrased as a question about the CAR, never about the data model — a technician is being
// asked whether the customer's problem is gone, not to grade a repair.
export const OUTCOMES = [
  { value: 'complete',       label: 'Fixed completely',   hint: 'The problem is gone',                tone: 'emerald' },
  { value: 'partial',        label: 'Partly better',      hint: 'Improved, but not fully resolved',   tone: 'amber' },
  { value: 'temporary',      label: 'Temporary fix',      hint: 'Will need proper repair later',      tone: 'orange' },
  { value: 'no_improvement', label: 'No improvement',     hint: 'Still the same as before',           tone: 'red' },
  // Always available, never penalised. Removing it would leave "Fixed completely" as the only way
  // past this screen, and the dataset would quietly fill with false successes.
  { value: 'unknown',        label: "Can't tell yet",     hint: 'Intermittent, or not confirmed',     tone: 'slate' },
];

// The inspector's verdict. Deliberately NOT part of the capture form — the person who performed a
// repair must never be the only one confirming it, so this lives behind a separate action, a
// separate permission (inspections.manage), and a server-side rule that blocks the claimant from
// verifying their own work.
export const VERIFICATION_RESULTS = [
  { value: 'verified',     label: 'Repair confirmed',  hint: 'The fault is gone',              tone: 'emerald' },
  { value: 'partial',      label: 'Partly resolved',   hint: 'Better, but not fully fixed',    tone: 'amber' },
  { value: 'still_faulty', label: 'Still faulty',      hint: 'The fault is still there',       tone: 'red' },
  { value: 'inconclusive', label: 'Could not confirm', hint: 'Intermittent, or not reproducible', tone: 'slate' },
];

// How the inspector checked.
export const VERIFICATION_METHODS = [
  { value: 'road_test',        label: 'Road test' },
  { value: 'visual',           label: 'Visual inspection' },
  { value: 'scan_tool',        label: 'Diagnostic scan' },
  { value: 'measurement',      label: 'Measurement' },
  { value: 'pressure_test',    label: 'Pressure test' },
  { value: 'customer_confirm', label: 'Customer confirmed' },
  { value: 'other',            label: 'Other' },
];

export const outcomeMeta = (value) => OUTCOMES.find((o) => o.value === value) || null;
export const verificationLabel = (value) =>
  VERIFICATION_METHODS.find((v) => v.value === value)?.label || value || null;

/** What this fault's capture form should offer: suggested actions, vocabularies, and prior capture. */
export async function getCaptureOptions(taskId) {
  const { data } = await api.get(base(taskId));
  return data?.data ?? data;
}

/**
 * Submit the capture.
 *
 * `duration_ms` and `skipped_fields` are rollout telemetry. They ride along with the real payload
 * rather than going to a separate endpoint, so instrumentation can never fail independently of the
 * work it is measuring — and so a technician never pays a second round-trip for our analytics.
 */
export async function submitCapture(taskId, payload) {
  const { data } = await api.post(base(taskId), payload);
  return data?.data ?? data;
}

/**
 * Opens a friction session when the form opens.
 *
 * The start is what makes abandonment measurable at all: without it the table only ever held people
 * who finished, and an abandonment rate computed from survivors is not a measurement. Failures are
 * swallowed — telemetry must never stop someone from recording a repair.
 */
export async function startCapture(taskId) {
  try {
    const { data } = await api.post(`${base(taskId)}/start`);
    return (data?.data ?? data)?.session_id ?? null;
  } catch {
    return null;
  }
}

/** Closes a session that was never completed. Best-effort, for the same reason. */
export async function abandonCapture(taskId, sessionId, payload = {}) {
  if (!sessionId) return;
  try {
    await api.post(`${base(taskId)}/abandon`, { session_id: sessionId, ...payload });
  } catch {
    /* a lost abandon beacon costs one telemetry row, never the user's work */
  }
}

/** What the inspector's verification form should offer, plus whether this user may verify at all. */
export async function getVerificationOptions(taskId) {
  const { data } = await api.get(`/maintenance-tasks/${taskId}/verification`);
  return data?.data ?? data;
}

export async function submitVerification(taskId, payload) {
  const { data } = await api.post(`/maintenance-tasks/${taskId}/verification`, payload);
  return data?.data ?? data;
}

/**
 * Tracks how much effort a capture actually cost.
 *
 * Deliberately measures what the user DID, not what we hoped they would: `skipped` records optional
 * fields that were shown and left empty, which is the one signal that tells us whether a field earns
 * its place. A field skipped by everybody should be removed, and this turns that from an argument
 * into a measurement.
 */
export function createFrictionTracker(fieldsOffered) {
  const startedAt = Date.now();
  const skipped = new Set();

  return {
    skip(field) { skipped.add(field); },
    unskip(field) { skipped.delete(field); },
    payload() {
      return {
        duration_ms: Date.now() - startedAt,
        skipped_fields: Array.from(skipped),
        fields_offered: fieldsOffered,
      };
    },
  };
}
