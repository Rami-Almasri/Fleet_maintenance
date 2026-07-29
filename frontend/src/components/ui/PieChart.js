// A bespoke, dependency-free SVG pie chart with a legend — the "product analysis"
// card. Full pie (not a donut), animated grow-in, and a slice that pops + shows a
// tooltip on hover. Same palette/tokens as the rest of the chart family.
//
//   <PieChart
//     segments={[
//       { label: 'Rental', value: 12, color: 'purple' },
//       { label: 'Maintenance', value: 5, color: 'teal' },
//     ]}
//     size={210}
//   />
//
// `stacked` puts the legend BELOW the pie at every width. The default side-by-side
// layout flips on the viewport's sm: breakpoint, which is wrong inside a narrow card
// (a third-width panel leaves the legend ~180px and its labels/numbers get squeezed) —
// pass stacked whenever the chart lives in a column rather than a full-width card.

import { useState } from 'react';
import { palette, useMounted } from './chartUtils';
import { ChartTooltip } from './Tooltip';

const resolve = (c) => (typeof c === 'string' && c.startsWith('#') ? c : palette(c).from);

// Point on the circle for an angle measured clockwise from 12 o'clock.
const pt = (cx, cy, r, deg) => {
  const a = ((deg - 90) * Math.PI) / 180;
  return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
};

export default function PieChart({ segments = [], size = 210, stacked = false, className = '' }) {
  const mounted = useMounted();
  const [hover, setHover] = useState(null);
  const [tip, setTip] = useState(null);

  const data = segments.filter((s) => (s.value || 0) > 0);
  const sum = data.reduce((a, s) => a + (s.value || 0), 0);
  const cx = size / 2, cy = size / 2, r = size / 2;

  // Walk the slices, accumulating the start angle.
  let acc = 0;
  const arcs = data.map((s) => {
    const frac = sum ? (s.value || 0) / sum : 0;
    const a0 = acc * 360;
    const a1 = (acc + frac) * 360;
    acc += frac;
    const large = a1 - a0 > 180 ? 1 : 0;
    const [x0, y0] = pt(cx, cy, r, a0);
    const [x1, y1] = pt(cx, cy, r, a1);
    // Full-circle guard: a single 100% slice can't be drawn as one arc.
    const d = frac >= 0.9999
      ? `M ${cx} ${cy - r} A ${r} ${r} 0 1 1 ${cx - 0.01} ${cy - r} Z`
      : `M ${cx} ${cy} L ${x0} ${y0} A ${r} ${r} 0 ${large} 1 ${x1} ${y1} Z`;
    // Direction the slice pops toward on hover (its mid-angle).
    const mid = ((a0 + a1) / 2 - 90) * Math.PI / 180;
    return { ...s, frac, color: resolve(s.color), d, dx: Math.cos(mid) * 7, dy: Math.sin(mid) * 7 };
  });

  const move = (e, a) => setTip({
    x: e.clientX, y: e.clientY,
    content: (
      <div>
        <div className="font-bold tabular-nums">{a.label}</div>
        <div className="mt-0.5 text-white/70 tabular-nums">{a.value} · {Math.round(a.frac * 100)}%</div>
      </div>
    ),
  });

  return (
    <div
      className={`flex flex-col items-center gap-5 ${stacked ? '' : 'sm:flex-row sm:gap-7'} ${className}`}
    >
      <div className="shrink-0" style={{ width: size, height: size }}>
        <svg
          width={size} height={size} viewBox={`0 0 ${size} ${size}`}
          role="img" aria-label="Composition pie chart"
          style={{ transform: mounted ? 'scale(1)' : 'scale(0.85)', opacity: mounted ? 1 : 0, transition: 'transform .7s cubic-bezier(0.22,1,0.36,1), opacity .5s ease', transformOrigin: 'center' }}
        >
          {arcs.map((a, i) => {
            const on = hover === i;
            return (
              <path
                key={i}
                d={a.d}
                fill={a.color}
                stroke="rgb(var(--surface))"
                strokeWidth={2}
                style={{
                  cursor: 'pointer',
                  opacity: hover == null || on ? 1 : 0.55,
                  transform: on ? `translate(${a.dx}px, ${a.dy}px)` : 'none',
                  transition: 'transform .2s ease, opacity .2s ease',
                }}
                onMouseEnter={(e) => { setHover(i); move(e, a); }}
                onMouseMove={(e) => move(e, a)}
                onMouseLeave={() => { setHover(null); setTip(null); }}
              />
            );
          })}
        </svg>
      </div>

      {/* legend */}
      <div
        className={
          stacked
            ? 'flex w-full flex-col gap-2'
            : 'grid w-full grid-cols-2 gap-x-6 gap-y-2.5 sm:flex sm:flex-col'
        }
      >
        {arcs.map((a, i) => (
          <div
            key={i}
            className="flex items-center gap-2.5 transition"
            style={{ opacity: hover == null || hover === i ? 1 : 0.5 }}
            onMouseEnter={() => setHover(i)}
            onMouseLeave={() => setHover(null)}
          >
            <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: a.color }} />
            <span className="flex-1 truncate text-sm font-medium text-slate-600">{a.label}</span>
            {/* min-w keeps the count and the % from collapsing into each other in a narrow card */}
            <span className="min-w-[2ch] shrink-0 text-right text-sm font-bold text-slate-900 tabular-nums">{a.value}</span>
            <span className="w-10 shrink-0 text-right text-xs font-semibold text-slate-500 tabular-nums">{Math.round(a.frac * 100)}%</span>
          </div>
        ))}
      </div>
      <ChartTooltip tip={tip} />
    </div>
  );
}
