// Vehicle Timeline — investigation engine.
//
// Pure, side-effect-free helpers that turn the raw Activity Audit Trail (GET /Vehicle/{id}/activity,
// unioned from vehicle_log_events + logistics_task_events + inspection_records) into an investigable
// dataset: a rich event-type taxonomy, facet extraction, combinable filtering, sorting, grouping and a
// live KPI summary. Nothing here fetches or renders — the component wires these together. No backend
// data-model change: every field read (event_type, category, workflow_status, severity, garage,
// actor_role, odometer, photo_url, description…) already ships on each event.

import Icon from '../components/ui/Icon';
import { fmtDate, fmtSeconds } from './format';

// ── Event-type taxonomy ────────────────────────────────────────────────────────────
// One investigation "kind" per event — the colour badge, the Event-type filter and the Quick-jump bar
// all speak this vocabulary. Order here is the order the filter chips render in.
export const TYPE_META = {
  workflow:       { label: 'Workflow',      tone: 'slate',   Icon: Icon.Route },
  inspection:     { label: 'Inspection',    tone: 'blue',    Icon: Icon.Shield },
  test_drive:     { label: 'Test Drive',    tone: 'cyan',    Icon: Icon.Gauge },
  fault:          { label: 'Fault',         tone: 'red',     Icon: Icon.Alert },
  routine:        { label: 'Routine',       tone: 'emerald', Icon: Icon.Spark },
  dispatch:       { label: 'Dispatch',      tone: 'violet',  Icon: Icon.Truck },
  // A tow, not a drive. Split out of `dispatch` because the two answer different questions on an
  // investigation: a recovery leg means the car could not move under its own power (and so carries no
  // mileage between pickup and arrival), which is exactly the row an investigator is scanning for.
  recovery:       { label: 'Recovery',      tone: 'orange',  Icon: Icon.Tow },
  garage:         { label: 'Garage',        tone: 'indigo',  Icon: Icon.Wrench },
  parts:          { label: 'Parts',         tone: 'amber',   Icon: Icon.Card },
  approval:       { label: 'Approval',      tone: 'green',   Icon: Icon.Check },
  recommendation: { label: 'Recommendation', tone: 'orange', Icon: Icon.Flag },
  // The system-check obligation chain: the platform asked for a check, somebody answered it, a
  // decision followed. Its OWN kind rather than folded into `inspection`, because these rows answer a
  // question no other event can — whether a car was actually looked at. Filtering to `check` gives an
  // investigator "every time we asked, and what came back", including the checks nobody ever answered.
  check:          { label: 'System Check',  tone: 'teal',    Icon: Icon.Shield },
  followup:       { label: 'Follow-up',     tone: 'cyan',    Icon: Icon.Clock },
  accident:       { label: 'Accident',      tone: 'red',     Icon: Icon.XCircle },
  // Reported ABOUT the car rather than performed on it — what the customer said, what the driver noticed.
  complaint:      { label: 'Complaint',     tone: 'red',     Icon: Icon.Users },
  observation:    { label: 'Driver Note',   tone: 'yellow',  Icon: Icon.Info },
  system:         { label: 'System',        tone: 'gray',    Icon: Icon.Activity },
};

export const TYPE_KEYS = Object.keys(TYPE_META);

export const typeMeta = (kind) => TYPE_META[kind] || TYPE_META.system;

