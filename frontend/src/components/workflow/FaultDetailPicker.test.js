// The detail step: pick a fault → say how many and where → see the sentence it will be filed as.
//
// WHAT THESE DEFEND. The editor must be driven entirely by the catalog it is handed — the policy map
// decides which faults get a row, the vocabulary decides what places exist. Nothing here may know
// what a scratch is, so every test below drives it with a made-up type as well as a real one.

import { render, screen, fireEvent, within } from '@testing-library/react';
import FaultDetailPicker from './FaultDetailPicker';

jest.mock('../../i18n/I18nContext', () => {
  const { LABELS } = jest.requireActual('../../i18n/labels');
  const walk = (p) => p.split('.').reduce((n, k) => (n == null ? undefined : n[k]), LABELS.en);
  const fill = (s, v) => (v ? s.replace(/\{(\w+)\}/g, (m, key) => (v[key] != null ? String(v[key]) : m)) : s);
  return {
    useI18n: () => ({
      lang: 'en',
      t: (k, v) => {
        const hit = walk(k);
        return typeof hit === 'string' ? fill(hit, v) : k;
      },
      tf: (k, fallback, v) => {
        const hit = walk(k);
        return fill(typeof hit === 'string' ? hit : fallback, v);
      },
    }),
  };
});

const GROUPS = [
  {
    key: 'exterior',
    label: 'Exterior',
    locations: [
      { key: 'front_bumper', label: 'Front Bumper', aliases: ['bumper'] },
      { key: 'hood', label: 'Hood', aliases: [] },
      { key: 'body', label: 'Body', aliases: [] },
    ],
  },
  {
    key: 'wheels',
    label: 'Wheels',
    locations: [{ key: 'rims', label: 'Rims', aliases: ['rim'] }],
  },
];

const POLICY = {
  Scratch: 'required',
  'Oil leak': 'optional',
  Overheating: 'none',
  // A type nobody has written code for. It must behave exactly like the real ones.
  'Invented fault 2027': 'required',
};

// Drives the component the way the modal does: it owns `value`, the picker reports the next one.
function Harness({ symptoms, policy = POLICY, initial = {}, onValue = () => {} }) {
  const [value, setValue] = require('react').useState(initial);
  return (
    <FaultDetailPicker
      symptoms={symptoms}
      groups={GROUPS}
      policy={policy}
      value={value}
      maxQuantity={40}
      onChange={(next) => { setValue(next); onValue(next); }}
    />
  );
}

const header = (symptom) => screen.getByText(symptom, { selector: 'p' });
const rowFor = (symptom) => header(symptom).closest('div.rounded-xl');

describe('which faults get a row', () => {
  it('gives a row to every fault that has a place on the car', () => {
    render(<Harness symptoms={['Scratch', 'Oil leak']} />);
    expect(header('Scratch')).toBeInTheDocument();
    expect(header('Oil leak')).toBeInTheDocument();
  });

  // The wiper/overheating case: a fault with nowhere to point at must not show an unanswerable box.
  it('skips a fault with nowhere to point at, and says so rather than hiding it', () => {
    render(<Harness symptoms={['Scratch', 'Overheating']} />);
    expect(header('Scratch')).toBeInTheDocument();
    expect(screen.queryByText('Overheating', { selector: 'p.text-sm' })).not.toBeInTheDocument();
    expect(screen.getByText(/No location needed for: Overheating/)).toBeInTheDocument();
  });

  it('says nothing is locatable when none of the picks has a place', () => {
    render(<Harness symptoms={['Overheating']} />);
    expect(screen.getByText(/None of the selected faults has a place/)).toBeInTheDocument();
  });

  it('prompts when no fault is picked at all', () => {
    render(<Harness symptoms={[]} />);
    expect(screen.getByText(/Pick a fault above/)).toBeInTheDocument();
  });

  // The property the whole design rests on.
  it('handles a fault type that did not exist when this component was written', () => {
    render(<Harness symptoms={['Invented fault 2027']} />);
    expect(header('Invented fault 2027')).toBeInTheDocument();
    expect(within(rowFor('Invented fault 2027')).getByText('Invented fault 2027', { selector: 'strong' })).toBeInTheDocument();
  });
});

