// Colour palettes for the Theme Studio.
//
// The whole design system already resolves through two CSS variable ramps —
// `--brand-50…950` (primary actions: buttons, links, active tabs) and
// `--accent-50…900` (branding, gauges, gradient sheen). Every Tailwind
// `indigo-*` / `brand-*` / `violet-*` utility in the app is declared as
// `rgb(var(--brand-500) / <alpha-value>)` in tailwind.config.js, so rewriting
// those eleven variables re-skins ~every screen at once — no rebuild, no
// per-component work.
//
// This module owns: the curated ramps, a generator that turns ANY hex into a
// usable ramp, and the single function that writes them onto <html>.
// See [[ThemeContext]] for persistence and [[ThemePicker]] for the UI.

// A ramp is 50→950 keyed to space-separated "R G B" (the format the CSS vars
// need so Tailwind's `/ <alpha-value>` opacity modifiers keep working).
const ramp = (...v) => ({
  50: v[0], 100: v[1], 200: v[2], 300: v[3], 400: v[4], 500: v[5],
  600: v[6], 700: v[7], 800: v[8], 900: v[9], 950: v[10],
});

// ---------------------------------------------------------------------------
// Primary (brand) presets — the action colour.
// `navy` reproduces the shipped default EXACTLY, including its custom 950
// (17 27 56, darker than Tailwind's blue-950), so "Reset" is a true no-op.
// ---------------------------------------------------------------------------
export const BRAND_PRESETS = [
  {
    id: 'navy', name: 'Faster Navy', hex: '#3b82f6',
    ramp: ramp('239 246 255', '219 234 254', '191 219 254', '147 197 253', '96 165 250',
      '59 130 246', '37 99 235', '29 78 216', '30 64 175', '30 58 138', '17 27 56'),
  },
  {
    id: 'indigo', name: 'Indigo', hex: '#6366f1',
    ramp: ramp('238 242 255', '224 231 255', '199 210 254', '165 180 252', '129 140 248',
      '99 102 241', '79 70 229', '67 56 202', '55 48 163', '49 46 129', '30 27 75'),
  },
  {
    id: 'violet', name: 'Violet', hex: '#8b5cf6',
    ramp: ramp('245 243 255', '237 233 254', '221 214 254', '196 181 253', '167 139 250',
      '139 92 246', '124 58 237', '109 40 217', '91 33 182', '76 29 149', '46 16 101'),
  },
  {
    id: 'sky', name: 'Sky', hex: '#0ea5e9',
    ramp: ramp('240 249 255', '224 242 254', '186 230 253', '125 211 252', '56 189 248',
      '14 165 233', '2 132 199', '3 105 161', '7 89 133', '12 74 110', '8 47 73'),
  },
  {
    id: 'teal', name: 'Teal', hex: '#14b8a6',
    ramp: ramp('240 253 250', '204 251 241', '153 246 228', '94 234 212', '45 212 191',
      '20 184 166', '13 148 136', '15 118 110', '17 94 89', '19 78 74', '4 47 46'),
  },
  {
    id: 'emerald', name: 'Emerald', hex: '#10b981',
    ramp: ramp('236 253 245', '209 250 229', '167 243 208', '110 231 183', '52 211 153',
      '16 185 129', '5 150 105', '4 120 87', '6 95 70', '6 78 59', '2 44 34'),
  },
  {
    id: 'amber', name: 'Amber', hex: '#f59e0b',
    ramp: ramp('255 251 235', '254 243 199', '253 230 138', '252 211 77', '251 191 36',
      '245 158 11', '217 119 6', '180 83 9', '146 64 14', '120 53 15', '69 26 3'),
  },
  {
    id: 'orange', name: 'Sunset', hex: '#f97316',
    ramp: ramp('255 247 237', '255 237 213', '254 215 170', '253 186 116', '251 146 60',
      '249 115 22', '234 88 12', '194 65 12', '154 52 18', '124 45 18', '67 20 7'),
  },
  {
    id: 'rose', name: 'Rose', hex: '#f43f5e',
    ramp: ramp('255 241 242', '255 228 230', '254 205 211', '253 164 175', '251 113 133',
      '244 63 94', '225 29 72', '190 18 60', '159 18 57', '136 19 55', '76 5 25'),
  },
  {
    id: 'fuchsia', name: 'Fuchsia', hex: '#d946ef',
    ramp: ramp('253 244 255', '250 232 255', '245 208 254', '240 171 252', '232 121 249',
      '217 70 239', '192 38 211', '162 28 175', '134 25 143', '112 26 117', '74 4 78'),
  },
  {
    id: 'graphite', name: 'Graphite', hex: '#64748b',
    ramp: ramp('248 250 252', '241 245 249', '226 232 240', '203 213 225', '148 163 184',
      '100 116 139', '71 85 105', '51 65 85', '30 41 59', '15 23 42', '2 6 23'),
  },
];

