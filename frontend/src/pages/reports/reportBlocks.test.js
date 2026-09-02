import { fireEvent, render, screen, within } from '@testing-library/react';
import { I18nProvider } from '../../i18n/I18nContext';
import {
  SystemStory, IncidentList, Durability, WorkLedger, DataQuality,
  DateRangeFilter, PeriodBanner, PeriodProblems, PeriodSummary,
} from './reportBlocks';

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

/* ── THE DATE-RANGE FILTER AND THE PERIOD REPORT ───────────────────────────────────────────────
 *
 * The three ways a date filter misleads, each pinned here:
 *   1. it silently corrects an inverted range instead of refusing it;
 *   2. it prints a filtered report with no statement of which dates it covers;
 *   3. it calls a second visit to the same system a repeat of the first visit's fault.
 */

/** The brief's own worked example, in the shape VehicleSystemPeriodService returns. */
const engineNoise = {
  fault: 'Engine noise',
  fault_key: 'engine noise',
  occurrences: 2,
  repeated: true,
  first_seen: '2026-07-04',
  last_seen: '2026-07-28',
  span_days: 24,
  garages: ['7 CYLINDER', 'AL Muharik AL hakiki'],
  worst_severity: null,
  repairs_recorded: 1,
  followed_by: null,
  returned: { status: 'yes', days: 24, from: '2026-07-04', to: '2026-07-28', times: 2, after_repair: false },
  events: [
    {
      date: '2026-07-04', end: '2026-07-04', garage: '7 CYLINDER', garages: ['7 CYLINDER'], severity: null,
      note_lines: ['Engine noise / abnormal sound reported by the driver.'],
      all_note_lines: ['Engine noise / abnormal sound reported by the driver.'],
      action: 'inspection', action_text: 'The radiator was checked.', action_evidence: 'strong',
      row_count: 1, refs: [102], gap_days: null,
    },
    {
      date: '2026-07-28', end: '2026-07-28', garage: 'AL Muharik AL hakiki', garages: ['AL Muharik AL hakiki'],
      severity: null,
      note_lines: ['Engine noise continued.'],
      all_note_lines: ['Engine noise continued.'],
      action: 'repair', action_text: 'The engine mounting was repaired.', action_evidence: 'strong',
      row_count: 1, refs: [103], gap_days: 24,
    },
  ],
};

const workshopOnlyVisit = {
  date: '2026-05-10', end: '2026-05-10', garage: 'Deals On Wheels auto', garages: ['Deals On Wheels auto'],
  severity: null, kind: 'workshop_mention', strength: 'medium',
  note_lines: ['Engine cover fasteners/clips have been secured.'],
  action: 'repair', action_text: 'Engine cover fasteners/clips have been secured.', action_evidence: 'strong',
  row_count: 1, refs: [101], gap_days: null,
};

const periodSummary = {
  system_visits: 3, named_faults: 1, named_fault_visits: 2, repeated_faults: 1,
  repairs: 2, workshop_only_visits: 1, source_records: 3,
  first_record: '2026-05-10', last_record: '2026-07-28', scope: 'period',
};

describe('DateRangeFilter', () => {
  const filtered = { from: '2026-05-01', to: '2026-07-31' };

  it('shows the applied range and commits it on Apply, not on every keystroke', () => {
    const onApply = jest.fn();
    wrap(<DateRangeFilter {...filtered} onApply={onApply} onClear={() => {}} />);

    expect(screen.getByLabelText(/^from$/i)).toHaveValue('2026-05-01');
    expect(screen.getByLabelText(/^to$/i)).toHaveValue('2026-07-31');

    fireEvent.change(screen.getByLabelText(/^from$/i), { target: { value: '2026-06-01' } });
    expect(onApply).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /apply/i }));
    expect(onApply).toHaveBeenCalledWith('2026-06-01', '2026-07-31');
  });

  /* Clearing must return to ALL HISTORY — nulls, not empty strings that would ride into the URL. */
  it('Clear returns the report to all history', () => {
    const onClear = jest.fn();
    wrap(<DateRangeFilter {...filtered} onApply={() => {}} onClear={onClear} />);

    fireEvent.click(screen.getByRole('button', { name: /^clear$/i }));
    expect(onClear).toHaveBeenCalled();
  });

  /* THE SILENT SWAP, refused. An inverted range is reported and Apply is unavailable — never
     reordered behind the reader's back into a period they did not choose. */
  it('refuses an inverted range instead of swapping the dates', () => {
    const onApply = jest.fn();
    wrap(<DateRangeFilter from="2026-07-31" to="2026-05-01" onApply={onApply} onClear={() => {}} />);

    expect(screen.getByRole('alert')).toHaveTextContent(/cannot be earlier than its start/i);
    expect(screen.getByRole('button', { name: /apply/i })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: /apply/i }));
    expect(onApply).not.toHaveBeenCalled();
  });

  /* The URL is the source of truth: a shared link or the back button arrives as new props. */
  it('follows the applied range when it changes underneath it', () => {
    const { rerender } = wrap(<DateRangeFilter from="2026-05-01" to="2026-07-31" onApply={() => {}} onClear={() => {}} />);

    rerender(
      <I18nProvider>
        <DateRangeFilter from="2026-01-01" to={null} onApply={() => {}} onClear={() => {}} />
      </I18nProvider>,
    );

    expect(screen.getByLabelText(/^from$/i)).toHaveValue('2026-01-01');
    expect(screen.getByLabelText(/^to$/i)).toHaveValue('');
  });

  it('the quick ranges fill the same two fields', () => {
    const onApply = jest.fn();
    wrap(<DateRangeFilter from={null} to={null} onApply={onApply} onClear={() => {}} />);

    fireEvent.click(screen.getByRole('button', { name: /this year/i }));
    const year = new Date().getFullYear();
    expect(onApply).toHaveBeenCalledWith(`${year}-01-01`, `${year}-12-31`);
  });
});