// Raw event_type → investigation kind. Anything unlisted falls through to the category/source heuristics
// in eventKind(). Mirrors the VehicleLogEvent + LogisticsTaskEvent + InspectionRecord event vocabularies.
const EVENT_KIND = {
  // Test drive / diagnostic
  inspection_requested: 'test_drive', diagnostic_started: 'test_drive', diagnostic_cleared: 'test_drive',
  // Inspection / readiness / condition
  pre_inspection: 'inspection', post_inspection: 'inspection', condition_graded: 'inspection',
  readiness_confirmed: 'inspection', readiness_override: 'inspection',
  // Faults — including the severity-review outcomes, which are decisions ABOUT a fault's grade.
  report_filed: 'fault', task_identified: 'fault', task_transferred: 'fault', task_resolved: 'fault',
  task_reinspection_failed: 'fault', task_marked_incorrect: 'fault', part_recurrence_flagged: 'fault',
  severity_upgraded: 'fault', severity_review_kept: 'fault',
  // Routine service
  service_logged: 'routine',
  // Dispatch / movement — `task_assigned` is the per-fault twin of `garage_assigned` and only survives
  // the feed's collapse when its ticket-level twin is outside the window, so it files with its parent.
  dispatched: 'dispatch', garage_assigned: 'dispatch', task_assigned: 'dispatch', transport_assigned: 'dispatch',
  reassigned: 'dispatch', delegated: 'dispatch', status_update: 'dispatch',
  claimed: 'dispatch', picked_up: 'dispatch', delivered: 'dispatch', returned: 'dispatch', cancelled: 'dispatch',
  // Garage / invoice
  under_repair: 'garage', ready: 'garage', invoice_requested: 'garage', awaiting_invoice: 'garage',
  invoice_received: 'garage', cost_recorded: 'garage',
  garage_invoice_submitted: 'garage', garage_invoice_accepted: 'garage', garage_invoice_rejected: 'garage',
  // Parts
  parts_ordered: 'parts', parts_ready: 'parts', part_requested: 'parts', part_approved: 'parts',
  part_rejected: 'parts', part_purchased: 'parts', part_installed: 'parts', part_completed: 'parts',
  part_duplicate_flagged: 'parts', part_required: 'parts', part_delivered: 'parts',
  // Asset layer — a component fitted to / pulled off / moved between cars is a parts event on the car.
  component_installed: 'parts', component_removed: 'parts',
  component_transferred: 'parts', component_disposed: 'parts',
  // Spare keys — the NEED half. The buy half already reads as part_requested/approved/purchased
  // above, and the key arriving lands as component_installed, so the car's biography shows the
  // whole lifecycle without any of it being filed under a different heading.
  spare_key_required: 'parts', spare_key_purchase_requested: 'parts',
  spare_key_received: 'parts', spare_key_cancelled: 'parts',
  // Approvals
  review_approved: 'approval', review_rejected: 'approval', incident_acknowledged: 'approval',
  // Recommendations
  recommendation_approved: 'recommendation', recommendation_dismissed: 'recommendation',
  recommendation_scheduled: 'recommendation',
  // System check requirements — raised → inspected → decided → resolved. Read together they are the
  // whole story of one obligation, which is why they share a kind and sort next to each other.
  check_raised: 'check', check_inspected: 'check', check_decided: 'check', check_resolved: 'check',
  // Follow-up (custody / release / resume)
  returned_to_service: 'followup', resumed: 'followup', vehicle_returned: 'followup',
  temp_released: 'followup', temp_returned: 'followup', follow_up: 'followup',
  // Oil recall relay — coordinating a car back from a customer is follow-up, not workshop work.
  oil_recall_sales_confirmed: 'followup', oil_recall_instructed: 'followup',
  oil_recall_handed_to_supervisor: 'followup',
  // …but the change itself is a completed routine service, alongside service_logged.
  oil_change_recorded: 'routine',
  // Handing the car back to the customer is coordination, not workshop work.
  oil_recall_returned: 'followup',
  // Accident / incident
  handover_incident: 'accident', accident_visit: 'accident',
  // Reported by people (complaints entity + driver handover notes)
  complaint_logged: 'complaint', driver_observation: 'observation',
  // Workflow lifecycle
  closed: 'workflow', reopened: 'workflow', type_changed: 'workflow', prioritized: 'workflow',
  // Legacy sheet workshop visit (no granular event) — a completed garage visit.
  workshop_visit: 'garage',
  // System / housekeeping
  cleaning_updated: 'system', odometer_corrected: 'system',
};

// Task-scoped event types: the row is about ONE maintenance event, so its bucket must come from that
// event's stored type rather than from the event_type name. `task_identified` says a finding was
// recorded — it does not say the finding was a fault.
const TASK_SCOPED = new Set([
  'task_identified', 'task_transferred', 'task_resolved', 'task_reinspection_failed',
  'task_marked_incorrect', 'severity_upgraded', 'severity_review_kept', 'task_assigned',
]);

// A tow is not stamped as its own event_type — it rides on the SAME dispatch events as a driven leg and
// is only distinguishable by the marker the service writes into the event's meta (shipped as `details`):
//   • dispatchRecovery()  → event `dispatched`,         meta.recovery = true
//   • requestTransfer()   → event `transport_assigned`, meta.transport_method = 'recovery'
// Anything without that marker stays a normal driver dispatch. Mirrors MaintenanceWorkflowService.
const RECOVERY_CARRIERS = new Set(['dispatched', 'transport_assigned']);

function isRecoveryEvent(e) {
  if (!RECOVERY_CARRIERS.has(e.event_type)) return false;
  const d = e.details || {};
  return Boolean(d.recovery) || d.transport_method === 'recovery';
}

