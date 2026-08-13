// The catalog resolver, with NO React in it.
//
// `I18nContext` builds t()/tf()/tp() on top of this for components. This module
// exists separately for the code that CANNOT call a hook — chiefly the plain
// formatters in `src/lib/format.js`, which return words ("just now", "2h 10m",
// month names, AM/PM) from hundreds of call sites, including table column
// definitions and module-level config evaluated outside any component.
//
// Keeping it out of I18nContext.js matters for a second reason: several test
// suites `jest.mock('.../i18n/I18nContext')` to stub the hook. If the formatters
// imported their resolver from that module too, the mock would replace it with
// `undefined` and every date/number helper would throw inside those tests.
// Mocking the React layer must not break plain string formatting.
//
// This is NOT a second translation system — same PHRASES, same LABELS, same
// lookup order as the hook.

import { LABELS, LANGS, PHRASES } from './labels';

export const STORAGE_KEY = 'fv:lang';

// Walk a dot-path ('workflow.meta.dispatch.title') into a nested object.
export function resolve(tree, path) {
  return path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), tree);
}

export function interpolate(str, vars) {
  if (!vars || typeof str !== 'string') return str;
  return str.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? String(vars[k]) : m));
}

// The single resolution rule: phrase catalog first (exact match, so a sentence
// containing a '.' is never mistaken for a dot-path), then the nested LABELS
// tree, then English, then the key itself — which IS the English.
export function lookup(lang, key, vars) {
  const phrase = PHRASES[lang]?.[key];
  if (phrase !== undefined) return interpolate(phrase, vars);
  const hit = resolve(LABELS[lang], key);
  const val = hit !== undefined ? hit : resolve(LABELS.en, key);
  if (val === undefined) return interpolate(key, vars);
  return interpolate(val, vars);
}

// The active language, read from the same storage key the provider persists, so
// no formatter needs it threaded through its signature. Toggling the language
// re-renders the tree, which re-runs the formatters, so their output updates
// along with everything else.
export function activeLang() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    return LANGS.includes(saved) ? saved : 'en';
  } catch {
    return 'en';
  }
}

export function translate(key, vars) {
  return lookup(activeLang(), key, vars);
}

// Locales for Intl. Arabic MUST be pinned to the Gregorian calendar with Latin
// digits: plain 'ar' resolves to the Hijri calendar and Arabic-Indic numerals,
// which is the wrong calendar for this fleet's records and breaks the alignment
// of every `tabular-nums` column.
export function dateLocale() {
  return activeLang() === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : 'en-GB';
}

export function numberLocale() {
  return activeLang() === 'ar' ? 'ar-AE-u-nu-latn' : undefined;
}
