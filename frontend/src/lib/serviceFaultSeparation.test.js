import { eventKind } from './vehicleTimeline';
import {
  visitFaults, visitDamage, isServiceOnlyVisit, isNonFaultVisit, faultTagSegments,
} from './faultCategories';

/**
 * The Service/Fault separation on the read side of the UI — regression tests for the two frontend
 * defects the pre-release audit found (docs/Service-Fault-Separation-Audit.md M4, and the vehicle
 * dossier's fault donut, which had no coverage at all).
 */
describe('vehicle timeline — an event is bucketed by its own type', () => {
  // REGRESSION (audit M4): every task_* event was hard-mapped to 'fault', so a logged oil change sat
  // under the "Faults" quick-jump and inflated the Faults counter.
  it('files a service task under routine, not faults', () => {
    expect(eventKind({ event_type: 'task_identified', task_kind: 'service' })).toBe('routine');
    expect(eventKind({ event_type: 'task_resolved', task_kind: 'service' })).toBe('routine');
  });

  it('still files a fault task under faults', () => {
    expect(eventKind({ event_type: 'task_identified', task_kind: 'fault' })).toBe('fault');
    expect(eventKind({ event_type: 'task_reinspection_failed', task_kind: 'fault' })).toBe('fault');
  });

  it('files an inspection task under inspections', () => {
    expect(eventKind({ event_type: 'task_identified', task_kind: 'inspection' })).toBe('inspection');
  });

  // Backwards compatibility: a payload from before the field existed must behave exactly as it used to,
  // so a cached response or an older client never changes shape under the user.
  it('falls back to the legacy mapping when the payload carries no type', () => {
    expect(eventKind({ event_type: 'task_identified' })).toBe('fault');
    expect(eventKind({ event_type: 'service_logged' })).toBe('routine');
  });

  // A ticket-level event belongs to no single task, so its own vocabulary still decides.
  it('ignores task_kind on events that are not task-scoped', () => {
    expect(eventKind({ event_type: 'under_repair', task_kind: 'service' })).toBe('garage');
    expect(eventKind({ event_type: 'parts_ordered', task_kind: 'service' })).toBe('parts');
  });
});

// A tow shares its event_type with a driven leg — only the meta marker separates them, so the marker is
// the thing worth pinning down. Mirrors MaintenanceWorkflowService::dispatchRecovery() / requestTransfer().
describe('vehicle timeline — a towed leg is a recovery, not a dispatch', () => {
  it('reads the recovery marker on a breakdown tow', () => {
    expect(eventKind({ event_type: 'dispatched', details: { recovery: true, recovery_unit: 'Recovery Truck #05' } })).toBe('recovery');
  });

  it('reads the transport method on a recovery transfer', () => {
    expect(eventKind({ event_type: 'transport_assigned', details: { transport_method: 'recovery' } })).toBe('recovery');
  });

  it('leaves a driven leg under dispatch', () => {
    expect(eventKind({ event_type: 'dispatched', details: { garage: 'Al Faris' } })).toBe('dispatch');
    expect(eventKind({ event_type: 'transport_assigned', details: { transport_method: 'driver' } })).toBe('dispatch');
    expect(eventKind({ event_type: 'dispatched' })).toBe('dispatch');
  });

  // The marker only means "towed" on the legs that can BE towed — it must not re-bucket other events.
  it('does not turn a non-dispatch event into a recovery', () => {
    expect(eventKind({ event_type: 'under_repair', details: { recovery: true } })).toBe('garage');
  });
});

describe('vehicle dossier — the fault donut counts faults only', () => {
  it('reads the typed list when the backend supplies one', () => {
    const visit = { tags: ['Oil & Fillter Change', 'Rim Scratch'], fault_tags: ['Rim Scratch'], service_tags: ['Oil & Fillter Change'] };

    expect(visitFaults(visit)).toEqual(['Rim Scratch']);
  });

  it('falls back to the raw tags for payloads that predate the split', () => {
    const legacy = { tags: ['Rim Scratch', 'Oil & Fillter Change'] };

    expect(visitFaults(legacy)).toEqual(['Rim Scratch', 'Oil & Fillter Change']);
  });

  // A service-only visit has zero faults AND is not missing data — it must neither be counted as a
  // fault nor inflate "Unspecified".
  it('excludes a service-only visit from the chart entirely', () => {
    const serviceOnly = { fault_tags: [], service_tags: ['Oil & Fillter Change'] };
    const realFault = { fault_tags: ['Brake Pad Wear'], service_tags: [] };

    expect(isServiceOnlyVisit(serviceOnly)).toBe(true);
    expect(isServiceOnlyVisit(realFault)).toBe(false);

    const segments = faultTagSegments([serviceOnly, realFault]);

    expect(segments.map((s) => s.label)).toEqual(['Brake Pad Wear']);
    expect(segments.find((s) => s.label === 'Unspecified')).toBeUndefined();
  });

  it('still records a visit with nothing at all as Unspecified', () => {
    const segments = faultTagSegments([{ fault_tags: [], service_tags: [] }]);

    expect(segments.map((s) => s.label)).toEqual(['Unspecified']);
  });
});

describe('damage is the third kind — visible, but never a fault', () => {
  // The question that started the domain change: "Rim Scratch — this is fault??" It is not.
  it('reads damage from its own bucket, never as a fault', () => {
    const visit = {
      tags: ['Rim Scratch', 'Brake Pad Wear'],
      fault_tags: ['Brake Pad Wear'],
      service_tags: [],
      damage_tags: ['Rim Scratch'],
    };

    expect(visitFaults(visit)).toEqual(['Brake Pad Wear']);
    expect(visitDamage(visit)).toEqual(['Rim Scratch']);
  });

  // A visit that only kerbed a rim is not a fault and is not missing data either — we know exactly what
  // it was, so it must not become an "Unspecified" slice on the fault donut.
  it('keeps a damage-only visit off the fault chart without inflating Unspecified', () => {
    const damageOnly = { fault_tags: [], service_tags: [], damage_tags: ['Rim Scratch'] };
    const realFault = { fault_tags: ['Coolant leak'], service_tags: [], damage_tags: [] };

    expect(isNonFaultVisit(damageOnly)).toBe(true);
    expect(isServiceOnlyVisit(damageOnly)).toBe(false);

    const segments = faultTagSegments([damageOnly, realFault]);

    expect(segments.map((s) => s.label)).toEqual(['Coolant leak']);
    expect(segments.find((s) => s.label === 'Unspecified')).toBeUndefined();
    expect(segments.find((s) => s.label === 'Rim Scratch')).toBeUndefined();
  });

  it('files a damage task with accidents on the timeline, not with faults', () => {
    expect(eventKind({ event_type: 'task_identified', task_kind: 'damage' })).toBe('accident');
    expect(eventKind({ event_type: 'task_resolved', task_kind: 'damage' })).toBe('accident');
    // …and a genuine fault is untouched.
    expect(eventKind({ event_type: 'task_identified', task_kind: 'fault' })).toBe('fault');
  });

  it('falls back cleanly for payloads that predate the damage kind', () => {
    const legacy = { tags: ['Rim Scratch'] };

    expect(visitFaults(legacy)).toEqual(['Rim Scratch']);
    expect(visitDamage(legacy)).toEqual([]);
    expect(isNonFaultVisit(legacy)).toBe(false);
  });
});
