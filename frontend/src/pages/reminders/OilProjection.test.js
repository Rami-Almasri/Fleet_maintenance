// The Oil Mileage Follow-up page, tested as a WORKFLOW rather than as markup.
//
// The loop this page closes is: a car goes out → the projection drifts toward the oil limit → someone
// calls the customer → the number they read off the dash is typed in → and the system answers the only
// question that matters operationally: WILL THIS CAR STILL BE INSIDE ITS ALLOWANCE WHEN IT COMES BACK?
//
// Most cars will be, and are simply serviced on return with nobody interrupted. These tests assert the
// parts of that loop a person depends on: that a car finishing inside the allowance is never turned into
// a decision, that a car finishing outside it is — with the figures spelled out — that a car we cannot
// project is never given an invented number, and that saving a reading shows the recalculated verdict.

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import OilProjection, { reasonFor } from './OilProjection';
import { I18nProvider } from '../../i18n/I18nContext';
import { ToastProvider } from '../../components/ui/Toast';
import api from '../../api/client';

// react-router-dom v7 ships an `exports` map CRA's Jest resolver can't follow, so the real module is
// unreachable from a test. The page only uses <Link> for navigation, which a plain anchor models
// faithfully for these assertions. Virtual so the mock stands in even though resolution would fail.
jest.mock('react-router-dom', () => ({
  __esModule: true,
  Link: ({ to, children, ...rest }) => <a href={typeof to === 'string' ? to : '#'} {...rest}>{children}</a>,
}), { virtual: true });

jest.mock('../../api/client', () => ({ get: jest.fn(), post: jest.fn(), patch: jest.fn() }));
jest.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ can: () => true, canAny: () => true, hasRole: () => false, roles: [], permissions: [], isSuperAdmin: true }),
}));

// Every fixture uses the fleet's own worked example: a 7,500 km oil limit, a 500 km tolerance, and so
// an 8,000 km allowed maximum. What separates the cars is only how long each rental still has to run.
const LIMITS = { oil_limit: 7500, tolerance: 500, allowed_max: 8000, threshold: 8000, rate: 200, grace: 500 };

