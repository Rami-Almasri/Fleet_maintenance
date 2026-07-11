// A compact, dependency-free inline sparkline (pure SVG). Same palette, easing and
// smoothing as BarChart/LineChart/Gauge, so a tiny trend behind a KPI looks like it
// was born in the project. Fluid by default (measures its container); pass `width`
// for a fixed size. Draws the line on mount and fades the area fill in.
//
//   <Sparkline data={[4200, 3800, 5100, 4600]} color="indigo" />
//   <Sparkline data={series} color="emerald" height={40} showDot />
//
// `data` accepts raw numbers or objects with a `.value` (so trend series drop in directly).

import { palette, useUid, useMounted, useChartWidth, smoothPath } from './chartUtils';

export default function Sparkline({
  data = [],
  color = 'indigo',
  width,                 // omit for fluid (fills container); set for a fixed width
  height = 36,
  strokeWidth = 2,
  area = true,
  showDot = true,
  className = '',
}) {
  const gid = useUid('spark');
  const pal = palette(color);
  const mounted = useMounted();
  const [ref, measured] = useChartWidth(width || 120);
  const w = width || measured;

  const vals = data.map((d) => (typeof d === 'number' ? d : Number(d?.value) || 0));

  // Nothing to draw — keep the footprint so layout doesn't jump.
  if (vals.length < 2) {
    return <div ref={ref} className={className} style={{ height }} />;
  }

  const pad = strokeWidth + (showDot ? 2.5 : 0);
  const min = Math.min(...vals);
  const max = Math.max(...vals);
  const span = max - min || 1;
  const n = vals.length;

  const x = (i) => pad + (i * (w - pad * 2)) / (n - 1);
  const y = (v) => pad + (1 - (v - min) / span) * (height - pad * 2);

  const pts = vals.map((v, i) => [x(i), y(v)]);
  const line = smoothPath(pts);
  const last = pts[pts.length - 1];
  const areaPath = `${line} L ${last[0]} ${height} L ${pts[0][0]} ${height} Z`;

  return (
    <div ref={ref} className={className} style={{ lineHeight: 0 }}>
      <svg width={w} height={height} role="img" aria-hidden="true">
        <defs>
          <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={pal.from} stopOpacity="0.25" />
            <stop offset="100%" stopColor={pal.from} stopOpacity="0" />
          </linearGradient>
        </defs>
        {area && (
          <path
            d={areaPath}
            fill={`url(#${gid})`}
            style={{ opacity: mounted ? 1 : 0, transition: 'opacity 0.9s ease 0.2s' }}
          />
        )}
        <path
          d={line}
          fill="none"
          stroke={pal.from}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeLinejoin="round"
          pathLength={1}
          strokeDasharray={1}
          strokeDashoffset={mounted ? 0 : 1}
          style={{ transition: 'stroke-dashoffset 1s cubic-bezier(0.22,1,0.36,1)' }}
        />
        {showDot && (
          <circle
            cx={last[0]}
            cy={last[1]}
            r={strokeWidth + 0.5}
            fill={pal.from}
            style={{ opacity: mounted ? 1 : 0, transition: 'opacity 0.3s ease 0.9s' }}
          />
        )}
      </svg>
    </div>
  );
}
