import { useI18n } from '../../i18n/I18nContext';

/**
 * The eight rungs, and where this case is on them.
 *
 * The stage list comes from the SERVER (`stages` on the case payload), not from a constant here —
 * the ladder the progress bar draws and the ladder the service will actually accept must be the same
 * list, and two copies of it drift the first time a stage is added.
 *
 * Stages already passed are solid, the current one is ringed, the rest are outlines. A case that
 * skipped a rung (a yard scrape never sees a police report) still renders it as passed, which is
 * correct: the question was answered, just not by climbing.
 */
const LABELS = {
  reported: 'Accident reported',
  awaiting_police: 'Police report',
  assessment: 'Damage assessment',
  liability: 'Liability',
  insurance: 'Insurance',
  repair: 'Repair',
  settlement: 'Financial settlement',
  closed: 'Closed',
};

export default function AccidentProgress({ stages = [], current, index = 0 }) {
  const { t } = useI18n();

  return (
    <ol className="flex flex-wrap items-center gap-1.5" aria-label={t('Accident workflow progress')}>
      {stages.map((s, i) => {
        const done = i < index;
        const here = s === current;
        return (
          <li key={s} className="flex items-center gap-1.5">
            <span
              aria-current={here ? 'step' : undefined}
              className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition
                ${here
                  ? 'bg-rose-600 text-white ring-2 ring-rose-300'
                  : done
                    ? 'bg-emerald-100 text-emerald-700 ring-1 ring-inset ring-emerald-600/20'
                    : 'bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-300/60'}`}
            >
              <span aria-hidden className="text-[10px]">{done ? '✓' : i + 1}</span>
              {t(LABELS[s] || s.replace(/_/g, ' '))}
            </span>
            {i < stages.length - 1 && <span aria-hidden className="text-slate-300">→</span>}
          </li>
        );
      })}
    </ol>
  );
}
