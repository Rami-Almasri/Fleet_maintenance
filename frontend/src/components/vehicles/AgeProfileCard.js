// Age Profile — the in-service fleet's model-year spread, which is the replacement-planning view.
//
// Every year between the oldest and newest car gets a bar, including the years with none: the gaps
// are the point. A chart drawn only from the years that happen to have cars would compress those
// gaps away and make an uneven fleet look evenly renewed.
//
// Cars with a missing or implausible year are left out rather than bucketed at the left edge, where
// they would invent a phantom cohort of very old vehicles.

import { useMemo, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';

export default function AgeProfileCard({ vehicles = [], className = '' }) {
  const { t } = useI18n();
  const [hover, setHover] = useState(null);

  const bars = useMemo(() => {
    const years = vehicles
      .map((v) => Number(v.year))
      .filter((y) => Number.isFinite(y) && y > 1980 && y < 2100);
    if (!years.length) return null;
    const min = Math.min(...years);
    const max = Math.max(...years);
    const counts = {};
    years.forEach((y) => { counts[y] = (counts[y] || 0) + 1; });
    const out = [];
    for (let y = min; y <= max; y++) out.push({ year: y, value: counts[y] || 0 });
    return out;
  }, [vehicles]);

  const peak = bars ? bars.reduce((m, b) => Math.max(m, b.value), 0) : 0;
  // Round the axis up to something a person would draw — 10, 20, 50 — so the ticks are readable
  // numbers rather than whatever the tallest bar happened to be.
  const ceiling = peak <= 5 ? 5 : Math.ceil(peak / 10) * 10;
  const ticks = [ceiling, Math.round((ceiling * 2) / 3), Math.round(ceiling / 3), 0];

  return (
    <div className={`rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm dark:border-slate-700/60 dark:bg-slate-900 ${className}`}>
      <h2 className="font-display text-lg font-bold tracking-tight text-slate-900 dark:text-slate-50">
        {t('vehicles.age.title')}
      </h2>
      <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{t('vehicles.age.subtitle')}</p>

      {!bars ? (
        <p className="flex h-[210px] items-center justify-center text-sm text-slate-400">
          {t('vehicles.age.empty')}
        </p>
      ) : (
        <div className="mt-6 flex gap-3">
          {/* Y axis */}
          <div className="flex h-[190px] w-6 shrink-0 flex-col justify-between text-end text-[10px] font-medium text-slate-400 tabular-nums">
            {ticks.map((n) => <span key={n}>{n}</span>)}
          </div>

          <div className="relative min-w-0 flex-1">
            <div className="flex h-[190px] items-end gap-2">
              {bars.map((b) => {
                const on = hover === b.year;
                return (
                  <div
                    key={b.year}
                    className="group relative flex h-full flex-1 items-end justify-center"
                    onMouseEnter={() => setHover(b.year)}
                    onMouseLeave={() => setHover(null)}
                  >
                    {on && b.value > 0 && (
                      <span className="pointer-events-none absolute -top-1 z-10 -translate-y-full whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg dark:bg-slate-700">
                        {t('vehicles.age.tooltip', { n: b.value })}
                      </span>
                    )}
                    <span
                      className={`w-full rounded-t-md transition-colors ${
                        b.value === peak && peak > 0
                          ? 'bg-blue-600 dark:bg-blue-500'
                          : on
                            ? 'bg-blue-500 dark:bg-blue-400'
                            : 'bg-blue-300 dark:bg-blue-500/50'
                      }`}
                      // A year with no cars still occupies its slot, drawn as a hairline so the gap
                      // in the fleet is visible instead of being silently skipped.
                      style={{ height: b.value ? `${Math.max(2, (b.value / ceiling) * 100)}%` : '2px' }}
                    />
                  </div>
                );
              })}
            </div>
            <div className="mt-2 flex gap-2">
              {bars.map((b) => (
                <span key={b.year} className="flex-1 text-center text-[11px] font-medium text-slate-400 tabular-nums">
                  {b.year}
                </span>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