// The single investigation kind for an event. Damage walk-arounds are accidents whatever their source
// label; then the event's OWN maintenance type when it has one; then the explicit event_type map, then a
// category/source fallback.
export function eventKind(e) {
  if (!e) return 'system';
  if (e.flagged && e.source === 'inspection') return 'accident';

  // READ THE TYPE, DON'T GUESS IT. The backend ships `task_kind` (fault | service | damage | inspection) from
  // maintenance_tasks.kind. Every task_* event used to be hard-mapped to 'fault', so a logged oil change
  // sat under the Faults quick-jump and inflated the Faults counter (audit M4). Falls through when the
  // payload predates the field, keeping the old behaviour for cached/legacy responses.
  if (TASK_SCOPED.has(e.event_type) && e.task_kind) {
    if (e.task_kind === 'service') return 'routine';
    if (e.task_kind === 'inspection') return 'inspection';
    // Externally-caused damage files with accidents, not with faults: both are "something happened to
    // this car", which is a different investigation from "this car is failing".
    if (e.task_kind === 'damage') return 'accident';
    return 'fault';
  }

  // Ahead of the event_type map, which would file both tow legs under the generic 'dispatch'.
  if (isRecoveryEvent(e)) return 'recovery';

  const mapped = EVENT_KIND[e.event_type];
  if (mapped) return mapped;
  if (e.source === 'logistics' || e.category === 'movement') return 'dispatch';
  if (e.category === 'inspection') return 'inspection';
  if (e.category === 'readiness' || e.category === 'condition') return 'inspection';
  if (e.category === 'cleaning') return 'system';
  return 'workflow';
}

// The Quick-jump bar — one click narrows the Event-type filter to the listed kinds. Order = bar order.
export const QUICK_JUMPS = [
  { key: 'faults',          label: 'Faults',        Icon: Icon.Alert,  types: ['fault'] },
  { key: 'inspections',     label: 'Inspections',   Icon: Icon.Shield, types: ['inspection'] },
  { key: 'test_drives',     label: 'Test Drives',   Icon: Icon.Gauge,  types: ['test_drive'] },
  { key: 'garage',          label: 'Garage Visits', Icon: Icon.Wrench, types: ['garage'] },
  { key: 'recommendations', label: 'Recommendations', Icon: Icon.Flag, types: ['recommendation'] },
  { key: 'routine',         label: 'Routine',       Icon: Icon.Spark,  types: ['routine'] },
  { key: 'accidents',       label: 'Accidents',     Icon: Icon.XCircle, types: ['accident'] },
  { key: 'parts',           label: 'Parts',         Icon: Icon.Card,   types: ['parts'] },
  { key: 'approvals',       label: 'Approvals',     Icon: Icon.Check,  types: ['approval'] },
];

// ── Severity ────────────────────────────────────────────────────────────────────────
// Severity strings vary by source (fault grades vs inspection damage grades); the facet is built from
// whatever the data carries, so this is only the tone/rank lookup, with a sane default for the unknown.
const SEVERITY_META = {
  critical: { label: 'Critical', tone: 'red',    rank: 5 },
  high:     { label: 'High',     tone: 'red',    rank: 4 },
  major:    { label: 'Major',    tone: 'orange', rank: 4 },
  moderate: { label: 'Moderate', tone: 'amber',  rank: 3 },
  medium:   { label: 'Medium',   tone: 'amber',  rank: 3 },
  minor:    { label: 'Minor',    tone: 'yellow', rank: 2 },
  routine:  { label: 'Routine',  tone: 'emerald', rank: 1 },
  low:      { label: 'Low',      tone: 'emerald', rank: 1 },
};

export const severityMeta = (sev) => SEVERITY_META[String(sev || '').toLowerCase()]
  || { label: sev ? String(sev) : 'Unspecified', tone: 'slate', rank: 0 };

const severityRank = (sev) => severityMeta(sev).rank;

// ── Workflow stage label + ordering ──────────────────────────────────────────────────
const titleCase = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

// The stage an event sits at — the real workflow_status when present (the ticket state machine), else
// the plain-language milestone the feed tags movements/inspections with (Check-out, Garage Arrival…).
export function stageLabel(e) {
  if (e?.workflow_status) return titleCase(e.workflow_status);
  return e?.stage || null;
}

// ── Legacy timeline normaliser ─────────────────────────────────────────────────────────
// The Vehicle Profile payload already ships `data.timeline` — the ORIGINAL "Vehicle Timeline": the
// N-Maintenance sheet workshop history + the workflow audit trail + driver follow-ups, unified. The
// activity feed (/Vehicle/{id}/activity) covers only the workflow-era log/logistics/inspection sources,
// so ~2/3 of cars (sheet-only history) look empty on it. We reconcile by taking the rich workflow rows
// from the activity feed and folding in the two row kinds the feed CANNOT have — the sheet `workshop`
// visits and the `follow_up` notes — normalised to the same event shape. No double-count: kind
// 'workflow' legacy rows ARE the activity feed's vehicle_log_events, so they're skipped here.

