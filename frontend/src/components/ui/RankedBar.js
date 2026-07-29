// A horizontal ranked bar list — "the top / bottom N entities by one metric".
// The shape almost every management page needs (worst downtime, biggest spend,
// most visits, best net profit) and the one the SVG family didn't cover: BarChart
// is vertical, so entity labels like "A-12345 · Toyota Corolla" never fit.
//
// Built in HTML/CSS rather than SVG so labels truncate properly and can be real
// <Link>s, but wearing the same clothes as the SVG charts — chartUtils gradients,
// rgb(var(--line)) tracks, the shared cursor tooltip and the same grow-in easing.
//
//   <RankedBar
//     items={[{ label: 'A-12345', sub: 'Toyota Corolla', value: 4200, to: '/vehicles/7' }, …]}
//     format={aed2} color="amber" valueLabel="Maintenance"
//   />
//
//   // diverging: profit above / loss below a centre zero line
//   <RankedBar items={…} diverging format={aed2} valueLabel="Net profit" />

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ChartTooltip } from './Tooltip';
import { palette, useMounted, LINE } from './chartUtils';

const resolve = (c) =>
  typeof c === 'string' && c.startsWith('#') ? { from: c, to: c } : palette(c);

// A left→right gradient fill, same stops the SVG bars use.
const fill = (c) => {
  const p = resolve(c);
  return `linear-gradient(90deg, ${p.from}, ${p.to})`;
};

export default function RankedBar({
  items = [],
  format = (n) => Math.round(n).toLocaleString(),
  color = 'indigo',           // single-direction bar colour
  positiveColor = 'emerald',  // diverging: value > 0
  negativeColor = 'red',      // diverging: value < 0
  diverging = false,
  valueLabel = 'Value',
  tooltip,                    // optional (item) => node, an extra tooltip line
  showRank = false,
  labelWidth = 132,
  valueWidth = 104,
  empty = 'Nothing to rank yet.',
  className = '',
}) {
  const mounted = useMounted();
  const [tip, setTip] = useState(null);
  const [active, setActive] = useState(null);

  const rows = items.filter(Boolean);
  // Scale off the largest magnitude so the longest bar fills the track exactly.
  const max = Math.max(1, ...rows.map((r) => Math.abs(r.value || 0)));

  if (!rows.length) {
    return <div className="flex h-[120px] items-center justify-center text-sm text-slate-400">{empty}</div>;
  }

  const showTip = (r, i) => (e) => {
    setActive(i);
    setTip({
      x: e.clientX,
      y: e.clientY,
      content: (
        <div>
          <div className="text-[10px] font-semibold uppercase tracking-wide text-white/60">
            {r.label}{r.sub ? ` · ${r.sub}` : ''}
          </div>
          <div className="mt-0.5 font-bold tabular-nums">{valueLabel}: {format(r.value || 0)}</div>
          {tooltip && <div className="mt-0.5 text-white/70">{tooltip(r)}</div>}
        </div>
      ),
    });
  };
  const clear = () => { setActive(null); setTip(null); };

  return (
    <div className={`space-y-1.5 ${className}`} role="img" aria-label={`${valueLabel} ranking`}>
      {rows.map((r, i) => {
        const v = r.value || 0;
        // Never let a non-zero value render as an invisible sliver.
        const share = Math.abs(v) / max;
        const pct = v === 0 ? 0 : Math.max(1.5, share * 100);
        const neg = diverging && v < 0;
        const tone = r.color || (diverging ? (neg ? negativeColor : positiveColor) : color);
        const dim = active != null && active !== i;

        return (
          <div
            key={r.key ?? `${r.label}-${i}`}
            className="flex items-center gap-3 transition"
            style={{ opacity: dim ? 0.45 : 1 }}
            onMouseMove={showTip(r, i)}
            onMouseLeave={clear}
          >
            {/* label gutter — truncates rather than pushing the bar around */}
            <div className="shrink-0 truncate" style={{ width: labelWidth }}>
              <span className="flex items-baseline gap-1.5">
                {showRank && (
                  <span className="w-4 shrink-0 text-right text-[10px] font-bold tabular-nums text-slate-300">{i + 1}</span>
                )}
                <span className="min-w-0 flex-1 truncate">
                  {r.to ? (
                    <Link to={r.to} className="text-sm font-semibold text-indigo-600 hover:text-indigo-700">{r.label}</Link>
                  ) : (
                    <span className="text-sm font-semibold text-slate-700">{r.label}</span>
                  )}
                </span>
              </span>
              {r.sub && <span className="block truncate text-[11px] text-slate-400">{r.sub}</span>}
            </div>

            {/* track */}
            <div
              className="relative h-2.5 min-w-0 flex-1 overflow-hidden rounded-full"
              style={{ backgroundColor: LINE }}
            >
              {diverging ? (
                <>
                  <span
                    className="absolute inset-y-0 rounded-full"
                    style={{
                      background: fill(tone),
                      left: neg ? `calc(50% - ${mounted ? pct / 2 : 0}%)` : '50%',
                      width: `${mounted ? pct / 2 : 0}%`,
                      transition: 'width .9s cubic-bezier(0.22,1,0.36,1), left .9s cubic-bezier(0.22,1,0.36,1)',
                    }}
                  />
                  {/* Centre zero line — bars grow right for profit, left for loss. Painted OVER
                      the bar in the SURFACE colour, so it reads as a notch cut out of the track
                      in both themes (a fixed grey would glare on the dark Cockpit background). */}
                  <span
                    className="absolute inset-y-0 left-1/2 w-0.5 -translate-x-1/2"
                    style={{ backgroundColor: 'rgb(var(--surface))' }}
                  />
                </>
              ) : (
                <span
                  className="absolute inset-y-0 left-0 rounded-full"
                  style={{
                    background: fill(tone),
                    width: `${mounted ? pct : 0}%`,
                    transition: 'width .9s cubic-bezier(0.22,1,0.36,1)',
                  }}
                />
              )}
            </div>

            {/* value */}
            <div
              className={`shrink-0 text-right text-sm font-semibold tabular-nums ${
                diverging ? (v > 0 ? 'text-emerald-600' : v < 0 ? 'text-red-600' : 'text-slate-400') : 'text-slate-900'
              }`}
              style={{ width: valueWidth }}
            >
              {diverging && v !== 0 ? (v > 0 ? '+' : '−') : ''}{format(diverging ? Math.abs(v) : v)}
            </div>
          </div>
        );
      })}
      <ChartTooltip tip={tip} />
    </div>
  );
}
