import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { I18nProvider } from '../../i18n/I18nContext';
import api from '../../api/client';
import VehicleReport from './VehicleReport';

jest.mock('../../api/client', () => ({ __esModule: true, default: { get: jest.fn() } }));

/**
 * WHAT THIS PAGE PROMISES, AND WHAT WOULD SILENTLY BREAK IT.
 *
 * The report exists to answer "what is wrong with this car" without the reader picking a system
 * first, and to make every count traceable to a garage and a contract. Four things carry that, and
 * each of them can regress while the page still renders:
 *
 *   • THE RANKING leads with what happens most. A list that renders in payload order looks identical
 *     until the day the worst fault is third.
 *   • THE FILTERS narrow the detail AND say what they are hiding. A filter that silently hides eight
 *     of nine systems is how a reader concludes a car is clean.
 *   • THE CONTRACT reaches the page. It is the whole reason a repeat fault is actionable, and it is
 *     one optional field away from never being rendered.
 *   • THE PERIOD lives in the URL and reaches the request, so a filtered report can be sent to
 *     somebody and printed as what it says it is.
 */

const problem = (over = {}) => ({
  fault: 'Rim Scratch',
  fault_key: 'rim scratch',
  key: 'bodywork::rim scratch',
  system: 'bodywork',
  system_label: 'Bodywork & Exterior',
  systems: ['bodywork'],
  system_labels: ['Bodywork & Exterior'],
  occurrences: 4,
  repeated: true,
  first_seen: '2026-04-02',
  last_seen: '2026-08-30',
  span_days: 150,
  garages: ['GPT GARRAGE'],
  worst_severity: 'high',
  strength: 'strong',
  repairs_recorded: 1,
  followed_by: null,
  without_contract: 0,
  days_since_last: 6,
  contracts: [{ id: 71, no: '4712', out_date: '2026-04-01', in_date: '2026-04-05' }],
  returned: { status: 'yes', days: 42, from: '2026-07-19', to: '2026-08-30', times: 4, after_repair: true },
  events: [
    {
      date: '2026-04-02', end: '2026-04-02', garage: 'GPT GARRAGE', garages: ['GPT GARRAGE'], severity: 'high',
      note_lines: ['Rim scratch on the front left wheel.'], all_note_lines: ['Rim scratch on the front left wheel.'],
      action: 'repair', action_text: 'The rim was refinished.', action_evidence: 'strong',
      row_count: 1, refs: [301], gap_days: null,
      contract: { id: 71, no: '4712', out_date: '2026-04-01', in_date: '2026-04-05' },
    },
  ],
  ...over,
});

/**
 * The payload, scoped the way the SERVICE scopes it: `system` narrows the problems, the visits and
 * the summary, and leaves `systems` whole because it is the picker. The fixture mirrors that contract
 * so the test proves the page reads a narrowed payload, not that it filters one itself.
 */
