// jest-dom adds custom jest matchers for asserting on DOM nodes.
// allows you to do things like:
// expect(element).toHaveTextContent(/react/i)
// learn more: https://github.com/testing-library/jest-dom
import '@testing-library/jest-dom';

// jsdom ships no TextEncoder/TextDecoder, but react-router v7 reaches for TextEncoder at import
// time. Without these, ANY test that renders a component importing react-router-dom dies on the
// import line — which is why this codebase had no component tests at all, only pure-logic ones.
// Node has had both in `util` since v11; jsdom simply does not expose them on the global.
import { TextEncoder, TextDecoder } from 'util';

if (typeof global.TextEncoder === 'undefined') global.TextEncoder = TextEncoder;
if (typeof global.TextDecoder === 'undefined') global.TextDecoder = TextDecoder;
