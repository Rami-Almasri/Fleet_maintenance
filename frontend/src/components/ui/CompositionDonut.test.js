import { render, screen, fireEvent } from '@testing-library/react';
import CompositionDonut from './CompositionDonut';

/**
 * The donut's drill-down contract. Without `onSelect` the chart must stay exactly as inert as it
 * always was — it is shared by several read-only cards — and with it, a legend row must hand back
 * the segment that was clicked.
 */
const SEGMENTS = [
  { key: 'Unspecified', label: 'Unspecified', value: 30, color: '#94a3b8' },
  { key: 'Engine', label: 'Engine', value: 4, color: '#2a78d6' },
  {
    key: '__other',
    label: 'Other (2 types)',
    value: 3,
    color: '#64748b',
    children: [
      { key: 'Flat Tire', label: 'Flat Tire', value: 2 },
      { key: 'Camera System Issue', label: 'Camera System Issue', value: 1 },
    ],
  },
];

it('hands the clicked segment back through onSelect', () => {
  const onSelect = jest.fn();
  render(<CompositionDonut segments={SEGMENTS} onSelect={onSelect} />);

  fireEvent.click(screen.getByText('Engine'));

  expect(onSelect).toHaveBeenCalledTimes(1);
  expect(onSelect.mock.calls[0][0].key).toBe('Engine');
});

it('opens a folded row instead of drilling, then drills each type inside it', () => {
  const onSelect = jest.fn();
  render(<CompositionDonut segments={SEGMENTS} onSelect={onSelect} />);

  // The "Other" row owns click-to-expand — it must reveal, not drill.
  fireEvent.click(screen.getByText(/Other \(2 types\)/));
  expect(onSelect).not.toHaveBeenCalled();

  fireEvent.click(screen.getByText('Flat Tire'));
  expect(onSelect.mock.calls[0][0].key).toBe('Flat Tire');
});

it('stays inert when no onSelect is given', () => {
  render(<CompositionDonut segments={SEGMENTS} />);

  expect(screen.getByText('Engine').closest('[role="button"]')).toBeNull();
});