const payload = ({ from = null, to = null, system = null } = {}) => ({
  vehicle: { id: 1741, label: 'DODGE CHALLENGER · 2021 · 55321', plate: '55321', vin: 'X' },
  brief: [{ key: 'model', kind: 'text', value: 'DODGE · CHALLENGER · 2021' }],
  period: {
    from, to, active: !!(from || to),
    shape: from && to ? 'between' : from ? 'since' : to ? 'until' : 'all_history',
    covered_from: '2026-04-02', covered_to: '2026-08-30',
  },
  summary: {
    system_visits: 9, workshop_visits: 7, named_faults: 3, fault_occurrences: 7,
    repeated_faults: 2, returns: 4, systems_affected: 2, repairs: 3,
    workshop_only_visits: 1, source_records: 12, garages: 2,
    first_record: '2026-04-02', last_record: '2026-08-30', worst_severity: 'high',
  },
  systems: [
    { key: 'bodywork', label: 'Bodywork & Exterior', visits: 6, named_faults: 2, fault_visits: 6,
      repeated_faults: 2, repairs: 2, workshop_only: 0, first_seen: '2026-04-02', last_seen: '2026-08-30',
      worst_severity: 'high', top_fault: 'Rim Scratch' },
    { key: 'brakes', label: 'Brakes', visits: 3, named_faults: 1, fault_visits: 2, repeated_faults: 0,
      repairs: 1, workshop_only: 1, first_seen: '2026-05-02', last_seen: '2026-06-02',
      worst_severity: 'moderate', top_fault: 'Brake Pad Wear' },
  ],
  // Deliberately NOT in ranked order: the page must sort, not trust the array.
  problems: [
    problem({ fault: 'Brake Pad Wear', fault_key: 'brake pad wear', key: 'brakes::brake pad wear',
      system: 'brakes', system_label: 'Brakes', systems: ['brakes'], system_labels: ['Brakes'],
      occurrences: 1, repeated: false, contracts: [], without_contract: 1,
      returned: { status: 'no', days: null, from: null, to: null, after_repair: false },
      events: [{ date: '2026-05-02', end: '2026-05-02', garage: 'FUTURE TYRES', garages: ['FUTURE TYRES'],
        severity: 'moderate', note_lines: ['Brake pads worn.'], all_note_lines: ['Brake pads worn.'],
        action: 'inspection', action_text: null, action_evidence: 'strong', row_count: 1, refs: [401],
        gap_days: null, contract: null }] }),
    problem(),
  ],
  workshop_only: [
    { date: '2026-06-02', end: '2026-06-02', garage: 'FUTURE TYRES', garages: ['FUTURE TYRES'],
      system: 'brakes', system_label: 'Brakes', kind: 'workshop_mention', strength: 'medium',
      note_lines: ['Brake fluid topped up.'], action: 'repair', action_text: 'Brake fluid topped up.',
      action_evidence: 'strong', row_count: 1, refs: [402] },
  ],
  garages: [
    { garage: 'GPT GARRAGE', visits: 4, faults: 1, systems: ['bodywork'], first: '2026-04-02', last: '2026-08-30' },
  ],
  months: [
    { month: '2026-04', faults: 1, other: 0, visits: 1 },
    { month: '2026-05', faults: 1, other: 0, visits: 1 },
    { month: '2026-06', faults: 0, other: 1, visits: 3 },
  ],
  contracts: [
    { id: 71, no: '4712', out_date: '2026-04-01', in_date: '2026-04-05', occurrences: 1, faults: ['Rim Scratch'] },
  ],
  provenance: { source: 's', window: 'w', split: 'sp', grouping: 'g', omitted: 'o', derived: 'd', i18n: {} },
});

/** The service's scoping, reproduced: problems and visits narrow, the picker does not. */
const scoped = (args = {}) => {
  const full = payload(args);
  if (!args.system) return full;

  const problems = full.problems.filter((p) => p.systems.includes(args.system));

  return {
    ...full,
    system: { key: args.system, label: full.systems.find((s) => s.key === args.system)?.label || args.system },
    problems,
    workshop_only: full.workshop_only.filter((v) => v.system === args.system),
    summary: { ...full.summary, named_faults: problems.length, systems_affected: 1 },
  };
};

const renderAt = (url) =>
  render(
    <I18nProvider>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route path="/reports/vehicle/:vehicleId" element={<VehicleReport />} />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
  );

/** Every params object the page sent to the overview endpoint, oldest first. */
const overviewCalls = () =>
  api.get.mock.calls.filter(([url]) => url.startsWith('/reports/vehicle-overview/')).map(([, cfg]) => cfg?.params);

beforeEach(() => {
  api.get.mockReset();
  api.get.mockImplementation((url, cfg) => {
    if (url.startsWith('/reports/vehicle-overview/')) {
      return Promise.resolve({ data: { data: scoped({ from: cfg?.params?.from ?? null, to: cfg?.params?.to ?? null, system: cfg?.params?.system ?? null }) } });
    }
    return Promise.resolve({ data: { data: null } });
  });
});

afterEach(() => { jest.restoreAllMocks(); });

