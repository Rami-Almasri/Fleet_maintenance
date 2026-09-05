import { faultTagSegments, visitsForFault } from './faultCategories';

// THE DOSSIER DONUT GROUPS BY SYSTEM, and the reason is a bug that shipped:
//
// The sheet writes one visit across two columns of different specificity — `service_main` holds a
// closed set of system words ("Engine", "Electrical", "Tires"), `service_sup` the actual findings
// ("Engine Oil leak", "Dashboard Warning Lights"). The donut tallied the flat union of both, so a
// category sat as a PEER OF ITS OWN CHILDREN and one visit was counted at two grains. Measured on the
// Dodge Challenger (vehicle 1741) when this was written: 19 counted fault tags collapsed to 9 real
// findings once the grain rule was applied, with "Engine ×4 / Electrical ×3 / Tires ×3" — the three
// biggest slices on the chart — turning out to be the system headers of faults already counted below
// them.
//
// The backend now ships `fault_systems` per visit, and these lock the two rules that follow from it.
describe('the fault donut groups a system above the faults inside it', () => {
  const visit = (systems, extra = {}) => ({ fault_tags: systems.flatMap((s) => s.faults), fault_systems: systems, ...extra });

  it('ranks the system, and hangs its individual faults underneath', () => {
    const segments = faultTagSegments([
      visit([{ key: 'engine', label: 'Engine', faults: ['Engine Oil leak', 'Ignition Issues'] }]),
      visit([{ key: 'engine', label: 'Engine', faults: ['Engine Oil leak'] }]),
      visit([{ key: 'brakes', label: 'Brakes', faults: ['Brake Pad Wear'] }]),
    ]);

    expect(segments.map((s) => s.label)).toEqual(['Engine', 'Brakes']);

    const engine = segments.find((s) => s.label === 'Engine');
    // Two VISITS had an engine fault — not three, though three engine faults were recorded. A visit
    // that logs two faults in one system is one engine visit.
    expect(engine.value).toBe(2);
    expect(engine.children.map((c) => c.label)).toEqual(['Engine Oil leak', 'Ignition Issues']);
    expect(engine.children.find((c) => c.label === 'Engine Oil leak').value).toBe(2);
  });

  /**
   * The bug in one assertion: the bare system word must never be counted beside the fault it
   * introduced. Before the fix this produced an "Engine" slice AND an "Engine Oil leak" slice from a
   * single visit.
   */
  it('never ranks a system as a peer of its own child', () => {
    const segments = faultTagSegments([
      visit([{ key: 'engine', label: 'Engine', faults: ['Engine Oil leak'] }]),
    ]);

    expect(segments).toHaveLength(1);
    expect(segments[0].label).toBe('Engine');
    expect(segments[0].value).toBe(1);
  });

  /**
   * A visit whose only content was the system word is real history and must still COUNT — it is just
   * thinner evidence. How that thinness is displayed is asserted separately below; this only locks
   * that the visit is not dropped for being vague.
   */
  it('keeps a visit that recorded only the system name', () => {
    const segments = faultTagSegments([
      visit([{ key: 'engine', label: 'Engine', faults: [] }], { fault_tags: ['Engine'] }),
    ]);

    expect(segments[0].value).toBe(1);
    expect(segments[0].children).toHaveLength(1);
  });

  it('still counts a visit with nothing recorded as Unspecified', () => {
    const segments = faultTagSegments([
      { fault_tags: [], service_tags: [], fault_systems: [] },
      visit([{ key: 'brakes', label: 'Brakes', faults: ['Brake Pad Wear'] }]),
    ]);

    expect(segments.find((s) => s.label === 'Unspecified').value).toBe(1);
  });

  it('leaves a service-only visit off the chart entirely', () => {
    const segments = faultTagSegments([
      { fault_tags: [], service_tags: ['Oil & Fillter Change'], fault_systems: [] },
      visit([{ key: 'brakes', label: 'Brakes', faults: ['Brake Pad Wear'] }]),
    ]);

    expect(segments.map((s) => s.label)).toEqual(['Brakes']);
  });

  /**
   * The drill-down opens a system onto the visits behind EVERY fault in it — the donut's existing
   * expand-then-drill contract. A slice nobody can open back to its records is a claim, not evidence.
   */
  it('opens a system row onto every visit behind its faults', () => {
    const a = visit([{ key: 'engine', label: 'Engine', faults: ['Engine Oil leak'] }], { id: 1 });
    const b = visit([{ key: 'engine', label: 'Engine', faults: ['Ignition Issues'] }], { id: 2 });
    const c = visit([{ key: 'brakes', label: 'Brakes', faults: ['Brake Pad Wear'] }], { id: 3 });

    const engine = faultTagSegments([a, b, c]).find((s) => s.label === 'Engine');
    const keys = engine.children.map((k) => k.key);

    expect(visitsForFault([a, b, c], keys).map((v) => v.id)).toEqual([1, 2]);
  });

  /**
   * A system whose visit recorded nothing finer must not be shown by repeating its own name. On the
   * Challenger's dossier that rendered as "Engine › Engine", which reads as a duplicate rather than as
   * missing detail — and sat directly above the genuine children, so the whole panel looked broken.
   */
  it('names an unrecorded detail rather than repeating the system', () => {
    const segments = faultTagSegments([
      visit([{ key: 'engine', label: 'Engine', faults: [] }], { fault_tags: ['Engine'] }),
    ]);

    expect(segments[0].label).toBe('Engine');
    expect(segments[0].children.map((c) => c.label)).toEqual(['Not specified']);
    // The KEY stays the recorded label, so the row still opens onto the visits behind it.
    expect(segments[0].children[0].key).toBe('Engine');
  });

  it('still drills an unspecified child back to its visits', () => {
    const v = visit([{ key: 'engine', label: 'Engine', faults: [] }], { fault_tags: ['Engine'], id: 7 });
    const child = faultTagSegments([v])[0].children[0];

    expect(visitsForFault([v], [child.key]).map((x) => x.id)).toEqual([7]);
  });

  it('falls back to the flat tally for payloads that predate fault_systems', () => {
    const segments = faultTagSegments([{ fault_tags: ['Engine Oil leak'] }]);

    expect(segments.map((s) => s.label)).toEqual(['Engine Oil leak']);
  });
});