const QUEUE = {
  contracts: [
    {
      // Five days still to run — 7,600 now becomes 8,600 by the time it is back. Cannot be absorbed.
      contract_id: 91, contract_no: 'C-9001', customer: 'Hazem Ali',
      vehicle_id: 5, plate: 'K 81836', car: 'JEEP CHEROKEE', out_date: '2026-07-30',
      projection: {
        ...LIMITS, status: 'ok', expected: 7600, km_to_threshold: 400,
        days_elapsed: 0, anchor_odometer: 7600, anchor_on: '2026-08-05', anchor_source: 'reading',
        reading_id: 9, breach_on: '2026-08-07', key: 'oil_projection:91:r9',
        oil_status: 'decision_required', return_due_on: '2026-08-10', remaining_days: 5,
        return_date_known: true, expected_return: 8600, over_tolerance_km: 600, decision: null,
        decision_ready: true,
      },
    },
    {
      // The SAME reading with two days left. It lands on exactly 8,000 — the last kilometre it is
      // allowed — so the rental runs on and the oil change is booked for the return.
      contract_id: 92, contract_no: 'C-9002', customer: 'Dima Nasser',
      vehicle_id: 6, plate: 'F 21099', car: 'CHEVROLET CAMARO', out_date: '2026-08-02',
      projection: {
        ...LIMITS, status: 'ok', expected: 7600, km_to_threshold: 400,
        days_elapsed: 0, anchor_odometer: 7600, anchor_on: '2026-08-05', anchor_source: 'reading',
        reading_id: 11, breach_on: '2026-08-07', key: 'oil_projection:92:r11',
        oil_status: 'service_required_on_return', return_due_on: '2026-08-07', remaining_days: 2,
        return_date_known: true, expected_return: 8000, over_tolerance_km: 0, decision: null,
      },
    },
    {
      contract_id: 93, contract_no: 'C-9003', customer: 'Yousef Karim',
      vehicle_id: 7, plate: 'J 17096', car: 'FORD MUSTANG', out_date: '2026-07-01',
      projection: {
        ...LIMITS, status: 'no_data', expected: null, km_to_threshold: null,
        days_elapsed: null, anchor_odometer: null, anchor_on: null, anchor_source: null,
        reading_id: null, breach_on: null, key: null,
        oil_status: 'no_data', return_due_on: null, remaining_days: null,
        return_date_known: false, expected_return: null, over_tolerance_km: null, decision: null,
      },
    },
    {
      contract_id: 94, contract_no: 'C-9004', customer: 'Rana Odeh',
      vehicle_id: 8, plate: 'D 40021', car: 'NISSAN SUNNY', out_date: '2026-08-03',
      projection: {
        ...LIMITS, status: 'ok', expected: 6000, km_to_threshold: 2000,
        days_elapsed: 2, anchor_odometer: 5600, anchor_on: '2026-08-03', anchor_source: 'handover',
        reading_id: null, breach_on: '2026-08-15', key: 'oil_projection:94:start',
        oil_status: 'within_tolerance', return_due_on: '2026-08-07', remaining_days: 2,
        return_date_known: true, expected_return: 6400, over_tolerance_km: -1600, decision: null,
      },
    },
    {
      // A 30-day hire. It is arithmetically certain to bust its allowance — every long rental is —
      // but the only number we have is a 200 km/day guess from the handover reading. Nobody can
      // answer for this car until someone phones the customer.
      contract_id: 95, contract_no: 'C-9005', customer: 'Samir Haddad',
      vehicle_id: 9, plate: 'B 55510', car: 'TOYOTA COROLLA', out_date: '2026-07-28',
      projection: {
        ...LIMITS, status: 'chase_due', expected: 8200, km_to_threshold: -200,
        days_elapsed: 8, anchor_odometer: 6600, anchor_on: '2026-07-28', anchor_source: 'handover',
        reading_id: null, breach_on: '2026-08-04', key: 'oil_projection:95:start',
        oil_status: 'decision_required', return_due_on: '2026-08-27', remaining_days: 22,
        return_date_known: true, expected_return: 12600, over_tolerance_km: 4600, decision: null,
        decision_ready: false,
      },
    },
  ],
  summary: {
    chase_due: 1, ok: 3, no_data: 1, total: 5,
    decision_required: 1, awaiting_reading: 1, recall_required: 0,
    service_required_on_return: 1, within_tolerance: 1,
  },
  model: {
    rate_km_per_day: 200,
    grace_km: 500,
    tolerance_km: 500,
    basis: 'expected = anchor odometer + days since anchor × rate; oil limit = last service odometer + interval (Oil Change sheet); allowed max = oil limit + tolerance; expected on return = expected + remaining rental days × rate',
  },
};

const DETAIL = {
  contract_id: 91,
  projection: QUEUE.contracts[0].projection,
  readings: [{ id: 4, odometer: 6800, reported_on: '2026-08-01', source: 'customer_reported', reported_by: 'Customer', note: null }],
};

// The customer turns out to have driven far less than the model assumed: the car now finishes on
// exactly its allowance and the decision that was hanging over it disappears.
const RECALCULATED = {
  ...LIMITS, status: 'ok', expected: 7200, km_to_threshold: 800,
  days_elapsed: 0, anchor_odometer: 7200, anchor_on: '2026-08-05', anchor_source: 'reading',
  reading_id: 12, breach_on: '2026-08-09', key: 'oil_projection:91:r12',
  oil_status: 'service_required_on_return', return_due_on: '2026-08-10', remaining_days: 4,
  return_date_known: true, expected_return: 8000, over_tolerance_km: 0, decision: null,
};

