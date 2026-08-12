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
const LIMITS = {
  oil_limit: 7500, tolerance: 500, allowed_max: 8000, threshold: 8000, rate: 200, grace: 500,
  oil_service_odometer: null, oil_service_ahead_km: null, oil_service_state: null,
};

const QUEUE = {
  contracts: [
    {
      // Five days still to run — 7,600 now becomes 8,600 by the time it is back. Cannot be absorbed.
      contract_id: 91, contract_no: 'C-9001', customer: 'Hazem Ali',
      vehicle_id: 5, plate: 'K 81836', car: 'JEEP CHEROKEE', out_date: '2026-07-30',
      projection: {
        ...LIMITS, status: 'ok', expected: 7600, km_to_threshold: 400,
        days_elapsed: 0, anchor_odometer: 7600, anchor_on: '2026-08-05', anchor_source: 'reading',
        handover_odometer: 6500, handover_on: '2026-07-30',
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
      customer_no: '5121', customer_phone: '0501234567', customer_whatsapp: '0509999999',
      vehicle_id: 9, plate: 'B 55510', car: 'TOYOTA COROLLA', out_date: '2026-07-28',
      projection: {
        ...LIMITS, status: 'chase_due', expected: 8200, km_to_threshold: -200,
        days_elapsed: 8, anchor_odometer: 6600, anchor_on: '2026-07-28', anchor_source: 'handover',
        handover_odometer: 6600, handover_on: '2026-07-28',
        reading_id: null, breach_on: '2026-08-04', key: 'oil_projection:95:start',
        oil_status: 'decision_required', return_due_on: '2026-08-27', remaining_days: 22,
        return_date_known: true, expected_return: 12600, over_tolerance_km: 4600, decision: null,
        decision_ready: false,
      },
    },
    {
      // Due back TODAY on a stale handover number. THE case the lane routing exists for: the
      // customer is already returning the car, so this is an arrival to catch — never a call.
      contract_id: 96, contract_no: 'C-9006', customer: 'Mona Adel',
      vehicle_id: 10, plate: 'M 70707', car: 'CITROEN C4', out_date: '2026-06-11',
      projection: {
        ...LIMITS, status: 'chase_due', expected: 18600, km_to_threshold: -10600,
        days_elapsed: 60, anchor_odometer: 6600, anchor_on: '2026-06-11', anchor_source: 'handover',
        handover_odometer: 6600, handover_on: '2026-06-11',
        reading_id: null, breach_on: '2026-06-18', key: 'oil_projection:96:start',
        oil_status: 'decision_required', return_due_on: '2026-08-10', remaining_days: 0,
        return_date_known: true, expected_return: 18600, over_tolerance_km: 10600, decision: null,
        decision_ready: false, lane: 'service_on_return',
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
let queue = QUEUE;

/**
 * The board as it looks once contract 91 has been recalled and the relay is at `stage`.
 *
 * The relay block is the backend's own `projection.decision.recall` payload — the page derives no
 * stage of its own, so a fixture is simply that block as the API serves it.
 */
const recalledQueue = (stage, extra = {}) => ({
  ...QUEUE,
  contracts: QUEUE.contracts.map((c) => (c.contract_id !== 91 ? c : {
    ...c,
    projection: {
      ...c.projection,
      oil_status: 'recall_required',
      decision: {
        id: 7, decision: 'recall', decided_by: 'Marwa', settled_at: null,
        recall: {
          decision_id: 7,
          stage,
          owner: { waiting_sales: 'sales', ready_for_driver: 'supervisor' }[stage] || 'driver',
          awaiting_sales: stage === 'waiting_sales',
          in_our_custody: ['vehicle_collected', 'at_workshop'].includes(stage),
          sales: stage === 'waiting_sales'
            ? { confirmed: false, confirmed_at: null, confirmed_by: null, note: null }
            : { confirmed: true, confirmed_at: '2026-08-11T09:00:00+00:00', confirmed_by: 'Marwa', note: 'Thursday morning' },
          collection: stage === 'waiting_sales' ? null : {
            task_id: 44, status: 'dispatched', phase: 'Awaiting driver',
            driver: null, claimed: false, active: true, destination: 'Workshop',
          },
          required_actions: {
            oil_change: { required: true, locked: true, reason: 'oil_projection_recall' },
            test: { required: false, locked: false, decided: false },
          },
          inspection_ticket_id: 500,
          service_ticket_id: null,
          ...extra,
        },
      },
    },
  })),
});

beforeEach(() => {
  jest.clearAllMocks();
  recallTasks = RECALL_TASKS;
  queue = QUEUE;
  api.get.mockImplementation((url) => {
    if (url === '/OilProjection') return Promise.resolve({ data: { data: queue } });
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

/** The queue opens on the calls to make — cars still out for days on a stale number. */
test('the queue opens on the customers to call', async () => {
  recallTasks = [];   // this test is about the board; the recall queue lists plates too
  await load();

  expect(await screen.findByText('B 55510')).toBeInTheDocument();
  // Everything with a different primary action lives in its own lane, not here: the answerable
  // decision, the car arriving today, the two safe cars, and the one we can't project.
  expect(screen.queryByText('K 81836')).not.toBeInTheDocument();
  expect(screen.queryByText('M 70707')).not.toBeInTheDocument();
  expect(screen.queryByText('F 21099')).not.toBeInTheDocument();
  expect(screen.queryByText('D 40021')).not.toBeInTheDocument();
  expect(screen.queryByText('J 17096')).not.toBeInTheDocument();
});

/**
 * THE RULE THIS REDESIGN EXISTS FOR: a car due back TODAY is never asked for by phone — the
 * customer is already bringing it back. It is routed to Service on return with arrival steps.
 */
test('a car due back today is routed to Service on return, never Call customer', async () => {
  recallTasks = [];
  await load();

  // Not in the default Call customer lane…
  await screen.findByText('B 55510');
  expect(screen.queryByText('M 70707')).not.toBeInTheDocument();

  // …but in Service on return, with the arrival checklist and no phone-call action.
  await chip(/Service on return \(1\)/);
  const card = within((await screen.findByText('M 70707')).closest('[data-card]'));
  expect(card.getByText('Service on return')).toBeInTheDocument();
  expect(card.getByText('Due back today')).toBeInTheDocument();
  expect(card.getByText(/they are already returning the car/)).toBeInTheDocument();
  expect(card.getByText('Read the actual odometer as soon as it arrives')).toBeInTheDocument();
  expect(card.queryByText(/Call customer/)).not.toBeInTheDocument();
  expect(card.queryByText('Recall now')).not.toBeInTheDocument();
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
  await chip(/Call customer \(1\)/);

  const card = within((await screen.findByText('B 55510')).closest('[data-card]'));
  expect(card.getByText('Call customer')).toBeInTheDocument();
  expect(card.getByText(/No customer reading yet — estimate runs from handover/)).toBeInTheDocument();
  expect(card.getByText(/No decision until a fresh number is in/)).toBeInTheDocument();

  // The two answers are withheld until someone has a real figure to answer on.
  expect(card.queryByText('Recall now')).not.toBeInTheDocument();
  expect(card.queryByText('Do it on return')).not.toBeInTheDocument();
  expect(card.getByText('Enter reading')).toBeInTheDocument();
});

/**
 * THE LOAD-BEARING ASSERTION for the board: a card must say why it is here, in the language of the
 * person about to make the call, with the numbers the call is actually made on.
 */
test('a card that needs a decision states where it lands, what it is allowed, and how long is left', async () => {
  recallTasks = [];   // scope the assertions to the board
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  expect(card.getByText('Action required')).toBeInTheDocument();
  expect(card.getByText('8,600 km')).toBeInTheDocument();          // where it comes back (Return stat)
  expect(card.getByText('8,000 km')).toBeInTheDocument();          // the hard maximum
  expect(card.getByText('7,500 km')).toBeInTheDocument();          // the oil limit itself
  expect(card.getByText('600 km over grace')).toBeInTheDocument();
  expect(card.getByText('5 days left')).toBeInTheDocument();
  expect(card.getByText(/Fresh customer reading — 7,600 km/)).toBeInTheDocument();
  // Both answers are on offer, because the number is real and fresh.
  expect(card.getByText('Recall now')).toBeInTheDocument();
  expect(card.getByText('Do it on return')).toBeInTheDocument();
});

/**
 * THE RULE THIS CHANGE EXISTS FOR. A car already past its oil limit, with two days left, lands on
 * exactly its allowance. It is NOT a decision, it is not recalled, and it is not interrupted — it is
 * an oil change booked for the day it comes back, and the row says so without offering a choice.
 */
test('a car that finishes inside the allowance is booked for service, never turned into a decision', async () => {
  await load();
  await chip(/Safe \/ scheduled \(2\)/);

  const card = within((await screen.findByText('F 21099')).closest('[data-card]'));
  expect(card.getByText('Safe / scheduled')).toBeInTheDocument();
  expect(card.getByText('0 km inside grace')).toBeInTheDocument();
  expect(card.getByText(/finishes inside grace/)).toBeInTheDocument();

  // The two answers are not on offer, because there is no question.
  expect(card.queryByText('Recall now')).not.toBeInTheDocument();
  expect(card.queryByText('Do it on return')).not.toBeInTheDocument();
});

/** A car nowhere near its oil point is reported as exactly that. */
test('a car still short of its oil point is left alone', async () => {
  await load();
  await chip(/Safe \/ scheduled \(2\)/);

  const card = within((await screen.findByText('D 40021')).closest('[data-card]'));
  expect(card.getByText('Safe / scheduled')).toBeInTheDocument();
  expect(card.getByText(/Comes back before the oil point/)).toBeInTheDocument();
  expect(card.getByText('1,600 km inside grace')).toBeInTheDocument();
});

/** A car we cannot project must never be given an invented figure — it says so plainly. */
test('a car with no handover reading is reported as unprojectable, not estimated', async () => {
  await load();
  await chip(/Can’t project \(1\)/);

  const card = within((await screen.findByText('J 17096')).closest('[data-card]'));
  expect(card.getByText('Can’t project')).toBeInTheDocument();
  expect(card.getByText(/No mileage recorded at handover/)).toBeInTheDocument();
  expect(card.getByText('no return date on the contract')).toBeInTheDocument();
  // Nothing is invented: no landing figure, no margin, no decision.
  expect(card.queryByText(/km (over|inside) grace/)).not.toBeInTheDocument();
  expect(card.queryByText('Recall now')).not.toBeInTheDocument();
  expect(card.queryByText('Enter reading')).not.toBeInTheDocument();
});

/**
 * Recalling a paying customer's car is confirmed, not fired from a table button — and the confirmation
 * restates the figures it is being done on, because that is what the person is answering for.
 */
test('recalling a car confirms against the figures before it is recorded', async () => {
  api.post.mockResolvedValue({ data: { data: { decision: { id: 1 }, projection: {} } } });
  recallTasks = [];
  await load();
  await chip(/Action required \(1\)/);

  fireEvent.click(await screen.findByText('Recall now'));

  expect(await screen.findByText(/Recall now — K 81836/)).toBeInTheDocument();
  expect(screen.getByText(/600 km past what this car is allowed to run/)).toBeInTheDocument();
  // The consequences are spelled out BEFORE the confirm — including the one that is NOT going to
  // happen yet: no driver is dispatched until Sales confirm the customer agreed to give the car back.
  expect(screen.getByText(/ask Sales to agree the return with the customer/)).toBeInTheDocument();
  expect(screen.getByText(/Notify no driver yet/)).toBeInTheDocument();
  expect(screen.getByText(/Waleed and Abdullah to arrange a driver/)).toBeInTheDocument();

  // Confirm from the dialog footer (the table button carries the same words).
  fireEvent.click(screen.getAllByText('Recall now').at(-1));

  // The two choices ride along with the decision. Nothing is pending on this car, so the test is
  // off and the garage is the default lane — both stated on the dialog before it was confirmed.
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/oil-decision', {
    decision: 'recall', note: null, test_required: false, service_location: 'garage',
  }));
});

/**
 * THE SALES GATE. A recalled car is not "being collected" — it is waiting for somebody to agree the
 * return with the customer, and the card must say so and offer exactly one way forward.
 */
test('a recalled car waits on Sales and offers the confirmation as the only action', async () => {
  recallTasks = [];
  queue = recalledQueue('waiting_sales');
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));

  expect(card.getByText(/Waiting for Sales OK/)).toBeInTheDocument();
  expect(card.getByText(/nobody is dispatched until this is pressed/)).toBeInTheDocument();
  // WHO acts now, called out on its own — the one line somebody reads if they read nothing else.
  expect(card.getByText('Sales must agree the return with the customer')).toBeInTheDocument();
  // The mandatory half is visible from the first stage, and is not a control at all.
  expect(card.getByText('Oil change')).toBeInTheDocument();
  expect(card.queryByLabelText('Oil change — required')).not.toBeInTheDocument();

  api.post.mockResolvedValue({ data: { data: {} } });
  fireEvent.click(card.getByText('Sales OK — customer confirmed'));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith(
    '/Contract/91/oil-recall/sales-confirm', {},
  ));
});

/** After Sales OK the card stops asking and starts reporting: who confirmed, and where the car is. */
test('a confirmed recall reports the confirmation and the collection instead of the gate', async () => {
  recallTasks = [];
  queue = recalledQueue('ready_for_driver');
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));

  expect(card.queryByText('Sales OK — customer confirmed')).not.toBeInTheDocument();
  // The same three facts, now read as a labelled list instead of a pile of sentences.
  expect(card.getByText(/Confirmed/)).toBeInTheDocument();
  expect(card.getByText(/Marwa/)).toBeInTheDocument();
  expect(card.getByText(/nobody has claimed it yet/)).toBeInTheDocument();
  expect(card.getByText(/Awaiting driver · #44/)).toBeInTheDocument();
  expect(card.getByText('Waleed / Abdullah arrange a driver')).toBeInTheDocument();
});

/** The test is the only instruction on offer — and posting it never mentions the oil change. */
test('asking for a test posts only the test flag', async () => {
  recallTasks = [];
  queue = recalledQueue('ready_for_driver');
  api.post.mockResolvedValue({ data: { data: {} } });
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  fireEvent.click(card.getByLabelText('Test / Inspection'));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith(
    '/Contract/91/oil-recall/instructions', { test_required: true },
  ));
  // Nothing about the oil change is sendable — it is not a field on this form.
  expect(api.post.mock.calls.every(([, body]) => !('oil_change_required' in (body || {})))).toBe(true);
});

/**
 * THE TWO CHOICES ON A RECALL — is this car also being tested, and where is the oil changed?
 *
 * The first exists because the system has usually already flagged the car ("routine check overdue")
 * and that card is sitting in the review queue. Filing a second one for the same car is how a queue
 * stops being believed, so the dialog says so and offers to add the oil change to it.
 */
test('a recall offers to add the oil change to the test request the system already raised', async () => {
  recallTasks = [];
  queue = {
    ...QUEUE,
    contracts: QUEUE.contracts.map((c) => (c.contract_id !== 91 ? c : {
      ...c,
      pending_test_request: {
        id: 812, requested_at: '2026-08-11T10:30:00+00:00', trigger_reason: 'test_drive',
        request_origin: 'system_schedule',
        reason: 'Routine check overdue — 15 days since last maintenance completion.',
      },
    })),
  };
  api.post.mockResolvedValue({ data: { data: {} } });
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  fireEvent.click(card.getByText('Recall now'));

  // The card the fleet already has is named, quoted, and offered — not silently duplicated.
  expect(await screen.findByText(/The system has already asked for a test on this car/)).toBeInTheDocument();
  expect(screen.getByText(/Routine check overdue/)).toBeInTheDocument();
  // Said twice on purpose: beside the choice, and again in the "this will:" consequences.
  expect(screen.getAllByText(/no second card/i).length).toBeGreaterThanOrEqual(2);
  expect(screen.getByText(/Add the oil change to the test request already waiting \(#812\)/)).toBeInTheDocument();

  // Ticked by default: dropping a check the system asked for is a decision, not an omission.
  const useExisting = screen.getByRole('checkbox', { name: /Do the test too/ });
  expect(useExisting).toBeChecked();

  fireEvent.click(screen.getAllByText('Recall now').at(-1));
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/oil-decision', {
    decision: 'recall', note: null, test_required: true, service_location: 'garage',
  }));
});

test('declining the test and choosing the parking routes the job to Abu Maroof', async () => {
  recallTasks = [];
  api.post.mockResolvedValue({ data: { data: {} } });
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  fireEvent.click(card.getByText('Recall now'));

  // Nothing pending on this car, so the test is opt-IN and off by default.
  const alsoTest = await screen.findByRole('checkbox', { name: /Test the car as well/ });
  expect(alsoTest).not.toBeChecked();

  fireEvent.click(screen.getByRole('radio', { name: /In our parking/ }));
  // The consequence is stated before the confirm — who is told, and where the car goes.
  expect(screen.getByText(/Abu Maroof is told to do the change/)).toBeInTheDocument();
  expect(screen.getByText(/File no inspection request/)).toBeInTheDocument();

  fireEvent.click(screen.getAllByText('Recall now').at(-1));
  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/oil-decision', {
    decision: 'recall', note: null, test_required: false, service_location: 'parking',
  }));
});