// ---------------------------------------------------------------------------
// Accent presets — branding, highlights, gauges, gradient sheen. Never the
// primary button (see the header of index.css).
// ---------------------------------------------------------------------------
export const ACCENT_PRESETS = [
  {
    id: 'lemon', name: 'Faster Lemon', hex: '#eab308',
    ramp: ramp('254 252 232', '254 249 195', '254 240 138', '253 224 71', '250 204 21',
      '234 179 8', '202 138 4', '161 98 7', '133 77 14', '113 63 18', '66 32 6'),
  },
  {
    id: 'cyan', name: 'Cyan', hex: '#06b6d4',
    ramp: ramp('236 254 255', '207 250 254', '165 243 252', '103 232 249', '34 211 238',
      '6 182 212', '8 145 178', '14 116 144', '21 94 117', '22 78 99', '8 51 68'),
  },
  {
    id: 'emerald', name: 'Emerald', hex: '#10b981',
    ramp: ramp('236 253 245', '209 250 229', '167 243 208', '110 231 183', '52 211 153',
      '16 185 129', '5 150 105', '4 120 87', '6 95 70', '6 78 59', '2 44 34'),
  },
  {
    id: 'violet', name: 'Violet', hex: '#8b5cf6',
    ramp: ramp('245 243 255', '237 233 254', '221 214 254', '196 181 253', '167 139 250',
      '139 92 246', '124 58 237', '109 40 217', '91 33 182', '76 29 149', '46 16 101'),
  },
  {
    id: 'rose', name: 'Rose', hex: '#f43f5e',
    ramp: ramp('255 241 242', '255 228 230', '254 205 211', '253 164 175', '251 113 133',
      '244 63 94', '225 29 72', '190 18 60', '159 18 57', '136 19 55', '76 5 25'),
  },
  {
    id: 'orange', name: 'Ember', hex: '#f97316',
    ramp: ramp('255 247 237', '255 237 213', '254 215 170', '253 186 116', '251 146 60',
      '249 115 22', '234 88 12', '194 65 12', '154 52 18', '124 45 18', '67 20 7'),
  },
  {
    id: 'lime', name: 'Lime', hex: '#84cc16',
    ramp: ramp('247 254 231', '236 252 203', '217 249 157', '190 242 100', '163 230 53',
      '132 204 22', '101 163 13', '77 124 15', '63 98 18', '54 83 20', '26 46 5'),
  },
  {
    id: 'sky', name: 'Ice', hex: '#0ea5e9',
    ramp: ramp('240 249 255', '224 242 254', '186 230 253', '125 211 252', '56 189 248',
      '14 165 233', '2 132 199', '3 105 161', '7 89 133', '12 74 110', '8 47 73'),
  },
];

// ---------------------------------------------------------------------------
// Full-look combinations — one click sets both ramps AND the dark shell shade
// (see SURFACE_PRESETS below) so the look holds in dark mode too. These are the
// "designed" answer for someone who doesn't want to mix colours themselves.
// ---------------------------------------------------------------------------
export const LOOKS = [
  { id: 'classic',  name: 'Faster Classic', brand: 'navy',     accent: 'lemon',  surface: 'midnight' },
  { id: 'aurora',   name: 'Aurora',         brand: 'indigo',   accent: 'cyan',   surface: 'midnight' },
  { id: 'midnight', name: 'Midnight',       brand: 'violet',   accent: 'violet', surface: 'plum' },
  { id: 'forest',   name: 'Forest',         brand: 'emerald',  accent: 'lime',   surface: 'forest' },
  { id: 'lagoon',   name: 'Lagoon',         brand: 'teal',     accent: 'sky',    surface: 'ocean' },
  { id: 'sunset',   name: 'Sunset',         brand: 'orange',   accent: 'amber',  surface: 'espresso' },
  { id: 'crimson',  name: 'Crimson',        brand: 'rose',     accent: 'orange', surface: 'graphite' },
  { id: 'steel',    name: 'Steel',          brand: 'graphite', accent: 'cyan',   surface: 'obsidian' },
];

export const DEFAULT_BRAND = 'navy';
export const DEFAULT_ACCENT = 'lemon';

