// Theme Studio — pick the app's primary + accent colour, and light/dark mode.
//
// Everything here writes through [[ThemeContext]], which rewrites the
// `--brand-*` / `--accent-*` CSS variables on <html>. Because every Tailwind
// brand/indigo/violet utility in the app resolves through those variables, the
// whole product re-skins live as you hover — including this panel, which is
// deliberately built out of the same tokens so it previews itself.

import { useEffect, useRef, useState } from 'react';
import { useTheme } from '../theme/ThemeContext';
import { useI18n } from '../i18n/I18nContext';
import {
  BRAND_PRESETS, ACCENT_PRESETS, SURFACE_PRESETS, LOOKS,
  findBrand, findAccent, findSurface, resolveShell, rampFromHex, hexToRgb,
} from '../theme/palettes';

// A single round colour chip. The tick only appears on the active one; the ring
// is drawn OUTSIDE the swatch (offset) so it reads on any background.
//
// `.swatch-active` (index.css) draws the selection ring from the --ink /
// --surface tokens, so it stays legible in Cockpit as well as Platinum.
const ACTIVE_RING = 'swatch-active';

function Swatch({ color, active, title, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={title}
      aria-label={title}
      aria-pressed={active}
      className={`relative h-8 w-8 rounded-full transition duration-200 hover:scale-110 focus:outline-none ${
        active ? `scale-110 ${ACTIVE_RING}` : 'ring-1 ring-black/10'
      }`}
      style={{ background: color }}
    >
      {active && (
        <svg className="absolute inset-0 m-auto h-4 w-4 text-white drop-shadow" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      )}
    </button>
  );
}

// Preset row + "custom" chip. The custom chip wraps a native <input type=color>
// so we get the OS picker for free — the input itself is invisible and sized to
// cover the chip, which is the only reliable way to style it cross-browser.
function ColorRow({ presets, activeId, customHex, onPreset, onCustom, label }) {
  return (
    <div>
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">{label}</p>
      <div className="flex flex-wrap items-center gap-2.5">
        {presets.map((p) => (
          <Swatch
            key={p.id}
            color={p.hex}
            title={p.name}
            active={activeId === p.id}
            onClick={() => onPreset(p.id)}
          />
        ))}

        <span className="mx-0.5 h-6 w-px bg-slate-200" />

        <label
          title={label}
          className={`relative h-8 w-8 cursor-pointer overflow-hidden rounded-full transition duration-200 hover:scale-110 ${
            activeId === 'custom' ? `scale-110 ${ACTIVE_RING}` : 'ring-1 ring-black/10'
          }`}
          style={{
            background: activeId === 'custom'
              ? customHex
              : 'conic-gradient(from 0deg, #f43f5e, #f59e0b, #84cc16, #10b981, #06b6d4, #3b82f6, #8b5cf6, #f43f5e)',
          }}
        >
          <input
            type="color"
            value={customHex}
            onChange={(e) => onCustom(e.target.value)}
            className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
          />
          {activeId !== 'custom' && (
            <svg className="pointer-events-none absolute inset-0 m-auto h-4 w-4 text-white drop-shadow" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round">
              <path d="M12 5v14M5 12h14" />
            </svg>
          )}
        </label>
      </div>
    </div>
  );
}

// A dark-shell chip. Round swatches don't work here: what you're choosing is a
// page + card + text relationship, not one colour, so each chip is a tiny
// rendering of that — page background, a raised card on it, and two ink bars.
function ShellChip({ vars, name, active, onClick }) {
  const rgb = (v) => `rgb(${v})`;
  return (
    <button
      type="button"
      onClick={onClick}
      title={name}
      aria-label={name}
      aria-pressed={active}
      className={`relative h-11 w-14 overflow-hidden rounded-lg transition duration-200 hover:scale-105 focus:outline-none ${
        active ? `scale-105 ${ACTIVE_RING}` : 'ring-1 ring-black/20'
      }`}
      style={{ background: rgb(vars['--bg']) }}
    >
      <span
        className="absolute inset-x-1.5 bottom-1.5 top-3.5 rounded-md"
        style={{ background: rgb(vars['--surface']), border: `1px solid ${rgb(vars['--line'])}` }}
      >
        <span className="absolute left-1 right-3 top-1.5 h-1 rounded-full" style={{ background: rgb(vars['--ink']) }} />
        <span className="absolute left-1 right-5 top-3.5 h-1 rounded-full" style={{ background: rgb(vars['--ink-3']) }} />
      </span>
    </button>
  );
}