/**
 * THE FAR END — recording the change that ends the follow-up.
 *
 * The offer is gated on CUSTODY, not on the recall existing: while the car is still at the
 * customer's there is no dash to read, and a button there would invite a number nobody observed.
 */
test('the oil change can only be recorded once the car is actually with us', async () => {
  recallTasks = [];
  queue = recalledQueue('ready_for_driver');   // Sales agreed, but a driver is still being arranged
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  expect(card.queryByText('Oil changed')).not.toBeInTheDocument();
});

test('recording the oil change posts the reading and reports the car’s new service point', async () => {
  recallTasks = [];
  queue = recalledQueue('vehicle_collected');
  // The card carries the car's own interval, so the dialog can show what the reading will set.
  queue.contracts = queue.contracts.map((c) => (c.contract_id !== 91 ? c
    : { ...c, last_service_odometer: 500, service_interval_km: 7000 }));
  api.post.mockResolvedValue({
    data: { data: { vehicle: {
      last_service_odometer: 20000, service_interval_km: 7000,
      next_service_odometer: 27000, allowed_max: 27500,
    } } },
  });
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  fireEvent.click(card.getByText('Oil changed'));

  // Before saving: what this number is about to do to the car.
  fireEvent.change(await screen.findByLabelText(/Odometer at the oil change/), { target: { value: '20000' } });
  expect(screen.getByText(/Next oil change will be set to/)).toBeInTheDocument();
  expect(screen.getByText('27,000 km')).toBeInTheDocument();

  fireEvent.click(screen.getByText('Record the oil change'));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith(
    '/Contract/91/oil-change-done', { odometer: 20000, note: null },
  ));

  // After saving: the car's own schedule, and the grace stated as the BOARD's, not the car's.
  expect(await screen.findByText(/This car’s schedule now reads/)).toBeInTheDocument();
  expect(screen.getByText('27,500 km')).toBeInTheDocument();
});

