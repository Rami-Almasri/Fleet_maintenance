import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { I18nProvider } from '../../i18n/I18nContext';
import api from '../../api/client';
import VehicleSystemDashboard from './VehicleSystemDashboard';

jest.mock('../../api/client', () => ({ __esModule: true, default: { get: jest.fn() } }));

/**
 * THE PERIOD LIVES IN THE URL, and this file is the only place that can prove it.
 *
 * The block-level tests render the filter and the problem list in isolation, which says nothing
 * about the thing that makes a filtered report a real document: that the dates are in the address
 * bar, that they reach the request, that Apply and Clear rewrite them, and that the browser's back
 * button walks back through the periods the reader actually looked at.
 *
 * Those four are one mechanism — searchParams — and every one of them is a way the feature can look
 * finished and not be. A filter held in component state passes every isolated test and then loses
 * itself on refresh, cannot be shared, and prints a PDF of the wrong period.
 */

/** A dashboard payload shaped like VehicleSystemDashboardService::build(), for one period. */
const payload = ({ from = null, to = null, visits = 3, problems = 1 } = {}) => ({
  vehicle: { id: 1743, label: 'CHEVROLET CAMARO · Red Black · 2020 · 89529', plate: '89529', vin: 'X' },
  brief: [{ key: 'model', kind: 'text', value: 'CHEVROLET · CAMARO · 2020' }],
  system: { key: 'engine', label: 'Engine' },
  verdict: { decision: 'REPAIR AND WATCH', tone: 'warn', status: 'ACTIVE HISTORY', confidence: 'low' },
  kpis: [
    { key: 'risk', label: 'Risk', value: '34 / 100', note: 'Evidence confidence: LOW.', scope: 'history' },
    { key: 'incidents', label: 'Real Incidents', value: visits, note: 'Grouped from 5 source records.', scope: 'period' },
  ],
  risk: {
    score: 34, health: 66, ceiling: 65, band: 'moderate', scope: 'history',
    confidence: { level: 'low' }, components: [], basis: 'Sum of five counts.',
  },

  period: {
    from, to,
    active: !!(from || to),
    shape: from && to ? (from === to ? 'single_day' : 'between') : from ? 'since' : to ? 'until' : 'all_history',
    covered_from: visits ? '2026-05-10' : null,
    covered_to: visits ? '2026-07-28' : null,
  },
  period_summary: {
    system_visits: visits, named_faults: problems, named_fault_visits: problems ? 2 : 0,
    repeated_faults: problems ? 1 : 0, repairs: problems ? 2 : 0,
    workshop_only_visits: visits && problems ? 1 : visits, source_records: visits,
    first_record: visits ? '2026-05-10' : null, last_record: visits ? '2026-07-28' : null,
    scope: 'period',
  },
  problems: problems
    ? [{
        fault: 'Engine Noise', fault_key: 'engine noise', occurrences: 2, repeated: true,
        first_seen: '2026-07-04', last_seen: '2026-07-28', span_days: 24,
        garages: ['7 CYLINDER', 'AL Muharik AL hakiki'], worst_severity: null, strength: 'strong',
        repairs_recorded: 1, followed_by: null,
        returned: { status: 'yes', days: 24, from: '2026-07-04', to: '2026-07-28', times: 2, after_repair: false },
        events: [
          { date: '2026-07-04', end: '2026-07-04', garage: '7 CYLINDER', garages: ['7 CYLINDER'], severity: null,
            note_lines: ['Engine noise / abnormal sound reported by the driver.'],
            all_note_lines: ['Engine noise / abnormal sound reported by the driver.'],
            action: 'inspection', action_text: 'The radiator was checked.', action_evidence: 'strong',
            row_count: 1, refs: [102], gap_days: null },
          { date: '2026-07-28', end: '2026-07-28', garage: 'AL Muharik AL hakiki', garages: ['AL Muharik AL hakiki'],
            severity: null, note_lines: ['Engine noise continued.'], all_note_lines: ['Engine noise continued.'],
            action: 'repair', action_text: 'The engine mounting was repaired.', action_evidence: 'strong',
            row_count: 1, refs: [103], gap_days: 24 },
        ],
      }]
    : [],
  workshop_only: visits && problems
    ? [{ date: '2026-05-10', end: '2026-05-10', garage: 'Deals On Wheels auto', garages: ['Deals On Wheels auto'],
        kind: 'workshop_mention', strength: 'medium',
        note_lines: ['Engine cover fasteners/clips have been secured.'],
        action: 'repair', action_text: 'Engine cover fasteners/clips have been secured.',
        action_evidence: 'strong', row_count: 1, refs: [101] }]
    : [],
  period_repairs: [],

  incidents: problems
    ? [
        { start: '2026-05-10', end: '2026-05-10', kind: 'workshop_mention', strength: 'medium', severity: null,
          row_count: 1, is_confirmed: false, fault: null, garages: ['Deals On Wheels auto'], odometer: null,
          work: [], records: [{ ref: 101, refs: [101], system_lines: ['Engine cover fasteners/clips have been secured.'], all_lines: ['Engine cover fasteners/clips have been secured.'] }] },
        { start: '2026-07-04', end: '2026-07-04', kind: 'confirmed_fault', strength: 'strong', severity: 'critical',
          row_count: 2, is_confirmed: true, fault: 'Engine Noise', garages: ['7 CYLINDER'], odometer: null,
          work: [], records: [{ ref: 102, refs: [102], system_lines: ['Engine noise / abnormal sound reported by the driver.'], all_lines: ['Engine noise / abnormal sound reported by the driver.'] }] },
      ]
    : [],
  recurrence: { status: problems ? 'confirmed_recurring_fault' : 'no_evidence', repeated_faults: [], named_faults: [] },
  work: [],
  durability: [],
  facts: {
    records: visits, incidents: visits, confirmed_faults: problems,
    latest_incident: visits ? { start: '2026-07-28' } : null,
    latest_confirmed: problems ? { start: '2026-07-28', fault: 'Engine Noise' } : null,
  },
  confidence: { level: 'low', named_share: 0.33, ticket_share: 0, note_share: 0.6 },
  data_quality: [{ code: 'no_odometer', n: visits, total: visits }],
  data_quality_scope: from || to ? 'period' : 'history',
  takeaway: { actions: [] },
  failure_mix: visits ? [{ label: 'Engine Noise', count: 2 }] : [],
  fault_history: [],
  timeline: [],
  repairs: [],
  provenance: { source: 's', window: 'w', split: 'sp', grouping: 'g', omitted: 'o', derived: 'd', i18n: {} },
});

