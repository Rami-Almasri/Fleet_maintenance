// The per-vehicle Suggested Checks panel.
//
// These lock the RULES, not the layout, because the rules are the whole point of the feature — this
// panel replaced a fixed Battery/Fluids/Brakes row that appeared identically on every idle car:
//
//   · the post-idle checklist is NEVER offered as a tappable finding — it is an agenda, and
//     presenting it as tap-to-confirm is exactly the bug this feature exists to fix
//   · a suggestion states the evidence behind it (returned N×, last seen, usual gap) — no bare chips
//   · a fault with no catalog wording is shown but not selectable — the engine never invents a keyword
//   · the stalling counter-signal blames the workshop out loud instead of the car
//   · Arabic renders the Arabic keyword, not an English keyword inside an Arabic sentence
//   · nothing to say → nothing rendered (an empty panel reads as a broken one)
//
// Labels resolve against the REAL tables, so a dropped or mistyped key fails here rather than
// shipping a raw dot-path to an inspector's phone.

import { render, screen, waitFor } from '@testing-library/react';
import SuggestedChecks from './SuggestedChecks';

jest.mock('../../api/client', () => ({ get: jest.fn() }));

let mockLang = 'en';
jest.mock('../../i18n/I18nContext', () => {
  const { LABELS } = jest.requireActual('../../i18n/labels');
  const walk = (tree, p) => p.split('.').reduce((n, k) => (n == null ? undefined : n[k]), tree);
  const fill = (s, v) =>
    typeof s === 'string' && v ? s.replace(/\{(\w+)\}/g, (m, k) => (v[k] != null ? String(v[k]) : m)) : s;
  return {
    useI18n: () => ({
      lang: mockLang,
      t: (k, v) => fill(walk(LABELS[mockLang], k) ?? walk(LABELS.en, k) ?? k, v),
      tf: (k, fb, v) => fill(walk(LABELS[mockLang], k) ?? walk(LABELS.en, k) ?? fb, v),
      tp: (base, n, v) => {
        const cat = new Intl.PluralRules(mockLang === 'ar' ? 'ar' : 'en').select(n);
        const hit = walk(LABELS[mockLang], `${base}.${cat}`) ?? walk(LABELS[mockLang], `${base}.other`)
                 ?? walk(LABELS.en, `${base}.${cat}`) ?? walk(LABELS.en, `${base}.other`);
        return fill(hit ?? base, { n, ...v });
      },
    }),
  };
});

beforeEach(() => {
  mockLang = 'en';
});

const brakes = (over = {}) => ({
  group: 'recurring',
  category_key: 'brakes',
  category_label: 'Brakes',
  category_label_ar: 'الفرامل',
  chip: 'Brake noise (squeal / grind)',
  chip_ar: 'صوت غير طبيعي من الفرامل',
  selectable: true,
  picker_category: 'brakes',
  level: 'critical',
  promoted: true,
  episodes: 3,
  days_since_last: 52,
  avg_gap_days: 47,
  probability: null,
  reasons: [
    { code: 'repeat.returned', params: { episodes: 3 } },
    { code: 'repeat.last_seen', params: { days: 52 } },
    { code: 'repeat.usual_gap', params: { days: 47 } },
    { code: 'repeat.past_usual_gap', params: { days: 5 } },
  ],
  ...over,
});

const payload = (over = {}) => ({
  vehicle_id: 7,
  suggestions: [brakes()],
  checklist: null,
  fleet_pattern: { available: false, blocked_reason: 'no coverage', suggestions: [] },
  summary: { total: 1, promoted: 1, recurring: 1, forecast: 0, shown: 1, unplaceable: 0 },
  generated_at: '2026-08-02T10:00:00+00:00',
  ...over,
});

// `data` is passed directly throughout so these assert the RENDER rules; the lazy fetch is a
// separate concern and would only add an IntersectionObserver stub to every case.
const show = (data) => render(<SuggestedChecks vehicleId={7} data={data} onPick={jest.fn()} />);

