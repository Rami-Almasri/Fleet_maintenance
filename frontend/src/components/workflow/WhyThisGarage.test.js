// Render tests for the "Why this garage?" explanation card (ticket history + vehicle timeline).

import { render, screen } from '@testing-library/react';
import WhyThisGarage from './WhyThisGarage';

test('shows the accepted recommendation with reasons and confidence (English fallback)', () => {
  render(<WhyThisGarage rec={{
    recommended_garage: 'Nissan Service',
    chosen_garage: 'Nissan Service',
    accepted: true,
    followed: true,
    confidence: 'high',
    reasons: [{ t: '119 previous Patrol repairs' }, { t: 'Strong Nissan concentration' }],
  }} />);

  expect(screen.getByText('Why this garage?')).toBeInTheDocument();
  expect(screen.getByText('Nissan Service')).toBeInTheDocument();
  expect(screen.getByText('Accepted: Yes')).toBeInTheDocument();
  expect(screen.getByText('119 previous Patrol repairs')).toBeInTheDocument();
  expect(screen.getByText('Strong Nissan concentration')).toBeInTheDocument();
  expect(screen.getByText('High')).toBeInTheDocument();
});

test('flags a manual override and shows the assigned garage', () => {
  render(<WhyThisGarage rec={{
    recommended_garage: 'Nissan Service',
    chosen_garage: 'Some Other Garage',
    accepted: false,
    followed: false,
    confidence: 'low',
    reasons: [],
  }} />);

  expect(screen.getByText('Manual override')).toBeInTheDocument();
  expect(screen.getByText('Some Other Garage')).toBeInTheDocument(); // the assigned garage row
  expect(screen.getByText('Nissan Service')).toBeInTheDocument();    // still shows what was recommended
});

test('renders nothing when no recommendation', () => {
  const { container } = render(<WhyThisGarage rec={null} />);
  expect(container).toBeEmptyDOMElement();
});
