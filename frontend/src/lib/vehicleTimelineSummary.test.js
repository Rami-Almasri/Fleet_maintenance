import { summarizeMatches } from './vehicleTimeline';

// The recurrence read behind the Timeline's search box. The rule that matters most is EVENTS vs
// OCCASIONS: a single visit writes several log rows, so counting rows would answer a question nobody
// asked ("how many log lines mention oil?") instead of the real one ("how many times was it changed?").

const ev = (occurred_at, extra = {}) => ({ id: `${occurred_at}-${Math.random()}`, occurred_at, ...extra });

describe('summarizeMatches', () => {
  it('collapses the several rows of one visit into a single occasion', () => {
    const s = summarizeMatches([
      ev('2026-01-10T08:00:00Z', { maintenance_id: 1, odometer: 10000 }),
      ev('2026-01-10T12:00:00Z', { maintenance_id: 1 }),
      ev('2026-01-12T09:00:00Z', { maintenance_id: 1 }),
      ev('2026-04-10T08:00:00Z', { maintenance_id: 2, odometer: 15000 }),
    ]);
    expect(s.matches).toBe(4);
    expect(s.occasions).toBe(2);
    expect(s.avgKm).toBe(5000);
  });

  it('groups undated-ticket rows by calendar day instead', () => {
    const s = summarizeMatches([
      ev('2026-01-10T08:00:00Z'),
      ev('2026-01-10T18:00:00Z'),
      ev('2026-02-10T08:00:00Z'),
    ]);
    expect(s.occasions).toBe(2);
  });

  it('reads the cadence in days and projects the next one from 3+ occasions', () => {
    const s = summarizeMatches([
      ev('2026-01-01T00:00:00Z', { maintenance_id: 1 }),
      ev('2026-02-01T00:00:00Z', { maintenance_id: 2 }),
      ev('2026-03-01T00:00:00Z', { maintenance_id: 3 }),
    ]);
    expect(s.avgDays).toBe(30); // 29.5 (31 and 28), rounded for display
    // The projection runs off the UNROUNDED cadence, so it lands half a day before "+30 days".
    expect(s.nextExpectedAt.slice(0, 10)).toBe('2026-03-30');
  });

  it('does not project a next date from a cadence of one gap', () => {
    const s = summarizeMatches([
      ev('2026-01-01T00:00:00Z', { maintenance_id: 1 }),
      ev('2026-02-01T00:00:00Z', { maintenance_id: 2 }),
    ]);
    expect(s.avgDays).toBe(31);
    expect(s.nextExpectedAt).toBeNull();
  });

  it('flags a fault that came back far sooner than it used to', () => {
    const s = summarizeMatches([
      ev('2026-01-01T00:00:00Z', { maintenance_id: 1 }),
      ev('2026-04-01T00:00:00Z', { maintenance_id: 2 }),
      ev('2026-07-01T00:00:00Z', { maintenance_id: 3 }),
      ev('2026-07-12T00:00:00Z', { maintenance_id: 4 }),
    ]);
    expect(s.accelerating).toBe(true);
    expect(s.lastGap).toBe(11);
  });

  it('measures km-since against the car\'s newest reading, not the last match', () => {
    const matches = [ev('2026-01-01T00:00:00Z', { maintenance_id: 1, odometer: 20000 })];
    const all = [...matches, ev('2026-06-01T00:00:00Z', { maintenance_id: 9, odometer: 26000 })];
    expect(summarizeMatches(matches, all).sinceKm).toBe(6000);
  });

  it('reports nothing when no matching row carries a date', () => {
    expect(summarizeMatches([ev(null), ev('')])).toBeNull();
  });

  it('names the garage a recurring problem keeps coming back to', () => {
    const s = summarizeMatches([
      ev('2026-01-01T00:00:00Z', { maintenance_id: 1, garage: 'Al Reem' }),
      ev('2026-03-01T00:00:00Z', { maintenance_id: 2, garage: 'Al Reem' }),
      ev('2026-05-01T00:00:00Z', { maintenance_id: 3, garage: 'Other' }),
    ]);
    expect(s.topGarage).toEqual({ name: 'Al Reem', count: 2 });
  });

  it('stays quiet about a garage seen only once', () => {
    const s = summarizeMatches([
      ev('2026-01-01T00:00:00Z', { maintenance_id: 1, garage: 'Al Reem' }),
      ev('2026-03-01T00:00:00Z', { maintenance_id: 2, garage: 'Other' }),
    ]);
    expect(s.topGarage).toBeNull();
  });
});