// A sheet workshop row has no granular event vocabulary — synthesise one from its maintenance type so it
// still lands in the right bucket (accident / routine / general garage visit).
function workshopEventType(row) {
  const t = String(row.type || '').toLowerCase();
  if (row.damage || t.includes('accident') || t.includes('incident')) return 'accident_visit';
  if (t.includes('routine') || t.includes('periodic') || t.includes('service') || t.includes('oil')) return 'service_logged';
  return 'workshop_visit';
}

const joinTruthy = (arr, sep = ' · ') => arr.filter((x) => x != null && String(x).trim() !== '').join(sep);

// Map ONE legacy timeline row → the canonical event shape the engine consumes. Returns null for the
// kind 'workflow' rows (owned by the activity feed) so they don't double up.
function normalizeLegacyRow(row) {
  if (!row) return null;
  const at = row.ts || (row.date ? `${row.date}T00:00:00` : null);

  // Follow-up notes (kind 'workflow', event_type 'follow_up') — the feed has no equivalent, so keep them.
  if (row.event_type === 'follow_up') {
    return {
      id: `lg-${row.id}`,
      source: 'followup',
      event_type: 'follow_up',
      maintenance_id: row.ticket_id ?? null,
      category: 'maintenance',
      action: 'Follow-up note',
      description: row.description || null,
      occurred_at: at,
      actor_name: row.actor || null,
      actor_role: 'garage',
      garage: null, odometer: null, severity: null, workflow_status: null, stage: null,
      flagged: false, photo_url: null, transition: null,
      contract_id: null, contract_no: null,
      details: {},
    };
  }

  // The activity feed owns every other kind 'workflow' row (they ARE the vehicle_log_events).
  if (row.kind === 'workflow') return null;

  // A sheet workshop visit.
  if (row.kind === 'workshop') {
    const eventType = workshopEventType(row);
    const issues = Array.isArray(row.issues) ? row.issues.map((i) => (typeof i === 'object' ? i.label || i.text || '' : i)) : [];
    return {
      id: `lg-ws-${row.id}`,
      source: 'workshop',
      event_type: eventType,
      maintenance_id: null,
      category: 'maintenance',
      action: row.event || 'Workshop visit',
      description: joinTruthy([row.main, row.sup, issues.length ? issues.join(', ') : null]) || null,
      occurred_at: at,
      actor_name: row.driver || null,
      actor_role: row.driver ? 'driver' : null,
      garage: row.garage || null,
      odometer: null,
      severity: row.severity || null,
      workflow_status: null,
      stage: null,
      flagged: Boolean(row.damage),
      photo_url: null,
      transition: null,
      // Left null on purpose: the row itself is a button, so it must not nest a contract <Link>.
      // The contract is reachable from the workshop-event drawer this row opens.
      contract_id: null, contract_no: null,
      details: joinTruthy([row.type, row.damage]) ? { type: row.type, damage: row.damage, notes: row.notes } : { notes: row.notes },
      // Keep the untouched sheet row: the timeline opens it in the workshop-event detail drawer
      // (garage, cost, issues, full notes) — the one thing the retired Maintenance Log tab could do.
      raw: row,
    };
  }

  return null;
}

// Normalise a whole legacy `data.timeline` array, keeping only the rows the activity feed can't supply.
export function normalizeLegacyTimeline(rows) {
  if (!Array.isArray(rows)) return [];
  const out = [];
  for (const r of rows) {
    const e = normalizeLegacyRow(r);
    if (e) out.push(e);
  }
  return out;
}

// Rough pipeline order for the "Workflow stage" sort — earliest lifecycle stage first. Unknown → end.
const STAGE_ORDER = [
  'pending_review', 'complaint_triage', 'inspection_requested', 'inspection_diagnostic',
  'triage_approval_pending', 'recommendation_pending', 'inspection_pending', 'on_site_pending',
  'awaiting_dispatch', 'in_transit', 'under_repair', 'repair_review',
  'reinspection_failed', 'ready_reinspection', 'ready_for_pickup', 'in_our_park', 'awaiting_invoice',
  'paused_returned_to_service', 'closed',
];
const stageOrderOf = (e) => {
  const i = STAGE_ORDER.indexOf(e?.workflow_status);
  return i < 0 ? STAGE_ORDER.length : i;
};

