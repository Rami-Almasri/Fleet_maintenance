// Premium, dependency-free circular indicators (pure SVG).
//   • useCountUp   — animate a number from 0 → value on mount
//   • RadialGauge  — a single animated progress ring with a count-up number in
//                    the middle (the "circle that shows the number")
//   • FleetDonut   — a multi-segment composition donut with a legend
//
// No chart library: just SVG + requestAnimationFrame, so it stays light and
// matches the existing Tailwind look.

import { useEffect, useRef, useState } from 'react';

const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

// Animate from 0 to `end` over `duration` ms. Returns the current value.
export function useCountUp(end = 0, duration = 1100) {
  const [val, setVal] = useState(0);
  const fromRef = useRef(0);
  const rafRef = useRef(0);

  useEffect(() => {
    const start = fromRef.current;
    const delta = (end || 0) - start;
    let startTs = null;

    const tick = (ts) => {
      if (startTs === null) startTs = ts;
      const p = Math.min(1, (ts - startTs) / duration);
      setVal(start + delta * easeOutCubic(p));
      if (p < 1) rafRef.current = requestAnimationFrame(tick);
      else fromRef.current = end || 0;
    };

    rafRef.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(rafRef.current);
  }, [end, duration]);

  return val;
}

// Named palettes → [from, to] gradient stops. The ring track uses the themed
// --line token so gauges read correctly in both Platinum and Cockpit.
const TRACK = 'rgb(var(--line))';
const PALETTES = {
  indigo:  { from: '#B37F0A', to: '#FACC15' },   // brand: molten gold → electric yellow
  brand:   { from: '#B37F0A', to: '#FACC15' },
  emerald: { from: '#10b981', to: '#34d399' },   // success green
  success: { from: '#10b981', to: '#34d399' },
  blue:    { from: '#3b82f6', to: '#60a5fa' },
  amber:   { from: '#f59e0b', to: '#fbbf24' },
  orange:  { from: '#f97316', to: '#fb923c' },   // alert orange
  alert:   { from: '#f97316', to: '#fb923c' },
  red:     { from: '#ef4444', to: '#f87171' },
  violet:  { from: '#D9A310', to: '#FDE047' },   // accent: bright yellow sheen
  cyan:    { from: '#06b6d4', to: '#22d3ee' },
  slate:   { from: '#64748b', to: '#94a3b8' },
};

let GID = 0; // unique gradient ids so multiple gauges don't collide

/**
 * A single circular progress ring with the value rendered in the centre.
 *
 * @param value     the number to display (and count up to)
 * @param max       denominator for the ring fill (defaults to value → full ring)
 * @param color     palette key (indigo | emerald | blue | amber | red | violet | cyan | slate)
 * @param label     caption under the number
 * @param size      diameter in px
 * @param format    optional formatter for the centre value
 */
export function RadialGauge({
  value = 0,
  max = null,
  color = 'indigo',
  label,
  size = 132,
  stroke = 11,
  format,
}) {
  const pal = PALETTES[color] || PALETTES.indigo;
  const [gid] = useState(() => `g${++GID}`);

  const denom = max == null ? value || 1 : max || 1;
  const ratio = Math.max(0, Math.min(1, (value || 0) / denom));

  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;

  // Animate the arc fill alongside the number.
  const [shown, setShown] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setShown(ratio));
    return () => cancelAnimationFrame(id);
  }, [ratio]);
  const display = useCountUp(value);

  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <defs>
          <linearGradient id={gid} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor={pal.from} />
            <stop offset="100%" stopColor={pal.to} />
          </linearGradient>
        </defs>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={TRACK} strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={`url(#${gid})`}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - shown)}
          style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-display text-3xl font-bold tracking-tight text-slate-900 tabular-nums">
          {format ? format(display) : Math.round(display).toLocaleString()}
        </span>
        {max != null && (
          <span className="text-[11px] font-medium text-slate-400 tabular-nums">of {max.toLocaleString()}</span>
        )}
      </div>
      {label && (
        <span className="absolute -bottom-0 translate-y-full pt-2 text-center text-xs font-semibold text-slate-500">
          {label}
        </span>
      )}
    </div>
  );
}

/**
 * A multi-segment composition donut (e.g. fleet status mix) with the total in
 * the centre and a colour legend.
 *
 * @param segments  [{ label, value, color }]  color = palette key
 * @param total     centre number (defaults to sum of segments)
 * @param centerLabel caption under the total
 */
export function FleetDonut({ segments = [], total = null, centerLabel = 'Total', size = 200, stroke = 22 }) {
  const data = segments.filter((s) => (s.value || 0) > 0);
  const sum = total != null ? total : data.reduce((a, s) => a + (s.value || 0), 0);
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;

  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(1));
    return () => cancelAnimationFrame(id);
  }, [sum]);
  const display = useCountUp(sum);

  // Walk the segments, accumulating offset so each arc starts where the last ended.
  let acc = 0;
  const arcs = data.map((s) => {
    const frac = sum ? (s.value || 0) / sum : 0;
    const arc = { ...s, frac, offset: acc };
    acc += frac;
    return arc;
  });

  return (
    <div className="flex flex-col items-center gap-5">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={TRACK} strokeWidth={stroke} />
          {arcs.map((a, i) => {
            const pal = PALETTES[a.color] || PALETTES.indigo;
            const len = c * a.frac * grow;
            return (
              <circle
                key={i}
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={pal.from}
                strokeWidth={stroke}
                strokeLinecap="butt"
                strokeDasharray={`${len} ${c - len}`}
                strokeDashoffset={-c * a.offset * grow}
                style={{ transition: 'stroke-dasharray 1.1s cubic-bezier(0.22,1,0.36,1), stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
              />
            );
          })}
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className="font-display text-4xl font-bold tracking-tight text-slate-900 tabular-nums">
            {Math.round(display).toLocaleString()}
          </span>
          <span className="text-xs font-medium text-slate-400">{centerLabel}</span>
        </div>
      </div>

      <div className="flex w-full flex-col gap-y-2.5">
        {arcs.map((a, i) => {
          const pal = PALETTES[a.color] || PALETTES.indigo;
          const pct = sum ? Math.round((a.value / sum) * 100) : 0;
          return (
            <div key={i} className="flex items-center gap-3">
              <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: pal.from }} />
              <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-600">{a.label}</span>
              <span className="shrink-0 text-sm font-bold text-slate-900 tabular-nums">{a.value}</span>
              <span className="w-10 shrink-0 text-end text-xs font-medium text-slate-400 tabular-nums">{pct}%</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
