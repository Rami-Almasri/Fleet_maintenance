import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { I18nProvider } from '../../i18n/I18nContext';
import api from '../../api/client';
import DailyMaintenanceIntelligence from './DailyMaintenanceIntelligence';

jest.mock('../../api/client', () => ({ __esModule: true, default: { get: jest.fn() } }));

/**
 * THE DAILY REPORT'S DERIVED PANELS.
 *
 * The severity mix, the workload bars, the release-readiness notes and the priority queue are all
 * counted ON THE PAGE, over the same rows the operations table shows. That is the only way they can
 * be trusted: a chart whose number cannot be found in the table below it is a chart nobody can check.
 *
 * So these tests pin the two properties that make that true:
 *   1. every count matches the visible rows, and moves with the bucket toggle;
 *   2. nothing is invented — an unrated case is reported as unrated, never as low severity, and a
 *      long stay is reported with the threshold that produced it.
 */

const CASES = [
  {
    id: 1, vehicle_id: 11, vehicle: 'FORD MUSTANG - Red - 2023 - V 23733',
    severity: 'critical', severity_label: 'Critical', severity_rank: 4,
    status: 'Follow up', garage: 'Qasr Al Zaytoun', days: 11,
    issues: 'Major rebuild with GTD-spec body requirements.',
    progress: 'Vehicle reassembled and prepared for paint. Sanding in progress before lacquer.',
    categories: ['bodywork'], entries: 2, bucket: 'today',
  },
  {
    id: 2, vehicle_id: 12, vehicle: 'CHEVROLET CAMARO - Orange Black - 2022 - V 75354',
    severity: 'high', severity_label: 'High', severity_rank: 3,
    status: 'IN / OUT', garage: 'FUTURE TYRES / 7 CYLINDER', days: 0,
    issues: 'Rear-right wheel noise when braking.',
    progress: 'New rear-right bearing installed. Four brake drums to be machined.',
    categories: ['brakes', 'tyres'], entries: 1, bucket: 'today',
  },
  {
    id: 3, vehicle_id: 13, vehicle: 'NISSAN PATROL - Grey - 2025 - P 45051',
    severity: null, severity_label: 'Unrated', severity_rank: 0,
    status: 'IN', garage: 'Nissan Service', days: 9,
    issues: 'Routine oil and filter service.',
    progress: 'Vehicle at Nissan Service.',
    categories: ['fluids'], entries: 1, bucket: 'still_out',
  },
];

const payload = () => ({
  meta: { date: '2026-08-08', date_label: '08 August 2026', generated: '2026-08-08 07:00',
    top_alert: 'Ford Mustang V 23733 — paint at sanding stage', latest_entry_date: '2026-08-08' },
  kpis: [{ key: 'active', label: 'Active Vehicles', value: 3, note: 'In garage, follow-up or test.' }],
  cases: CASES,
  alerts: [{ vehicle: 'FORD MUSTANG', severity: 'critical', label: 'CRITICAL — Ford Mustang', text: 'Paint at sanding stage.' }],
  garages: [{ garage: '7 CYLINDER', load: 2, critical: 0, activity: 'Brakes and bearings' }],
  provenance: { source: 's', window: 'w', counts: { cases: 3, unrated: 1 } },
});

const renderPage = () =>
  render(
    <I18nProvider>
      <MemoryRouter initialEntries={['/reports/daily-maintenance?date=2026-08-08']}>
        <DailyMaintenanceIntelligence />
      </MemoryRouter>
    </I18nProvider>,
  );

/** The panel a heading belongs to, once the report has loaded. */
const panel = async (name) =>
  (await screen.findByRole('heading', { name })).closest('.ir-panel');

/** The same, after the page has already been awaited once — synchronous re-reads. */
const panelNow = (name) => screen.getByRole('heading', { name }).closest('.ir-panel');

beforeEach(() => {
  api.get.mockReset();
  api.get.mockResolvedValue({ data: { data: payload() } });
});

