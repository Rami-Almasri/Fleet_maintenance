// App-wide localization. Holds the active language, persists the choice, flips the
// document direction (LTR/RTL) so the whole UI mirrors for Arabic, and exposes a
// single `t()` resolver used everywhere instead of hardcoded strings.
//
//   const { t, lang, dir, setLang, toggle } = useI18n();
//   <h1>{t('workflow.meta.dispatch.title')}</h1>
//   t('workflow.success.ready', { who: 'D-12345' })
//
// `t()` resolves a dot-path against the active language, falls back to English,
// then to the raw key — so a missing translation degrades gracefully instead of
// blanking the UI. {var} tokens are interpolated from the second argument.

import { createContext, useContext, useEffect, useMemo, useState, useCallback } from 'react';
import { LABELS, LANGS, PHRASES } from './labels';

const STORAGE_KEY = 'fv:lang';
const I18nContext = createContext(null);

// Walk a dot-path ('workflow.meta.dispatch.title') into a nested object.
function resolve(tree, path) {
  return path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), tree);
}

function interpolate(str, vars) {
  if (!vars || typeof str !== 'string') return str;
  return str.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? String(vars[k]) : m));
}

export function I18nProvider({ children }) {
  const [lang, setLangState] = useState(() => {
    const saved = localStorage.getItem(STORAGE_KEY);
    return LANGS.includes(saved) ? saved : 'en';
  });

  const dir = lang === 'ar' ? 'rtl' : 'ltr';

  // Reflect the language + direction on <html> so Tailwind's logical properties
  // (ms-*, ps-*, start-*, text-start) and native bidi rendering flip correctly.
  useEffect(() => {
    const el = document.documentElement;
    el.setAttribute('lang', lang);
    el.setAttribute('dir', dir);
    localStorage.setItem(STORAGE_KEY, lang);
  }, [lang, dir]);

  const setLang = useCallback((next) => {
    if (LANGS.includes(next)) setLangState(next);
  }, []);

  const toggle = useCallback(() => {
    setLangState((cur) => (cur === 'en' ? 'ar' : 'en'));
  }, []);

  // Two kinds of key are supported, checked in this order:
  //
  //   1. PHRASE keys — the English sentence itself:  t('Save changes')
  //      Looked up in the flat PHRASES[lang] map. English needs no entry: an
  //      untranslated phrase falls through to step 3 and renders the key, which
  //      IS the correct English. This is how the bulk of the app is localized —
  //      the English stays the source text and only Arabic is ever added.
  //
  //   2. DOT-PATH keys — a namespaced label:  t('vehicles.registry')
  //      Resolved against the nested LABELS tree, English fallback, as before.
  //      Used where one string needs per-context wording or plural forms.
  //
  //   3. Neither found → return the key untouched.
  //
  // Phrases are checked first and by exact match, so a sentence containing a '.'
  // is never mistaken for a dot-path.
  const t = useCallback(
    (key, vars) => {
      const phrase = PHRASES[lang]?.[key];
      if (phrase !== undefined) return interpolate(phrase, vars);
      const hit = resolve(LABELS[lang], key);
      const val = hit !== undefined ? hit : resolve(LABELS.en, key);
      if (val === undefined) return interpolate(key, vars); // the key IS the English
      return interpolate(val, vars);
    },
    [lang],
  );

  // Same resolution as t(), but falls back to a caller-supplied English string
  // instead of the raw key. This is what lets a page be migrated to i18n in one
  // pass — wrap the existing hardcoded English as the fallback and the UI is
  // never worse than before, then the Arabic fills in as keys land in labels.js.
  //
  //   tf('nav.items.vehicles.name', 'Vehicles')
  const tf = useCallback(
    (key, fallback, vars) => {
      const hit = resolve(LABELS[lang], key);
      const val = hit !== undefined ? hit : resolve(LABELS.en, key);
      return interpolate(val !== undefined ? val : fallback, vars);
    },
    [lang],
  );

  // Plural resolver. English has two forms (one/other); Arabic has six
  // (zero/one/two/few/many/other), so a naive `n === 1 ? a : b` produces wrong
  // Arabic for 2, for 3–10, and for 11+. Intl.PluralRules picks the right
  // category per locale; we look up `<baseKey>.<category>` and fall back through
  // `other` so a catalog only needs the forms that language actually uses.
  //
  //   tp('modules.sectionCount', 3)  →  ar: 'modules.sectionCount.few'
  const tp = useCallback(
    (baseKey, n, vars) => {
      const cat = new Intl.PluralRules(lang === 'ar' ? 'ar' : 'en').select(n);
      const args = { n, ...vars };
      for (const c of [cat, 'other']) {
        const hit = resolve(LABELS[lang], `${baseKey}.${c}`) ?? resolve(LABELS.en, `${baseKey}.${c}`);
        if (hit !== undefined) return interpolate(hit, args);
      }
      return baseKey;
    },
    [lang],
  );

  const value = useMemo(
    () => ({ t, tf, tp, lang, dir, setLang, toggle, isRTL: dir === 'rtl' }),
    [t, tf, tp, lang, dir, setLang, toggle],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error('useI18n must be used within <I18nProvider>');
  return ctx;
}

// Convenience hook when a component only needs the resolver.
export function useT() {
  return useI18n().t;
}
