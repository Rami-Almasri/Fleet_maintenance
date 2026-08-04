// The Oil Mileage Follow-up page, tested as a WORKFLOW rather than as markup.
//
// The loop this page closes is: a car goes out → the projection drifts past the oil limit → someone
// calls the customer → the number they read off the dash is typed in → the projection re-anchors and
// the next check moves. These tests assert the parts of that loop a person depends on: that the queue
// says WHY a car is on it, that a car we cannot project is never given an invented number, that
// saving a reading shows the recalculated verdict (not just "saved"), and that a rejected reading
// surfaces the API's reason instead of a generic failure.

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

jest.mock('../../api/client', () => ({ get: jest.fn(), post: jest.fn() }));
jest.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ can: () => true, canAny: () => true, hasRole: () => false, roles: [], permissions: [], isSuperAdmin: true }),
}));

// One car past its limit, one comfortably inside it, one with no handover reading to project from.
const QUEUE = {
  contracts: [
    {
      contract_id: 91, contract_no: 'C-9001', customer: 'Hazem Ali',
      vehicle_id: 5, plate: 'K 81836', car: 'JEEP CHEROKEE', out_date: '2026-07-30',
      projection: {
        status: 'chase_due', expected: 7500, threshold: 7000, km_to_threshold: -500,
        days_elapsed: 5, anchor_odometer: 6500, anchor_on: '2026-07-30', anchor_source: 'handover',
        reading_id: null, rate: 200, grace: 500, breach_on: '2026-08-02',
        key: 'oil_projection:91:start',
      },
    },
    {
      contract_id: 92, contract_no: 'C-9002', customer: 'Dima Nasser',
      vehicle_id: 6, plate: 'F 21099', car: 'CHEVROLET CAMARO', out_date: '2026-08-02',
      projection: {
        status: 'ok', expected: 6900, threshold: 7500, km_to_threshold: 600,
        days_elapsed: 2, anchor_odometer: 6500, anchor_on: '2026-08-02', anchor_source: 'handover',
        reading_id: null, rate: 200, grace: 500, breach_on: '2026-08-07',
        key: 'oil_projection:92:start',
      },
    },
    {
      contract_id: 93, contract_no: 'C-9003', customer: 'Yousef Karim',
      vehicle_id: 7, plate: 'J 17096', car: 'FORD MUSTANG', out_date: '2026-07-01',
      projection: {
        status: 'no_data', expected: null, threshold: 7500, km_to_threshold: null,
        days_elapsed: null, anchor_odometer: null, anchor_on: null, anchor_source: null,
        reading_id: null, rate: 200, grace: 500, breach_on: null, key: null,
      },
    },
  ],
  summary: { chase_due: 1, ok: 1, no_data: 1, total: 3 },
  model: {
    rate_km_per_day: 200,
    grace_km: 500,
    basis: 'expected = anchor odometer + days since anchor × rate; limit = last service odometer + interval (Oil Change sheet) + grace',
  },
};

const DETAIL = {
  contract_id: 91,
  projection: QUEUE.contracts[0].projection,
  readings: [{ id: 4, odometer: 6800, reported_on: '2026-08-01', source: 'customer_reported', reported_by: 'Customer', note: null }],
};

// After the customer reports 7,000 km the car is back inside the limit and the anchor has moved.
const RECALCULATED = {
  status: 'ok', expected: 7000, threshold: 7500, km_to_threshold: 500,
  days_elapsed: 0, anchor_odometer: 7000, anchor_on: '2026-08-04', anchor_source: 'reading',
  reading_id: 9, rate: 200, grace: 500, breach_on: '2026-08-07', key: 'oil_projection:91:r9',
};