// ── Search haystack ───────────────────────────────────────────────────────────────────
// One lowercased blob per event, spanning every field an investigator would search: fault/action text,
// garage, vendor, inspector/driver (actor), workflow stage, notes, recommendation text, routine service
// names, parts, mileage and the event type. Memoised on the event object so re-filtering is cheap.
const HAYSTACK = new WeakMap();
function haystack(e) {
  if (HAYSTACK.has(e)) return HAYSTACK.get(e);
  const parts = [
    e.action, e.description, e.actor_name, e.garage, e.plate, e.model,
    e.stage, e.from_stage, e.to_stage, e.transition, e.workflow_status && titleCase(e.workflow_status),
    e.severity, e.event_type, typeMeta(eventKind(e)).label, e.contract_no,
    e.odometer != null ? String(e.odometer) : null,
  ];
  const d = e.details || {};
  for (const v of Object.values(d)) {
    if (v == null) continue;
    if (typeof v === 'object') { parts.push(JSON.stringify(v)); } else { parts.push(String(v)); }
  }
  const blob = parts.filter(Boolean).join(' • ').toLowerCase();
  HAYSTACK.set(e, blob);
  return blob;
}

// ── Facets (dropdown options) ──────────────────────────────────────────────────────────
// Distinct, counted option lists for the facet dropdowns — built from the data so they only ever offer
// values that actually occur. Each entry: { value, label, count }.
export function buildFacets(events) {
  const garages = new Map();
  const inspectors = new Map();
  const drivers = new Map();
  const stages = new Map();
  const severities = new Map();
  const bump = (map, key, label) => {
    if (!key) return;
    const cur = map.get(key);
    if (cur) cur.count += 1; else map.set(key, { value: key, label: label ?? key, count: 1 });
  };
  for (const e of events) {
    bump(garages, e.garage);
    if (e.actor_role === 'inspector' && e.actor_name && e.actor_name !== 'System') bump(inspectors, e.actor_name);
    if (e.actor_role === 'driver' && e.actor_name && e.actor_name !== 'System') bump(drivers, e.actor_name);
    const sl = stageLabel(e);
    if (sl) bump(stages, sl);
    if (e.severity) bump(severities, String(e.severity).toLowerCase(), severityMeta(e.severity).label);
  }
  const byCount = (a, b) => b.count - a.count || String(a.label).localeCompare(String(b.label));
  return {
    garages: [...garages.values()].sort(byCount),
    inspectors: [...inspectors.values()].sort(byCount),
    drivers: [...drivers.values()].sort(byCount),
    stages: [...stages.values()].sort(byCount),
    severities: [...severities.values()].sort((a, b) => severityRank(b.value) - severityRank(a.value)),
  };
}

// Per-event counts by kind — powers the Event-type filter chip counts and the Quick-jump badges.
export function countByKind(events) {
  const out = {};
  for (const e of events) { const k = eventKind(e); out[k] = (out[k] || 0) + 1; }
  return out;
}

// ── Ticket open/closed derivation ───────────────────────────────────────────────────────
// The feed carries no ticket status, but a ticket that has a `closed` event in the trail IS closed; any
// other ticketed event belongs to an open ticket. Non-ticket events (movements, inspections) are neither.
function closedTicketIds(events) {
  const closed = new Set();
  for (const e of events) if (e.event_type === 'closed' && e.maintenance_id) closed.add(e.maintenance_id);
  return closed;
}

// ── Filtering ─────────────────────────────────────────────────────────────────────────
// filters = {
//   q, types:Set<kind>, severities:Set<sev>, garage, inspector, driver, stage,
//   status:'all'|'open'|'closed', from:ISOdate, to:ISOdate, flags:Set<'photos'|'attachments'|'notes'|'recommendations'>
// } — every clause is ANDed, so filters combine.
export function applyFilters(events, filters) {
  const f = filters || {};
  const q = (f.q || '').trim().toLowerCase();
  const types = f.types instanceof Set ? f.types : null;
  const severities = f.severities instanceof Set ? f.severities : null;
  const flags = f.flags instanceof Set ? f.flags : null;
  const fromT = f.from ? Date.parse(f.from) : null;
  // `to` is inclusive of the whole day.
  const toT = f.to ? Date.parse(f.to) + 86400000 - 1 : null;
  const closed = (f.status === 'open' || f.status === 'closed') ? closedTicketIds(events) : null;

  return events.filter((e) => {
    if (q && !haystack(e).includes(q)) return false;
    if (types && types.size && !types.has(eventKind(e))) return false;
    if (severities && severities.size) {
      if (!e.severity || !severities.has(String(e.severity).toLowerCase())) return false;
    }
    if (f.garage && e.garage !== f.garage) return false;
    if (f.inspector && !(e.actor_role === 'inspector' && e.actor_name === f.inspector)) return false;
    if (f.driver && !(e.actor_role === 'driver' && e.actor_name === f.driver)) return false;
    if (f.stage && stageLabel(e) !== f.stage) return false;

    if (closed) {
      if (!e.maintenance_id) return false;
      const isClosed = closed.has(e.maintenance_id);
      if (f.status === 'open' && isClosed) return false;
      if (f.status === 'closed' && !isClosed) return false;
    }

    const t = e.occurred_at ? Date.parse(e.occurred_at) : NaN;
    if (fromT && !(t >= fromT)) return false;
    if (toT && !(t <= toT)) return false;

    if (flags && flags.size) {
      if (flags.has('photos') && !e.photo_url) return false;
      if (flags.has('attachments') && !e.photo_url) return false;
      if (flags.has('notes') && !hasNote(e)) return false;
      if (flags.has('recommendations') && eventKind(e) !== 'recommendation') return false;
    }
    return true;
  });
}