/** Capture the standalone document Print writes, without opening a real window. */
const capturePrintWindow = () => {
  const written = [];
  const w = { document: { write: (html) => written.push(html), close: () => {} }, focus: () => {} };
  jest.spyOn(window, 'open').mockReturnValue(w);
  return { html: () => written.join('') };
};

test('leads with the fault that happens most, however the payload was ordered', async () => {
  renderAt('/reports/vehicle/1741');

  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  const ranking = screen.getAllByRole('button').filter((b) => b.className.includes('ir-rank-row'));

  expect(ranking).toHaveLength(2);
  // Rim Scratch is SECOND in the payload and must be first on the page.
  expect(within(ranking[0]).getByText('Rim Scratch')).toBeInTheDocument();
  expect(within(ranking[0]).getByText('4 times')).toBeInTheDocument();
  expect(within(ranking[1]).getByText('Brake Pad Wear')).toBeInTheDocument();
});

test('states the repeat and the contracts it happened on', async () => {
  renderAt('/reports/vehicle/1741');

  // The repeat is a word, never colour alone.
  expect(await screen.findAllByText('Came back')).not.toHaveLength(0);
  // The contract reaches both the ranked row and the occurrence.
  expect(screen.getAllByText('1 contract').length).toBeGreaterThan(0);
  expect(await screen.findAllByText('Contract 4712')).not.toHaveLength(0);
});

test('choosing a system narrows the WHOLE report — request, ranking and detail', async () => {
  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  // Both faults are on the page to start with.
  expect(screen.getAllByRole('heading', { name: /Rim Scratch/ }).length).toBeGreaterThan(0);
  expect(screen.getAllByRole('heading', { name: /Brake Pad Wear/ }).length).toBeGreaterThan(0);

  await userEvent.click(screen.getByRole('button', { name: /^Brakes/ }));

  // The narrowing happens in the SERVICE, so it reaches the request. A client-side filter would pass
  // every assertion below and still leave the charts and the counters describing the whole car.
  await waitFor(() => expect(overviewCalls().some((p) => p?.system === 'brakes')).toBe(true));

  await waitFor(() => {
    expect(screen.queryByRole('heading', { name: /Rim Scratch/ })).not.toBeInTheDocument();
  });

  // The ranked chart narrows with everything else — that was the whole complaint.
  const ranking = screen.getAllByRole('button').filter((b) => b.className.includes('ir-rank-row'));
  expect(ranking).toHaveLength(1);
  expect(within(ranking[0]).getByText('Brake Pad Wear')).toBeInTheDocument();

  // And the page says out loud that it is narrowed.
  expect(screen.getByText(/narrowed to Brakes/)).toBeInTheDocument();
});

test('the chosen system is in the URL, so a narrowed report survives a reload and can be sent on', async () => {
  renderAt('/reports/vehicle/1741?system=brakes');

  await waitFor(() => expect(overviewCalls().length).toBeGreaterThan(0));
  expect(overviewCalls()[0]).toEqual({ system: 'brakes' });

  // The picker still lists every system the car has a record against — a picker narrowed to the
  // option already chosen cannot be used to choose anything else.
  expect(await screen.findByRole('button', { name: /^Bodywork & Exterior/ })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'All systems' })).toBeInTheDocument();
});

test('clicking a ranked fault shows only that fault, and clicking it again clears', async () => {
  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  const rimRow = screen.getAllByRole('button')
    .find((b) => b.className.includes('ir-rank-row') && within(b).queryByText('Rim Scratch'));

  await userEvent.click(rimRow);
  await waitFor(() => {
    expect(screen.queryByRole('heading', { name: /Brake Pad Wear/ })).not.toBeInTheDocument();
  });

  await userEvent.click(rimRow);
  await waitFor(() => {
    expect(screen.getAllByRole('heading', { name: /Brake Pad Wear/ }).length).toBeGreaterThan(0);
  });
});

test('the period comes out of the URL and goes into the request', async () => {
  renderAt('/reports/vehicle/1741?from=2026-05-01&to=2026-07-31');

  await waitFor(() => expect(overviewCalls().length).toBeGreaterThan(0));
  expect(overviewCalls()[0]).toEqual({ from: '2026-05-01', to: '2026-07-31' });
  // And it prints: a PDF that does not say its period reads as the whole history.
  expect(await screen.findByText('01 May 2026 → 31 Jul 2026')).toBeInTheDocument();
});