/** The other answer: accept the overrun, keep the customer moving, service it at close. */
test('accepting the overrun records the decision with the reason given', async () => {
  api.post.mockResolvedValue({ data: { data: { decision: { id: 2 }, projection: {} } } });
  await load();
  await chip(/Action required \(1\)/);

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

  await screen.findByText('B 55510');
  expect(screen.queryByText(/Recalls to arrange/)).not.toBeInTheDocument();
});

/** The four lanes are stated on the page, not left to be inferred from badge colours. */
test('the page spells out the four lanes and what each one means', async () => {
  await load();

  expect(await screen.findByText(/Phone for the real odometer before anything is decided/)).toBeInTheDocument();
  expect(screen.getByText(/read the actual odometer when it arrives, check the oil/)).toBeInTheDocument();
  expect(screen.getByText(/Cannot finish inside the allowance on a fresh reading/)).toBeInTheDocument();
  expect(screen.getByText(/raised automatically as soon as the car\s+is back/)).toBeInTheDocument();
});

/** Opening a contract shows what was reported last time, so the caller isn't typing blind. */
test('opening a contract loads its previous readings', async () => {
  await load();
  await chip(/Action required \(1\)/);
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
  api.post.mockResolvedValue({ data: { data: {
    reading: { id: 12, odometer: 7200, reported_on: '2026-08-05', reported_by: 'Customer (phone)' },
    projection: RECALCULATED,
  } } });
  await load();
  await chip(/Action required \(1\)/);

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

  // EVERY connected number in the dialog moves with the new anchor, not just the verdict panel:
  // the saved reading tops the history list, and the Expected-today reference is the new figure.
  expect(screen.getAllByText('7,200 km').length).toBeGreaterThanOrEqual(2);
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

/**
 * The evidence terminology contract: the handover reading is never dressed up as the latest
 * reading, and each stat names its source.
 */
test('the card separates the latest known odometer from the handover reading', async () => {
  recallTasks = [];
  await load();
  await chip(/Action required \(1\)/);

  const card = within((await screen.findByText('K 81836')).closest('[data-card]'));
  expect(card.getByText('Latest known odometer')).toBeInTheDocument();
  expect(card.getByText('Handover reading')).toBeInTheDocument();
  expect(card.getByText('6,500 km')).toBeInTheDocument();                  // the handover, under its own name
  expect(card.getByText('05 Aug 2026 · customer reading')).toBeInTheDocument(); // the anchor names its source
  expect(screen.queryByText('Last real reading')).not.toBeInTheDocument(); // the misleading label is gone
});

/** A plausible mid-rental oil service is information, not an alarm. */
test('a plausible mid-rental oil service is explained without alarming anyone', async () => {
  const alt = JSON.parse(JSON.stringify(QUEUE));
  const row = alt.contracts.find((r) => r.plate === 'B 55510');
  row.projection.oil_service_odometer = 7000;
  row.projection.oil_service_ahead_km = 400;
  row.projection.oil_service_state = 'mid_rental_service';
  api.get.mockImplementation((url) => {
    if (url === '/OilProjection') return Promise.resolve({ data: { data: alt } });
    if (url === '/OilRecallTasks') return Promise.resolve({ data: { data: { tasks: [] } } });
    return Promise.resolve({ data: { data: null } });
  });
  await load();
  await chip(/Call customer \(1\)/);

  const card = within((await screen.findByText('B 55510')).closest('[data-card]'));
  expect(card.getByText('Mid-rental oil service')).toBeInTheDocument();
  expect(card.getByText(/a mid-rental service, not an error/)).toBeInTheDocument();
  expect(card.queryByText('Odometer conflict')).not.toBeInTheDocument();
});

/** A suspicious oil-service reading is surfaced as a conflict — never silently trusted. */
test('a suspicious oil-service reading shows an odometer conflict warning', async () => {
  const alt = JSON.parse(JSON.stringify(QUEUE));
  const row = alt.contracts.find((r) => r.plate === 'B 55510');
  row.projection.oil_service_odometer = 30390;
  row.projection.oil_service_ahead_km = 23790;
  row.projection.oil_service_state = 'suspicious';
  api.get.mockImplementation((url) => {
    if (url === '/OilProjection') return Promise.resolve({ data: { data: alt } });
    if (url === '/OilRecallTasks') return Promise.resolve({ data: { data: { tasks: [] } } });
    return Promise.resolve({ data: { data: null } });
  });
  await load();
  await chip(/Call customer \(1\)/);

  const card = within((await screen.findByText('B 55510')).closest('[data-card]'));
  expect(card.getByText('Odometer conflict')).toBeInTheDocument();
  expect(card.getByText(/Verify the sheet row before relying on the oil limit/)).toBeInTheDocument();
  // The projection still runs from the dated anchor, and no decision is unlocked by a bad row.
  expect(card.queryByText('Recall now')).not.toBeInTheDocument();
});

/** Traceability: the page states the arithmetic and where every input came from. */
test('the page declares how every number was derived', async () => {
  await load();
  expect(await screen.findByText(/Data origin:/)).toBeInTheDocument();
  expect(screen.getByText(/expected on return = expected \+ remaining rental days × rate/)).toBeInTheDocument();
  expect(screen.getByText(/remaining days\s+come from the contract’s own duration/)).toBeInTheDocument();
  expect(screen.getByText(/never change the car’s odometer/)).toBeInTheDocument();
});

/**
 * MAKING THE CALLS. The cards are for deciding one car at a time; twenty-six phone calls is a
 * different job, and it needs the number on the screen. The call list is that job: the same lane,
 * flattened, with who to ring and under which account.
 */
test('the call list carries the car, the customer and the number to dial', async () => {
  recallTasks = [];
  await load();
  await chip(/Call customer \(1\)/);

  fireEvent.click(await screen.findByText(/Call list \(1\)/));

  const dialog = within(await screen.findByRole('dialog'));
  expect(dialog.getByText('TOYOTA COROLLA')).toBeInTheDocument();
  expect(dialog.getByText('B 55510')).toBeInTheDocument();
  expect(dialog.getByText('Samir Haddad')).toBeInTheDocument();
  expect(dialog.getByText('5121')).toBeInTheDocument();
  // The number is a link that dials, not text somebody has to copy out.
  expect(dialog.getByText('0501234567').closest('a')).toHaveAttribute('href', 'tel:0501234567');
});

/** The same list, written out — the caller can work off a phone or hand it to someone else. */
test('the call list downloads as a CSV of every row it shows', async () => {
  recallTasks = [];
  const blobs = [];
  const RealBlob = global.Blob;
  global.Blob = function (parts, opts) { blobs.push(parts.join('')); return new RealBlob(parts, opts); };
  global.URL.createObjectURL = jest.fn(() => 'blob:call-list');
  global.URL.revokeObjectURL = jest.fn();
  const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

  try {
    await load();
    await chip(/Call customer \(1\)/);
    fireEvent.click(await screen.findByText(/Call list \(1\)/));
    fireEvent.click(await screen.findByText('Download CSV'));

    expect(click).toHaveBeenCalled();
    const csv = blobs.at(-1);
    expect(csv).toContain('"Car","Plate","Customer","Phone","CX number","Contract"');
    expect(csv).toContain('"TOYOTA COROLLA","B 55510","Samir Haddad","0501234567","5121","C-9005"');
  } finally {
    global.Blob = RealBlob;
    click.mockRestore();
  }
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