const hasNote = (e) => Boolean((e.description && e.description.trim()) || (e.details && (e.details.note || e.details.notes)));

// ── Sorting ────────────────────────────────────────────────────────────────────────────
export const SORTS = {
  newest:   { label: 'Newest first' },
  oldest:   { label: 'Oldest first' },
  severity: { label: 'Severity' },
  stage:    { label: 'Workflow stage' },
  mileage:  { label: 'Mileage' },
};

const timeOf = (e) => (e.occurred_at ? Date.parse(e.occurred_at) : 0);

export function sortEvents(events, sort) {
  const arr = [...events];
  const newest = (a, b) => timeOf(b) - timeOf(a);
  switch (sort) {
    case 'oldest': return arr.sort((a, b) => timeOf(a) - timeOf(b));
    case 'severity': return arr.sort((a, b) => severityRank(b.severity) - severityRank(a.severity) || newest(a, b));
    case 'stage': return arr.sort((a, b) => stageOrderOf(a) - stageOrderOf(b) || newest(a, b));
    case 'mileage': return arr.sort((a, b) => (b.odometer ?? -1) - (a.odometer ?? -1) || newest(a, b));
    case 'newest':
    default: return arr.sort(newest);
  }
}

// ── Grouping ───────────────────────────────────────────────────────────────────────────
export const GROUPS = {
  none:   { label: 'No grouping' },
  month:  { label: 'Month' },
  year:   { label: 'Year' },
  visit:  { label: 'Maintenance visit' },
  garage: { label: 'Garage' },
  ticket: { label: 'Workflow ticket' },
};

// Split an already-sorted event list into ordered { key, label, sublabel, count, events } sections.
// Returns null for group='none' so the caller renders the flat list. Group order follows the incoming
// sort (each group anchors at its first appearance), except Month/Year which stay chronological desc.
export function groupEvents(events, group) {
  if (!group || group === 'none' || !GROUPS[group]) return null;

  const keyOf = (e) => {
    switch (group) {
      case 'month': return e.occurred_at ? e.occurred_at.slice(0, 7) : 'unknown';
      case 'year':  return e.occurred_at ? e.occurred_at.slice(0, 4) : 'unknown';
      case 'garage': return e.garage || '__none';
      case 'visit':
      case 'ticket': return e.maintenance_id != null ? String(e.maintenance_id) : '__none';
      default: return '__none';
    }
  };
  const labelOf = (e, key) => {
    switch (group) {
      case 'month': return key === 'unknown' ? 'Unknown date' : fmtDate(`${key}-01`).replace(/^\d+\s/, '');
      case 'year':  return key === 'unknown' ? 'Unknown date' : key;
      case 'garage': return key === '__none' ? 'No garage' : key;
      case 'visit':  return key === '__none' ? 'No visit' : `Visit #${key}`;
      case 'ticket': return key === '__none' ? 'No ticket' : `Ticket #${key}`;
      default: return key;
    }
  };

  const map = new Map();
  for (const e of events) {
    const key = keyOf(e);
    let g = map.get(key);
    if (!g) { g = { key, label: labelOf(e, key), events: [] }; map.set(key, g); }
    g.events.push(e);
  }
  let groups = [...map.values()];
  if (group === 'month' || group === 'year') {
    groups.sort((a, b) => (a.key < b.key ? 1 : a.key > b.key ? -1 : 0)); // newest period first
  }
  for (const g of groups) {
    g.count = g.events.length;
    if (group === 'garage' || group === 'visit' || group === 'ticket') {
      const times = g.events.map(timeOf).filter(Boolean);
      if (times.length) g.sublabel = `${fmtDate(new Date(Math.min(...times)).toISOString())} – ${fmtDate(new Date(Math.max(...times)).toISOString())}`;
    }
  }
  return groups;
}

