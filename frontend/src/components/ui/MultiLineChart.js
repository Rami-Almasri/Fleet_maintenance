// A bespoke, dependency-free multi-series SVG trend chart — the "analysis" card:
// two (or more) smooth Catmull-Rom lines over a shared x-axis, dashed gridlines,
// an animated draw-on stroke and a floating value pill that follows the cursor
// (the "$9.2" callout). Same palette/tokens as the rest of the chart family, so
// it looks native to the app — no chart library.
//
//   <MultiLineChart
//     data={[{ label: 'Jan', rentals: 4, service: 1 }, …]}
//     series={[
//       { key: 'rentals', label: 'Rentals', color: 'purple' },
//       { key: 'service', label: 'Service visits', color: 'teal' },
//     ]}
//     height={260} format={(n) => n}
//   />

import { useState } from 'react';
import { palette, LINE, useChartWidth, useMounted, niceScale, smoothPath } from './chartUtils';
import { useI18n } from '../../i18n/I18nContext';

// Resolve a series colour: raw hex passes through, otherwise a palette key → its `from` stop.
const resolve = (c) => (typeof c === 'string' && c.startsWith('#') ? c : palette(c).from);

export default function MultiLineChart({
  data = [],
  series = [],
  height = 260,
  format = (n) => Math.round(n).toLocaleString(),
  tickFormat = format,
  integer = false, // count data → whole-number gridlines
  className = '',
  primary = 0, // which series the hover pill reads from
}) {
  const { t } = useI18n();
  const [ref, width] = useChartWidth();
  const mounted = useMounted();
  const [active, setActive] = useState(null);

  const padL = 40, padR = 22, padT = 26, padB = 30;
  const innerW = Math.max(0, width - padL - padR);
  const innerH = Math.max(0, height - padT - padB);
  const n = data.length;

  const allVals = data.flatMap((d) => series.map((s) => d[s.key] || 0));
  const { max, ticks } = niceScale(Math.max(1, ...allVals), 5, integer);
  // Too many months → label every other one so they never overlap or clip.
  const labelEvery = n > 8 ? 2 : 1;

  // Span the full width edge-to-edge so the trend reads as continuous.
  const cx = (i) => (n <= 1 ? padL + innerW / 2 : padL + innerW * (i / (n - 1)));
  const cy = (v) => padT + innerH * (1 - (v || 0) / max);

  const lines = series.map((s) => ({
    ...s,
    color: resolve(s.color),
    pts: data.map((d, i) => [cx(i), cy(d[s.key])]),
  }));

  const onMove = (e) => {
    if (!n) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const mx = e.clientX - rect.left;
    const step = n <= 1 ? innerW : innerW / (n - 1);
    const i = Math.max(0, Math.min(n - 1, Math.round((mx - padL) / step)));
    setActive(i);
  };
  const clear = () => setActive(null);

  const pal = lines[primary];
  const pillVal = active != null && pal ? format(data[active][pal.key] || 0) : '';
  const pillW = Math.max(34, pillVal.length * 8.5 + 18);

  return (
    <div ref={ref} className={className}>
      <svg width={width} height={height} role="img" aria-label={t('Trend analysis')}>
        {/* dashed gridlines + y labels — evenly-spaced "nice" values */}
        {ticks.map((val, t) => {
          const gy = cy(val);
          return (
            <g key={t}>
              <line x1={padL} x2={width - padR} y1={gy} y2={gy} stroke={LINE} strokeWidth={1} strokeDasharray="4 5" />
              <text x={padL - 10} y={gy + 3} textAnchor="end" className="fill-slate-400" style={{ fontSize: 10 }}>
                {tickFormat(val)}
              </text>
            </g>
          );
        })}

        {/* each series line, drawn on left→right */}
        {lines.map((s, si) => {
          const d = smoothPath(s.pts);
          if (!d) return null;
          return (
            <path
              key={si}
              d={d}
              fill="none"
              stroke={s.color}
              strokeWidth={3}
              strokeLinecap="round"
              strokeLinejoin="round"
              pathLength={1}
              strokeDasharray={1}
              strokeDashoffset={mounted ? 0 : 1}
              style={{ transition: `stroke-dashoffset 1.2s cubic-bezier(0.22,1,0.36,1) ${si * 0.12}s` }}
            />
          );
        })}

        {/* crosshair + a dot on every series at the active x */}
        {active != null && (
          <g>
            <line
              x1={cx(active)} x2={cx(active)} y1={padT} y2={padT + innerH}
              stroke="rgb(148 163 184)" strokeWidth={1} strokeDasharray="3 3" opacity={0.5}
            />
            {lines.map((s, si) => (
              <circle key={si} cx={s.pts[active][0]} cy={s.pts[active][1]} r={si === primary ? 5.5 : 4}
                fill="#fff" stroke={s.color} strokeWidth={2.5} />
            ))}
          </g>
        )}

        {/* floating value pill above the primary point — the signature "$9.2" callout */}
        {active != null && pal && (() => {
          const [px, py] = pal.pts[active];
          const top = Math.max(padT + 2, py - 34);
          const left = Math.min(Math.max(padL + pillW / 2, px), width - padR - pillW / 2);
          return (
            <g style={{ transition: 'opacity .15s ease' }}>
              <rect x={left - pillW / 2} y={top - 12} width={pillW} height={24} rx={8} fill={pal.color} />
              <path d={`M ${px - 5} ${top + 11} L ${px + 5} ${top + 11} L ${px} ${top + 17} Z`} fill={pal.color} />
              <text x={left} y={top + 4} textAnchor="middle" className="fill-white" style={{ fontSize: 12, fontWeight: 700 }}>
                {pillVal}
              </text>
            </g>
          );
        })()}

        {/* x labels — thinned when crowded; always keep the first & last */}
        {data.map((d, i) => {
          const show = active === i || i === 0 || i === n - 1 || i % labelEvery === 0;
          if (!show) return null;
          return (
            <text
              key={i}
              x={cx(i)}
              y={height - 8}
              textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}
              className={active === i ? 'fill-slate-600' : 'fill-slate-400'}
              style={{ fontSize: 10, fontWeight: active === i ? 700 : 500, textTransform: 'uppercase', letterSpacing: '0.03em' }}
            >
              {d.label}
            </text>
          );
        })}

        {/* hover capture */}
        <rect x={padL} y={padT} width={innerW} height={innerH} fill="transparent" onMouseMove={onMove} onMouseLeave={clear} />
      </svg>

      {/* legend */}
      {series.length > 1 && (
        <div className="mt-1 flex flex-wrap items-center gap-x-5 gap-y-1.5 px-1">
          {lines.map((s, si) => (
            <span key={si} className="inline-flex items-center gap-2 text-xs font-medium text-slate-500">
              <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: s.color }} />
              {s.label}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
