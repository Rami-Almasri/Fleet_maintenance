// FleetStatusCard — a self-contained "Fleet Status" panel: a segmented donut
// with rounded, gapped arcs floating over a soft colour glow, a big count-up
// total in the middle, a period switcher in the header, and a legend of rows
// with per-status counts.
//
// Pure SVG + requestAnimationFrame (no chart library), so it matches the rest
// of the design system and themes cleanly in Platinum / Cockpit.
//
//   <FleetStatusCard
//     title="Fleet Status"
//     centerLabel="Total Fleet"
//     unit="cars"
//     segments={[
//       { label: 'Available',   value: 6,  color: 'green' },
//       { label: 'On Rent',     value: 22, color: 'blue' },
//       { label: 'Maintenance', value: 4,  color: 'yellow' },
//     ]}
//     periods={['Week', 'Month', 'Quarter', 'Year']}
//     period={period}
//     onPeriodChange={setPeriod}
//   />

import { useEffect, useState } from 'react';
import { useCountUp } from './Gauge';

// Flat, saturated status colours matching the reference (Google-ish palette).
// Each has a soft tint used only for the drop-glow behind its arc.
const COLORS = {
  green:  { solid: '#22C55E', glow: 'rgba(34,197,94,0.45)' },
  blue:   { solid: '#2F7EF6', glow: 'rgba(47,126,246,0.45)' },
  yellow: { solid: '#F5C518', glow: 'rgba(245,197,24,0.45)' },
  red:    { solid: '#F97316', glow: 'rgba(249,115,22,0.45)' },
  orange: { solid: '#F97316', glow: 'rgba(249,115,22,0.45)' },
  slate:  { solid: '#94A3B8', glow: 'rgba(148,163,184,0.40)' },
  violet: { solid: '#8B5CF6', glow: 'rgba(139,92,246,0.45)' },
  cyan:   { solid: '#06B6D4', glow: 'rgba(6,182,212,0.45)' },
};
const color = (k) => COLORS[k] || COLORS.slate;

let GID = 0;

export default function FleetStatusCard({
  title = 'Fleet Status',
  centerLabel = 'Total Fleet',
  unit = 'cars',
  segments = [],
  total = null,
  periods = ['Week', 'Month', 'Quarter', 'Year'],
  period = 'Month',
  onPeriodChange,
  headerRight = null,
  size = 240,
  stroke = 26,
  className = '',
}) {
  const [gid] = useState(() => `fsc${++GID}`);
  const data = segments.filter((s) => (s.value || 0) > 0);
  const sum = total != null ? total : data.reduce((a, s) => a + (s.value || 0), 0);

  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  // A small visual gap between adjacent arcs (in circumference units).
  const gap = data.length > 1 ? Math.min(10, c * 0.012) : 0;

  // Grow the arcs in on mount, and count the centre number up alongside.
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(1));
    return () => cancelAnimationFrame(id);
  }, [sum, period]);
  const display = useCountUp(sum);

  // Walk segments, accumulating a running offset so each arc starts where the
  // previous one ended. Each arc is shortened by `gap` and nudged forward by
  // half a gap so the rounded caps sit inside their slot with even spacing.
  let acc = 0;
  const arcs = data.map((s) => {
    const frac = sum ? (s.value || 0) / sum : 0;
    const full = c * frac;
    const len = Math.max(0, full - gap);
    const arc = { ...s, len, offset: acc + gap / 2 };
    acc += full;
    return arc;
  });

  return (
    <div
      className={`rounded-2xl border border-slate-200/60 bg-white p-6 shadow-soft ${className}`}
    >
      {/* Header — title + period switcher */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold tracking-tight text-slate-900">{title}</h2>
        {/* Header-right: an explicit node wins; otherwise a period switcher if
            periods are supplied; otherwise nothing. Fleet status is a live "now"
            snapshot, so callers pass a Live badge rather than a fake filter. */}
        {headerRight ? (
          headerRight
        ) : periods && periods.length ? (
          <div className="relative">
            <select
              value={period}
              onChange={(e) => onPeriodChange?.(e.target.value)}
              disabled={!onPeriodChange}
              className="appearance-none rounded-lg border border-slate-200 bg-white py-1.5 ps-3 pe-8 text-sm font-medium text-slate-700 shadow-sm outline-none transition hover:border-slate-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 disabled:cursor-default disabled:opacity-100"
            >
              {periods.map((p) => (
                <option key={p} value={p}>{p}</option>
              ))}
            </select>
            <svg
              className="pointer-events-none absolute end-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 9l6 6 6-6" />
            </svg>
          </div>
        ) : null}
      </div>

      {/* Donut */}
      <div className="flex justify-center py-6">
        <div className="relative" style={{ width: size, height: size }}>
          <svg width={size} height={size} className="-rotate-90 overflow-visible">
            <defs>
              <filter id={`${gid}-blur`} x="-30%" y="-30%" width="160%" height="160%">
                <feGaussianBlur stdDeviation="7" />
              </filter>
            </defs>

            {/* Track */}
            <circle
              cx={size / 2} cy={size / 2} r={r}
              fill="none" stroke="rgb(var(--line))" strokeWidth={stroke}
              opacity="0.5"
            />

            {/* Soft colour glow — a blurred, offset copy of each arc sitting
                behind the crisp ring to give it the lifted, luminous look. */}
            <g filter={`url(#${gid}-blur)`} opacity="0.9" style={{ transform: 'translateY(6px)' }}>
              {arcs.map((a, i) => (
                <circle
                  key={i}
                  cx={size / 2} cy={size / 2} r={r}
                  fill="none" stroke={color(a.color).glow} strokeWidth={stroke}
                  strokeLinecap="round"
                  strokeDasharray={`${a.len * grow} ${c - a.len * grow}`}
                  strokeDashoffset={-a.offset * grow}
                  style={{ transition: 'stroke-dasharray 1.1s cubic-bezier(0.22,1,0.36,1), stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
                />
              ))}
            </g>

            {/* Crisp coloured arcs */}
            {arcs.map((a, i) => (
              <circle
                key={i}
                cx={size / 2} cy={size / 2} r={r}
                fill="none" stroke={color(a.color).solid} strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={`${a.len * grow} ${c - a.len * grow}`}
                strokeDashoffset={-a.offset * grow}
                style={{ transition: 'stroke-dasharray 1.1s cubic-bezier(0.22,1,0.36,1), stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
              />
            ))}
          </svg>

          {/* Centre total */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-display text-5xl font-extrabold tracking-tight text-slate-900 tabular-nums">
              {Math.round(display).toLocaleString()}
            </span>
            <span className="mt-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">
              {centerLabel}
            </span>
          </div>
        </div>
      </div>

      {/* Legend */}
      <div className="divide-y divide-slate-100 border-t border-slate-100">
        {segments.map((s, i) => (
          <div key={i} className="flex items-center gap-3 py-3">
            <span
              className="h-3.5 w-3.5 shrink-0 rounded"
              style={{ backgroundColor: color(s.color).solid }}
            />
            <span className="flex-1 text-sm font-medium text-slate-600">{s.label}</span>
            <span className="text-sm font-semibold text-slate-900 tabular-nums">
              {(s.value || 0).toLocaleString()} {unit}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
