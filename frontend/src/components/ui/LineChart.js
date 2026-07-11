// A bespoke, dependency-free SVG trend line — smooth (Catmull-Rom) path with a
// gradient area fill, an animated "draw-on" stroke, a crosshair + dot on hover and
// the shared cursor tooltip. Matches Gauge.js gradients, easing and theme tokens.
//
//   <LineChart
//     data={[{ label: 'Jan', value: 5.2, visits: 6 }, …]}
//     color="emerald" height={260} format={(v) => `${v}d`} valueLabel="Avg days in shop"
//     tooltip={(d) => <>{d.visits} visits</>}
//   />

import { useState } from 'react';
import { ChartTooltip } from './Tooltip';
import { palette, LINE, useUid, useChartWidth, useMounted, niceMax, smoothPath } from './chartUtils';

export default function LineChart({
  data = [],
  color = 'emerald',
  height = 260,
  format = (n) => Math.round(n).toLocaleString(),
  tickFormat = format,   // axis labels — defaults to `format`; pass a compact one for currency
  valueLabel = 'Value',
  tooltip,
  yTicks = 4,
  className = '',
}) {
  const [ref, width] = useChartWidth();
  const mounted = useMounted();
  const gid = useUid('line');
  const aid = useUid('area');
  const [tip, setTip] = useState(null);
  const [active, setActive] = useState(null);

  const pal = palette(color);
  const padL = 46, padR = 16, padT = 16, padB = 30;
  const innerW = Math.max(0, width - padL - padR);
  const innerH = Math.max(0, height - padT - padB);
  const max = niceMax(Math.max(0, ...data.map((d) => d.value || 0)));
  const band = data.length ? innerW / data.length : innerW;

  const cx = (i) => padL + band * (i + 0.5);
  const cy = (v) => padT + innerH * (1 - (v || 0) / max);

  const pts = data.map((d, i) => [cx(i), cy(d.value)]);
  const line = smoothPath(pts);
  const area = pts.length
    ? `${line} L ${pts[pts.length - 1][0]} ${padT + innerH} L ${pts[0][0]} ${padT + innerH} Z`
    : '';

  const onMove = (e) => {
    if (!data.length) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const mx = e.clientX - rect.left;
    const i = Math.max(0, Math.min(data.length - 1, Math.round((mx - padL) / band - 0.5)));
    const d = data[i];
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
      <svg width={width} height={height} role="img" aria-label={`${valueLabel} trend line`}>
        <defs>
          <linearGradient id={gid} x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor={pal.from} />
            <stop offset="100%" stopColor={pal.to} />
          </linearGradient>
          <linearGradient id={aid} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={pal.from} stopOpacity={0.28} />
            <stop offset="100%" stopColor={pal.from} stopOpacity={0} />
          </linearGradient>
        </defs>

        {/* gridlines + y labels */}
        {Array.from({ length: yTicks + 1 }).map((_, t) => {
          const v = (max / yTicks) * t;
          const gy = cy(v);
          return (
            <g key={t}>
              <line x1={padL} x2={width - padR} y1={gy} y2={gy} stroke={LINE} strokeWidth={1} />
              <text x={padL - 8} y={gy + 3} textAnchor="end" className="fill-slate-400" style={{ fontSize: 10 }}>
                {tickFormat(v)}
              </text>
            </g>
          );
        })}

        {/* area fill (fades in) */}
        {area && (
          <path d={area} fill={`url(#${aid})`} opacity={mounted ? 1 : 0} style={{ transition: 'opacity 1.1s ease' }} />
        )}

        {/* the line, drawn on from left to right */}
        {line && (
          <path
            d={line}
            fill="none"
            stroke={`url(#${gid})`}
            strokeWidth={2.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            pathLength={1}
            strokeDasharray={1}
            strokeDashoffset={mounted ? 0 : 1}
            style={{ transition: 'stroke-dashoffset 1.2s cubic-bezier(0.22,1,0.36,1)' }}
          />
        )}

        {/* crosshair + active dot */}
        {active != null && pts[active] && (
          <g>
            <line x1={pts[active][0]} x2={pts[active][0]} y1={padT} y2={padT + innerH} stroke={pal.from} strokeWidth={1} strokeDasharray="3 3" opacity={0.5} />
            <circle cx={pts[active][0]} cy={pts[active][1]} r={5} fill="#fff" stroke={pal.from} strokeWidth={2.5} />
          </g>
        )}

        {/* x labels */}
        {data.map((d, i) => (
          <text
            key={i}
            x={cx(i)}
            y={height - 10}
            textAnchor="middle"
            className={active === i ? 'fill-slate-700' : 'fill-slate-400'}
            style={{ fontSize: 11, fontWeight: active === i ? 700 : 500 }}
          >
            {d.label}
          </text>
        ))}

        {/* full-area hover capture */}
        <rect x={padL} y={padT} width={innerW} height={innerH} fill="transparent" onMouseMove={onMove} onMouseLeave={clear} />
      </svg>
      <ChartTooltip tip={tip} />
    </div>
  );
}
