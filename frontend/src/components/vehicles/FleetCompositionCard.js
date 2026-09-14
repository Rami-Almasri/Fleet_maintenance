// Fleet Composition — what the working fleet is actually made of, marque by marque.
//
// Counted over the IN-SERVICE fleet only (cars whose status is ready or rented). Cars parked in
// maintenance, out of order, suspended, on office use, returned, sold or disposed are not part of
// what the fleet is made of; the KPI tiles above still count the whole active fleet.
//
// The marque logos are decoration on top of the name, never a replacement for it: a marque we hold
// no mark for falls back to a monogram, and the row reads the same either way.

import { brandLogo, brandInitial } from '../../lib/carAssets';
import { useI18n } from '../../i18n/I18nContext';

function Mark({ make }) {
  const src = brandLogo(make);
  if (!src) {
    return (
      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500 dark:bg-slate-700 dark:text-slate-300">
        {brandInitial(make)}
      </span>
    );
  }
  return (
    <img
      src={src}
      alt=""
      aria-hidden="true"
      loading="lazy"
      className="h-6 w-6 shrink-0 object-contain"
      // A logo file that has gone missing must not leave a broken-image glyph in a data row.
      onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }}
    />
  );
}

export default function FleetCompositionCard({ items = [], total = 0, className = '' }) {
  const { t } = useI18n();
  const max = items.reduce((m, i) => Math.max(m, i.value || 0), 0) || 1;

  return (
    <div className={`rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm dark:border-slate-700/60 dark:bg-slate-900 ${className}`}>
      <h2 className="font-display text-lg font-bold tracking-tight text-slate-900 dark:text-slate-50">
        {t('vehicles.composition.title')}
      </h2>
      <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{t('vehicles.composition.subtitle')}</p>

      <div className="mt-5 space-y-3">
        {items.length === 0 && (
          <p className="py-8 text-center text-sm text-slate-400">{t('vehicles.composition.empty')}</p>
        )}
        {items.map((row) => (
          <div
            key={row.key}
            className="flex items-center gap-3"
            title={t('vehicles.composition.share', { pct: total ? Math.round((row.value / total) * 100) : 0 })}
          >
            <Mark make={row.label} />
            <span className="w-24 shrink-0 truncate text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              {row.label}
            </span>
            <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
              <span
                className="block h-full rounded-full bg-gradient-to-r from-blue-600 to-blue-400 transition-[width] duration-700 ease-out"
                style={{ width: `${Math.max(2, (row.value / max) * 100)}%` }}
              />
            </span>
            <span className="w-8 shrink-0 text-end text-sm font-bold text-slate-900 tabular-nums dark:text-slate-100">
              {row.value}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
