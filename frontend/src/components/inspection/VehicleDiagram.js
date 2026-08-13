// Interactive top-down vehicle diagram — the "hotspot" inspection UI.
//
// The car body is decomposed into clickable exterior zones (bumpers, hood,
// windshields, roof, the four doors, trunk). Each zone is a plain SVG <path>, so
// it scales crisply and is fully themeable. A zone's fill reflects its inspection
// state: empty (nothing captured), captured (photos taken, looks fine), or damage
// (a defect was flagged) — the last paints the panel red for an instant visual
// alert, exactly as specified.
//
// This component is presentational: it renders state and reports clicks. The
// parent owns the data (which zones have photos / damage) and decides what a click
// does (open the camera, show the gallery, etc.). Interior items that can't be
// shown from above (dashboard, odometer, fuel) are exposed by the parent as chips.

// Zone geometry. `d` is the SVG path; (cx, cy) is the centroid where we drop the
// status marker. Order is front → back so the visual reads top-to-bottom.
export const VEHICLE_ZONES = [
  { id: 'front_bumper',     label: 'Front Bumper',      d: 'M62 60 L62 46 Q62 22 92 22 L168 22 Q198 22 198 46 L198 60 Z', cx: 130, cy: 42 },
  { id: 'hood',             label: 'Hood',              d: 'M62 60 L198 60 L198 116 L62 116 Z',                            cx: 130, cy: 88 },
  { id: 'windshield_front', label: 'Front Windshield',  d: 'M62 116 L198 116 L174 156 L86 156 Z',                          cx: 130, cy: 136 },
  { id: 'door_front_left',  label: 'Front-Left Door',   d: 'M62 156 L86 156 L86 228 L62 228 Z',                            cx: 74,  cy: 192 },
  { id: 'door_rear_left',   label: 'Rear-Left Door',    d: 'M62 228 L86 228 L86 300 L62 300 Z',                            cx: 74,  cy: 264 },
  { id: 'roof',             label: 'Roof',              d: 'M86 156 L174 156 L174 300 L86 300 Z',                          cx: 130, cy: 228 },
  { id: 'door_front_right', label: 'Front-Right Door',  d: 'M174 156 L198 156 L198 228 L174 228 Z',                        cx: 186, cy: 192 },
  { id: 'door_rear_right',  label: 'Rear-Right Door',   d: 'M174 228 L198 228 L198 300 L174 300 Z',                        cx: 186, cy: 264 },
  { id: 'windshield_rear',  label: 'Rear Windshield',   d: 'M86 300 L174 300 L198 338 L62 338 Z',                          cx: 130, cy: 320 },
  { id: 'trunk',            label: 'Trunk / Boot',      d: 'M62 338 L198 338 L198 404 L62 404 Z',                          cx: 130, cy: 371 },
  { id: 'rear_bumper',      label: 'Rear Bumper',       d: 'M62 404 L198 404 L198 440 Q198 462 168 462 L92 462 Q62 462 62 440 Z', cx: 130, cy: 432 },
];

// Decorative wheels (not interactive) so the silhouette reads as a car.
const WHEELS = [
  { x: 44, y: 92 }, { x: 200, y: 92 }, { x: 44, y: 322 }, { x: 200, y: 322 },
];

// Damage zones are tinted by severity so the diagram reads urgency at a glance:
// high = red, medium = orange, low = amber.
const SEVERITY_FILL = {
  high:   'fill-rose-100 stroke-rose-500 hover:fill-rose-200',
  medium: 'fill-orange-100 stroke-orange-500 hover:fill-orange-200',
  low:    'fill-amber-100 stroke-amber-400 hover:fill-amber-200',
};
const SEVERITY_DOT = { high: 'fill-rose-500', medium: 'fill-orange-500', low: 'fill-amber-500' };

import { useI18n } from '../../i18n/I18nContext';

function zoneClasses(state, isSelected, severity) {
  if (isSelected) return 'fill-indigo-200 stroke-indigo-500';
  switch (state) {
    case 'damage':   return SEVERITY_FILL[severity] || SEVERITY_FILL.high;
    case 'captured': return 'fill-emerald-100 stroke-emerald-400 hover:fill-emerald-200';
    default:         return 'fill-slate-100 stroke-slate-300 hover:fill-indigo-100 hover:stroke-indigo-400';
  }
}

export default function VehicleDiagram({ zones = {}, selected = null, onSelect, className = '' }) {
  const { t } = useI18n();
  return (
    <svg
      viewBox="0 0 260 500"
      className={`w-full max-w-[320px] select-none ${className}`}
      role="group"
      aria-label={t('Vehicle inspection diagram')}
    >
      {/* soft ground shadow */}
      <ellipse cx="130" cy="250" rx="118" ry="244" className="fill-slate-50" />

      {WHEELS.map((w, i) => (
        <rect key={i} x={w.x} y={w.y} width="16" height="52" rx="7" className="fill-slate-300" />
      ))}

      {VEHICLE_ZONES.map((zone) => {
        const state = zones[zone.id]?.state || 'empty';
        const count = zones[zone.id]?.count || 0;
        const severity = zones[zone.id]?.severity || 'high';
        const isSelected = selected === zone.id;
        return (
          <g key={zone.id} className="cursor-pointer" onClick={() => onSelect?.(zone.id)}>
            <title>{zone.label}</title>
            <path
              d={zone.d}
              strokeWidth={isSelected ? 2.5 : 1.5}
              className={`transition-colors duration-150 ${zoneClasses(state, isSelected, severity)}`}
            />
            {/* status marker at the panel centroid */}
            {state === 'damage' && (
              <>
                <circle cx={zone.cx} cy={zone.cy} r="8" className={`${SEVERITY_DOT[severity] || SEVERITY_DOT.high} opacity-30 animate-ping`} />
                <circle cx={zone.cx} cy={zone.cy} r="5" className={SEVERITY_DOT[severity] || SEVERITY_DOT.high} />
              </>
            )}
            {state === 'captured' && (
              <g>
                <circle cx={zone.cx} cy={zone.cy} r="7" className="fill-emerald-500" />
                <path
                  d={`M${zone.cx - 3} ${zone.cy} l2 2 l4 -4`}
                  className="fill-none stroke-white"
                  strokeWidth="1.6"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </g>
            )}
            {/* photo count badge for captured zones with more than one shot */}
            {state !== 'empty' && count > 1 && (
              <text x={zone.cx + 10} y={zone.cy + 3} className="fill-slate-600 text-[9px] font-semibold">
                ×{count}
              </text>
            )}
          </g>
        );
      })}
    </svg>
  );
}

// Shared legend so any page hosting the diagram explains the colour code.
export function DiagramLegend({ className = '' }) {
  const items = [
    { label: 'Not captured', dot: 'bg-slate-200 ring-slate-300' },
    { label: 'Captured',     dot: 'bg-emerald-100 ring-emerald-400' },
    { label: 'Damage flagged', dot: 'bg-rose-100 ring-rose-400' },
  ];
  return (
    <div className={`flex flex-wrap items-center gap-x-4 gap-y-1.5 ${className}`}>
      {items.map((it) => (
        <span key={it.label} className="flex items-center gap-1.5 text-xs text-slate-500">
          <span className={`h-3 w-3 rounded-full ring-1 ${it.dot}`} />
          {it.label}
        </span>
      ))}
    </div>
  );
}
