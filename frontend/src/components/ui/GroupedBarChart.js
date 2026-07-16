// A bespoke, dependency-free grouped SVG bar chart — the "analysis" card's
// easy-read cousin of MultiLineChart. Each x-slot holds one bar per series side
// by side, so small monthly counts (0–3) read at a glance instead of as two
// wiggly overlapping curves. Same palette/tokens/animation as BarChart, so it
// looks native to the app — no chart library.
//
//   <GroupedBarChart
//     data={[{ label: 'Jan', rentals: 4, service: 1 }, …]}
//     series={[
//       { key: 'rentals', label: 'Rentals', color: 'purple' },
//       { key: 'service', label: 'Service visits', color: 'teal' },
//     ]}
//     height={280} integer format={(n) => n}
//   />

import { useState } from 'react';
import { ChartTooltip } from './Tooltip';
import { palette, LINE, useUid, useChartWidth, useMounted, niceScale } from './chartUtils';

export default function GroupedBarChart({
  data = [],
  series = [],
  height = 280,
  format = (n) => Math.round(n).toLocaleString(),
  tickFormat = format,
  integer = false,
  className = '',
}) {
  const [ref, width] = useChartWidth();
  const mounted = useMounted();
  const gid = useUid('gbar');
  const [tip, setTip] = useState(null);
  const [active, setActive] = useState(null);

  const padL = 40, padR = 16, padT = 16, padB = 30;
  const innerW = Math.max(0, width - padL - padR);
  const innerH = Math.max(0, height - padT - padB);
  const n = data.length;

  const allVals = data.flatMap((d) => series.map((s) => d[s.key] || 0));
  const { max, ticks } = niceScale(Math.max(1, ...allVals), 4, integer);
  const labelEvery = n > 8 ? 2 : 1;

  const band = n ? innerW / n : innerW;
  // Bars share ~64% of the band, split across the series with a small gap between.
  const groupW = Math.min(band * 0.64, 34 * series.length);
  const gap = series.length > 1 ? Math.min(6, groupW * 0.12) : 0;
  const barW = series.length ? (groupW - gap * (series.length - 1)) / series.length : groupW;

  const y = (v) => padT + innerH * (1 - (v || 0) / max);

  const showTip = (d, i) => (e) => {
    setActive(i);
    setTip({
      x: e.clientX,
      y: e.clientY,
      content: (
        <div>
          <div className="text-[10px] font-semibold uppercase tracking-wide text-white/60">{d.label}</div>
          {series.map((s) => (
            <div key={s.key} className="mt-0.5 flex items-center gap-2 tabular-nums">
              <span className="h-2 w-2 rounded-full" style={{ backgroundColor: palette(s.color).from }} />
              <span className="text-white/70">{s.label}:</span>
              <span className="font-bold">{format(d[s.key] || 0)}</span>
            </div>
          ))}
        </div>
      ),
    });
  };
  const clear = () => { setActive(null); setTip(null); };

  return (
    <div ref={ref} className={className}>
      <svg width={width} height={height} role="img" aria-label="Grouped activity bar chart">
        <defs>
          {series.map((s) => {
            const pal = palette(s.color);
            return (
              <linearGradient key={s.key} id={`${gid}-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={pal.to} />
                <stop offset="100%" stopColor={pal.from} />
              </linearGradient>
            );
          })}
        </defs>

        {/* gridlines + y-axis labels on friendly whole numbers */}
        {ticks.map((v, t) => {
          const gy = y(v);
          return (
            <g key={t}>
              <line x1={padL} x2={width - padR} y1={gy} y2={gy} stroke={LINE} strokeWidth={1} />
              <text x={padL - 8} y={gy + 3} textAnchor="end" className="fill-slate-400" style={{ fontSize: 10 }}>
                {tickFormat(v)}
              </text>
            </g>
          );
        })}

        {/* one group of bars per x-slot */}
        {data.map((d, i) => {
          const gx = padL + band * i + (band - groupW) / 2;
          const isActive = active === i;
          const showLabel = i === 0 || i === n - 1 || i % labelEvery === 0 || isActive;
          return (
            <g key={i} onMouseMove={showTip(d, i)} onMouseLeave={clear} style={{ cursor: 'pointer' }}>
              {/* forgiving full-height hit area */}
              <rect x={padL + band * i} y={padT} width={band} height={innerH} fill="transparent" />
              {series.map((s, si) => {
                const full = innerH * ((d[s.key] || 0) / max);
                const h = mounted ? full : 0;
                const bx = gx + si * (barW + gap);
                return (
                  <rect
                    key={s.key}
                    x={bx}
                    y={padT + innerH - h}
                    width={barW}
                    height={h}
                    rx={Math.min(4, barW / 2)}
                    fill={`url(#${gid}-${s.key})`}
                    opacity={active == null || isActive ? 1 : 0.4}
                    style={{ transition: `height 0.8s cubic-bezier(0.22,1,0.36,1) ${si * 0.08}s, y 0.8s cubic-bezier(0.22,1,0.36,1) ${si * 0.08}s, opacity 0.2s` }}
                  />
                );
              })}
              {showLabel && (
                <text
                  x={padL + band * i + band / 2}
                  y={height - 10}
                  textAnchor="middle"
                  className={isActive ? 'fill-slate-700' : 'fill-slate-400'}
                  style={{ fontSize: 11, fontWeight: isActive ? 700 : 500 }}
                >
                  {d.label}
                </text>
              )}
            </g>
          );
        })}
      </svg>

      {/* legend */}
      {series.length > 1 && (
        <div className="mt-1 flex flex-wrap items-center gap-x-5 gap-y-1.5 px-1">
          {series.map((s) => (
            <span key={s.key} className="inline-flex items-center gap-2 text-xs font-medium text-slate-500">
              <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: palette(s.color).from }} />
              {s.label}
            </span>
          ))}
        </div>
      )}

      <ChartTooltip tip={tip} />
    </div>
  );
}
