// Single source of truth for the Maintenance Cycle board lanes — the operator-facing stage names,
// tones and hints. Shared by the board page (MaintenanceWorkflow) and any surface that visualises the
// same pipeline (e.g. the Dashboard analytics panel), so a lane rename/re-tint updates everywhere at once.
//
// The first eight are the canonical pipeline (always shown, left→right = the ticket journey). The rest
// are EXCEPTION lanes — off the linear path.
//
// LOCALIZATION — like `nav` and `modules`, the English lives HERE (the lane name and hint document
// what the stage means alongside its key, tone and flag) and only the Arabic lives in labels.js, under
// `ar.lanes.<key>.name` / `.hint`. Never read `.name`/`.hint` off these constants for display; call
// `useLanes()` below, which resolves both through tf() so a lane renders in the active language.
import { useMemo } from 'react';
import { useI18n } from '../i18n/I18nContext';
import { SHOW_VIDEO_REVIEW } from './features';

// `role` is WHOSE desk the stage sits on — the one thing about a lane that isn't visual. It drives
// the "Who's holding the work" split, so it lives here beside the key rather than in each surface.
export const PRIMARY_LANES = [
  { key: 'requested',        name: 'Needs Test Drive',   role: 'inspector',  tone: '#d946ef', hint: 'Vehicles need a test drive to confirm the issue' },
  { key: 'diagnostic',       name: 'Being Inspected',    role: 'inspector',  tone: '#8b5cf6', hint: 'Currently under inspection or diagnostic' },
  { key: 'pending',          name: 'Needs Dispatch',     role: 'supervisor', tone: '#a855f7', hint: 'Ready to be dispatched to a garage' },
  { key: 'awaiting_pickup',  name: 'Awaiting Pickup',    role: 'driver',     tone: '#f59e0b', hint: 'Garage + driver assigned — awaiting pickup' },
  { key: 'in_transit',       name: 'En Route to Garage', role: 'driver',     tone: '#f59e0b', hint: 'On the way to the garage' },
  { key: 'under_repair',     name: 'In Workshop',        role: 'garage',     tone: '#f97316', hint: 'Being worked on at the garage' },
  { key: 'ready_for_pickup', name: 'Ready for Pickup',   role: 'driver',     tone: '#10b981', hint: 'Work complete — awaiting collection' },
  { key: 'qa_reinspection',  name: 'Final QA',           role: 'inspector',  tone: '#9333ea', hint: 'Back at our park, awaiting re-inspection sign-off' },
];

export const EXCEPTION_LANES = [
  // ACCIDENTS. An exception lane because it genuinely is one: no garage has been chosen, no work has
  // been authorised, and what the car is waiting for is the police, an insurer or a liability verdict
  // — none of which anybody on this board can hurry. It is here so the workshop can SEE that a car is
  // out of the running, which is the one thing the board used to be silent about. The card's actions
  // belong to the accident case; this lane only shows where the case has got to.
  { key: 'accident',                name: 'Accident Case',         role: 'none',       tone: '#e11d48', hint: 'A crash is being worked — no repair has been authorised yet' },
  { key: 'triage',                  name: 'Complaint Triage',      role: 'inspector',  tone: '#f43f5e', hint: 'A driver complaint waiting to be judged into a ticket' },
  ...(SHOW_VIDEO_REVIEW ? [{ key: 'repair_review', name: 'Video Review', role: 'supervisor', tone: '#7c3aed', hint: 'Awaiting supervisor video sign-off' }] : []),
  { key: 'reinspection_failed',     name: 'Sent Back — QA Failed', role: 'supervisor', tone: '#dc2626', hint: 'Came back still broken — supervisor re-dispatches' },
  { key: 'paused',                  name: 'Paused',                role: 'none',       tone: '#64748b', hint: 'Repair on hold — car released to service' },
  { key: 'returned_waiting_resume', name: 'Returned — Resume Due', role: 'none',       tone: '#f97316', hint: 'Physically back — return handover pending' },
  { key: 'on_site',                 name: 'On-Site Service',       role: 'inspector',  tone: '#0d9488', hint: 'Minor job done where the car is parked' },
  // An EXCEPTION lane on purpose, and the one lane where nobody is expected to act today. Mixing these
  // into "Needs Dispatch" would make the dispatch queue read as a backlog of ignored work — which is the
  // confusion that used to make people untick findings rather than record them and wait.
  { key: 'deferred',                name: 'Deferred — Later',      role: 'supervisor', tone: '#f59e0b', hint: 'Fault recorded, repair scheduled for later — the car stays in service' },
];

// Every lane, in render order.
export const ALL_LANE_DEFS = [...PRIMARY_LANES, ...EXCEPTION_LANES];

// The canonical linear journey (the 8 primary lanes, left→right). A ticket's position in this list is
// how far the vehicle has travelled — the card's pipeline spine renders that as a progress bar.
export const PIPELINE_KEYS = PRIMARY_LANES.map((l) => l.key);

// The lane definitions with `name` + `hint` resolved into the active language. This is what every
// display surface must use; the raw constants above are for keys, tones and ordering only.
export function useLanes() {
  const { tf } = useI18n();
  return useMemo(() => {
    const loc = (l) => ({
      ...l,
      name: tf(`lanes.${l.key}.name`, l.name),
      hint: tf(`lanes.${l.key}.hint`, l.hint),
    });
    const primary = PRIMARY_LANES.map(loc);
    const exception = EXCEPTION_LANES.map(loc);
    return { primary, exception, all: [...primary, ...exception] };
  }, [tf]);
}

// Attach each lane's tickets from a board `columns` object ({ [laneKey]: ticket[] }), applying an
// optional ticket filter. Returns [{ ...laneDef, tickets }].
export function buildLanes(defs, columns = {}, filter) {
  const keep = filter || (() => true);
  return defs.map((l) => ({ ...l, tickets: (columns[l.key] || []).filter(keep) }));
}