describe('PeriodBanner', () => {
  /* Every printed report states its own scope. This one is NOT ir-no-print, on purpose. */
  it('prints the selected dates and is not hidden from print', () => {
    const { container } = wrap(
      <PeriodBanner period={{ from: '2026-05-01', to: '2026-07-31', active: true, shape: 'between', covered_from: '2026-05-10', covered_to: '2026-07-28' }} />,
    );

    expect(container.querySelector('.ir-period-term')).toHaveTextContent(/period/i);
    // Both ends of the range are on the printed line — a half-stated period is a wrong one.
    expect(container.querySelector('.ir-period-value')).toHaveTextContent(/2026.*→.*2026/);
    expect(container.querySelector('.ir-period-banner')).not.toHaveClass('ir-no-print');
  });

  it('says All history when no filter is applied', () => {
    wrap(<PeriodBanner period={{ from: null, to: null, active: false, shape: 'all_history' }} />);
    expect(screen.getByText(/all history/i)).toBeInTheDocument();
  });
});

describe('PeriodSummary', () => {
  /* THE CONFLATION, refused: three visits, one named problem, one repeat — three separate tiles. */
  it('keeps visits, named problems and repeats as separate counts', () => {
    const { container } = wrap(
      <PeriodSummary summary={periodSummary} period={{ active: true, shape: 'between' }} />,
    );

    const tiles = [...container.querySelectorAll('.ir-kpi')].map((el) => el.textContent);
    expect(tiles.some((txt) => /System visits/i.test(txt) && /3/.test(txt))).toBe(true);
    expect(tiles.some((txt) => /Named problems/i.test(txt) && /1/.test(txt))).toBe(true);
    expect(tiles.some((txt) => /Repeated problems/i.test(txt) && /1/.test(txt))).toBe(true);
    expect(tiles.some((txt) => /No problem named/i.test(txt))).toBe(true);

    expect(screen.getByText(/In the selected period the record shows/i)).toBeInTheDocument();
  });

  it('reads as all-history wording when no filter is applied', () => {
    wrap(<PeriodSummary summary={periodSummary} period={{ active: false, shape: 'all_history' }} />);
    expect(screen.getByText(/Across the whole record/i)).toBeInTheDocument();
  });
});

