// A bespoke, dependency-free SVG bar chart — same gradients, easing and theme
// awareness as Gauge.js. Responsive (measures its container), animates bars up on
// mount, and shares the cursor tooltip used across the app's SVGs.
//
//   <BarChart
//     data={[{ label: 'Jan', value: 4200, visits: 6 }, …]}
//     color="indigo" height={260} format={aed} valueLabel="Spend"
//     tooltip={(d) => <>{d.visits} visits</>}   // optional extra line
//   />

import { useState } from 'react';
import { ChartTooltip } from './Tooltip';
import { palette, LINE, useUid, useChartWidth, useMounted, niceMax } from './chartUtils';

export default function BarChart({
  data = [],
  color = 'indigo',
  height = 260,
  format = (n) => Math.round(n).toLocaleString(),
  tickFormat = format,   // axis labels — defaults to `format`; pass a compact one for currency
  valueLabel = 'Value',
  tooltip,           // optional (d) => node, rendered under the headline value
  yTicks = 4,
  className = '',
}) {
  const [ref, width] = useChartWidth();
  const mounted = useMounted();
  const gid = useUid('bar');
  const [tip, setTip] = useState(null);
  const [active, setActive] = useState(null);

  // Per-bar colour: a datum may carry its own palette key, for the case where each
  // bar is a STATUS rather than a position on a scale (e.g. expired / due / valid).
  // One gradient is defined per distinct key; without `d.color` everything falls
  // back to the chart-level colour exactly as before.
  const tones = [...new Set(data.map((d) => d.color || color))];
  const toneIndex = (d) => tones.indexOf(d.color || color);

  const padL = 46, padR = 14, padT = 14, padB = 30;
  const innerW = Math.max(0, width - padL - padR);
  const innerH = Math.max(0, height - padT - padB);
  const max = niceMax(Math.max(0, ...data.map((d) => d.value || 0)));
  const band = data.length ? innerW / data.length : innerW;
  const barW = Math.min(46, band * 0.6);

  const x = (i) => padL + band * i + (band - barW) / 2;
  const y = (v) => padT + innerH * (1 - (v || 0) / max);

  const showTip = (d, i) => (e) => {
    setActive(i);
    setTip({
      x: e.clientX,
      y: e.clientY,
      content: (
        <div>
          <div className="text-[10px] font-semibold uppercase tracking-wide text-white/60">{d.label}</div>
          <div className="mt-0.5 font-bold tabular-nums">{valueLabel}: {format(d.value || 0)}</div>
          {tooltip && <div className="mt-0.5 text-white/70">{tooltip(d)}</div>}
        </div>
      ),
    });
  };
  const clear = () => { setActive(null); setTip(null); };

  return (
    <div ref={ref} className={className}>
      <svg width={width} height={height} role="img" aria-label={`${valueLabel} bar chart`}>
        <defs>
          {tones.map((t, ti) => {
            const p = palette(t);
            return (
              <linearGradient key={ti} id={`${gid}-${ti}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={p.to} />
                <stop offset="100%" stopColor={p.from} />
              </linearGradient>
            );
          })}
        </defs>

        {/* horizontal gridlines + y-axis labels on friendly numbers */}
        {Array.from({ length: yTicks + 1 }).map((_, t) => {
          const v = (max / yTicks) * t;
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

        {/* bars */}
        {data.map((d, i) => {
          const full = innerH * ((d.value || 0) / max);
          const h = mounted ? full : 0;
          const isActive = active === i;
          return (
            <g key={i} onMouseMove={showTip(d, i)} onMouseLeave={clear} style={{ cursor: 'pointer' }}>
              {/* invisible full-height hit area so hover is forgiving */}
              <rect x={padL + band * i} y={padT} width={band} height={innerH} fill="transparent" />
              <rect
                x={x(i)}
                y={padT + innerH - h}
                width={barW}
                height={h}
                rx={Math.min(6, barW / 2)}
                fill={`url(#${gid}-${toneIndex(d)})`}
                opacity={active == null || isActive ? 1 : 0.45}
                style={{ transition: 'height 0.9s cubic-bezier(0.22,1,0.36,1), y 0.9s cubic-bezier(0.22,1,0.36,1), opacity 0.2s' }}
              />
              <text
                x={padL + band * i + band / 2}
                y={height - 10}
                textAnchor="middle"
                className={isActive ? 'fill-slate-700' : 'fill-slate-400'}
                style={{ fontSize: 11, fontWeight: isActive ? 700 : 500 }}
              >
                {d.label}
              </text>
            </g>
          );
        })}
      </svg>
      <ChartTooltip tip={tip} />
    </div>
  );
}
