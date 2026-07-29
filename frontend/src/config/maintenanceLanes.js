// Single source of truth for the Maintenance Cycle board lanes — the operator-facing stage names,
// tones and hints. Shared by the board page (MaintenanceWorkflow) and any surface that visualises the
// same pipeline (e.g. the Dashboard analytics panel), so a lane rename/re-tint updates everywhere at once.
//
// The first eight are the canonical pipeline (always shown, left→right = the ticket journey). The rest
// are EXCEPTION lanes — off the linear path.
import { SHOW_VIDEO_REVIEW } from './features';

export const PRIMARY_LANES = [
  { key: 'requested',        name: 'Needs Test Drive',   tone: '#d946ef', hint: 'Vehicles need a test drive to confirm the issue' },
  { key: 'diagnostic',       name: 'Being Inspected',    tone: '#8b5cf6', hint: 'Currently under inspection or diagnostic' },
  { key: 'pending',          name: 'Needs Dispatch',     tone: '#a855f7', hint: 'Ready to be dispatched to a garage' },
  { key: 'awaiting_pickup',  name: 'Awaiting Pickup',    tone: '#f59e0b', hint: 'Garage + driver assigned — awaiting pickup' },
  { key: 'in_transit',       name: 'En Route to Garage', tone: '#f59e0b', hint: 'On the way to the garage' },
  { key: 'under_repair',     name: 'In Workshop',        tone: '#f97316', hint: 'Being worked on at the garage' },
  { key: 'ready_for_pickup', name: 'Ready for Pickup',   tone: '#10b981', hint: 'Work complete — awaiting collection' },
  { key: 'qa_reinspection',  name: 'Final QA',           tone: '#9333ea', hint: 'Back at our park, awaiting re-inspection sign-off' },
];

export const EXCEPTION_LANES = [
  ...(SHOW_VIDEO_REVIEW ? [{ key: 'repair_review', name: 'Video Review', tone: '#7c3aed', hint: 'Awaiting supervisor video sign-off' }] : []),
  { key: 'reinspection_failed',     name: 'Sent Back — QA Failed', tone: '#dc2626', hint: 'Came back still broken — supervisor re-dispatches' },
  { key: 'paused',                  name: 'Paused',                tone: '#64748b', hint: 'Repair on hold — car released to service' },
  { key: 'returned_waiting_resume', name: 'Returned — Resume Due', tone: '#f97316', hint: 'Physically back — return handover pending' },
  { key: 'on_site',                 name: 'On-Site Service',       tone: '#0d9488', hint: 'Minor job done where the car is parked' },
];

// Every lane, in render order.
export const ALL_LANE_DEFS = [...PRIMARY_LANES, ...EXCEPTION_LANES];

// The canonical linear journey (the 8 primary lanes, left→right). A ticket's position in this list is
// how far the vehicle has travelled — the card's pipeline spine renders that as a progress bar.
export const PIPELINE_KEYS = PRIMARY_LANES.map((l) => l.key);

// Attach each lane's tickets from a board `columns` object ({ [laneKey]: ticket[] }), applying an
// optional ticket filter. Returns [{ ...laneDef, tickets }].
export function buildLanes(defs, columns = {}, filter) {
  const keep = filter || (() => true);
  return defs.map((l) => ({ ...l, tickets: (columns[l.key] || []).filter(keep) }));
}