describe('the day at a glance', () => {
  it('counts the severity mix over the visible rows, and never calls an unrated case low', async () => {
    renderPage();

    const mix = await panel('Case Severity Mix');
    // The bucket starts on "Today's entries" — the Patrol is still_out, so it is not counted here.
    expect(within(mix).getByText('Critical')).toBeInTheDocument();
    expect(within(mix).getByText('High')).toBeInTheDocument();
    expect(within(mix).queryByText('Low')).not.toBeInTheDocument();
    // The donut hole carries the total, so the chart is never the only place a number appears.
    expect(mix.querySelector('.ir-donut-total')).toHaveTextContent('2');
  });

  it('counts a car with two faults under both systems, and says so', async () => {
    renderPage();

    const work = await panel('Workload by System');
    await within(work).findByText('Brakes');
    expect(within(work).getByText('Tyres & Wheels')).toBeInTheDocument();
    expect(within(work).getByText('Bodywork & Exterior')).toBeInTheDocument();
    // 3 bars from 2 cases — the hint has to explain that rather than leave it looking like an error.
    expect(screen.getByText(/A car with two faults is counted under both/i)).toBeInTheDocument();
  });

  it('reports a long stay with the threshold that produced it', async () => {
    renderPage();

    const ready = await panel('Release Readiness');
    expect(within(ready).getByText(/Long-stay cases: 1/)).toBeInTheDocument();
    expect(within(ready).getByText(/FORD MUSTANG.*11 days/)).toBeInTheDocument();
    // The threshold is stated as this report's reading aid, not as a fleet rule.
    expect(within(ready).getByText(/not a rule the fleet operates by/i)).toBeInTheDocument();
  });

  it('states how many cases nobody rated, so the mix is not read as complete', async () => {
    const user = userEvent.setup ? userEvent.setup() : userEvent;
    renderPage();

    // The unrated Patrol sits in "Still out", so switch buckets to bring it into view.
    await user.click(screen.getByRole('button', { name: /still out/i }));

    const ready = await panel('Release Readiness');
    await waitFor(() => expect(within(ready).getByText(/Severity not set: 1 of 1/)).toBeInTheDocument());
    expect(within(ready).getByText(/An unrated case is not a low-severity case/i)).toBeInTheDocument();
  });
});

describe('the priority queue', () => {
  it('lists Critical before High, with the garage, the days and the recorded work', async () => {
    renderPage();

    const queue = await panel('Priority Case Sequence');
    const items = queue.querySelectorAll('.ir-titem');

    expect(items).toHaveLength(2);
    expect(items[0]).toHaveClass('sev-critical');
    expect(items[0]).toHaveTextContent('FORD MUSTANG');
    expect(items[0]).toHaveTextContent('Qasr Al Zaytoun');
    expect(items[0]).toHaveTextContent('11 days');
    // The issue leads and the recorded work follows — both verbatim from the ticket.
    expect(items[0]).toHaveTextContent('Major rebuild with GTD-spec body requirements.');
    expect(items[0]).toHaveTextContent(/Sanding in progress before lacquer/);
    expect(items[1]).toHaveClass('sev-high');
  });

  it('an empty queue says what that does and does not mean', async () => {
    api.get.mockResolvedValue({
      data: { data: { ...payload(), cases: [{ ...CASES[2], bucket: 'today' }] } },
    });
    renderPage();

    const queue = await panel('Priority Case Sequence');
    expect(within(queue).getByText(/not a clean bill of health/i)).toBeInTheDocument();
  });

  /* The counts follow the toggle, because a chart that disagrees with the table under it is worse
     than no chart. */
  it('every panel recounts when the bucket changes', async () => {
    const user = userEvent.setup ? userEvent.setup() : userEvent;
    renderPage();
    await panel('Case Severity Mix');

    expect(panelNow('Case Severity Mix').querySelector('.ir-donut-total')).toHaveTextContent('2');

    await user.click(screen.getByRole('button', { name: /everything/i }));

    await waitFor(() =>
      expect(panelNow('Case Severity Mix').querySelector('.ir-donut-total')).toHaveTextContent('3'));
    expect(panelNow('Priority Case Sequence').querySelectorAll('.ir-titem')).toHaveLength(2);
  });
});