// The call a recall produced: everything the controller has to say, frozen as of the decision.
const RECALL_TASKS = [{
  id: 3, status: 'open', reason_code: 'oil_tolerance_exceeded_before_return',
  contract_id: 91, contract_no: 'C-9001', customer: 'Hazem Ali',
  vehicle_id: 5, plate: 'K 81836', car: 'JEEP CHEROKEE',
  customer_reading: 7600, customer_reading_on: '2026-08-05',
  oil_limit: 7500, allowed_max: 8000, expected_return_odometer: 8600,
  over_tolerance_km: 600, remaining_days: 5,
  created_by: 'Marwa', decided_at: '2026-08-05 11:20:00',
  note: 'Customer is local; ask for Thursday.', outcome_note: null,
  claimed_by: null, completed_at: null,
}];

let recallTasks = RECALL_TASKS;

beforeEach(() => {
  jest.clearAllMocks();
  recallTasks = RECALL_TASKS;
  api.get.mockImplementation((url) => {
    if (url === '/OilProjection') return Promise.resolve({ data: { data: QUEUE } });
    if (url === '/OilRecallTasks') return Promise.resolve({ data: { data: { tasks: recallTasks, summary: { open: recallTasks.length, contacted: 0 } } } });
    if (url.includes('/oil-projection')) return Promise.resolve({ data: { data: DETAIL } });
    return Promise.resolve({ data: { data: null } });
  });
});

const load = async () => {
  render(
    <I18nProvider>
      <ToastProvider>
        <OilProjection />
      </ToastProvider>
    </I18nProvider>,
  );
  await waitFor(() => expect(screen.getByText('Oil Mileage Follow-up')).toBeInTheDocument());
};

const chip = async (label) => fireEvent.click(await screen.findByText(label));

/** The queue opens on the only thing that needs a human: the cars that can't finish inside the allowance. */
test('the queue opens on the cars that need a decision', async () => {
  recallTasks = [];   // this test is about the board; the recall queue lists plates too
  await load();

  expect(await screen.findByText('K 81836')).toBeInTheDocument();
  // Everything that resolves itself is filtered out of the default view — including the car that is
  // already past its oil limit but will still come back inside the allowance.
  expect(screen.queryByText('F 21099')).not.toBeInTheDocument();
  expect(screen.queryByText('D 40021')).not.toBeInTheDocument();
  expect(screen.queryByText('J 17096')).not.toBeInTheDocument();
  // …and so is the long hire that only LOOKS like a decision: it rests on an estimate.
  expect(screen.queryByText('B 55510')).not.toBeInTheDocument();
});

/**
 * THE GUARD THAT KEEPS THIS QUEUE WORTH READING. Every long rental is arithmetically certain to run
 * past its allowance, so counting the raw state would put most of the fleet under "Decision needed"
 * every morning and the board would be ignored inside a week. A decision resting on a 200 km/day
 * assumption is not a decision — it is a phone call, and the row says exactly that instead of
 * offering two buttons nobody should press yet.
 */
test('a car that only busts its allowance on an estimate is a phone call, not a decision', async () => {
  await load();
  await chip(/Needs a number first \(1\)/);

  const row = within((await screen.findByText('B 55510')).closest('tr'));
  expect(row.getByText('Needs a number first')).toBeInTheDocument();
  expect(row.getByText(/That is an estimate, not a reading — get the real number/)).toBeInTheDocument();

  // The two answers are withheld until someone has a real figure to answer on.
  expect(row.queryByText('Recall now')).not.toBeInTheDocument();
  expect(row.queryByText('Do it on return')).not.toBeInTheDocument();
  expect(row.getByText('Enter reading')).toBeInTheDocument();
});

/**
 * THE LOAD-BEARING ASSERTION for the table: a row must say why it is here, in the language of the
 * person about to make the call, with the three numbers the call is actually made on.
 */
test('a row that needs a decision states where it lands, what it is allowed, and how long is left', async () => {
  recallTasks = [];   // scope the assertions to the board table
  await load();

  const row = within((await screen.findByText('K 81836')).closest('tr'));
  expect(row.getByText('8,600 km')).toBeInTheDocument();          // where it comes back
  expect(row.getByText('8,000 km')).toBeInTheDocument();          // what it is allowed to reach
  expect(row.getByText('7,500 + 500')).toBeInTheDocument();       // and how that allowance is built
  expect(row.getByText('600 km over')).toBeInTheDocument();
  expect(row.getByText('5d to run')).toBeInTheDocument();
  expect(row.getByText('Decision needed')).toBeInTheDocument();
  expect(row.getByText(/600 km past the 8,000 km allowance/)).toBeInTheDocument();
});

