// The Intelligence Center's editorial contract, not just its markup.
//
// This page exists so a supervisor shown an intelligence card can find out what the platform knows
// and how sure it is. That only works if the page refuses to flatter itself, so these assertions
// encode the rules that keep it honest: a blocked capability is never dressed up as merely
// under-evidenced, a job producing fresh output while absent from the scheduler is still a failure,
// refusals appear beside promotions, and a proxy is always labelled a proxy.

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import IntelligenceCenter from './IntelligenceCenter';
import { I18nProvider } from '../i18n/I18nContext';
import api from '../api/client';

jest.mock('../api/client', () => ({ get: jest.fn(), post: jest.fn() }));

const SNAPSHOT = {
  generated_at: '2026-08-01T09:00:00+00:00',
  cached: false,
  headline: { severity: 'info', text: 'Comeback Warning (shipped) — Needs 21 more post-repair QC verdicts.' },
  alert: null,
  qc: {
    closed: 17, with_verdict: 8, conclusive: 8, unverifiable: 0,
    coverage: 0.47, lost: 9, lost_recently: 0, leaking: false, unverifiable_share: 0,
    throughput: [
      { week: '2026-06-08', count: 0, conclusive: 0 },
      { week: '2026-07-27', count: 6, conclusive: 6 },
    ],
  },
  capabilities: [
    {
      id: 'eta-prediction', label: 'ETA Prediction (not built)', evidence: 'in/out timestamps',
      shipped: false, score: 35, band: 'blocked',
      dimensions: { coverage: 0.4, volume: 0.9, freshness: 0.2, evaluation: 0.3, trust: 0.85 },
      weakest: 'freshness', attention: 'Blocked by data, not by build: 4,082 of 6,880 durations are negative',
      status: 'blocked', current: 2798, threshold: 3000, remaining: 202, weekly_rate: 0,
      ready_at: null, ready_label: 'BLOCKED', coverage: 0.4, quality: 'measured',
      blocker: '4,082 of 6,880 durations are negative (out_date before in_date)',
      median_age_days: 900, oldest_at: '2022-01-02', newest_at: '2026-07-30',
      dataset_age_days: 2, last_evaluated_at: null, reevaluation_reason: null,
      evidence_stale: true, feed_quiet: false,
    },
    {
      id: 'comeback-warning', label: 'Comeback Warning (shipped)', evidence: 'post-repair QC verdicts',
      shipped: true, score: 67.2, band: 'watch',
      dimensions: { coverage: 0.47, volume: 0.3, freshness: 0.99, evaluation: 0.9, trust: 0.4 },
      weakest: 'volume', attention: 'Needs 21 more post-repair QC verdicts.',
      status: 'growing', current: 9, threshold: 30, remaining: 21, weekly_rate: 3,
      ready_at: '2026-09-01', ready_label: '2026-09-01', coverage: 0.47, quality: 'proxy',
      blocker: null, median_age_days: 12, oldest_at: '2026-06-01', newest_at: '2026-07-31',
      dataset_age_days: 2, last_evaluated_at: '2026-07-31', reevaluation_reason: null,
      evidence_stale: false, feed_quiet: false,
    },
  ],
  promotions: [{
    id: 4, capability_id: 'comeback-warning', decided_at: '2026-07-31T04:00:00+00:00',
    promoted: false, reason: 'Below the evidence threshold: 9 of 30 post-repair QC verdicts.',
    evidence_count: 9, evidence_threshold: 30, proxy_metrics: null, measured_metrics: null,
    provenance: { dataset: 'proj/v1:49501:2026-07-30', backtest: 'v1', capability: 'v2' },
    superseded: false,
  }],
  flags: [
    { key: 'MAINT_REQUIRE_QC_VERDICT', label: 'Require a QC verdict', enabled: true, expected: true, impact: 'OFF means repaired cars close unverified.' },
    { key: 'FEATURE_INTEL_COMEBACK', label: 'Show the Comeback card', enabled: true, expected: false, impact: 'Held off until measured evidence is in.' },
  ],
  jobs: [
    {
      command: 'intelligence:rebuild-signatures', purpose: 'Rebuilds the historical corpus.',
      evidence: 'the most recent signature row', status: 'unscheduled', registered: false,
      scheduled: null, next_due_at: null, last_effect_at: '2026-07-31T00:00:00+00:00', age_days: 1, basis: 'proxy',
    },
  ],
  data_quality: [{
    key: 'verdicts-lost', severity: 'high', title: 'Repaired tickets that closed with no verdict',
    count: 9, detail: 'QC coverage is 47%.', remedy: 'Keep MAINT_REQUIRE_QC_VERDICT on.',
  }],
  versions: { query_layer: 'v1', dataset: 'proj/v1:49501:2026-07-30', capabilities: {}, evaluation: { backtest_version: 'v1' } },
};

beforeEach(() => {
  jest.clearAllMocks();
  api.get.mockResolvedValue({ data: SNAPSHOT });
});

// The page resolves its text through useI18n, so it needs the provider the app
// wraps it in. Language defaults to English, so the assertions below still read
// the same English sentences.
const load = async () => {
  render(<I18nProvider><IntelligenceCenter /></I18nProvider>);
  await waitFor(() => expect(screen.getByText('Intelligence Center')).toBeInTheDocument());
};

