// The Save button answers "what do I still have to fill in?" on hover, by
// reading the form scope it sits in. See lib/formGuide.js.
import { fireEvent, render, screen } from '@testing-library/react';
import { I18nProvider } from '../../i18n/I18nContext';
import Button from './Button';
import { Input, Requirement, Select } from './Field';

function Form({ plate = '', odometer = '', garage = '', ...buttonProps }) {
  return (
    <I18nProvider>
      <div data-form-scope="">
        <Input label="Plate" required value={plate} onChange={() => {}} />
        <Input label="Odometer" type="number" required value={odometer} onChange={() => {}} />
        <Input label="Note" value="" onChange={() => {}} />
        <Select label="Garage" required value={garage} onChange={() => {}}>
          <option value="">—</option>
          <option value="g1">Al Quoz</option>
        </Select>
        <Button {...buttonProps}>Save</Button>
      </div>
    </I18nProvider>
  );
}

// The tooltip trigger is the wrapper span; a disabled button emits no mouse
// events of its own, which is exactly the case that needs explaining.
const hoverSave = () => fireEvent.mouseEnter(screen.getByRole('button', { name: 'Save' }).parentElement);

test('names every required field that is still empty', async () => {
  render(<Form />);
  hoverSave();
  expect(await screen.findByRole('tooltip')).toHaveTextContent('Still needed: Plate, Odometer and Garage');
});

test('names only what is left once some fields are answered', async () => {
  render(<Form plate="D-12345" garage="g1" />);
  hoverSave();
  expect(await screen.findByRole('tooltip')).toHaveTextContent('Still needed: Odometer');
});

test('says nothing when the form is complete', () => {
  render(<Form plate="D-12345" odometer="41200" garage="g1" />);
  hoverSave();
  expect(screen.queryByRole('tooltip')).toBeNull();
});

test('explains a disabled button too', async () => {
  render(<Form disabled />);
  hoverSave();
  expect(await screen.findByRole('tooltip')).toHaveTextContent('Still needed: Plate');
});

test('a custom hint shows alongside the missing fields', async () => {
  render(<Form plate="D-12345" odometer="41200" garage="g1" hint="Attach the garage quote before saving." />);
  hoverSave();
  expect(await screen.findByRole('tooltip')).toHaveTextContent('Attach the garage quote before saving.');
});

// The Pick up step: the odometer is typed in, the garage is preset by the supervisor, and the only
// thing left is a photo — which is a file, not a field, and so was invisible until it said so.
function PickUp({ photo = null }) {
  return (
    <I18nProvider>
      <div data-form-scope="">
        <Input label="Odometer reading (km)" type="number" required value="57476" onChange={() => {}} />
        <Requirement label="Odometer photo" value={photo} />
        <Button disabled={!photo}>Confirm pickup</Button>
      </div>
    </I18nProvider>
  );
}

test('names a requirement that no field holds', async () => {
  render(<PickUp />);
  fireEvent.mouseEnter(screen.getByRole('button', { name: 'Confirm pickup' }).parentElement);
  expect(await screen.findByRole('tooltip')).toHaveTextContent('Still needed: Odometer photo');
});

test('and stops naming it once it is attached', () => {
  render(<PickUp photo={{ name: 'odo.jpg' }} />);
  fireEvent.mouseEnter(screen.getByRole('button', { name: 'Confirm pickup' }).parentElement);
  expect(screen.queryByRole('tooltip')).toBeNull();
});

test('Cancel stays quiet — guidance belongs on the button that finishes the form', () => {
  render(
    <I18nProvider>
      <div data-form-scope="">
        <Input label="Plate" required value="" onChange={() => {}} />
        <Button variant="secondary">Cancel</Button>
      </div>
    </I18nProvider>
  );
  // No wrapper at all: an unguided, hintless button renders as a bare <button>.
  const cancel = screen.getByRole('button', { name: 'Cancel' });
  fireEvent.mouseEnter(cancel.parentElement);
  expect(screen.queryByRole('tooltip')).toBeNull();
});