/**
 * THE RULE THIS CHANGE EXISTS FOR. A car already past its oil limit, with two days left, lands on
 * exactly its allowance. It is NOT a decision, it is not recalled, and it is not interrupted — it is
 * an oil change booked for the day it comes back, and the row says so without offering a choice.
 */
test('a car that finishes inside the allowance is booked for service, never turned into a decision', async () => {
  await load();
  await chip(/On return \(1\)/);

  const row = within((await screen.findByText('F 21099')).closest('tr'));
  expect(row.getByText('Oil change on return')).toBeInTheDocument();
  expect(row.getByText('0 km spare')).toBeInTheDocument();
  expect(row.getByText(/Let the rental finish — the oil change is booked for the return/)).toBeInTheDocument();

  // The two answers are not on offer, because there is no question.
  expect(row.queryByText('Recall now')).not.toBeInTheDocument();
  expect(row.queryByText('Do it on return')).not.toBeInTheDocument();
});

/** A car nowhere near its oil point is reported as exactly that. */
test('a car still short of its oil point is left alone', async () => {
  await load();
  await chip(/Within tolerance \(1\)/);

  const row = within((await screen.findByText('D 40021')).closest('tr'));
  expect(row.getByText('Within tolerance')).toBeInTheDocument();
  expect(row.getByText(/still short of the 7,500 km oil point. Nothing to do/)).toBeInTheDocument();
});

/** A car we cannot project must never be given an invented figure — it says so plainly. */
test('a car with no handover reading is reported as unprojectable, not estimated', async () => {
  await load();
  await chip(/Can’t project \(1\)/);

  const plate = await screen.findByText('J 17096');
  expect(screen.getByText(/we can’t work out where it is now/)).toBeInTheDocument();

  const row = within(plate.closest('tr'));
  expect(row.getByText('Can’t project')).toBeInTheDocument();
  // Nothing is invented: no landing figure, no margin, no decision.
  expect(row.getByText('Not stated')).toBeInTheDocument();
  expect(row.queryByText(/km (over|spare)/)).not.toBeInTheDocument();
  expect(row.queryByText('Recall now')).not.toBeInTheDocument();
});

/**
 * Recalling a paying customer's car is confirmed, not fired from a table button — and the confirmation
 * restates the figures it is being done on, because that is what the person is answering for.
 */
test('recalling a car confirms against the figures before it is recorded', async () => {
  api.post.mockResolvedValue({ data: { data: { decision: { id: 1 }, projection: {} } } });
  recallTasks = [];
  await load();

  fireEvent.click(await screen.findByText('Recall now'));

  expect(await screen.findByText(/Recall now — K 81836/)).toBeInTheDocument();
  expect(screen.getByText(/600 km past what this car is allowed to run/)).toBeInTheDocument();
  // It promises a CALL, and is explicit that no vehicle movement is being booked.
  expect(screen.getByText(/contact the customer and agree a day to bring the car in/)).toBeInTheDocument();
  expect(screen.getByText(/No driver is dispatched and no collection is booked/)).toBeInTheDocument();

  // Confirm from the dialog footer (the table button carries the same words).
  fireEvent.click(screen.getAllByText('Recall now').at(-1));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/oil-decision', {
    decision: 'recall', note: null,
  }));
});

/** The other answer: accept the overrun, keep the customer moving, service it at close. */
test('accepting the overrun records the decision with the reason given', async () => {
  api.post.mockResolvedValue({ data: { data: { decision: { id: 2 }, projection: {} } } });
  await load();

  fireEvent.click(await screen.findByText('Do it on return'));
  fireEvent.change(await screen.findByLabelText(/Why/), { target: { value: 'Customer is mid-trip' } });
  fireEvent.click(screen.getAllByText('Do it on return').at(-1));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/oil-decision', {
    decision: 'defer', note: 'Customer is mid-trip',
  }));
});

