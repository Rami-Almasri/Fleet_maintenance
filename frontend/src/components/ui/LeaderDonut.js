// A donut chart with EXTERNAL leader-line labels — the CRM-dashboard style: each segment's arc
// connects by a bent leader line to a label sitting just outside the ring, showing the segment
// name and its "count · pct%". The total sits in the centre hole. Dependency-free SVG, same
// palette tokens as the rest of the chart family (CompositionDonut / PieChart).
//
//   <LeaderDonut
//     segments={[{ label: 'Rental', value: 142, color: '#3b82f6' }, …]}
//     total={235}                 // defaults to the sum of segments
//     centerLabel="Contracts"
//     format={(n) => n.toLocaleString()}
//   />

import { palette } from './chartUtils';

const resolve = (c) => (typeof c === 'string' && c.startsWith('#') ? c : palette(c).from);

// Geometry — a fixed viewBox we scale to the container. The ring lives in the middle third; the
// left/right thirds hold the labels.
const W = 360;
const H = 236;
const CX = W / 2;
const CY = 116;
const R = 66;          // radius to the MIDDLE of the ring
const STROKE = 20;
const R_OUT = R + STROKE / 2;

export default function LeaderDonut({
  segments = [],
  total = null,
  centerLabel = 'Total',
  format = (n) => Math.round(n).toLocaleString(),
  className = '',
}) {
  const data = segments.filter((s) => (s.value || 0) > 0);
  const sum = total != null ? total : data.reduce((a, s) => a + (s.value || 0), 0);

  if (!data.length || sum <= 0) {
    return (
      <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
        No data to chart yet.
      </div>
    );
  }

  // Walk segments → arc paths + a mid-angle leader anchor for each.
  let acc = 0;
  const segs = data.map((s) => {
    const frac = (s.value || 0) / sum;
    const startF = acc;
    const endF = acc + frac;
    acc = endF;
    const mid = (startF + endF) / 2;
    const midAng = 2 * Math.PI * mid;          // from top (0), clockwise
    const dirX = Math.sin(midAng);
    const dirY = -Math.cos(midAng);
    const side = dirX >= 0 ? 1 : -1;           // right (+1) / left (-1)
    return {
      ...s,
      color: resolve(s.color),
      frac,
      startF,
      endF,
      side,
      dirX,
      dirY,
      anchor: { x: CX + R_OUT * dirX, y: CY + R_OUT * dirY },
      labelY: CY + (R_OUT + 14) * dirY,        // provisional; de-collided below
    };
  });

  // De-collide labels on each side: sort by y and enforce a minimum vertical gap.
  const MIN_GAP = 30;
  [-1, 1].forEach((side) => {
    const arr = segs.filter((s) => s.side === side).sort((a, b) => a.labelY - b.labelY);
    for (let i = 1; i < arr.length; i++) {
      if (arr[i].labelY - arr[i - 1].labelY < MIN_GAP) arr[i].labelY = arr[i - 1].labelY + MIN_GAP;
    }
  });

  // Point on the circle at fraction f (top = 0, clockwise) at radius R.
  const pt = (f, r = R) => ({ x: CX + r * Math.sin(2 * Math.PI * f), y: CY - r * Math.cos(2 * Math.PI * f) });

  const arcPath = (s) => {
    // A full-circle single segment can't be drawn as one arc — nudge the end just short of a full turn.
    const end = s.frac >= 0.9999 ? s.endF - 0.0001 : s.endF;
    const a = pt(s.startF);
    const b = pt(end);
    const large = end - s.startF > 0.5 ? 1 : 0;
    return `M ${a.x} ${a.y} A ${R} ${R} 0 ${large} 1 ${b.x} ${b.y}`;
  };

  const R_LABEL_X = W - 6;   // right labels anchored to the right edge
  const L_LABEL_X = 6;       // left labels anchored to the left edge
  const BEND = 12;           // radial elbow length before the horizontal run

  return (
    <div className={`w-full ${className}`}>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full" style={{ maxHeight: H }}>
        {/* track */}
        <circle cx={CX} cy={CY} r={R} fill="none" stroke="rgb(var(--line))" strokeWidth={STROKE} />

        {/* arcs */}
        {segs.map((s) => (
          <path
            key={`arc-${s.label}`}
            d={arcPath(s)}
            fill="none"
            stroke={s.color}
            strokeWidth={STROKE}
            strokeLinecap="butt"
          />
        ))}

        {/* leader lines + labels */}
        {segs.map((s) => {
          const elbowX = CX + (R_OUT + BEND) * s.dirX;
          const lineEndX = s.side === 1 ? R_LABEL_X - 4 : L_LABEL_X + 4;
          const textX = s.side === 1 ? R_LABEL_X : L_LABEL_X;
          const anchor = s.side === 1 ? 'end' : 'start';
          return (
            <g key={`lbl-${s.label}`}>
              <polyline
                points={`${s.anchor.x},${s.anchor.y} ${elbowX},${s.labelY} ${lineEndX},${s.labelY}`}
                fill="none"
                stroke={s.color}
                strokeWidth={1.25}
                opacity={0.7}
              />
              <circle cx={s.anchor.x} cy={s.anchor.y} r={2} fill={s.color} />
              <text x={textX} y={s.labelY - 3} textAnchor={anchor} className="fill-slate-700" style={{ fontSize: 11.5, fontWeight: 700 }}>
                {s.label}
              </text>
              <text x={textX} y={s.labelY + 11} textAnchor={anchor} className="fill-slate-400" style={{ fontSize: 10.5, fontWeight: 600 }}>
                {format(s.value)} · {Math.round(s.frac * 100)}%
              </text>
            </g>
          );
        })}

        {/* centre total */}
        <text x={CX} y={CY - 4} textAnchor="middle" className="fill-slate-900" style={{ fontSize: 26, fontWeight: 800 }}>
          {format(sum)}
        </text>
        <text x={CX} y={CY + 15} textAnchor="middle" className="fill-slate-400" style={{ fontSize: 10, fontWeight: 700, letterSpacing: '0.08em' }}>
          {centerLabel.toUpperCase()}
        </text>
      </svg>
    </div>
  );
}
