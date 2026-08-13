// The WHERE axis, client side — the vocabulary helpers, the policy, and the preview sentence.
//
// These mirror the backend (App\Support\FaultPhrase + App\Services\FaultLocationService), which is
// the authority on every write. They are tested separately because the inspector reads the sentence
// BEFORE submitting: if the preview and the stored record disagree, the preview is a lie, and the
// whole point of showing it is that it is not.

import {
  buildDetails,
  findingsMissingLocation,
  joinList,
  locationIndex,
  locationLabel,
  locationsComplete,
  policyFor,
  previewFor,
  renderFaultPhrase,
  requiresLocation,
  searchGroups,
  takesLocation,
  withDetails,
} from './faultLocations';

const GROUPS = [
  {
    key: 'exterior',
    label: 'Exterior',
    label_ar: 'الهيكل الخارجي',
    locations: [
      { key: 'front_bumper', label: 'Front Bumper', label_ar: 'الصدام الأمامي', aliases: ['bumper'] },
      { key: 'hood', label: 'Hood / Bonnet', label_ar: 'غطاء المحرك', aliases: ['bonnet'] },
      { key: 'body', label: 'Body (general)', label_ar: 'الهيكل', aliases: ['paint'] },
    ],
  },
  {
    key: 'wheels',
    label: 'Wheels & Tyres',
    label_ar: 'العجلات',
    locations: [
      { key: 'rims', label: 'Rims', label_ar: 'الجنوط', aliases: ['rim', 'جنط'] },
      { key: 'wheel_front_left', label: 'Front-Left Wheel', label_ar: 'العجلة الأمامية اليسرى', aliases: [] },
    ],
  },
];

// Mirrors what GET /maintenance-tickets/findings-catalog ships as `location_policy`.
const POLICY = {
  Scratch: 'required',
  Dent: 'required',
  'Worn / bald tyre': 'required',
  Overheating: 'none',
  'Wiper / washer fault': 'none',
  'Oil leak': 'optional',
};

// ── The sentence ─────────────────────────────────────────────────────────────

describe('renderFaultPhrase', () => {
  it('renders the shape the feature exists for', () => {
    expect(renderFaultPhrase('Scratch', 1, ['front bumper'])).toBe('Scratch — front bumper');
    expect(renderFaultPhrase('Scratch', 2, ['rims', 'body'])).toBe('2 scratches — rims and body');
    expect(renderFaultPhrase('Scratch', 3, ['front bumper', 'hood', 'left rear door']))
      .toBe('3 scratches — front bumper, hood and left rear door');
  });

  // The whole design: none of the above is about scratches.
  it('renders every other fault type by the same rule', () => {
    expect(renderFaultPhrase('Dent', 1, ['right front door'])).toBe('Dent — right front door');
    expect(renderFaultPhrase('Dent', 2, ['rear bumper', 'left rear door'])).toBe('2 dents — rear bumper and left rear door');
    expect(renderFaultPhrase('Crack', 1, ['windshield'])).toBe('Crack — windshield');
    expect(renderFaultPhrase('Tyre damage', 2, ['front left', 'rear right'])).toBe('2 tyre damages — front left and rear right');
    expect(renderFaultPhrase('Leak', 2, ['engine bay', 'underbody'])).toBe('2 leaks — engine bay and underbody');
  });

  it('leaves a fault with no count and no place exactly as it always read', () => {
    expect(renderFaultPhrase('Scratch')).toBe('Scratch');
    expect(renderFaultPhrase('Overheating', 1, [])).toBe('Overheating');
  });

  it('floors the quantity at one and drops blank places', () => {
    expect(renderFaultPhrase('Scratch', 0)).toBe('Scratch');
    expect(renderFaultPhrase('Scratch', 2, ['rims', '', '  '])).toBe('2 scratches — rims');
  });

  // Correct, or unmistakable — never a confidently wrong plural.
  it('falls back to the times form for labels it cannot inflect', () => {
    expect(renderFaultPhrase('Rough idle / misfire', 2)).toBe('2 × Rough idle / misfire');
    expect(renderFaultPhrase('Wiper / washer fault', 2)).toBe('2 × Wiper / washer fault');
    expect(renderFaultPhrase('Rust', 2)).toBe('2 × Rust');
  });

  it('renders Arabic as count plus singular, joined with و', () => {
    expect(renderFaultPhrase('خدش', 2, ['الجنوط', 'الهيكل'], 'ar')).toBe('2 خدش — الجنوط والهيكل');
    expect(renderFaultPhrase('خدش', 3, ['أ', 'ب', 'ج'], 'ar')).toBe('3 خدش — أ، ب وج');
  });

  it('never emits a leading dash when the type is missing', () => {
    expect(renderFaultPhrase('', 2, ['rims', 'body'])).toBe('rims and body');
    expect(renderFaultPhrase('', 1, [])).toBe('');
  });
});

describe('joinList', () => {
  it('reads as prose in both languages', () => {
    expect(joinList([])).toBe('');
    expect(joinList(['a'])).toBe('a');
    expect(joinList(['a', 'b'])).toBe('a and b');
    expect(joinList(['a', 'b', 'c'])).toBe('a, b and c');
    expect(joinList(['a', 'b', 'c'], 'ar')).toBe('a، b وc');
  });
});

// ── Vocabulary ───────────────────────────────────────────────────────────────