/**
 * The banner text and the capability's own attention line are deliberately the same sentence — the
 * headline IS `attentionFirst()`, not a second opinion about it. Asserting both are present is the
 * point: a summary that can disagree with the table it summarises is worse than no summary.
 */
test('the headline names what to act on first, in the capability’s own words', async () => {
  await load();
  expect(screen.getAllByText(/Needs 21 more post-repair QC verdicts/)).toHaveLength(2);
});

/**
 * THE LOAD-BEARING ASSERTION. A blocked capability sorts to the bottom of a score table and reads as
 * "nearly there" unless the page says why. Waiting will not fix it — the fleet's record-keeping has
 * to change — and that is a different kind of work from "needs 21 more verdicts".
 */
test('a blocked capability says it is blocked by data, not merely behind', async () => {
  await load();
  fireEvent.click(screen.getByText('ETA Prediction (not built)'));

  // Twice over: once in the collapsed attention line, once in the expanded explanation.
  expect(screen.getAllByText(/Blocked by data, not by build/).length).toBeGreaterThan(0);
  expect(screen.getByText(/Waiting will not fix this/)).toBeInTheDocument();
});

/** Proxy evidence is the platform's biggest standing caveat; it must never render unlabelled. */
test('a capability reasoning from a proxy is labelled a proxy', async () => {
  await load();
  expect(screen.getByText('proxy')).toBeInTheDocument();
  expect(screen.getByText('measured')).toBeInTheDocument();
});

/** The score is never the whole story — every dimension is rendered beside the total. */
test('all five health dimensions are shown next to the score', async () => {
  await load();
  for (const dimension of ['coverage', 'volume', 'freshness', 'evaluation', 'trust']) {
    expect(screen.getAllByText(dimension).length).toBeGreaterThan(0);
  }
});

/**
 * Fresh output plus no schedule is the failure that hides: it looks healthy right up until whoever
 * was running it by hand stops. This is exactly what the page found on its first real run.
 */
test('an unscheduled job is called out even though its output is fresh', async () => {
  await load();
  expect(screen.getByText('unscheduled')).toBeInTheDocument();
  expect(screen.getByText(/NOT in the scheduler — someone is running this by hand/)).toBeInTheDocument();
});

/** A flag silently off its expected value is the change nobody announced. */
test('a feature flag that has drifted from its expected value is flagged', async () => {
  await load();
  expect(screen.getByText(/Expected OFF/)).toBeInTheDocument();
});

/** Refusals are the informative rows — the platform declining evidence that predicted worse. */
test('refused promotions appear in the history with their provenance', async () => {
  await load();
  expect(screen.getByText('refused')).toBeInTheDocument();

  fireEvent.click(screen.getByText('provenance'));
  expect(screen.getByText('proj/v1:49501:2026-07-30')).toBeInTheDocument();
});

/**
 * A DEBT AND A LEAK NEED OPPOSITE RESPONSES, and lifetime coverage cannot tell them apart.
 *
 * Every ticket that closed before the verdict gate existed drags the lifetime figure down for good.
 * Showing that in alarm colours forever means showing the same number whether the leak was stopped
 * yesterday or is still running — and after a month of a number that never moves, nobody reads it.
 */
test('historical debt is reported as debt, not as an ongoing leak', async () => {
  await load();
  expect(screen.getByText('Evidence lost (historical)')).toBeInTheDocument();
  expect(screen.getByText(/the gap has stopped growing/)).toBeInTheDocument();
});

test('a gap that is still growing is reported as a live leak instead', async () => {
  api.get.mockResolvedValue({
    data: { ...SNAPSHOT, qc: { ...SNAPSHOT.qc, leaking: true, lost_recently: 4 } },
  });
  await load();

  expect(screen.getByText('Still leaking')).toBeInTheDocument();
  expect(screen.getByText(/closed unverified in 14 days/)).toBeInTheDocument();
});

/** The denominator is what a verdict was possible for — not every ticket that closed. */
test('coverage is measured against verifiable closes, not all closes', async () => {
  await load();
  expect(screen.getByText('Closed & verifiable')).toBeInTheDocument();
  expect(screen.getByText('reached a garage, had faults to fix')).toBeInTheDocument();
});

/**
 * A section that crashed must say so. Rendering it empty is worse than the 500 it replaced: "no
 * data-quality problems" and "the data-quality check failed" look identical and mean the opposite.
 */
test('a section that failed to read is named, and the rest of the page still renders', async () => {
  api.get.mockResolvedValue({
    data: { ...SNAPSHOT, data_quality: [], failed_sections: { data_quality: 'Base table not found' } },
  });
  await load();

  expect(screen.getByText('Part of this page could not be read.')).toBeInTheDocument();
  expect(screen.getByText(/Base table not found/)).toBeInTheDocument();
  // The rest is untouched and still accurate.
  expect(screen.getByText('Comeback Warning (shipped)')).toBeInTheDocument();
});

test('a healthy read shows no failure banner', async () => {
  await load();
  expect(screen.queryByText('Part of this page could not be read.')).not.toBeInTheDocument();
});

test('re-reading bypasses the cache rather than re-serving it', async () => {
  await load();
  fireEvent.click(screen.getByText('Re-read now'));

  await waitFor(() =>
    expect(api.get).toHaveBeenCalledWith('/intelligence/center', { params: { refresh: 1 } }));
});