// ---------------------------------------------------------------------------
// Dark shell ("Cockpit") shades.
//
// A SEPARATE axis from brand/accent. Those two ramps are theme-independent on
// purpose (index.css header: white text on them must stay readable in both
// themes); what actually makes dark mode feel navy vs. charcoal vs. black is
// the surface/ink/line group that index.css declares under [data-theme='dark'].
//
// Each shade supplies that whole group. `bg` is the page, `surface` the cards
// (every `bg-white` maps to it in dark), `panel` the raised bits, `ink*` the
// three text weights and `line` the hairlines.
// ---------------------------------------------------------------------------
const shell = (bg, surface, panel, ink, ink2, ink3, line) => ({
  '--bg': bg, '--surface': surface, '--panel': panel,
  '--ink': ink, '--ink-2': ink2, '--ink-3': ink3,
  '--line': line, '--chart-line': line,
});

export const SURFACE_PRESETS = [
  {
    // Reproduces the shipped [data-theme='dark'] block EXACTLY, so "Reset" is a
    // true no-op and this preset is indistinguishable from not having the feature.
    id: 'midnight', name: 'Midnight Navy', swatch: '#070c18',
    vars: shell('7 12 24', '20 27 46', '25 33 56', '232 237 247', '170 182 205', '120 134 160', '40 51 78'),
  },
  {
    id: 'graphite', name: 'Graphite', swatch: '#0c0e11',
    vars: shell('12 14 17', '24 27 32', '31 35 41', '236 238 242', '176 181 191', '126 132 143', '45 50 58'),
  },
  {
    id: 'obsidian', name: 'Obsidian', swatch: '#000000',
    vars: shell('0 0 0', '14 14 16', '22 22 25', '240 240 243', '175 175 182', '124 124 132', '38 38 43'),
  },
  {
    id: 'slate', name: 'Slate', swatch: '#0f172a',
    vars: shell('15 23 42', '30 41 59', '38 50 71', '241 245 249', '176 188 204', '124 136 154', '51 65 85'),
  },
  {
    id: 'ocean', name: 'Deep Ocean', swatch: '#051116',
    vars: shell('5 17 22', '14 32 40', '20 41 51', '230 244 248', '166 192 201', '116 143 152', '32 57 68'),
  },
  {
    id: 'forest', name: 'Forest', swatch: '#06120e',
    vars: shell('6 18 14', '16 32 26', '22 41 34', '231 245 238', '168 194 183', '118 145 134', '34 58 49'),
  },
  {
    id: 'plum', name: 'Plum', swatch: '#0f0918',
    vars: shell('15 9 24', '30 21 45', '39 28 57', '240 234 249', '189 178 205', '137 126 155', '55 41 78'),
  },
  {
    id: 'espresso', name: 'Espresso', swatch: '#140e0a',
    vars: shell('20 14 10', '38 28 22', '48 36 28', '247 240 233', '201 189 177', '150 138 126', '62 48 38'),
  },
];

export const DEFAULT_SURFACE = 'midnight';

// Lightness/saturation recipe for a custom dark shell built from one hex. The
// hue is taken from the input; saturation is capped hard because a fully
// saturated page background is unreadable behind a whole app, and the ink stays
// only faintly tinted so body copy reads as text rather than as coloured text.
const SHELL_RECIPE = [
  ['--bg', 0.055, 0.55], ['--surface', 0.115, 0.42], ['--panel', 0.150, 0.38],
  ['--line', 0.225, 0.30],
  ['--ink', 0.940, 0.14], ['--ink-2', 0.730, 0.12], ['--ink-3', 0.545, 0.11],
];

export function darkShellFromHex(hex) {
  const rgb = hexToRgb(hex);
  if (!rgb) return null;
  const [h, s] = rgbToHsl(rgb);
  const base = Math.min(0.85, Math.max(0.05, s));
  const out = {};
  for (const [name, l, satCap] of SHELL_RECIPE) {
    out[name] = hslToRgb(h, Math.min(base, satCap), l).join(' ');
  }
  out['--chart-line'] = out['--line'];
  return out;
}

export const findSurface = (id) => SURFACE_PRESETS.find((p) => p.id === id);

export function resolveShell(id, hex) {
  if (id === 'custom') {
    const custom = darkShellFromHex(hex);
    if (custom) return custom;
  }
  return (findSurface(id) || findSurface(DEFAULT_SURFACE)).vars;
}

// The dark shell CANNOT be applied as inline style on <html> the way the brand
// ramps are — those variables must only exist while data-theme='dark', or the
// light theme would inherit a black page background. So it ships as a real rule
// in an injected <style>.
//
// The selector is `html[data-theme='dark']` (specificity 0,1,1) rather than
// index.css's `[data-theme='dark']` (0,1,0). Winning on specificity rather than
// document order matters: the pre-paint script in index.html appends this tag
// BEFORE the CSS bundle's <link>, so an order-based tie would lose on load.
export function shellCss(vars) {
  const body = Object.entries(vars).map(([k, v]) => `${k}:${v}`).join(';');
  return `html[data-theme='dark']{${body}}`;
}

