import { eventKind, QUICK_JUMPS, TYPE_META } from './vehicleTimeline';

// The Vehicle Timeline's "Accidents" quick-jump has existed since long before accident CASES did, and
// eventKind() falls through to 'workflow' for anything it does not recognise. That combination is
// silent: a car with three crashes on file renders them all under the generic Workflow chip and the
// Accidents filter comes back empty, with nothing anywhere saying why.
//
// This is the regression guard. Every event type AccidentCaseService can write must classify as
// 'accident'. If somebody adds a seventeenth accident event and forgets the map, this goes red.

const ACCIDENT_EVENT_TYPES = [
  'accident_reported',
  'accident_context_captured',
  'accident_details_updated',
  'accident_damage_recorded',
  'accident_assessed',
  'police_report_recorded',
  'police_report_verified',
  'police_report_bypassed',
  'accident_liability_set',
  'accident_claim_updated',
  'accident_financial_recorded',
  'accident_repair_linked',
  'accident_document_added',
  'accident_stage_changed',
  'accident_closed',
  'accident_reopened',
  // The customer charge and its withdrawal — the two events that reach outside this system and
  // land on a real person's account.
  'accident_customer_charged',
  'accident_charge_reversed',
];

describe('accident events on the vehicle timeline', () => {
  it.each(ACCIDENT_EVENT_TYPES)('classifies %s as an accident, not workflow', (event_type) => {
    expect(eventKind({ event_type, category: 'accident', source: 'log' })).toBe('accident');
  });

  it('has an Accidents quick-jump pointing at that kind', () => {
    const jump = QUICK_JUMPS.find((q) => q.key === 'accidents');
    expect(jump).toBeTruthy();
    expect(jump.types).toContain('accident');
    expect(TYPE_META.accident).toBeTruthy();
  });

  // The other half of the same rule: the repair an accident causes is ORDINARY maintenance and must
  // keep classifying as such. Folding the repair into the accident bucket would make "what did the
  // workshop actually do?" unanswerable, which is the thing the parent/child split exists to protect.
  it('leaves the repair the accident caused classified as workshop work', () => {
    expect(eventKind({ event_type: 'under_repair', category: 'movement', source: 'log' })).not.toBe('accident');
    expect(eventKind({ event_type: 'garage_assigned', category: 'maintenance', source: 'log' })).not.toBe('accident');
  });

  // A damage finding recorded on a ticket already filed under accidents before this feature existed.
  // Kept green so the new mapping does not disturb the old one.
  it('still files externally-caused damage findings with accidents', () => {
    expect(eventKind({ event_type: 'task_identified', task_kind: 'damage' })).toBe('accident');
  });
});