/** Exposes the router's own history so back/forward can be driven the way a browser drives it. */
let navigate;
function CaptureNavigate() {
  navigate = useNavigate();
  return null;
}

const renderAt = (url) =>
  render(
    <I18nProvider>
      <MemoryRouter initialEntries={[url]}>
        <CaptureNavigate />
        <Routes>
          <Route path="/reports/vehicle-system/:vehicleId" element={<VehicleSystemDashboard />} />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
  );

/** Every params object the page sent to the dashboard endpoint, oldest first. */
const dashboardCalls = () =>
  api.get.mock.calls
    .filter(([url]) => url.startsWith('/reports/vehicle-system/'))
    .map(([, config]) => config?.params);

beforeEach(() => {
  api.get.mockReset();
  api.get.mockImplementation((url, config) => {
    if (url === '/reports/systems') {
      return Promise.resolve({
        data: { data: [{ key: 'engine', label: 'Engine' }, { key: 'brakes', label: 'Brakes' }] },
      });
    }
    const { from = null, to = null } = config?.params || {};
    // An empty window is answered, not refused — that is the report's own behaviour.
    const empty = from === '2026-01-01';
    return Promise.resolve({
      data: { data: payload({ from, to, visits: empty ? 0 : 3, problems: empty ? 0 : 1 }) },
    });
  });
});

