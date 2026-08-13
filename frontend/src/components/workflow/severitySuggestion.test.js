// The severity the risk library would give a set of findings — the suggestion shown beside the
// inspector's mandatory grade.
//
// It is a LOOKUP: every keyword in the admin-curated library already carries a grade in the same
// vocabulary as fault severity, so the suggestion is that stored grade, never a computed opinion. The
// rules worth pinning down are which grade wins when several findings are ticked (the most serious one —
// a critical brake fault does not become moderate because a routine one was ticked after it), and that
// an unknown keyword produces NO suggestion rather than a default.

import { severitySuggestion } from './TicketActionModal';

const META = {
  'brake noise':   { risk: 'critical', rank: 3, emoji: '🔴', label: 'Critical', ar: 'صوت فرامل' },
  'ac not cold':   { risk: 'moderate', rank: 2, emoji: '🟡', label: 'Moderate', ar: 'المكيف لا يبرّد' },
  'wiper worn':    { risk: 'routine',  rank: 1, emoji: '🟢', label: 'Routine',  ar: 'مساحات متهالكة' },
};

describe('severity suggestion — the library’s own grade', () => {
  it('takes the grade straight from the library for a single finding', () => {
    expect(severitySuggestion(['ac not cold'], META)).toMatchObject({ risk: 'moderate', keyword: 'ac not cold' });
  });

  it('takes the MOST SERIOUS grade when several findings are ticked', () => {
    const s = severitySuggestion(['wiper worn', 'brake noise', 'ac not cold'], META);
    expect(s.risk).toBe('critical');
    // …and names the finding it came from, so the suggestion can be checked rather than trusted.
    expect(s.keyword).toBe('brake noise');
  });

  it('suggests nothing for a keyword the library does not grade', () => {
    expect(severitySuggestion(['something the inspector typed'], META)).toBeNull();
  });

  it('suggests nothing when no findings are ticked yet', () => {
    expect(severitySuggestion([], META)).toBeNull();
  });

  it('reads finding objects as well as plain strings (the picker emits both)', () => {
    expect(severitySuggestion([{ text: 'brake noise' }], META)).toMatchObject({ risk: 'critical' });
  });
});