describe('evidence, not bare chips', () => {
  it('states why a recurring fault is suggested', async () => {
    show(payload());

    expect(await screen.findByRole('button', { name: 'Brake noise (squeal / grind)' })).toBeInTheDocument();
    expect(screen.getByText(/Returned 3 times/)).toBeInTheDocument();
    expect(screen.getByText(/Last seen 52 days ago/)).toBeInTheDocument();
    expect(screen.getByText(/Usually returns every ~47 days/)).toBeInTheDocument();
  });

  it('separates the two evidence sources by name', async () => {
    show(payload({
      suggestions: [
        brakes(),
        {
          ...brakes({ group: 'forecast', category_key: 'oil_change', chip: 'Oil Change', chip_ar: 'تغيير زيت المحرك' }),
          reasons: [
            { code: 'forecast.condition_due', params: { condition: 'oil_change' } },
            { code: 'forecast.overdue_km', params: { km: 1240 } },
          ],
        },
      ],
    }));

    expect(await screen.findByText('Recurring history')).toBeInTheDocument();
    expect(screen.getByText('Forecast')).toBeInTheDocument();
    expect(screen.getByText(/Overdue by 1,240 km/)).toBeInTheDocument();
  });

  it('says where the whole panel came from', async () => {
    show(payload());
    expect(await screen.findByText(/own repair history and service forecast/i)).toBeInTheDocument();
  });
});

describe('the post-idle checklist is an agenda, never a finding', () => {
  const withChecklist = payload({
    suggestions: [],
    checklist: {
      items: ['Battery', 'Fluids', 'Brakes'],
      days: 24,
      reason: { code: 'checklist.post_idle', params: { days: 24 } },
      selectable: false,
    },
  });

  it('renders the checklist under its own heading', async () => {
    show(withChecklist);
    expect(await screen.findByText('Standard post-idle checklist')).toBeInTheDocument();
    expect(screen.getByText('Battery · Fluids · Brakes')).toBeInTheDocument();
  });

  it('says out loud that it is not a set of findings', async () => {
    show(withChecklist);
    expect(await screen.findByText(/not findings to confirm/i)).toBeInTheDocument();
  });

  // The regression that started this: the queue used to re-derive these three from the trigger rules
  // and print them as tap-to-confirm chips on every idle car.
  it('never renders a checklist item as a tappable chip', async () => {
    show(withChecklist);
    await screen.findByText('Standard post-idle checklist');

    for (const item of ['Battery', 'Fluids', 'Brakes']) {
      expect(screen.queryByRole('button', { name: item })).not.toBeInTheDocument();
    }
  });
});

describe('vocabulary — the engine never invents a keyword', () => {
  it('shows a category-only fault but does not offer it as a finding', async () => {
    show(payload({
      suggestions: [brakes({ chip: null, chip_ar: null, selectable: false })],
    }));

    // Named, so the inspector knows what to look at…
    expect(await screen.findByText('Brakes')).toBeInTheDocument();
    // …but not selectable as a finding, because the catalog has no keyword for this car's wording.
    expect(screen.queryByRole('button', { name: 'Brakes' })).not.toBeInTheDocument();
    // It still leads somewhere: open the picker filtered to that category and let a person choose.
    expect(screen.getByRole('button', { name: /Open Brakes checks/ })).toBeInTheDocument();
  });

  it('reports repeat faults it could not place instead of dropping them silently', async () => {
    show(payload({ summary: { ...payload().summary, unplaceable: 2 } }));
    expect(await screen.findByText(/2 more repeat faults have no catalog wording/)).toBeInTheDocument();
  });
});

