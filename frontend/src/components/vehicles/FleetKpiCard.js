// One tile of the Vehicles KPI strip: a live count, what it is, and whether it is moving.
//
// TWO NUMBERS FROM TWO SOURCES, SAID AS SUCH
// The big figure is the live count from /Vehicle — the page's own rows, counted now. The trend
// beside it comes from /Vehicle/fleet-pulse, which replays the same four states from contract dates
// a month apart. They are measured differently on purpose (the live rule also weighs condition
// grade and lifecycle status, neither of which is historised), so the tile never implies the delta
// is a change in the headline figure. The tooltip names both dates and the basis.
//
// A missing or unusable trend renders NOTHING. No zero, no dash-as-percentage, no flat arrow — a
// state that has never been used (there are no booking contracts in this fleet's history) must not
// be dressed as "0% change", which reads as a measurement.
//
// DIRECTION IS NOT SENTIMENT. Up is good for available, reserved and rented; up is bad for cars in
// the garage. `goodWhen` carries that per tile, so colour follows meaning rather than sign.

import { useI18n } from '../../i18n/I18nContext';

const TONES = {
  emerald: { text: 'text-emerald-600 dark:text-emerald-400', chip: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400', wash: 'from-emerald-50/80 dark:from-emerald-500/[0.07]', ring: 'ring-emerald-400 dark:ring-emerald-500/70', stroke: '#10b981' },
  blue: { text: 'text-blue-600 dark:text-blue-400', chip: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400', wash: 'from-blue-50/80 dark:from-blue-500/[0.07]', ring: 'ring-blue-400 dark:ring-blue-500/70', stroke: '#3b82f6' },
  violet: { text: 'text-violet-600 dark:text-violet-400', chip: 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400', wash: 'from-violet-50/80 dark:from-violet-500/[0.07]', ring: 'ring-violet-400 dark:ring-violet-500/70', stroke: '#8b5cf6' },
  amber: { text: 'text-amber-600 dark:text-amber-400', chip: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400', wash: 'from-amber-50/80 dark:from-amber-500/[0.07]', ring: 'ring-amber-400 dark:ring-amber-500/70', stroke: '#f59e0b' },
};

const ICONS = {
  car: 'M5 13l1.6-4.7A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.3L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1M7 17h.01M17 17h.01',
  calendar: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  key: 'M15 7a4 4 0 1 1-3.9 5H9l-1.5 1.5L6 12l-1.5 1.5L3 12v-2l8.1-3A4 4 0 0 1 15 7zM16 10h.01',
  wrench: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.8 3.8z',
};

/** The tile's own tiny history line. Flat data draws a flat line rather than dividing by zero. */
function Spark({ points = [], color }) {
  if (points.length < 2) return null;
  const w = 62;
  const h = 24;
  const min = Math.min(...points);
  const max = Math.max(...points);
  const span = max - min || 1;
  const d = points
    .map((v, i) => `${(i / (points.length - 1)) * w},${h - ((v - min) / span) * (h - 3) - 1.5}`)
    .join(' ');
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} className="shrink-0 opacity-70" aria-hidden="true">
      <polyline points={d} fill="none" stroke={color} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

export default function FleetKpiCard({
  label,
  value,
  hint,
  tone = 'emerald',
  icon = 'car',
  delta = null,
  goodWhen = 'up',
  series = [],
  trendTitle,
  active = false,
  onClick,
}) {
  const { t } = useI18n();
  const T = TONES[tone] || TONES.emerald;

  // A zero delta is a real measurement ("unchanged") and is shown; null is an absence and is not.
  const hasDelta = delta != null && Number.isFinite(delta);
  const up = hasDelta && delta > 0;
  const flat = hasDelta && delta === 0;
  const good = flat || (goodWhen === 'up' ? up : !up);
  const deltaClass = flat
    ? 'text-slate-400 dark:text-slate-500'
    : good
      ? 'text-emerald-600 dark:text-emerald-400'
      : 'text-rose-600 dark:text-rose-400';

  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={trendTitle}
      className={`group relative w-full overflow-hidden rounded-2xl border bg-gradient-to-br to-white/0 p-5 text-start transition
        dark:to-transparent
        ${T.wash}
        ${active ? `border-transparent ring-2 ring-inset ${T.ring}` : 'border-slate-200/70 dark:border-slate-700/60'}
        ${onClick ? 'hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/5 dark:hover:shadow-black/20' : ''}`}
    >
      <div className="flex items-start gap-3">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${T.chip}`}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
            <path d={ICONS[icon] || ICONS.car} />
          </svg>
        </span>
        <span className={`mt-2 flex-1 truncate text-sm font-semibold ${T.text}`}>{label}</span>
        {hasDelta && (
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
            className={`mt-1.5 h-4 w-4 shrink-0 ${deltaClass}`} aria-hidden="true">
            <path d={up ? 'M7 17L17 7M9 7h8v8' : flat ? 'M5 12h14' : 'M7 7l10 10M17 9v8H9'} />
          </svg>
        )}
      </div>

      <div className="mt-3 flex items-end gap-3">
        <span className="font-display text-4xl font-extrabold leading-none tracking-tight text-slate-900 tabular-nums dark:text-slate-50">
          {value}
        </span>
        <span className="ms-auto flex items-center gap-2">
          <Spark points={series} color={T.stroke} />
          {hasDelta && (
            <span className="text-end leading-tight">
              <span className={`block text-xs font-bold tabular-nums ${deltaClass}`}>
                {delta > 0 ? '+' : ''}{delta}%
              </span>
              <span className="block text-[10px] font-medium text-slate-400 dark:text-slate-500">
                {t('vehicles.kpi.vsLastMonth')}
              </span>
            </span>
          )}
        </span>
      </div>

      {hint && <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
    </button>
  );
}
