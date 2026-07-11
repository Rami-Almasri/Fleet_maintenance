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
import { LABELS, LANGS } from './labels';

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

  const t = useCallback(
    (key, vars) => {
      const hit = resolve(LABELS[lang], key);
      const val = hit !== undefined ? hit : resolve(LABELS.en, key);
      if (val === undefined) return key; // last-resort: show the key, never blank
      return interpolate(val, vars);
    },
    [lang],
  );

  const value = useMemo(() => ({ t, lang, dir, setLang, toggle, isRTL: dir === 'rtl' }), [t, lang, dir, setLang, toggle]);

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