// The dark-shell row. Rendered whatever the current mode is, because clicking a
// shade switches you into dark (see setSurface) — hiding it in light mode would
// make the feature undiscoverable from the mode most people start in.
function ShellRow({ surface, surfaceHex, setSurface, t }) {
  return (
    <div>
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">{t('Dark mode shade')}</p>
      <div className="flex flex-wrap items-center gap-2">
        {SURFACE_PRESETS.map((s) => (
          <ShellChip
            key={s.id}
            vars={s.vars}
            name={s.name}
            active={surface === s.id}
            onClick={() => setSurface(s.id)}
          />
        ))}

        {/* Custom shade — the OS picker gives a hue, darkShellFromHex() turns it
            into the whole surface/ink/line group. */}
        <label
          title={t('Custom shade')}
          className={`relative h-11 w-14 cursor-pointer overflow-hidden rounded-lg transition duration-200 hover:scale-105 ${
            surface === 'custom' ? `scale-105 ${ACTIVE_RING}` : 'ring-1 ring-black/20'
          }`}
          style={{ background: `rgb(${resolveShell('custom', surfaceHex)['--bg']})` }}
        >
          <input
            type="color"
            value={surfaceHex}
            onChange={(e) => setSurface('custom', e.target.value)}
            className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
          />
          <span
            className="pointer-events-none absolute inset-x-1.5 bottom-1.5 top-3.5 rounded-md"
            style={{
              background: `rgb(${resolveShell('custom', surfaceHex)['--surface']})`,
              border: `1px solid rgb(${resolveShell('custom', surfaceHex)['--line']})`,
            }}
          />
          <svg className="pointer-events-none absolute inset-0 m-auto h-4 w-4 text-white/80" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round">
            <path d="M12 5v14M5 12h14" />
          </svg>
        </label>
      </div>
    </div>
  );
}

// Miniature of the real UI: a filled primary button, a tinted badge, the brand
// gradient bar and a small chart. Every element uses the live tokens, so this
// is an actual preview rather than a drawing of one.
function Preview({ t }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <span className="inline-flex h-7 items-center rounded-lg bg-indigo-600 px-2.5 text-[11px] font-semibold text-white shadow-sm">
            {t('Primary')}
          </span>
          <span className="inline-flex h-7 items-center rounded-lg bg-indigo-50 px-2.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200">
            {t('Badge')}
          </span>
          <span className="inline-flex h-7 items-center rounded-lg bg-violet-100 px-2.5 text-[11px] font-semibold text-violet-800">
            {t('Accent')}
          </span>
        </div>
        <span className="text-[11px] font-medium text-indigo-600">{t('Link')}</span>
      </div>

      <div className="mt-3 h-1.5 w-full rounded-full bg-gradient-to-r from-indigo-500 via-violet-400 to-violet-300" />

      <div className="mt-3 flex items-end gap-1">
        {[40, 62, 34, 78, 55, 88, 47, 70].map((h, i) => (
          <span
            key={i}
            className={`w-full rounded-sm ${i % 3 === 2 ? 'bg-violet-400' : 'bg-indigo-500'}`}
            style={{ height: `${h * 0.32}px` }}
          />
        ))}
      </div>
    </div>
  );
}

