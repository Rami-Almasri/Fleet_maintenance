// A composition donut with a side-by-side legend — the "architecture" card used on
// the Vehicle Overview dashboard (Revenue / Expense / Fault distribution). A ring on
// the left with a TOTAL in its hole, and a legend on the right where each row shows
// the segment label, its formatted value and its share of the whole. Dependency-free
// SVG, animated grow-in, hover-sync between arc and legend row — same palette/tokens
// as the rest of the chart family (FleetDonut / PieChart), no chart library.
//
//   <CompositionDonut
//     segments={[{ label: 'Rents', value: 289900, color: 'blue' }, …]}
//     total={384533}                    // defaults to the sum of segments
//     format={aed2}                     // how each value + the centre total print
//     centerLabel="Total"
//     onSelect={(seg) => …}              // optional: makes arcs + legend rows a drill-down
//   />
//
// onSelect receives the segment that was clicked. For a folded "Other" row the legend keeps its
// existing expand-on-click (the arrow reveals the children) and each CHILD row is what drills;
// clicking the "Other" ARC selects the whole folded group, children and all.

import { useEffect, useState } from 'react';
import { palette, LINE } from './chartUtils';
import { useCountUp } from './Gauge';
import { ChartTooltip } from './Tooltip';

const resolve = (c) => (typeof c === 'string' && c.startsWith('#') ? c : palette(c).from);

export default function CompositionDonut({
  segments = [],
  total = null,
  format = (n) => Math.round(n).toLocaleString(),
  centerLabel = 'Total',
  size = 176,
  stroke = 24,
  className = '',
  onSelect = null,
}) {
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

  const [active, setActive] = useState(null);
  const [tip, setTip] = useState(null);
  const [expanded, setExpanded] = useState(null); // legend index whose folded "children" are shown

  // Walk the segments, accumulating offset so each arc starts where the last ended.
  let acc = 0;
  const arcs = data.map((s, i) => {
    const frac = sum ? (s.value || 0) / sum : 0;
    const arc = { ...s, frac, offset: acc, i, color: resolve(s.color) };
    acc += frac;
    return arc;
  });

  const showTip = (a) => (e) => {
    setActive(a.i);
    setTip({
      x: e.clientX, y: e.clientY,
      content: (
        <div>
          <div className="text-[10px] font-semibold uppercase tracking-wide text-white/60">{a.label}</div>
          <div className="mt-0.5 font-bold tabular-nums">{format(a.value)}</div>
          <div className="tabular-nums text-white/70">{(a.frac * 100).toFixed(1)}%</div>
        </div>
      ),
    });
  };
  const clear = () => { setActive(null); setTip(null); };

  if (!data.length) {
    return (
      <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
        No data to chart yet.
      </div>
    );
  }

  return (
    <div className={`flex flex-col items-center gap-6 sm:flex-row sm:gap-7 ${className}`}>
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={LINE} strokeWidth={stroke} />
          {arcs.map((a) => {
            const len = c * a.frac * grow;
            const dim = active != null && active !== a.i;
            return (
              <circle
                key={a.label}
                cx={size / 2} cy={size / 2} r={r} fill="none"
                stroke={a.color} strokeWidth={stroke} strokeLinecap="butt"
                strokeDasharray={`${len} ${c - len}`}
                strokeDashoffset={-c * a.offset * grow}
                opacity={dim ? 0.35 : 1}
                onMouseMove={showTip(a)}
                onMouseLeave={clear}
                onClick={onSelect ? () => { clear(); onSelect(a); } : undefined}
                cursor={onSelect ? 'pointer' : undefined}
                style={{ transition: 'stroke-dasharray 1.1s cubic-bezier(0.22,1,0.36,1), stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1), opacity 0.2s' }}
              />
            );
          })}
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{centerLabel}</span>
          <span className="font-display text-xl font-bold tracking-tight text-slate-900 tabular-nums">
            {format(display)}
          </span>
        </div>
      </div>

      {/* legend — hover-synced with the arcs. min-w-0 + flex-1 so long labels TRUNCATE
          instead of pushing the percentage column off the (often narrow) card. */}
      <div className="w-full min-w-0 flex-1 space-y-1.5">
        {arcs.map((a) => {
          const dim = active != null && active !== a.i;
          const kids = Array.isArray(a.children) ? a.children.filter((k) => (k.value || 0) > 0) : [];
          const isOpen = expanded === a.i;
          // A row with folded children keeps click-to-expand; only a leaf row drills.
          const act = kids.length
            ? () => setExpanded(isOpen ? null : a.i)
            : onSelect ? () => onSelect(a) : null;
          return (
            <div key={a.label}>
              <div
                className={`flex items-center gap-2.5 transition ${act ? 'cursor-pointer select-none rounded-md hover:bg-slate-500/5' : ''}`}
                style={{ opacity: dim ? 0.45 : 1 }}
                onMouseEnter={() => setActive(a.i)}
                onMouseLeave={() => setActive(null)}
                onClick={act || undefined}
                role={act ? 'button' : undefined}
                tabIndex={act ? 0 : undefined}
                onKeyDown={act ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } } : undefined}
              >
                <span className="h-3 w-3 shrink-0 rounded-sm" style={{ backgroundColor: a.color }} />
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-600">
                  {a.label}
                  {kids.length > 0 && (
                    <span className="ms-1 text-slate-400">{isOpen ? '▾' : '▸'}</span>
                  )}
                </span>
                <span className="shrink-0 text-end text-xs font-semibold tabular-nums text-slate-400">
                  {(a.frac * 100).toFixed(1)}%
                </span>
              </div>
              {isOpen && kids.length > 0 && (
                <div className="mt-1 space-y-1 border-s border-slate-200 ps-3 ms-1.5">
                  {kids.map((k) => (
                    <div
                      key={k.key || k.label}
                      className={`flex items-center gap-2.5 ${onSelect ? 'cursor-pointer select-none rounded-md hover:bg-slate-500/5' : ''}`}
                      onClick={onSelect ? () => onSelect(k) : undefined}
                      role={onSelect ? 'button' : undefined}
                      tabIndex={onSelect ? 0 : undefined}
                      onKeyDown={onSelect ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(k); } } : undefined}
                    >
                      <span className="min-w-0 flex-1 truncate text-xs text-slate-500">{k.label}</span>
                      <span className="shrink-0 text-[11px] tabular-nums text-slate-400">
                        {format(k.value)} · {(sum ? (k.value / sum) * 100 : 0).toFixed(1)}%
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
      <ChartTooltip tip={tip} />
    </div>
  );
}