/*
 * ONE PRINTABLE DOCUMENT, NOT TWO. The page used to offer Print beside "Download dossier", which made
 * a reader choose between two sheets before knowing what either contained — and the dossier described
 * a different subject (what the car IS) from the page it was printed from (what is wrong with it).
 */
test('there is no second download button beside Print', async () => {
  renderAt('/reports/vehicle/1741');

  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  expect(screen.getByRole('button', { name: 'Print / Save as PDF' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Download dossier' })).not.toBeInTheDocument();
});

test('Print writes its own document rather than handing the printer this page', async () => {
  const printed = capturePrintWindow();

  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  await userEvent.click(screen.getByRole('button', { name: 'Print / Save as PDF' }));

  const html = printed.html();
  // A whole standalone sheet, not a copy of the app shell.
  expect(html).toContain('<!doctype html>');
  // Same subject, same ranking, and the workshop's own words still under each occurrence.
  expect(html).toContain('DODGE CHALLENGER · 2021 · 55321');
  expect(html.indexOf('Rim Scratch')).toBeLessThan(html.indexOf('Brake Pad Wear'));
  // No second query: the printed sheet is a re-render of what the page already holds.
  expect(overviewCalls()).toHaveLength(1);
});

/*
 * WHAT YOU SEE IS WHAT YOU GET. The period and the system live in the URL, so the payload already
 * carries them — but the fault drill-down and "only what came back" are client-side, and the sheet
 * used to print every fault while the screen showed one. Two documents claiming to be one report.
 */
test('the printed sheet opens on the drill-down the reader is looking at', async () => {
  const printed = capturePrintWindow();

  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  await userEvent.click(screen.getByRole('button', { name: 'Only what came back' }));
  await userEvent.click(screen.getByRole('button', { name: 'Print / Save as PDF' }));

  // The state reaches the document, which opens already narrowed to it.
  expect(printed.html()).toMatch(/"repeats":\s*true/);
});

test('the printed sheet carries a clicked fault, not the whole list', async () => {
  const printed = capturePrintWindow();

  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  const rimRow = screen.getAllByRole('button')
    .find((b) => b.className.includes('ir-rank-row') && within(b).queryByText('Rim Scratch'));
  await userEvent.click(rimRow);
  await userEvent.click(screen.getByRole('button', { name: 'Print / Save as PDF' }));

  expect(printed.html()).toMatch(/"fault":\s*"bodywork::rim scratch"/);
});

/*
 * The two lists are ONE report or they are not a report. The print sliced its ranking at 15 while the
 * page sliced at 12, so a car with fourteen problems showed twelve on screen and fourteen on paper.
 */
test('the printed ranking is cut at the same depth as the page', async () => {
  const printed = capturePrintWindow();

  renderAt('/reports/vehicle/1741');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');
  await userEvent.click(screen.getByRole('button', { name: 'Print / Save as PDF' }));

  const onScreen = screen.getAllByRole('button').filter((b) => b.className.includes('ir-rank-row')).length;
  // The attribute pair only ever appears on a ranked row — `[data-rank]` alone also matches the
  // document's own stylesheet and its filter script.
  const inPrint = (printed.html().match(/data-rank role="button"/g) || []).length;

  expect(inPrint).toBe(onScreen);
});

test('the printed sheet repeats what the report is narrowed to', async () => {
  const printed = capturePrintWindow();

  renderAt('/reports/vehicle/1741?system=brakes&from=2026-05-01&to=2026-07-31');
  await screen.findByText('DODGE CHALLENGER · 2021 · 55321');

  await userEvent.click(screen.getByRole('button', { name: 'Print / Save as PDF' }));

  const html = printed.html();
  // A filtered report that prints as if it were the whole car is how a reader concludes a car is clean.
  expect(html).toContain('01 May 2026 → 31 Jul 2026');
  expect(html).toMatch(/narrowed to Brakes/);
});