beforeEach(() => {
  jest.clearAllMocks();
  api.get.mockImplementation((url) => {
    if (url === '/OilProjection') return Promise.resolve({ data: { data: QUEUE } });
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

/** The queue opens on the only thing that needs a human: the cars past their limit. */
test('the queue opens on the cars that need a call', async () => {
  await load();

  expect(await screen.findByText('K 81836')).toBeInTheDocument();
  // The within-limit and un-projectable cars are filtered out of the default view.
  expect(screen.queryByText('F 21099')).not.toBeInTheDocument();
  expect(screen.queryByText('J 17096')).not.toBeInTheDocument();
});

/**
 * THE LOAD-BEARING ASSERTION for the table: a row must say why it is here, in the language of the
 * person about to make the call. A queue that shows a number without a reason gets ignored.
 */
test('each row explains why the car is on the list, with the numbers behind it', async () => {
  await load();

  expect(await screen.findByText(/Out 5 days/)).toBeInTheDocument();
  expect(screen.getByText(/500 km past the 7,000 km oil limit/)).toBeInTheDocument();
  expect(screen.getByText('7,500 km')).toBeInTheDocument();   // projected now
  expect(screen.getByText('500 km over')).toBeInTheDocument(); // margin
});

/** A car we cannot project must never be given an invented figure — it says so plainly. */
test('a car with no handover reading is reported as unprojectable, not estimated', async () => {
  await load();
  fireEvent.click(await screen.findByText(/Can’t project \(1\)/));

  const plate = await screen.findByText('J 17096');
  expect(screen.getByText(/we can’t work out where it is now/)).toBeInTheDocument();

  // Scoped to the row, because "Can’t project" is also a metric-card label and a filter chip.
  const row = within(plate.closest('tr'));
  expect(row.getByText('Can’t project')).toBeInTheDocument();
  // The oil LIMIT is still known and shown (7,500 km) — it comes off the sheet, not the projection.
  expect(row.getByText('7,500 km')).toBeInTheDocument();
  // But no margin is invented, because there is nothing to measure the distance from.
  expect(row.queryByText(/km (over|left)/)).not.toBeInTheDocument();
});

/** A car inside its limit reports the margin and when it will next be looked at. */
test('a car within the limit shows its remaining margin and next check', async () => {
  await load();
  fireEvent.click(await screen.findByText(/Within limit \(1\)/));

  expect(await screen.findByText('F 21099')).toBeInTheDocument();
  expect(screen.getByText('600 km left')).toBeInTheDocument();
  expect(screen.getByText(/Next check around/)).toBeInTheDocument();
});

/** Opening a contract shows what was reported last time, so the caller isn't typing blind. */
test('opening a contract loads its previous readings', async () => {
  await load();
  fireEvent.click(await screen.findByText('Enter reading'));

  await waitFor(() => expect(api.get).toHaveBeenCalledWith('/Contract/91/oil-projection'));
  expect(await screen.findByText('6,800 km')).toBeInTheDocument();
});

/**
 * THE WHOLE POINT OF THE LOOP. Saving the number the customer gave must post it against the
 * contract and then show the RECALCULATED verdict — the anchor has moved, so the answer changed.
 * "Saved" on its own would leave the caller not knowing whether the car still needs an oil change.
 */
test('entering the reported mileage re-anchors the projection and shows the new verdict', async () => {
  api.post.mockResolvedValue({ data: { data: { reading: { id: 9 }, projection: RECALCULATED } } });
  await load();

  fireEvent.click(await screen.findByText('Enter reading'));
  fireEvent.change(await screen.findByLabelText(/Odometer reported by the customer/), { target: { value: '7000' } });
  fireEvent.click(screen.getByText('Save reading'));

  await waitFor(() => expect(api.post).toHaveBeenCalledWith('/Contract/91/mileage-reading', {
    odometer: 7000, reported_by: null, note: null,
  }));

  const recalculated = await screen.findByText('Recalculated');
  const panel = recalculated.parentElement;
  // Back inside the limit, measured from the number the customer actually gave.
  expect(within(panel).getByText(/500 km before the 7,500 km oil limit/)).toBeInTheDocument();
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

/** Traceability: the page states the arithmetic and where the oil limit came from. */
test('the page declares how every number was derived', async () => {
  await load();
  expect(await screen.findByText(/Data origin:/)).toBeInTheDocument();
  expect(screen.getByText(/anchor odometer \+ days since anchor × rate/)).toBeInTheDocument();
  expect(screen.getByText(/never change the car’s odometer/)).toBeInTheDocument();
});

/** The reason sentence is the page's editorial contract — asserted directly, free of rendering. */
describe('reasonFor', () => {
  test('an unprojectable car blames the missing handover reading', () => {
    expect(reasonFor({ status: 'no_data' })).toMatch(/No mileage was recorded when this car went out/);
  });

  test('a single day out is not pluralised', () => {
    const s = reasonFor({ status: 'chase_due', days_elapsed: 1, expected: 7600, threshold: 7000 });
    expect(s).toMatch(/Out 1 day —/);
  });

  test('it never reports a negative overshoot', () => {
    const s = reasonFor({ status: 'chase_due', days_elapsed: 3, expected: 7000, threshold: 7000 });
    expect(s).toMatch(/0 km past/);
  });
});
