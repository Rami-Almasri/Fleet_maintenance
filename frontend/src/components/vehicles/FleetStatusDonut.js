// Fleet Status — the whole active fleet as one ring, split across the four states the KPI tiles
// count above. Same numbers, same source; the ring answers "what proportion", the tiles answer
// "how many", and a reader wants both without doing the arithmetic.
//
// Segments with no cars are kept in the LEGEND (a state that is empty today is a fact worth
// reading) but contribute no arc, because a zero-length arc with a rounded cap still draws a dot.
//
// Percentages are rounded for display and will not always total 100. That is honest rounding, not
// a bug to paper over by fudging the largest slice.

import { useEffect, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';

const COLORS = {
  emerald: '#22C55E',
  blue: '#2F7EF6',
  violet: '#8B5CF6',
  amber: '#F5A524',
};

export default function FleetStatusDonut({ segments = [], total = 0, className = '', size = 180, stroke = 26 }) {
  const { t } = useI18n();
  const drawn = segments.filter((s) => (s.value || 0) > 0);
  const sum = total || drawn.reduce((a, s) => a + (s.value || 0), 0);

  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const gap = drawn.length > 1 ? Math.min(9, c * 0.012) : 0;

  // Grow the arcs in on mount so the ring reads as a measurement being taken, not a static graphic.
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(1));
    return () => cancelAnimationFrame(id);
  }, [sum]);

  let acc = 0;
  const arcs = drawn.map((s) => {
    const full = sum ? c * ((s.value || 0) / sum) : 0;
    const arc = { ...s, len: Math.max(0, full - gap), offset: acc + gap / 2 };
    acc += full;
    return arc;
  });

  return (
    <div className={`rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm dark:border-slate-700/60 dark:bg-slate-900 ${className}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-display text-lg font-bold tracking-tight text-slate-900 dark:text-slate-50">
            {t('vehicles.fleetStatus.title')}
          </h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{t('vehicles.fleetStatus.total')}</p>
        </div>
        <div className="text-end">
          <span className="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            {t('vehicles.fleetStatus.total')}
          </span>
          <span className="block font-display text-2xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-slate-50">
            {sum.toLocaleString()}
          </span>
        </div>
      </div>

      <div className="flex justify-center py-4">
        <div className="relative" style={{ width: size, height: size }}>
          <svg width={size} height={size} className="-rotate-90" aria-hidden="true">
            <circle
              cx={size / 2} cy={size / 2} r={r}
              fill="none" stroke="currentColor" strokeWidth={stroke}
              className="text-slate-100 dark:text-slate-800"
            />
            {arcs.map((a, i) => (
              <circle
                key={i}
                cx={size / 2} cy={size / 2} r={r}
                fill="none" stroke={COLORS[a.color] || COLORS.blue} strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={`${a.len * grow} ${c - a.len * grow}`}
                strokeDashoffset={-a.offset * grow}
                style={{ transition: 'stroke-dasharray .9s cubic-bezier(.22,1,.36,1), stroke-dashoffset .9s cubic-bezier(.22,1,.36,1)' }}
              />
            ))}
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className="font-display text-3xl font-extrabold tracking-tight text-slate-900 tabular-nums dark:text-slate-50">
              {sum.toLocaleString()}
            </span>
            <span className="mt-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
              {t('vehicles.fleetStatus.unit')}
            </span>
          </div>
        </div>
      </div>

      <div className="space-y-2.5">
        {segments.map((s) => (
          <div key={s.key} className="flex items-center gap-2.5">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: COLORS[s.color] || COLORS.blue }} />
            <span className="flex-1 text-sm text-slate-600 dark:text-slate-300">{s.label}</span>
            <span className="text-sm font-semibold text-slate-900 tabular-nums dark:text-slate-100">
              {(s.value || 0).toLocaleString()}
              <span className="ms-1 font-normal text-slate-400">
                ({sum ? Math.round(((s.value || 0) / sum) * 100) : 0}%)
              </span>
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
