// App-wide theme: light "Platinum" / dark "Cockpit", plus the selectable
// brand + accent colour ramps behind the Theme Studio ([[ThemePicker]]).
//
// The selected theme is written to <html data-theme="..."> — Tailwind's dark
// variant and the CSS override layer in index.css both key off that attribute.
// The selected palette is written as inline `--brand-*` / `--accent-*` custom
// properties on <html>, which override the :root defaults in index.css and so
// re-skin every component that resolves through them (see palettes.js).
//
// A tiny inline script in public/index.html applies BOTH the saved theme and
// the saved palette BEFORE React mounts, so there's no flash of the wrong
// colours on load. That script replays a pre-flattened var map we persist here
// under `fv:palette:vars`, which keeps the palette tables out of index.html.

import { createContext, useContext, useEffect, useState, useCallback, useMemo } from 'react';
import {
  DEFAULT_BRAND, DEFAULT_ACCENT, DEFAULT_SURFACE, LOOKS,
  resolveRamp, paletteVars, applyVars,
  resolveShell, shellCss, applyShellCss,
} from './palettes';

const ThemeContext = createContext({
  theme: 'light', toggle: () => {}, setTheme: () => {},
  brand: DEFAULT_BRAND, accent: DEFAULT_ACCENT, surface: DEFAULT_SURFACE,
  brandHex: '#3b82f6', accentHex: '#eab308', surfaceHex: '#0c0e11',
  setBrand: () => {}, setAccent: () => {}, setSurface: () => {},
  applyLook: () => {}, resetPalette: () => {},
  isDefaultPalette: true,
});

const STORAGE_KEY = 'fv:theme';
const PALETTE_KEY = 'fv:palette';
const PALETTE_VARS_KEY = 'fv:palette:vars';
const SHELL_CSS_KEY = 'fv:dark:css';

function readInitialTheme() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'light' || saved === 'dark') return saved;
  } catch { /* ignore */ }
  // Fall back to whatever the pre-paint script already set on <html>.
  const attr = document.documentElement.getAttribute('data-theme');
  return attr === 'dark' ? 'dark' : 'light';
}

function readInitialPalette() {
  const base = {
    brand: DEFAULT_BRAND, accent: DEFAULT_ACCENT, surface: DEFAULT_SURFACE,
    brandHex: '#3b82f6', accentHex: '#eab308', surfaceHex: '#0c0e11',
  };
  try {
    const raw = localStorage.getItem(PALETTE_KEY);
    if (!raw) return base;
    const saved = JSON.parse(raw);
    return {
      brand: saved.brand || base.brand,
      accent: saved.accent || base.accent,
      surface: saved.surface || base.surface,
      brandHex: saved.brandHex || base.brandHex,
      accentHex: saved.accentHex || base.accentHex,
      surfaceHex: saved.surfaceHex || base.surfaceHex,
    };
  } catch {
    return base;
  }
}

export function ThemeProvider({ children }) {
  const [theme, setThemeState] = useState(readInitialTheme);
  const [palette, setPalette] = useState(readInitialPalette);

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem(STORAGE_KEY, theme); } catch { /* ignore */ }
  }, [theme]);

  useEffect(() => {
    const vars = paletteVars(
      resolveRamp('brand', palette.brand, palette.brandHex),
      resolveRamp('accent', palette.accent, palette.accentHex),
    );
    applyVars(vars);

    // The dark shell is a rule, not inline vars — it must not leak into light
    // mode. See shellCss() for why the selector is html-qualified.
    const css = shellCss(resolveShell(palette.surface, palette.surfaceHex));
    applyShellCss(css);

    try {
      localStorage.setItem(PALETTE_KEY, JSON.stringify(palette));
      localStorage.setItem(PALETTE_VARS_KEY, JSON.stringify(vars));
      localStorage.setItem(SHELL_CSS_KEY, css);
    } catch { /* ignore */ }
  }, [palette]);

  const setTheme = useCallback((t) => setThemeState(t === 'dark' ? 'dark' : 'light'), []);
  const toggle = useCallback(() => setThemeState((t) => (t === 'dark' ? 'light' : 'dark')), []);

  const setBrand = useCallback((id, hex) => {
    setPalette((p) => ({ ...p, brand: id, brandHex: id === 'custom' ? (hex || p.brandHex) : p.brandHex }));
  }, []);
  const setAccent = useCallback((id, hex) => {
    setPalette((p) => ({ ...p, accent: id, accentHex: id === 'custom' ? (hex || p.accentHex) : p.accentHex }));
  }, []);
  // Choosing a dark shade also switches you into dark mode — otherwise the
  // click appears to do nothing, since the shade only exists there.
  const setSurface = useCallback((id, hex) => {
    setPalette((p) => ({ ...p, surface: id, surfaceHex: id === 'custom' ? (hex || p.surfaceHex) : p.surfaceHex }));
    setThemeState('dark');
  }, []);

  const applyLook = useCallback((lookId) => {
    const look = LOOKS.find((l) => l.id === lookId);
    if (look) setPalette((p) => ({ ...p, brand: look.brand, accent: look.accent, surface: look.surface || p.surface }));
  }, []);
  const resetPalette = useCallback(() => {
    setPalette((p) => ({ ...p, brand: DEFAULT_BRAND, accent: DEFAULT_ACCENT, surface: DEFAULT_SURFACE }));
  }, []);

  const value = useMemo(() => ({
    theme, toggle, setTheme,
    ...palette,
    setBrand, setAccent, setSurface, applyLook, resetPalette,
    isDefaultPalette: palette.brand === DEFAULT_BRAND
      && palette.accent === DEFAULT_ACCENT
      && palette.surface === DEFAULT_SURFACE,
  }), [theme, toggle, setTheme, palette, setBrand, setAccent, setSurface, applyLook, resetPalette]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  return useContext(ThemeContext);
}