/**
 * A RECALL IS A CONVERSATION, NOT A DISPATCH. The queue carries what the controller has to say on
 * the phone — the customer's own number, the two limits, where the car lands — and nothing about
 * routes, drivers or collection times, because none of that exists on this path.
 */
test('a recall shows up as a call to make, with the figures it is about', async () => {
  await load();

  expect(await screen.findByText('Recalls to arrange (1)')).toBeInTheDocument();
  const q = within(screen.getByText(/The oil tolerance will be exceeded/).closest('li'));

  expect(q.getByText('To call')).toBeInTheDocument();
  expect(q.getByText(/Hazem Ali · contract C-9001/)).toBeInTheDocument();
  expect(q.getByText(/The oil tolerance will be exceeded before the rental ends/)).toBeInTheDocument();
  expect(q.getByText(/Recalled by Marwa/)).toBeInTheDocument();
  expect(q.getByText(/Customer is local; ask for Thursday/)).toBeInTheDocument();

  // The frozen numbers the call is made on.
  const figures = q.getByText(/Customer reported/).textContent;
  expect(figures).toMatch(/7,600 km on/);
  expect(figures).toMatch(/oil limit 7,500 km/);
  expect(figures).toMatch(/allowed 8,000 km/);
  expect(figures).toMatch(/would return on 8,600 km \(600 km over\)/);
  expect(figures).toMatch(/5d left on the contract/);

  // Nothing pretends a vehicle movement has been arranged.
  expect(q.queryByText(/driver/i)).not.toBeInTheDocument();
  expect(q.queryByText(/dispatch/i)).not.toBeInTheDocument();
  expect(q.queryByText(/pick[- ]?up/i)).not.toBeInTheDocument();
});

/** The two moves a controller makes on the call. */
test('a controller can mark the customer contacted', async () => {
  api.patch.mockResolvedValue({ data: { data: { task: {} } } });
  await load();

  fireEvent.click(await screen.findByText('Customer contacted'));

  await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/OilRecallTasks/3', { status: 'contacted' }));
});

/** No recalls outstanding ⇒ no empty box taking up the screen. */
test('the recall queue is absent when there is nothing to call about', async () => {
  recallTasks = [];
  await load();

  await screen.findByText('K 81836');
  expect(screen.queryByText(/Recalls to arrange/)).not.toBeInTheDocument();
});

/** The three states are stated on the page, not left to be inferred from badge colours. */
test('the page spells out the three states and what each one means', async () => {
  await load();

  expect(await screen.findByText(/Comes back before it even reaches the oil point. No action./)).toBeInTheDocument();
  expect(screen.getByText(/No decision needed — the rental\s+continues/)).toBeInTheDocument();
  expect(screen.getByText(/Cannot finish inside the allowance/)).toBeInTheDocument();
});

/** Opening a contract shows what was reported last time, so the caller isn't typing blind. */
test('opening a contract loads its previous readings', async () => {
  await load();
  fireEvent.click(await screen.findByText('Enter reading'));

  await waitFor(() => expect(api.get).toHaveBeenCalledWith('/Contract/91/oil-projection'));
  expect(await screen.findByText('6,800 km')).toBeInTheDocument();
});

/**
 * THE WHOLE POINT OF THE LOOP. Saving the number the customer gave must post it against the contract
 * and then show the RECALCULATED verdict — before the caller puts the phone down. "Saved" on its own
 * would leave them not knowing whether they still have a decision to take.
 */
test('entering the reported mileage recalculates the verdict on the spot', async () => {
  api.post.mockResolvedValue({ data: { data: { reading: { id: 12 }, projection: RECALCULATED } } });
  await load();

  fireEvent.click(await screen.findByText('Enter reading'));
  fireEvent.change(await screen.findByLabelText(/Odometer reported by the customer/), { target: { value: '7200' } });
  fireEvent.click(screen.getByText('Save reading'));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/mileage-reading', {
    odometer: 7200, reported_by: null, note: null,
  }));

  const panel = (await screen.findByText('Recalculated')).parentElement;
  // The decision has gone: on the real number the car comes back inside its allowance.
  expect(within(panel).getByText(/inside the 8,000 km allowance/)).toBeInTheDocument();
  expect(within(panel).getByText(/oil change is booked for the return/)).toBeInTheDocument();
});

