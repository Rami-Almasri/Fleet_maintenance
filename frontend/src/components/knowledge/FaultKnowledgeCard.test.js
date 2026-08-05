// One card, two audiences. The keyword admin is editing the matcher, so the matcher is what they must
// see: the score, the terms that fired, how each one matched and where that wording came from. The
// supervisor on a ticket is reading the card to learn what the fault usually IS, and none of that
// layer is actionable for them — see [[operational-language-over-engine-vocabulary]].
//
// These lock the split, in both directions: the ops view must not regain the matching furniture, and
// the admin view must not lose it.

import { render, screen } from '@testing-library/react';
import FaultKnowledgeCard from './FaultKnowledgeCard';

jest.mock('../../i18n/I18nContext', () => {
  const { LABELS } = jest.requireActual('../../i18n/labels');
  const walk = (p) => p.split('.').reduce((n, k) => (n == null ? undefined : n[k]), LABELS.en);
  return {
    useI18n: () => ({
      lang: 'en',
      t: (k) => (typeof walk(k) === 'string' ? walk(k) : k),
    }),
  };
});

const DATA = {
  keyword: 'Engine noise',
  category_label: 'Engine',
  risk_label: 'Critical',
  risk_tone: 'red',
  confidence: 'strong',
  score: 100,
  matches: [
    { term: 'engine noise', how: 'exact' },
    { term: 'engine nois', how: 'fuzzy' },
  ],
  explanation: {
    reasons: [
      { kind: 'lexical', text: 'matched on 2 terms' },
      { kind: 'provenance', text: 'wording on this fault comes from — 28 from the curated fault ontology this platform ships with' },
      { kind: 'curated', text: '4 term(s) on this fault were written or corrected by your staff' },
      { kind: 'fleet', text: 'in our own history, 10% of 94 case(s) also involved Oil leak' },
      { kind: 'graph', text: 'usually caused by: Low / degraded engine oil' },
      { kind: 'graph', text: 'usually fixed by: Change engine oil' },
      { kind: 'ungrounded', text: 'No external documentation has been cited for this fault yet — the wording is curated, not sourced from a manual. Treat the match as a suggestion.' },
    ],
  },
};

test('the ops view keeps what the fault IS and drops the matching layer', () => {
  render(<FaultKnowledgeCard data={DATA} matching={false} />);

  // What the fault is, what it costs you to ignore, and what usually fixes it — all kept.
  expect(screen.getByText('Engine noise')).toBeInTheDocument();
  expect(screen.getByText('Critical')).toBeInTheDocument();
  expect(screen.getByText('Engine')).toBeInTheDocument();
  expect(screen.getByText(/also involved Oil leak/)).toBeInTheDocument();
  expect(screen.getByText(/usually caused by/)).toBeInTheDocument();
  expect(screen.getByText(/usually fixed by/)).toBeInTheDocument();
  // The honesty caveat stays: dropping it would leave the card sounding more certain than it is.
  expect(screen.getByText(/Treat the match as a suggestion/)).toBeInTheDocument();

  // …and the matcher's own workings are gone.
  expect(screen.queryByText(/· 100/)).not.toBeInTheDocument();
  expect(screen.queryByText('engine nois')).not.toBeInTheDocument();
  // A sentence saying where the terms came from, on a card with no terms on it, answers a question
  // nobody can see being asked.
  expect(screen.queryByText(/wording on this fault comes from/)).not.toBeInTheDocument();
  expect(screen.queryByText(/written or corrected by your staff/)).not.toBeInTheDocument();
});

test('the admin view keeps the matching layer — it is the thing being edited', () => {
  render(<FaultKnowledgeCard data={DATA} />);

  expect(screen.getByText(/· 100/)).toBeInTheDocument();
  expect(screen.getByText('engine nois')).toBeInTheDocument();
  expect(screen.getByText(/wording on this fault comes from/)).toBeInTheDocument();
  expect(screen.getByText(/written or corrected by your staff/)).toBeInTheDocument();
});

test('the prose restating the term chips is dropped for both', () => {
  const { rerender } = render(<FaultKnowledgeCard data={DATA} />);
  expect(screen.queryByText('matched on 2 terms')).not.toBeInTheDocument();

  rerender(<FaultKnowledgeCard data={DATA} matching={false} />);
  expect(screen.queryByText('matched on 2 terms')).not.toBeInTheDocument();
});