describe('PeriodProblems', () => {
  it('groups the repeated fault and states plainly that it came back', () => {
    wrap(<PeriodProblems problems={[engineNoise]} workshopOnly={[]} period={{ active: true }} />);

    expect(screen.getByRole('heading', { name: /Engine noise/ })).toBeInTheDocument();
    expect(screen.getByText(/2 occurrences/i)).toBeInTheDocument();
    expect(screen.getByText('Repeated')).toBeInTheDocument();
    expect(screen.getByText(/recorded again 24 days later/i)).toBeInTheDocument();
  });

  /* THE ORIGINAL NOTE, preserved next to the normalised name — never instead of it. */
  it('shows the workshop’s own words and the action recorded for each occurrence', () => {
    wrap(<PeriodProblems problems={[engineNoise]} workshopOnly={[]} period={{ active: true }} />);

    expect(screen.getByText('Engine noise / abnormal sound reported by the driver.')).toBeInTheDocument();
    expect(screen.getByText('Engine noise continued.')).toBeInTheDocument();
    expect(screen.getAllByText(/Inspected/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Repaired/).length).toBeGreaterThan(0);
    expect(screen.getByText('7 CYLINDER')).toBeInTheDocument();
    expect(screen.getByText('AL Muharik AL hakiki')).toBeInTheDocument();
  });

  it('a single occurrence is never presented as a recurrence', () => {
    const once = {
      ...engineNoise,
      occurrences: 1, repeated: false, last_seen: '2026-07-04', span_days: null,
      events: [engineNoise.events[0]],
      returned: { status: 'no', days: null, from: null, to: null, after_repair: false },
    };

    wrap(<PeriodProblems problems={[once]} workshopOnly={[]} period={{ active: true }} />);

    expect(screen.getByText(/1 occurrence/i)).toBeInTheDocument();
    expect(screen.queryByText('Repeated')).not.toBeInTheDocument();
    expect(screen.getByText(/recorded once during the selected period/i)).toBeInTheDocument();
  });

  /* A different fault afterwards is named as a different fault — not counted as this one returning. */
  it('names a following different fault without calling it a recurrence', () => {
    const once = {
      ...engineNoise,
      occurrences: 1, repeated: false, events: [engineNoise.events[0]],
      returned: { status: 'no', days: null, from: null, to: null, after_repair: false },
      followed_by: { date: '2026-07-28', fault: 'Coolant Leak' },
    };

    wrap(<PeriodProblems problems={[once]} workshopOnly={[]} period={{ active: true }} />);

    expect(screen.getByText(/not a recurrence of this one/i)).toBeInTheDocument();
    expect(screen.getByText(/Coolant Leak/)).toBeInTheDocument();
  });

  it('says when a fault returned AFTER work had been recorded', () => {
    const afterRepair = {
      ...engineNoise,
      returned: { ...engineNoise.returned, after_repair: true },
    };

    wrap(<PeriodProblems problems={[afterRepair]} workshopOnly={[]} period={{ active: true }} />);
    expect(screen.getByText(/Work had been recorded before it came back/i)).toBeInTheDocument();
  });

  /* Workshop-only visits are shown, and shown as VISITS — the gap between 3 visits and 1 problem. */
  it('lists visits that named no problem separately from the problems', () => {
    const { container } = wrap(
      <PeriodProblems problems={[engineNoise]} workshopOnly={[workshopOnlyVisit]} period={{ active: true }} />,
    );

    const only = container.querySelector('.ir-problem.is-workshop-only');
    expect(only).toBeTruthy();
    // Twice on purpose: once as the quoted note, once as the action the note was read as.
    expect(within(only).getAllByText(/Engine cover fasteners\/clips have been secured\./)).toHaveLength(2);
    expect(screen.getByText(/These are visits, not faults/i)).toBeInTheDocument();
  });

  it('an empty period says so instead of showing a reassuring blank', () => {
    wrap(<PeriodProblems problems={[]} workshopOnly={[]} period={{ active: true }} />);
    expect(screen.getByText(/No problem is recorded for this system during the selected period/i)).toBeInTheDocument();
  });
});

/*
 * ARABIC. The bug this whole file exists for was a plural key read with t() instead of tp(), which
 * handed React a {one, other} object and killed the page. Arabic has six plural categories to
 * English's two, so a key that happens to resolve in English can still be an object in Arabic — and
 * the period report adds five more count-bearing keys (occurrences, returnedYes, visitsNote,
 * namedNote, gap). Rendering every new block in Arabic is the only check that covers them.
 */
describe('the period blocks render in Arabic', () => {
  beforeEach(() => localStorage.setItem('fv:lang', 'ar'));
  afterEach(() => localStorage.setItem('fv:lang', 'en'));

  /** Every Arabic plural category, so no branch of a count key goes unrendered. */
  const AR_PLURAL_COUNTS = [0, 1, 2, 3, 11, 100];

  it('renders the summary, the banner and the problems without handing React an object', () => {
    for (const n of AR_PLURAL_COUNTS) {
      const { unmount, container } = wrap(
        <>
          <PeriodBanner period={{ from: '2026-05-01', to: '2026-07-31', active: true, shape: 'between', covered_from: '2026-05-10', covered_to: '2026-07-28' }} />
          <PeriodSummary
            summary={{ ...periodSummary, system_visits: n, source_records: n, named_fault_visits: n }}
            period={{ active: true, shape: 'between' }}
          />
          <PeriodProblems
            problems={[{
              ...engineNoise,
              occurrences: Math.max(n, 1),
              returned: { ...engineNoise.returned, days: n },
              events: engineNoise.events.map((e, i) => (i === 1 ? { ...e, gap_days: n } : e)),
            }]}
            workshopOnly={[workshopOnlyVisit]}
            period={{ active: true }}
          />
        </>,
      );

      // "[object Object]" is exactly what a plural key read with t() prints before it crashes.
      expect(container.textContent).not.toMatch(/\[object Object\]/);
      unmount();
    }
  });

  it('renders the date-range control in Arabic', () => {
    wrap(<DateRangeFilter from="2026-05-01" to="2026-07-31" onApply={() => {}} onClear={() => {}} />);

    // Dates stay LTR whatever the page direction — a date read right-to-left is a different date.
    expect(screen.getByLabelText('من')).toHaveValue('2026-05-01');
    expect(screen.getByRole('button', { name: 'تطبيق' })).toBeEnabled();
  });
});