describe('the period travels in the URL', () => {
  it('reads ?from and ?to out of the URL and sends them to the API', async () => {
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    await waitFor(() =>
      expect(dashboardCalls()).toContainEqual({ system: 'engine', from: '2026-05-01', to: '2026-07-31' }));

    expect(await screen.findByLabelText(/^from$/i)).toHaveValue('2026-05-01');
    expect(screen.getByLabelText(/^to$/i)).toHaveValue('2026-07-31');
  });

  /* No params is ALL HISTORY, and no from/to is sent — the old request, unchanged. */
  it('sends no period at all when the URL carries none', async () => {
    const { container } = renderAt('/reports/vehicle-system/1743');

    await waitFor(() => expect(dashboardCalls()).toContainEqual({ system: 'engine' }));
    // The BANNER, not the quick-range button of the same name: the banner is what prints.
    await waitFor(() =>
      expect(container.querySelector('.ir-period-value')).toHaveTextContent(/all history/i));
  });

  it('Apply rewrites the URL and refetches for the new period', async () => {
    // user-event v13: the module is the API — there is no setup().
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    const from = await screen.findByLabelText(/^from$/i);
    await userEvent.clear(from);
    await userEvent.type(from, '2026-07-01');
    await userEvent.click(screen.getByRole('button', { name: /apply/i }));

    await waitFor(() =>
      expect(dashboardCalls()).toContainEqual({ system: 'engine', from: '2026-07-01', to: '2026-07-31' }));
  });

  it('Clear drops the params and returns the report to all history', async () => {
    // user-event v13: the module is the API — there is no setup().
    const { container } = renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    await userEvent.click(await screen.findByRole('button', { name: /^clear$/i }));

    await waitFor(() => expect(dashboardCalls()).toContainEqual({ system: 'engine' }));
    await waitFor(() =>
      expect(container.querySelector('.ir-period-value')).toHaveTextContent(/all history/i));
  });

  /* BACK MUST WALK THE PERIODS. This is the whole reason the filter is in the URL rather than in
     component state — and it is the one behaviour no isolated component test can see. */
  it('the back button returns to the previous period, and forward returns to the newer one', async () => {
    // user-event v13: the module is the API — there is no setup().
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    await userEvent.click(await screen.findByRole('button', { name: /last 90 days/i }));
    await waitFor(() => expect(dashboardCalls().length).toBeGreaterThan(1));

    // act(), because a history pop is a state update React did not schedule from an event handler.
    await act(async () => navigate(-1));
    await waitFor(() => expect(screen.getByLabelText(/^from$/i)).toHaveValue('2026-05-01'));
    expect(screen.getByLabelText(/^to$/i)).toHaveValue('2026-07-31');

    await act(async () => navigate(1));
    await waitFor(() => expect(screen.getByLabelText(/^from$/i)).not.toHaveValue('2026-05-01'));
  });

  /* Changing the system keeps the period — a reader comparing two systems over one window should
     not have to retype the window. */
  it('switching system preserves the selected period', async () => {
    // user-event v13: the module is the API — there is no setup().
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    // The picker is populated by its own request, so wait for the option before choosing it.
    await screen.findByRole('option', { name: 'Brakes' });
    await userEvent.selectOptions(screen.getByLabelText(/^system$/i), 'brakes');

    await waitFor(() =>
      expect(dashboardCalls()).toContainEqual({ system: 'brakes', from: '2026-05-01', to: '2026-07-31' }));
    expect(screen.getByLabelText(/^from$/i)).toHaveValue('2026-05-01');
  });
});