export const SHELL_STYLE_ID = 'fv-dark-shell';

export function applyShellCss(css) {
  let el = document.getElementById(SHELL_STYLE_ID);
  if (!el) {
    el = document.createElement('style');
    el.id = SHELL_STYLE_ID;
    document.head.appendChild(el);
  }
  el.textContent = css;
}

// ---------------------------------------------------------------------------
// Custom colour → ramp
// ---------------------------------------------------------------------------

export function hexToRgb(hex) {
  const m = String(hex || '').trim().replace('#', '');
  const full = m.length === 3 ? m.split('').map((c) => c + c).join('') : m;
  if (!/^[0-9a-fA-F]{6}$/.test(full)) return null;
  const n = parseInt(full, 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

export function rgbToHex([r, g, b]) {
  return `#${[r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')}`;
}

function rgbToHsl([r, g, b]) {
  const R = r / 255, G = g / 255, B = b / 255;
  const max = Math.max(R, G, B), min = Math.min(R, G, B), d = max - min;
  let h = 0;
  if (d) {
    if (max === R) h = ((G - B) / d) % 6;
    else if (max === G) h = (B - R) / d + 2;
    else h = (R - G) / d + 4;
    h *= 60;
    if (h < 0) h += 360;
  }
  const l = (max + min) / 2;
  const s = d ? d / (1 - Math.abs(2 * l - 1)) : 0;
  return [h, s, l];
}

function hslToRgb(h, s, l) {
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = l - c / 2;
  const seg = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][Math.floor(h / 60) % 6];
  return seg.map((v) => Math.round((v + m) * 255));
}

// Lightness targets per stop. Chosen so white text stays legible on 500→950 and
// dark text stays legible on 50→200 — the two contrast pairs the UI actually
// uses (filled buttons vs. tinted badges).
const STOPS = [
  [50, 0.965, 0.72], [100, 0.925, 0.80], [200, 0.855, 0.88], [300, 0.755, 0.95],
  [400, 0.645, 1.00], [500, 0.545, 1.00], [600, 0.455, 0.98], [700, 0.375, 0.94],
  [800, 0.305, 0.88], [900, 0.245, 0.82], [950, 0.150, 0.74],
];

// Build a full 50→950 ramp from one hex. The input's hue is preserved exactly;
// saturation is anchored to the input (clamped away from the washed-out and
// neon extremes) and lightness is driven by the table above.
export function rampFromHex(hex) {
  const rgb = hexToRgb(hex);
  if (!rgb) return null;
  const [h, s] = rgbToHsl(rgb);
  const base = Math.min(0.92, Math.max(0.18, s));
  const out = {};
  for (const [stop, l, satMul] of STOPS) {
    out[stop] = hslToRgb(h, Math.min(1, base * satMul), l).join(' ');
  }
  return out;
}

export const findBrand = (id) => BRAND_PRESETS.find((p) => p.id === id);
export const findAccent = (id) => ACCENT_PRESETS.find((p) => p.id === id);

// Resolve a {preset id | 'custom'} + hex pair into an actual ramp, falling back
// to the shipped default if the stored value is unknown (a preset we renamed,
// a corrupt localStorage entry) so the app never boots colourless.
export function resolveRamp(kind, id, hex) {
  const presets = kind === 'accent' ? ACCENT_PRESETS : BRAND_PRESETS;
  const fallback = kind === 'accent' ? DEFAULT_ACCENT : DEFAULT_BRAND;
  if (id === 'custom') {
    const custom = rampFromHex(hex);
    if (custom) return custom;
  }
  return (presets.find((p) => p.id === id) || presets.find((p) => p.id === fallback)).ramp;
}

// Flatten both ramps into the exact CSS custom properties index.css declares.
// Returned as a plain map so it can be (a) applied now and (b) serialised into
// localStorage for the pre-paint script in public/index.html to replay before
// React mounts — no flash of the previous colour.
export function paletteVars(brandRamp, accentRamp) {
  const vars = {};
  for (const stop of [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]) {
    vars[`--brand-${stop}`] = brandRamp[stop];
  }
  // index.css only declares --accent-50…900; 950 is deliberately absent (the
  // violet-950 utility maps to --brand-950). Writing it anyway would be dead.
  for (const stop of [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]) {
    vars[`--accent-${stop}`] = accentRamp[stop];
  }
  return vars;
}

export function applyVars(vars) {
  const root = document.documentElement;
  for (const [k, v] of Object.entries(vars)) root.style.setProperty(k, v);
}