// ── KPI summary ──────────────────────────────────────────────────────────────────────────
const PART_ORDER_EVENTS = new Set(['part_requested', 'part_purchased', 'parts_ordered']);
const REPAIR_START_EVENTS = new Set(['under_repair']);
const REPAIR_END_EVENTS = new Set(['ready']);
const TRANSFER_EVENTS = new Set(['dispatched', 'task_transferred']);
const TICKET_START_EVENTS = new Set(['report_filed', 'inspection_requested', 'garage_assigned', 'dispatched', 'under_repair']);
const TICKET_END_EVENTS = new Set(['ready', 'closed', 'task_resolved']);

// Every figure derived from the CURRENTLY-FILTERED events, so the strip re-computes as filters change.
export function computeKpis(events) {
  const kinds = countByKind(events);
  const countType = (t) => events.reduce((n, e) => n + (e.event_type === t ? 1 : 0), 0);

  // Average repair duration — per ticket, earliest `under_repair` → latest `ready`.
  const repairStart = new Map();
  const repairEnd = new Map();
  // Total maintenance days — per ticket, earliest lifecycle-start → latest lifecycle-end.
  const ticketStart = new Map();
  const ticketEnd = new Map();
  for (const e of events) {
    const mid = e.maintenance_id;
    if (!mid) continue;
    const t = timeOf(e);
    if (!t) continue;
    if (REPAIR_START_EVENTS.has(e.event_type)) repairStart.set(mid, Math.min(repairStart.get(mid) ?? Infinity, t));
    if (REPAIR_END_EVENTS.has(e.event_type)) repairEnd.set(mid, Math.max(repairEnd.get(mid) ?? -Infinity, t));
    if (TICKET_START_EVENTS.has(e.event_type)) ticketStart.set(mid, Math.min(ticketStart.get(mid) ?? Infinity, t));
    if (TICKET_END_EVENTS.has(e.event_type)) ticketEnd.set(mid, Math.max(ticketEnd.get(mid) ?? -Infinity, t));
  }
  const repairSpans = [];
  for (const [mid, start] of repairStart) {
    const end = repairEnd.get(mid);
    if (end != null && end >= start) repairSpans.push((end - start) / 1000);
  }
  const avgRepairSeconds = repairSpans.length
    ? repairSpans.reduce((a, b) => a + b, 0) / repairSpans.length : null;

  let maintMs = 0;
  for (const [mid, start] of ticketStart) {
    const end = ticketEnd.get(mid);
    if (end != null && end >= start) maintMs += end - start;
  }
  const totalMaintDays = maintMs ? Math.round(maintMs / 86400000) : 0;

  return [
    { key: 'total',      label: 'Total events',        value: events.length },
    { key: 'fault',      label: 'Faults',              value: kinds.fault || 0,          type: 'fault' },
    { key: 'inspection', label: 'Inspections',         value: kinds.inspection || 0,     type: 'inspection' },
    { key: 'test_drive', label: 'Test drives',         value: kinds.test_drive || 0,     type: 'test_drive' },
    { key: 'transfers',  label: 'Garage transfers',    value: events.reduce((n, e) => n + (TRANSFER_EVENTS.has(e.event_type) ? 1 : 0), 0) },
    { key: 'repairs',    label: 'Repairs',             value: countType('ready') },
    { key: 'recommendation', label: 'Recommendations', value: kinds.recommendation || 0, type: 'recommendation' },
    { key: 'parts',      label: 'Parts ordered',       value: events.reduce((n, e) => n + (PART_ORDER_EVENTS.has(e.event_type) ? 1 : 0), 0), type: 'parts' },
    { key: 'avg_repair', label: 'Avg repair duration', value: avgRepairSeconds != null ? fmtSeconds(avgRepairSeconds) : '—', text: true },
    { key: 'downtime',   label: 'Total maintenance days', value: totalMaintDays },
  ];
}

// ── Pattern summary ("what does this search actually tell me?") ──────────────────────────
// The trail is long by design; nobody reads 300 rows to learn that the oil was changed seven times.
// Given the events a search/filter has narrowed to, this answers the questions an investigator is
// really asking: how often, how far apart in days AND kilometres, when last, is it accelerating, and
// where does it keep happening. Pure derivation over fields already on each event.
//
// EVENTS vs OCCASIONS. One oil change writes several rows (report, repair, invoice, ready), so raw
// match counts overstate "how many times". Rows are collapsed into OCCASIONS — one per ticket, or per
// calendar day when a row carries no ticket — and every recurrence figure is counted on occasions.
// `matches` is still reported so the two numbers never look like a contradiction.
const DAY_MS = 86400000;
const dayKey = (t) => new Date(t).toISOString().slice(0, 10);