/** A reading that runs backwards is refused by the API; the caller sees its reason, not "failed". */
test('a rejected reading surfaces the API’s own explanation', async () => {
  api.post.mockRejectedValue({
    response: { data: { message: 'Mileage cannot go backwards — the last known reading is 6,800 km.' } },
  });
  await load();

  fireEvent.click(await screen.findByText('Enter reading'));
  fireEvent.change(await screen.findByLabelText(/Odometer reported by the customer/), { target: { value: '6000' } });
  fireEvent.click(screen.getByText('Save reading'));

  expect(await screen.findByText(/Mileage cannot go backwards/)).toBeInTheDocument();
});

/** Traceability: the page states the arithmetic and where every input came from. */
test('the page declares how every number was derived', async () => {
  await load();
  expect(await screen.findByText(/Data origin:/)).toBeInTheDocument();
  expect(screen.getByText(/expected on return = expected \+ remaining rental days × rate/)).toBeInTheDocument();
  expect(screen.getByText(/remaining days\s+come from the contract’s own duration/)).toBeInTheDocument();
  expect(screen.getByText(/never change the car’s odometer/)).toBeInTheDocument();
});

/** The verdict sentence is the page's editorial contract — asserted directly, free of rendering. */
describe('reasonFor', () => {
  const base = { oil_limit: 7500, tolerance: 500, allowed_max: 8000, return_date_known: true };

  test('an unprojectable car blames the missing handover reading', () => {
    expect(reasonFor({ status: 'no_data', oil_status: 'no_data' }))
      .toMatch(/No mileage was recorded when this car went out/);
  });

  const decidable = { ...base, oil_status: 'decision_required', decision_ready: true };

  test('a decision states the overrun, the allowance, and both answers', () => {
    const s = reasonFor({ ...decidable, expected: 7600, expected_return: 8600, over_tolerance_km: 600, remaining_days: 5 });
    expect(s).toMatch(/due back in 5 days/);
    expect(s).toMatch(/600 km past the 8,000 km allowance/);
    expect(s).toMatch(/Recall it now, or accept that/);
  });

  test('the same overrun on an estimate asks for a reading instead of a choice', () => {
    const s = reasonFor({ ...base, oil_status: 'decision_required', decision_ready: false, expected: 7600, expected_return: 8600, over_tolerance_km: 600, remaining_days: 5 });
    expect(s).toMatch(/600 km past the 8,000 km allowance/);
    expect(s).toMatch(/estimate, not a reading/);
    expect(s).not.toMatch(/Recall it now/);
  });

  test('a single remaining day is not pluralised', () => {
    const s = reasonFor({ ...decidable, expected: 7900, expected_return: 8100, over_tolerance_km: 100, remaining_days: 1 });
    expect(s).toMatch(/due back in 1 day —/);
  });

  test('it never reports a negative overshoot', () => {
    const s = reasonFor({ ...decidable, expected: 8000, expected_return: 8000, over_tolerance_km: -10, remaining_days: 0 });
    expect(s).toMatch(/0 km past/);
    expect(s).toMatch(/due back today/);
  });

  test('a contract with no duration says so rather than assuming the car is back today', () => {
    const s = reasonFor({ ...decidable, return_date_known: false, expected: 8600, expected_return: 8600, over_tolerance_km: 600, remaining_days: null });
    expect(s).toMatch(/no return date on the contract/);
  });

  test('a recall names who agreed it', () => {
    const s = reasonFor({ ...base, oil_status: 'recall_required', expected_return: 9600, decision: { decided_by: 'Marwa' } });
    expect(s).toMatch(/Recall agreed by Marwa/);
    expect(s).toMatch(/raised automatically once the car is back/);
  });
});