export default function ThemePicker({ className = '' }) {
  const {
    theme, setTheme,
    brand, accent, surface, brandHex, accentHex, surfaceHex,
    setBrand, setAccent, setSurface, applyLook, resetPalette, isDefaultPalette,
  } = useTheme();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  // The trigger shows the two live colours, so the button itself is the status.
  const swatchBrand = brand === 'custom' ? brandHex : findBrand(brand)?.hex;
  const swatchAccent = accent === 'custom' ? accentHex : findAccent(accent)?.hex;

  // A look owns all three axes, so it only reads as "active" when all three match.
  const activeLook = LOOKS.find((l) => l.brand === brand && l.accent === accent && l.surface === surface);

  return (
    <div ref={ref} className={`relative ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        title={t('Theme colours')}
        aria-label={t('Theme colours')}
        aria-expanded={open}
        className={`inline-flex h-9 w-9 items-center justify-center rounded-xl border bg-white shadow-sm transition ${
          open ? 'border-indigo-300 ring-1 ring-indigo-200' : 'border-slate-200 hover:ring-1 hover:ring-slate-200'
        }`}
      >
        <span
          className="h-[18px] w-[18px] rounded-full ring-1 ring-black/10"
          style={{ background: `conic-gradient(from 140deg, ${swatchBrand} 0deg 200deg, ${swatchAccent} 200deg 360deg)` }}
        />
      </button>

      {/* `end-0` is the logical inset — it anchors to the right in LTR and to
          the left under RTL on its own, so there's no direction branch here. */}
      {open && (
        <div className="absolute end-0 top-full z-50 mt-2 w-[21rem] animate-pop rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">
          {/* Header */}
          <div className="flex items-start justify-between gap-3">
            <div>
              <h3 className="font-display text-sm font-bold tracking-tight text-slate-900">{t('Theme studio')}</h3>
              <p className="mt-0.5 text-[11px] text-slate-500">{t('Pick the colours the whole app is painted in.')}</p>
            </div>
            {!isDefaultPalette && (
              <button
                type="button"
                onClick={resetPalette}
                className="shrink-0 rounded-lg px-2 py-1 text-[11px] font-semibold text-indigo-600 transition hover:bg-indigo-50"
              >
                {t('Reset')}
              </button>
            )}
          </div>

          {/* Light / dark — segmented pill */}
          <div className="mt-3 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
            {[
              { id: 'light', label: t('Light') },
              { id: 'dark', label: t('Dark') },
            ].map((m) => (
              <button
                key={m.id}
                type="button"
                onClick={() => setTheme(m.id)}
                className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                  theme === m.id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {m.label}
              </button>
            ))}
          </div>

          {/* Curated looks — sets both ramps at once */}
          <div className="mt-4">
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">{t('Looks')}</p>
            <div className="flex flex-wrap gap-1.5">
              {LOOKS.map((l) => {
                const b = findBrand(l.brand)?.hex;
                const a = findAccent(l.accent)?.hex;
                const s = findSurface(l.surface)?.swatch;
                const on = activeLook?.id === l.id;
                return (
                  <button
                    key={l.id}
                    type="button"
                    onClick={() => applyLook(l.id)}
                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${
                      on ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    <span
                      className="h-3 w-3 rounded-full ring-1 ring-black/10"
                      style={{ background: `conic-gradient(from 210deg, ${b} 0deg 120deg, ${a} 120deg 240deg, ${s} 240deg 360deg)` }}
                    />
                    {t(l.name)}
                  </button>
                );
              })}
            </div>
          </div>

          {/* The two ramps */}
          <div className="mt-4 space-y-4">
            <ColorRow
              label={t('Primary')}
              presets={BRAND_PRESETS}
              activeId={brand}
              customHex={brandHex}
              onPreset={(id) => setBrand(id)}
              onCustom={(hex) => setBrand('custom', hex)}
            />
            <ColorRow
              label={t('Accent')}
              presets={ACCENT_PRESETS}
              activeId={accent}
              customHex={accentHex}
              onPreset={(id) => setAccent(id)}
              onCustom={(hex) => setAccent('custom', hex)}
            />
            <ShellRow surface={surface} surfaceHex={surfaceHex} setSurface={setSurface} t={t} />
          </div>

          {/* Live preview */}
          <div className="mt-4">
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">{t('Preview')}</p>
            <Preview t={t} />
          </div>

          <p className="mt-3 text-[10px] leading-relaxed text-slate-400">
            {t('Saved on this device only — it does not change what anyone else sees.')}
          </p>
        </div>
      )}
    </div>
  );
}

// Re-exported for the Settings page, which shows the same controls inline
// (no popover) inside its Appearance card.
export function ThemeStudioInline() {
  const {
    brand, accent, surface, brandHex, accentHex, surfaceHex,
    setBrand, setAccent, setSurface, applyLook, resetPalette, isDefaultPalette,
  } = useTheme();
  const { t } = useI18n();
  // A look owns all three axes, so it only reads as "active" when all three match.
  const activeLook = LOOKS.find((l) => l.brand === brand && l.accent === accent && l.surface === surface);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-1.5">
        {LOOKS.map((l) => {
          const b = findBrand(l.brand)?.hex;
          const a = findAccent(l.accent)?.hex;
          const s = findSurface(l.surface)?.swatch;
          const on = activeLook?.id === l.id;
          return (
            <button
              key={l.id}
              type="button"
              onClick={() => applyLook(l.id)}
              className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                on ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'
              }`}
            >
              <span className="h-3.5 w-3.5 rounded-full ring-1 ring-black/10" style={{ background: `conic-gradient(from 210deg, ${b} 0deg 120deg, ${a} 120deg 240deg, ${s} 240deg 360deg)` }} />
              {t(l.name)}
            </button>
          );
        })}
      </div>

      <ColorRow
        label={t('Primary')}
        presets={BRAND_PRESETS}
        activeId={brand}
        customHex={brandHex}
        onPreset={(id) => setBrand(id)}
        onCustom={(hex) => setBrand('custom', hex)}
      />
      <ColorRow
        label={t('Accent')}
        presets={ACCENT_PRESETS}
        activeId={accent}
        customHex={accentHex}
        onPreset={(id) => setAccent(id)}
        onCustom={(hex) => setAccent('custom', hex)}
      />
      <ShellRow surface={surface} surfaceHex={surfaceHex} setSurface={setSurface} t={t} />

      <Preview t={t} />

      {!isDefaultPalette && (
        <button
          type="button"
          onClick={resetPalette}
          className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-50"
        >
          {t('Reset to the Faster palette')}
        </button>
      )}
    </div>
  );
}

// Exported for tests / future callers that want to validate a pasted hex.
export const isValidHex = (hex) => Boolean(hexToRgb(hex)) && Boolean(rampFromHex(hex));
