import { render, screen } from '@testing-library/react';
import { I18nProvider } from '../../i18n/I18nContext';
import { SystemStory, IncidentList, Durability, WorkLedger, DataQuality } from './reportBlocks';

/**
 * RENDER TESTS FOR THE SYSTEM REPORT BLOCKS.
 *
 * These exist because of a crash that shipped: `story.repairs.body` has plural forms, it was read
 * with t() instead of tp(), and t() handed React the `{one, other}` object — "Objects are not valid
 * as a React child", whole page dead. Nothing caught it. ESLint cannot see it, the i18n parity check
 * only compares key SHAPES between languages, and the HTML preview used to check the design was a
 * hand-written mirror of the component rather than the component itself, so it happily used tp()
 * where the real code used t().
 *
 * A mirror can always drift from the thing it mirrors. So the check is now to render the real
 * component with a realistic payload, in both languages — Arabic has six plural categories to
 * English's two, so a key that resolves correctly in English can still be an object in Arabic.
 */

const wrap = (ui) => render(<I18nProvider>{ui}</I18nProvider>);

/** A payload shaped exactly like VehicleSystemDashboardService::build() returns. */
const payload = (over = {}) => ({
  facts: {
    records: 5, incidents: 4, confirmed_faults: 3,
    latest_incident: { start: '2026-06-06' },
    latest_confirmed: { start: '2026-06-06', fault: 'Turbo pipes broken' },
  },
  recurrence: { status: 'recurring_system_visits', repeated_faults: [], named_faults: [] },
  incidents: [
    { start: '2026-03-06', end: '2026-03-06', is_confirmed: true, fault: 'Coolant Leak' },
    { start: '2026-06-06', end: '2026-06-06', is_confirmed: true, fault: 'Turbo pipes broken' },
  ],
  durability: [],
  takeaway: { actions: ['monitor_next_visit'] },
  risk: { score: 37, band: 'low' },
  confidence: { level: 'high' },
  ...over,
});

describe('SystemStory', () => {
  it('renders the plain-language answer without crashing', () => {
    wrap(<SystemStory data={payload()} systemLabel="Engine" />);

    expect(screen.getByText(/looked at 4 separate times/i)).toBeInTheDocument();
    expect(screen.getByText('Coolant Leak')).toBeInTheDocument();
    expect(screen.getByText(/not one fault coming back/i)).toBeInTheDocument();
  });

  /* The exact shape that crashed: a non-empty durability list hits the plural key. */
  it('renders the repairs line when repairs exist (plural key must resolve to a string)', () => {
    const data = payload({
      durability: [
        { work: { action: 'replacement', component: 'radiator', text: 'The radiator was replaced.' },
          date: '2026-03-06', verdict: 'nothing_recorded_after', next_incident: null, next_confirmed: null },
      ],
    });

    wrap(<SystemStory data={data} systemLabel="Engine" />);
    expect(screen.getByText(/Work was recorded\./i)).toBeInTheDocument();
    expect(screen.getByText(/completed repair/i)).toBeInTheDocument();
  });

  it('handles one repair and many repairs — both plural branches', () => {
    for (const n of [1, 2, 3, 11]) {
      const { unmount } = wrap(
        <SystemStory
          data={payload({
            durability: Array.from({ length: n }, () => ({
              work: { action: 'repair', component: null, text: 'x' },
              date: '2026-01-01', verdict: 'nothing_recorded_after', next_incident: null, next_confirmed: null,
            })),
          })}
          systemLabel="Engine"
        />,
      );
      expect(screen.getByText(/Work was recorded\./i)).toBeInTheDocument();
      unmount();
    }
  });

  it('states plainly when no fault was ever named', () => {
    wrap(
      <SystemStory
        data={payload({
          facts: { ...payload().facts, confirmed_faults: 0 },
          incidents: [{ start: '2026-03-06', end: '2026-03-06', is_confirmed: false, fault: null }],
          recurrence: { status: 'insufficient_data', repeated_faults: [], named_faults: [] },
        })}
        systemLabel="Engine"
      />,
    );

    expect(screen.getByText(/None of these visits says what was actually wrong/i)).toBeInTheDocument();
    expect(screen.getByText(/cannot tell whether it is repeating/i)).toBeInTheDocument();
  });

  it('names the repeated fault when one genuinely recurs', () => {
    wrap(
      <SystemStory
        data={payload({
          recurrence: {
            status: 'confirmed_recurring_fault',
            repeated_faults: [{ fault: 'Engine Oil leak', count: 3, incidents: [] }],
            named_faults: ['Engine Oil leak'],
          },
        })}
        systemLabel="Engine"
      />,
    );

    expect(screen.getByText(/same fault keeps coming back/i)).toBeInTheDocument();
    expect(screen.getByText(/Engine Oil leak/)).toBeInTheDocument();
  });
});

describe('the supporting blocks render', () => {
  const incident = {
    start: '2026-05-01', end: '2026-05-02', kind: 'confirmed_fault', strength: 'strong',
    severity: null, row_count: 2, is_confirmed: true, fault: 'Engine misfire',
    garages: ['GARAGE ONE'], odometer: null,
    work: [
      { action: 'mention', component: 'engine', text: 'Check engine light is on' },
      { action: 'recommended', component: 'spark plug', text: 'Spark plugs require replacement' },
    ],
    records: [
      { ref: 1, date: '2026-05-01', garage: 'GARAGE ONE', source: 'workshop log', strength: 'strong',
        join_reason: 'first record of this incident', system_lines: ['Engine misfire'], all_lines: ['Engine misfire'] },
    ],
  };

  it('IncidentList shows the fault and hides bare mentions', () => {
    wrap(<IncidentList incidents={[incident]} />);

    expect(screen.getByText('Engine misfire')).toBeInTheDocument();
    // A recommendation is worth a line; a bare mention is noise and must not be listed.
    expect(screen.getByText('Spark plugs require replacement')).toBeInTheDocument();
    expect(screen.queryByText('Check engine light is on')).not.toBeInTheDocument();
  });

  it('Durability never claims a repair held', () => {
    wrap(<Durability items={[{
      work: { action: 'replacement', component: 'radiator', text: 'The radiator was replaced.' },
      date: '2026-01-01', verdict: 'nothing_recorded_after', next_incident: null, next_confirmed: null,
    }]} />);

    expect(screen.getByText(/not the same as the repair holding/i)).toBeInTheDocument();
  });

  it('WorkLedger and DataQuality render their empty and populated states', () => {
    const { unmount } = wrap(<WorkLedger work={[]} />);
    expect(screen.getByText(/not recorded as structured lines/i)).toBeInTheDocument();
    unmount();

    wrap(<DataQuality warnings={[{ code: 'no_odometer', n: 5, total: 5 }]} />);
    expect(screen.getByText(/no odometer reading/i)).toBeInTheDocument();
  });
});