// Two episodes is ONE observed gap, and a fault silent for many intervals has stopped rather than
// become overdue. Both were shipping as confident "Due now" badges (93 and 56 live cases) until the
// engine started guarding them — these lock the wording that replaced them.
describe('weak evidence is stated as weak', () => {
  it('does not call a single observed gap a pattern', async () => {
    show(payload({
      suggestions: [brakes({
        episodes: 2,
        gap_samples: 1,
        interval_measured: false,
        promoted: false,
        reasons: [
          { code: 'repeat.returned', params: { episodes: 2 } },
          { code: 'repeat.last_seen', params: { days: 116 } },
          { code: 'repeat.single_gap', params: { days: 10 } },
        ],
      })],
    }));

    expect(await screen.findByText(/The two visits were 10 days apart/)).toBeInTheDocument();
    expect(screen.queryByText(/Usually returns/)).not.toBeInTheDocument();
    expect(screen.queryByText(/past its usual return/)).not.toBeInTheDocument();
    expect(screen.queryByText('Due now')).not.toBeInTheDocument();
  });

  it('says a long-silent fault has stopped instead of shouting that it is overdue', async () => {
    show(payload({
      suggestions: [brakes({
        promoted: false,
        pattern_lapsed: true,
        reasons: [
          { code: 'repeat.returned', params: { episodes: 4 } },
          { code: 'repeat.usual_gap', params: { days: 20, samples: 3 } },
          { code: 'repeat.pattern_lapsed', params: { days: 99, times: 4 } },
        ],
      })],
    }));

    expect(await screen.findByText(/No return in 99 days — the pattern appears to have stopped/)).toBeInTheDocument();
    expect(screen.queryByText('Due now')).not.toBeInTheDocument();
  });
});

describe('counter-signals and emphasis', () => {
  it('flags a suggestion that is past its own return interval', async () => {
    show(payload());
    expect(await screen.findByText('Due now')).toBeInTheDocument();
    expect(screen.getByText(/5 days past its usual return/)).toBeInTheDocument();
  });

  it('blames a slow workshop rather than the car', async () => {
    show(payload({
      suggestions: [brakes({
        promoted: false,
        reasons: [
          { code: 'repeat.returned', params: { episodes: 3 } },
          { code: 'repeat.stalling', params: { garage: 'Al Noor', visits: 3, days: 9 } },
        ],
      })],
    }));

    expect(await screen.findByText(/likely a slow repair, not a failing car/)).toBeInTheDocument();
    expect(screen.queryByText('Due now')).not.toBeInTheDocument();
  });

  it('marks a row both sources agreed on', async () => {
    show(payload({ suggestions: [brakes({ also_group: 'forecast' })] }));
    expect(await screen.findByText('history + forecast')).toBeInTheDocument();
  });
});

describe('Arabic', () => {
  it('renders the Arabic keyword and the Arabic evidence sentence', async () => {
    mockLang = 'ar';
    show(payload());

    expect(await screen.findByRole('button', { name: 'صوت غير طبيعي من الفرامل' })).toBeInTheDocument();
    expect(screen.getByText(/تكرر 3 مرات/)).toBeInTheDocument();
    expect(screen.getByText('مستحق الآن')).toBeInTheDocument();
  });

  it('falls back to the English keyword rather than blanking when Arabic is missing', async () => {
    mockLang = 'ar';
    show(payload({ suggestions: [brakes({ chip_ar: null })] }));

    expect(await screen.findByRole('button', { name: 'Brake noise (squeal / grind)' })).toBeInTheDocument();
  });
});

describe('silence', () => {
  it('renders nothing visible when the car has nothing to flag', async () => {
    const { container } = show(payload({ suggestions: [], checklist: null }));

    await waitFor(() => {
      expect(screen.queryByText('Suggested checks for this car')).not.toBeInTheDocument();
    });
    expect(container.textContent).toBe('');
  });

  it('renders nothing before its data arrives', () => {
    const { container } = render(<SuggestedChecks vehicleId={7} />);
    expect(container.textContent).toBe('');
  });
});