describe('the location vocabulary', () => {
  const index = locationIndex(GROUPS);

  it('flattens the grouped catalog by slug', () => {
    expect(Object.keys(index)).toEqual(
      expect.arrayContaining(['front_bumper', 'hood', 'body', 'rims', 'wheel_front_left']),
    );
    expect(index.rims.group).toBe('wheels');
  });

  it('labels a place in the active language and never renders blank', () => {
    expect(locationLabel(index, 'rims')).toBe('Rims');
    expect(locationLabel(index, 'rims', 'ar')).toBe('الجنوط');
    // An unknown slug shows the slug rather than an empty chip — visible, not silent.
    expect(locationLabel(index, 'not_a_place')).toBe('not_a_place');
  });

  it('searches name, Arabic name, slug and alias — the same four columns as the backend', () => {
    expect(searchGroups(GROUPS, 'rim')[0].locations.map((l) => l.key)).toEqual(['rims']);
    expect(searchGroups(GROUPS, 'جنط')[0].locations.map((l) => l.key)).toEqual(['rims']);
    expect(searchGroups(GROUPS, 'bonnet')[0].locations.map((l) => l.key)).toEqual(['hood']);
    // A group NAME match keeps everything in that group.
    expect(searchGroups(GROUPS, 'wheels')[0].locations).toHaveLength(2);
    expect(searchGroups(GROUPS, 'zzz')).toHaveLength(0);
    expect(searchGroups(GROUPS, '')).toBe(GROUPS);
  });

  it('previews one editor row', () => {
    expect(previewFor('Scratch', { quantity: 2, locations: ['rims', 'body'] }, index))
      .toBe('2 scratches — Rims and Body (general)');
    expect(previewFor('Scratch', undefined, index)).toBe('Scratch');
  });
});

// ── Policy ───────────────────────────────────────────────────────────────────

describe('the per-type location policy', () => {
  it('answers the three modes', () => {
    expect(policyFor(POLICY, 'Scratch')).toBe('required');
    expect(policyFor(POLICY, 'Oil leak')).toBe('optional');
    expect(policyFor(POLICY, 'Overheating')).toBe('none');
  });

  // A custom issue an inspector typed. Unknown must offer the picker, never demand an answer.
  it('offers the picker for a type it has never heard of', () => {
    expect(policyFor(POLICY, 'something nobody catalogued')).toBe('optional');
    expect(policyFor({}, 'anything')).toBe('optional');
    expect(takesLocation(POLICY, 'brand new fault type')).toBe(true);
    expect(requiresLocation(POLICY, 'brand new fault type')).toBe(false);
  });

  it('skips a fault type with nowhere to point at', () => {
    expect(takesLocation(POLICY, 'Overheating')).toBe(false);
    expect(takesLocation(POLICY, 'Wiper / washer fault')).toBe(false);
  });
});

describe('the client half of the intake gate', () => {
  it('names every fault still owing a place, not just the first', () => {
    const missing = findingsMissingLocation(
      ['Scratch', 'Dent', 'Worn / bald tyre', 'Overheating'],
      POLICY,
      { Dent: { quantity: 1, locations: ['hood'] } },
    );
    expect(missing).toEqual(['Scratch', 'Worn / bald tyre']);
  });

  it('is satisfied once every required fault has a place', () => {
    expect(locationsComplete(['Scratch'], POLICY, {})).toBe(false);
    expect(locationsComplete(['Scratch'], POLICY, { Scratch: { locations: ['rims'] } })).toBe(true);
    // Optional and none never block.
    expect(locationsComplete(['Oil leak', 'Overheating'], POLICY, {})).toBe(true);
  });

  it('does not retroactively invalidate a report with no detail at all', () => {
    expect(locationsComplete(['Overheating', 'Oil leak'], POLICY, {})).toBe(true);
  });
});

// ── The payload ──────────────────────────────────────────────────────────────

describe('buildDetails', () => {
  it('sends only what a person actually said', () => {
    expect(buildDetails(['Scratch', 'Oil leak'], {
      Scratch: { quantity: 2, locations: ['rims', 'body'] },
      // Left at the default with no place — nothing to say, so nothing is sent.
      'Oil leak': { quantity: 1, locations: [] },
    }, POLICY)).toEqual([
      { symptom: 'Scratch', quantity: 2, locations: ['rims', 'body'] },
    ]);
  });

  it('sends a count on its own', () => {
    expect(buildDetails(['Oil leak'], { 'Oil leak': { quantity: 3, locations: [] } }, POLICY))
      .toEqual([{ symptom: 'Oil leak', quantity: 3, locations: [] }]);
  });

  // A stale selection must not leak a place onto a fault that has none.
  it('never sends a location for a type whose policy is none', () => {
    expect(buildDetails(['Overheating'], { Overheating: { quantity: 2, locations: ['hood'] } }, POLICY))
      .toEqual([]);
  });

  it('sends nothing when nothing was detailed — a legacy-shaped report', () => {
    expect(buildDetails(['Scratch', 'Dent'], {}, POLICY)).toEqual([]);
  });
});

describe('withDetails (garage findings)', () => {
  it('merges the two fields onto each finding object', () => {
    expect(withDetails(
      [{ text: 'Scratch', severity: 'routine' }],
      { Scratch: { quantity: 2, locations: ['rims'] } },
      POLICY,
    )).toEqual([{ text: 'Scratch', severity: 'routine', quantity: 2, locations: ['rims'] }]);
  });

  it('leaves a finding with nothing to add exactly as it was', () => {
    const findings = [{ text: 'Overheating', severity: 'critical' }, { text: 'Dent' }];
    expect(withDetails(findings, {}, POLICY)).toEqual(findings);
  });
});
