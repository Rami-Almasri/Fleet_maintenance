import { render, screen } from '@testing-library/react';
import App from './App';

/**
 * The app mounts, and an unauthenticated visitor lands on the sign-in screen.
 *
 * This file used to hold Create React App's boilerplate assertion — that a "learn react" link is on
 * the page. There has never been such a link here, so the test has never passed; it was also unable
 * to run at all, because react-router v7 touches TextEncoder at import time and jsdom does not
 * provide one (see src/setupTests.js). A test that cannot run and would fail if it did is worse than
 * no test: it makes the suite report a failure that everybody learns to scroll past.
 *
 * What replaces it is small on purpose. It asserts the one thing that must be true of every build —
 * the whole provider stack (theme, i18n, auth, router) composes and renders without throwing — and
 * the one route an unauthenticated request must reach.
 */
test('the app mounts and sends an unauthenticated visitor to sign in', async () => {
  render(<App />);

  // By role, not by text: "Sign in" appears both as the strapline and on the button, and a bare
  // text query matches both.
  expect(await screen.findByRole('button', { name: /sign in/i })).toBeInTheDocument();
});
