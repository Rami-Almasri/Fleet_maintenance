// Shared internals for the bespoke SVG charts (BarChart, LineChart).
// Same gradient palette and theme tokens as Gauge.js / Progress.js, so a chart
// looks like it was born in the project — no chart library, no foreign styling.

import { useEffect, useRef, useState } from 'react';

// Named palettes → [from, to] gradient stops. Mirrors Gauge.js PALETTES exactly.
export const PALETTES = {
  indigo:  { from: '#B37F0A', to: '#FACC15' },
  brand:   { from: '#B37F0A', to: '#FACC15' },
  emerald: { from: '#10b981', to: '#34d399' },
  success: { from: '#10b981', to: '#34d399' },
  blue:    { from: '#3b82f6', to: '#60a5fa' },
  amber:   { from: '#f59e0b', to: '#fbbf24' },
  orange:  { from: '#f97316', to: '#fb923c' },
  alert:   { from: '#f97316', to: '#fb923c' },
  red:     { from: '#ef4444', to: '#f87171' },
  violet:  { from: '#D9A310', to: '#FDE047' },
  cyan:    { from: '#06b6d4', to: '#22d3ee' },
  teal:    { from: '#2dd4bf', to: '#5eead4' },
  purple:  { from: '#8b5cf6', to: '#a78bfa' },
  slate:   { from: '#64748b', to: '#94a3b8' },
};
export const palette = (key) => PALETTES[key] || PALETTES.indigo;

// Theme tokens — resolve to the right colour in both Platinum (light) and Cockpit (dark).
export const LINE = 'rgb(var(--line))';

let UID = 0;
// A stable unique id (for <defs> gradient ids that must not collide across charts).
export function useUid(prefix = 'c') {
  const [id] = useState(() => `${prefix}${++UID}`);
  return id;
}

// Measure a container's width and keep it current as the layout reflows, so the
// chart is fluid/responsive without a chart library. Returns [ref, width].
export function useChartWidth(fallback = 640) {
  const ref = useRef(null);
  const [width, setWidth] = useState(fallback);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => setWidth(el.clientWidth || fallback);
    measure();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', measure);
      return () => window.removeEventListener('resize', measure);
    }
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [fallback]);

  return [ref, width];
}

// Flip on after first paint so CSS transitions animate from the initial state.
export function useMounted() {
  const [on, setOn] = useState(false);
  useEffect(() => {
    const id = requestAnimationFrame(() => setOn(true));
    return () => cancelAnimationFrame(id);
  }, []);
  return on;
}

// Round a raw maximum up to a clean axis ceiling (e.g. 4180 → 5000, 7 → 8) so
// gridlines land on friendly numbers.
export function niceMax(raw) {
  if (!raw || raw <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(raw)));
  const n = raw / pow;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return step * pow;
}

// A "nice" axis scale: pick a clean step (1/2/2.5/5/10 × 10ⁿ) so gridlines land on
// friendly, evenly-spaced numbers. Returns { max, ticks } — e.g. rawMax 10 →
// {max:10, ticks:[0,2,4,6,8,10]} instead of the ugly 0/2.5/5/7.5/10. Pass
// integer=true for count data so steps never go fractional (0,1 not 0,0.2,0.4…).
export function niceScale(rawMax, maxTicks = 5, integer = false) {
  const max0 = rawMax > 0 ? rawMax : 1;
  const rough = max0 / Math.max(1, maxTicks);
  const pow = Math.pow(10, Math.floor(Math.log10(rough || 1)));
  const norm = rough / pow;
  let step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * pow;
  if (integer) step = Math.max(1, Math.round(step));
  const max = Math.ceil(max0 / step) * step;
  const ticks = [];
  for (let v = 0; v <= max + 1e-9; v += step) ticks.push(v);
  return { max, ticks };
}

// Catmull-Rom → cubic-bezier: a smooth path through the given [x,y] points.
// Gives the trend line its premium, flowing feel (vs. jagged straight segments).
export function smoothPath(pts) {
  if (pts.length < 2) return pts.length ? `M ${pts[0][0]} ${pts[0][1]}` : '';
  let d = `M ${pts[0][0]} ${pts[0][1]}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] || pts[i];
    const p1 = pts[i];
    const p2 = pts[i + 1];
    const p3 = pts[i + 2] || p2;
    const c1x = p1[0] + (p2[0] - p0[0]) / 6;
    const c1y = p1[1] + (p2[1] - p0[1]) / 6;
    const c2x = p2[0] - (p3[0] - p1[0]) / 6;
    const c2y = p2[1] - (p3[1] - p1[1]) / 6;
    d += ` C ${c1x} ${c1y} ${c2x} ${c2y} ${p2[0]} ${p2[1]}`;
  }
  return d;
}
