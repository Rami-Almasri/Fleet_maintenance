// The findings picker — specifically, the knowledge attached to what the inspector has picked.
//
// THE GAP THESE CLOSE. The causes-and-repairs block used to appear only when the literal search failed
// and the ontology had to FIND the fault. So an inspector who typed "فيه رجة" was told the misfire is
// usually worn spark plugs, and one who knew the name and tapped the chip was told nothing. Same fault,
// same knowledge, different route — and the person who knew the fault best got the least help.

import { render, screen, fireEvent } from '@testing-library/react';
import FindingsPicker from './FindingsPicker';

jest.mock('../../api/client', () => ({ post: jest.fn() }));
jest.mock('../../i18n/I18nContext', () => {
  const { LABELS } = jest.requireActual('../../i18n/labels');
  const walk = (p) => p.split('.').reduce((n, k) => (n == null ? undefined : n[k]), LABELS.en);
  const fill = (s, v) => (v ? s.replace(/\{(\w+)\}/g, (m, key) => (v[key] != null ? String(v[key]) : m)) : s);
  return {
    useI18n: () => ({
      lang: 'en',
      t: (k, v) => {
        const hit = walk(k);
        if (typeof hit !== 'string') return k;
        return fill(hit, v);
      },
      // Same contract as the real resolver: fall back to the caller's English
      // rather than to the raw key. Without this the component throws, because
      // the stub only had `t`.
      tf: (k, fallback, v) => {
        const hit = walk(k);
        return fill(typeof hit === 'string' ? hit : fallback, v);
      },
    }),
  };
});

const CATALOG = [
  { key: 'engine', label: 'Engine', on_site: false, keywords: ['Rough idle / misfire', 'Overheating'] },
  { key: 'brakes', label: 'Brakes', on_site: false, keywords: ['Soft / spongy pedal'] },
];

const META = {
  'Rough idle / misfire': {
    tone: 'amber', ar: 'تقطيع',
    causes: ['Worn spark plugs / coils', 'Clogged / faulty fuel injector', 'Vacuum leak'],
    fixes: [
      { label: 'Replace spark plugs', label_ar: null, typical: true },
      { label: 'Replace ignition coil', label_ar: null, typical: true },
      { label: 'Clean throttle body', label_ar: null, typical: false },
    ],
  },
  'Overheating': {
    tone: 'red', ar: 'ارتفاع حرارة',
    causes: ['Coolant leak'],
    fixes: [{ label: 'Replace thermostat', label_ar: null, typical: true }],
  },
  // Curated risk, but nothing described behind it.
  'Soft / spongy pedal': { tone: 'red', ar: 'دواسة لينة', causes: [], fixes: [] },
};

const draw = (props = {}) => render(
  <FindingsPicker catalog={CATALOG} keywordMeta={META} value={[]} onChange={() => {}} {...props} />,
);

test('a picked fault carries what usually causes it and what usually fixes it', () => {
  draw({ value: ['Rough idle / misfire'] });

  expect(screen.getByText(/Worn spark plugs \/ coils · Clogged \/ faulty fuel injector · Vacuum leak/)).toBeInTheDocument();
  expect(screen.getByText(/Replace spark plugs · Replace ignition coil · Clean throttle body/)).toBeInTheDocument();
});

test('the knowledge appears without the matcher being involved at all', () => {
  const api = require('../../api/client');
  draw({ value: ['Rough idle / misfire'] });

  // Tapping a chip must not cost a round trip — the catalog already carried this. An inspector taps
  // four or five faults in a row on a phone; four or five lookups would land after they moved on.
  expect(api.post).not.toHaveBeenCalled();
});

test('every picked fault is described, not just the first', () => {
  draw({ value: ['Rough idle / misfire', 'Overheating'] });

  expect(screen.getByText(/Vacuum leak/)).toBeInTheDocument();
  expect(screen.getByText(/Coolant leak/)).toBeInTheDocument();
});

test('a fault with nothing curated behind it is skipped rather than shown empty', () => {
  draw({ value: ['Soft / spongy pedal'] });

  // An empty "Usually caused by:" reads as missing data. Saying nothing is the honest rendering of
  // "we have no curated causes for this one".
  expect(screen.queryByText(/Usually caused by/)).not.toBeInTheDocument();
});

test('a hand-typed custom finding is never given invented causes', () => {
  draw({ value: ['weird clunk i heard'] });

  expect(screen.queryByText(/Usually caused by/)).not.toBeInTheDocument();
  expect(screen.queryByText(/Usually fixed by/)).not.toBeInTheDocument();
});

test('removing a fault removes its knowledge with it', () => {
  const onChange = jest.fn();
  draw({ value: ['Rough idle / misfire'], onChange });

  fireEvent.click(screen.getByLabelText('Remove Rough idle / misfire'));

  expect(onChange).toHaveBeenCalledWith([]);
});