export function summarizeMatches(events, allEvents = events) {
  const dated = events.filter((e) => timeOf(e));
  if (!dated.length) return null;

  // Collapse to occasions. Odometer/garage are lifted from whichever row in the occasion carries them.
  const byOccasion = new Map();
  for (const e of dated) {
    const t = timeOf(e);
    const key = e.maintenance_id ? `t${e.maintenance_id}` : `d${dayKey(t)}`;
    const o = byOccasion.get(key) || { key, start: t, end: t, odometer: null, garage: null, events: 0 };
    o.start = Math.min(o.start, t);
    o.end = Math.max(o.end, t);
    if (o.odometer == null && e.odometer != null) o.odometer = Number(e.odometer);
    if (!o.garage && e.garage) o.garage = e.garage;
    o.events += 1;
    byOccasion.set(key, o);
  }
  const occasions = [...byOccasion.values()].sort((a, b) => a.start - b.start);

  // Gaps between consecutive occasions — in days, and in kilometres where both ends carry a reading.
  const dayGaps = [];
  const kmGaps = [];
  for (let i = 1; i < occasions.length; i++) {
    const gap = (occasions[i].start - occasions[i - 1].end) / DAY_MS;
    if (gap >= 0) dayGaps.push(gap);
    const a = occasions[i - 1].odometer;
    const b = occasions[i].odometer;
    if (a != null && b != null && b > a) kmGaps.push(b - a);
  }
  const mean = (arr) => (arr.length ? arr.reduce((x, y) => x + y, 0) / arr.length : null);
  const avgDays = mean(dayGaps);
  const avgKm = mean(kmGaps);

  const first = occasions[0];
  const last = occasions[occasions.length - 1];

  // "Since last" is measured against the car's own latest known reading — the newest odometer anywhere
  // in the trail, not just among the matches — so the km figure is the car's, not the search's.
  let latestOdo = null;
  let latestOdoTime = 0;
  for (const e of allEvents) {
    const t = timeOf(e);
    if (e.odometer == null || !t || t < latestOdoTime) continue;
    latestOdoTime = t; latestOdo = Number(e.odometer);
  }
  const sinceDays = Math.max(0, Math.round((Date.now() - last.end) / DAY_MS));
  const sinceKm = last.odometer != null && latestOdo != null && latestOdo > last.odometer
    ? latestOdo - last.odometer : null;

  // Where it keeps happening — only worth stating when one garage genuinely dominates.
  const garages = new Map();
  for (const o of occasions) if (o.garage) garages.set(o.garage, (garages.get(o.garage) || 0) + 1);
  const topGarage = [...garages.entries()].sort((a, b) => b[1] - a[1])[0] || null;

  // Per-year counts, oldest → newest, for the mini bar row.
  const years = new Map();
  for (const o of occasions) {
    const y = new Date(o.start).getFullYear();
    years.set(y, (years.get(y) || 0) + 1);
  }
  const perYear = [...years.entries()].sort((a, b) => a[0] - b[0]).map(([year, count]) => ({ year, count }));

  // Is it getting worse? Compare the newest gap with the average of the ones before it. A return that
  // came back in well under half the usual interval is the signal worth surfacing.
  const lastGap = dayGaps.length ? dayGaps[dayGaps.length - 1] : null;
  const priorAvg = dayGaps.length > 1 ? mean(dayGaps.slice(0, -1)) : null;
  const accelerating = lastGap != null && priorAvg != null && priorAvg > 0 && lastGap < priorAvg * 0.6;
  const shortestGap = dayGaps.length ? Math.min(...dayGaps) : null;

  return {
    matches: events.length,
    undated: events.length - dated.length,
    occasions: occasions.length,
    firstAt: new Date(first.start).toISOString(),
    lastAt: new Date(last.end).toISOString(),
    firstOdometer: first.odometer,
    lastOdometer: last.odometer,
    spanDays: Math.round((last.end - first.start) / DAY_MS),
    avgDays: avgDays != null ? Math.round(avgDays) : null,
    avgKm: avgKm != null ? Math.round(avgKm) : null,
    shortestGap: shortestGap != null ? Math.round(shortestGap) : null,
    sinceDays,
    sinceKm,
    // Projected only from a real cadence (3+ occasions), and only forward of the last one.
    nextExpectedAt: avgDays != null && occasions.length >= 3
      ? new Date(last.end + avgDays * DAY_MS).toISOString() : null,
    dueInDays: avgDays != null && occasions.length >= 3
      ? Math.round((last.end + avgDays * DAY_MS - Date.now()) / DAY_MS) : null,
    topGarage: topGarage && topGarage[1] > 1 ? { name: topGarage[0], count: topGarage[1] } : null,
    garageCount: garages.size,
    perYear,
    accelerating,
    lastGap: lastGap != null ? Math.round(lastGap) : null,
  };
}