describe('picking where', () => {
  it('records a location and reports it upward', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: /Exterior/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Front Bumper' }));

    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 1, locations: ['front_bumper'] } });
  });

  it('keeps several places in the order they were picked', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: /Wheels/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Rims' }));
    fireEvent.click(screen.getByRole('button', { name: /Exterior/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Body' }));

    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 1, locations: ['rims', 'body'] } });
  });

  it('un-picks a place that was tapped twice', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} initial={{ Scratch: { quantity: 1, locations: ['rims'] } }} onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: /Wheels/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Rims' }));

    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 1, locations: [] } });
  });

  it('searches the vocabulary by name and by alias', () => {
    render(<Harness symptoms={['Scratch']} />);
    const search = screen.getByLabelText(/Search locations/);

    fireEvent.change(search, { target: { value: 'rim' } });
    expect(screen.getByRole('button', { name: 'Rims' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hood' })).not.toBeInTheDocument();

    fireEvent.change(search, { target: { value: 'bumper' } });
    expect(screen.getByRole('button', { name: 'Front Bumper' })).toBeInTheDocument();

    fireEvent.change(search, { target: { value: 'nowhere' } });
    expect(screen.getByText(/No location is named/)).toBeInTheDocument();
  });
});

describe('how many', () => {
  it('steps the quantity up and down, floored at one', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: 'How many +' }));
    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 2, locations: [] } });

    fireEvent.click(screen.getByRole('button', { name: 'How many −' }));
    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 1, locations: [] } });

    // Already at 1 — the decrement is unavailable rather than producing a zero-occurrence fault.
    expect(screen.getByRole('button', { name: 'How many −' })).toBeDisabled();
  });

  it('clamps a typed quantity to the catalog maximum', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} onValue={onValue} />);

    fireEvent.change(screen.getByLabelText('How many'), { target: { value: '900' } });
    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 40, locations: [] } });
  });

  it('keeps the places when the count changes', () => {
    const onValue = jest.fn();
    render(<Harness symptoms={['Scratch']} initial={{ Scratch: { quantity: 1, locations: ['rims'] } }} onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: 'How many +' }));
    expect(onValue).toHaveBeenLastCalledWith({ Scratch: { quantity: 2, locations: ['rims'] } });
  });
});

describe('the preview', () => {
  it('shows the sentence the fault will actually be filed as', () => {
    render(<Harness symptoms={['Scratch']} initial={{ Scratch: { quantity: 2, locations: ['rims', 'body'] } }} />);
    expect(within(rowFor('Scratch')).getByText('2 scratches — Rims and Body')).toBeInTheDocument();
  });

  it('reads as the plain fault before anything is detailed', () => {
    render(<Harness symptoms={['Scratch']} />);
    expect(within(rowFor('Scratch')).getByText('Scratch', { selector: 'strong' })).toBeInTheDocument();
  });

  it('updates as places are tapped', () => {
    render(<Harness symptoms={['Scratch']} />);
    fireEvent.click(screen.getByRole('button', { name: /Exterior/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Hood' }));
    expect(within(rowFor('Scratch')).getByText('Scratch — Hood')).toBeInTheDocument();
  });
});

describe('the required-location prompt', () => {
  it('flags a required fault that has no place yet', () => {
    render(<Harness symptoms={['Scratch']} />);
    expect(within(rowFor('Scratch')).getByText(/cannot be filed without a location/)).toBeInTheDocument();
  });

  it('clears the flag once a place is picked', () => {
    render(<Harness symptoms={['Scratch']} />);
    fireEvent.click(screen.getByRole('button', { name: /Exterior/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Hood' }));
    expect(within(rowFor('Scratch')).queryByText(/cannot be filed without a location/)).not.toBeInTheDocument();
  });

  it('never demands a place for an optional fault', () => {
    render(<Harness symptoms={['Oil leak']} />);
    expect(within(rowFor('Oil leak')).queryByText(/cannot be filed without a location/)).not.toBeInTheDocument();
  });

  // An unknown type (a custom issue an inspector typed) gets the editor but is never blocked by it.
  it('offers the editor for an unknown type without demanding an answer', () => {
    render(<Harness symptoms={['something nobody catalogued']} />);
    expect(header('something nobody catalogued')).toBeInTheDocument();
    expect(screen.queryByText(/cannot be filed without a location/)).not.toBeInTheDocument();
  });
});