describe('the filtered report on the page', () => {
  it('states the period, the counters and the grouped problem', async () => {
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    expect(await screen.findByText(/In the selected period the record shows/i)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Problems during this period/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Engine Noise/ })).toBeInTheDocument();
    expect(screen.getByText(/recorded again 24 days later/i)).toBeInTheDocument();
    // The workshop's own words, next to the normalised fault name and never instead of it.
    expect(screen.getByText('Engine noise continued.')).toBeInTheDocument();
    // The visit that named nothing is shown as a visit, not folded into the problem count.
    expect(screen.getByText(/These are visits, not faults/i)).toBeInTheDocument();
  });

  /* THE FAILURE RAIL — the shape of the history, in the order it happened. */
  it('draws the failure sequence with a dot per incident, coloured by recorded severity', async () => {
    const { container } = renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    const rail = (await screen.findByRole('heading', { name: /Engine failure sequence/i }))
      .closest('.ir-panel');
    const dots = rail.querySelectorAll('.ir-titem');

    expect(dots).toHaveLength(2);
    // Oldest first, and the visit that named nothing is NOT dressed up as a rated fault.
    expect(dots[0]).toHaveClass('sev-unrated');
    expect(dots[0]).toHaveTextContent('2026-05-10');
    expect(dots[0]).toHaveTextContent('Deals On Wheels auto');
    expect(dots[0]).toHaveTextContent(/No fault named/i);
    expect(dots[0]).toHaveTextContent('Engine cover fasteners/clips have been secured.');

    expect(dots[1]).toHaveClass('sev-critical');
    expect(dots[1]).toHaveTextContent('Engine Noise');
    expect(dots[1]).toHaveTextContent('7 CYLINDER');
    // The garage's own words travel with the dot — the rail is evidence, not a summary.
    expect(dots[1]).toHaveTextContent('Engine noise / abnormal sound reported by the driver.');

    expect(container.querySelector('.ir-timeline')).toBeTruthy();
  });

  /* THE ONE NUMBER THAT MUST NOT MOVE WITH THE FILTER, and the page must say so. */
  it('labels the risk score as all-history whenever a period is selected', async () => {
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    expect(await screen.findByRole('heading', { name: /How the risk score was reached \(all history\)/i })).toBeInTheDocument();
    expect(screen.getByText(/This score covers the whole record, not the selected period/i)).toBeInTheDocument();
  });

  it('an empty period says so and still shows the vehicle, the system and the filter', async () => {
    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-01-01&to=2026-04-30');

    // The heading and the report footer both name the car; either is proof it is still on the page.
    expect((await screen.findAllByText(/CHEVROLET CAMARO/)).length).toBeGreaterThan(0);
    expect(screen.getByLabelText(/^system$/i)).toHaveValue('engine');
    expect(screen.getByLabelText(/^from$/i)).toHaveValue('2026-01-01');
    expect(screen.getAllByText(/No problem is recorded for this system during the selected period/i).length)
      .toBeGreaterThan(0);
  });

  /* PRINT. The controls are hidden from print; the period banner is NOT, because a PDF that does
     not state its own dates reads as the whole history. */
  it('hides the controls from print but keeps the period banner on the page', async () => {
    const { container } = renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    // The banner only exists once the report has loaded — the filter renders before it.
    await waitFor(() => expect(container.querySelector('.ir-period-banner')).toBeTruthy());

    expect(container.querySelector('.ir-daterange')).toHaveClass('ir-no-print');
    expect(container.querySelector('.ir-controls')).toHaveClass('ir-no-print');
    expect(container.querySelector('.ir-period-banner')).not.toHaveClass('ir-no-print');
    expect(container.querySelector('.ir-period-banner')).toHaveTextContent(/2026/);
  });

  it('Print / Save as PDF prints the page as currently filtered', async () => {
    // user-event v13: the module is the API — there is no setup().
    // jsdom leaves window.print undefined, so it is replaced rather than spied on.
    const realPrint = window.print;
    const print = jest.fn();
    window.print = print;

    renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');
    await userEvent.click(await screen.findByRole('button', { name: /print/i }));

    expect(print).toHaveBeenCalled();
    // Nothing was refetched to print: the document on screen is the document that prints.
    expect(dashboardCalls()).toEqual([{ system: 'engine', from: '2026-05-01', to: '2026-07-31' }]);
    window.print = realPrint;
  });
});

describe('the filtered report in Arabic', () => {
  beforeEach(() => localStorage.setItem('fv:lang', 'ar'));
  afterEach(() => localStorage.setItem('fv:lang', 'en'));

  it('renders the whole page without handing React a plural object', async () => {
    const { container } = renderAt('/reports/vehicle-system/1743?system=engine&from=2026-05-01&to=2026-07-31');

    await screen.findByLabelText('من');
    expect(container.textContent).not.toMatch(/\[object Object\]/);
    expect(screen.getByRole('heading', { name: /المشكلات خلال هذه الفترة/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'تطبيق' })).toBeInTheDocument();
  });
});
