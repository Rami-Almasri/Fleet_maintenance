// ONE GOVERNED FIGURE, WITH EVERYTHING NEEDED TO JUDGE IT.
//
// The rule this component exists to enforce: no number appears without its sample size, and every
// number that can be audited says so. Leaving that to each page meant it was applied unevenly, and a
// figure that quietly omits its sample reads as more certain than one that states it — the opposite
// of the truth.
//
// It also owns the "not enough data" state, deliberately. A card that renders a percentage when the
// sample is below the floor is the single most damaging thing this platform could do: it grades a
// supplier on four repairs and looks exactly as confident as one grading on four hundred.

import ConfidenceBadge from './ConfidenceBadge';
import Tooltip from '../ui/Tooltip';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

export default function IntelligenceMetricCard({
  label,
  value,
  unit = '',
  tone = 'text-slate-900',
  hint,
  band,
  sampleSize,
  coverage,
  asOf,
  onEvidence,
  /** Why the figure is withheld. When present, the value is NOT rendered. */
  unavailableReason,
  className = '',
}) {
  const { t } = useI18n();
  const e = (k, v) => t(`intelligence.${k}`, v);

  const withheld = unavailableReason != null && unavailableReason !== '';

  return (
    <div className={`rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-slate-200 ${className}`}>
      <div className="flex items-start justify-between gap-2">
        <span className="text-xs font-medium text-slate-500">{label}</span>
        {!withheld && <ConfidenceBadge band={band} sampleSize={sampleSize} coverage={coverage} asOf={asOf} />}
      </div>

      {withheld ? (
        // NOT a zero, not a dash on its own. The reason is the point: an ungraded figure with no
        // explanation reads as a broken page rather than an honest one.
        <div className="mt-1.5">
          <p className="text-sm font-semibold text-slate-400">{e('metric.notEnough')}</p>
          <p className="mt-0.5 text-[11px] leading-relaxed text-slate-500">{unavailableReason}</p>
        </div>
      ) : (
        <>
          <div className="mt-1 flex items-baseline gap-1">
            <span className={`text-2xl font-bold tracking-tight tabular-nums ${tone}`}>{value ?? '—'}</span>
            {unit && <span className="text-sm font-medium text-slate-400">{unit}</span>}
          </div>

          {hint && (
            <Tooltip content={hint}>
              <p className="mt-0.5 line-clamp-2 text-[11px] leading-relaxed text-slate-500">{hint}</p>
            </Tooltip>
          )}

          {onEvidence && (
            <button
              type="button"
              onClick={onEvidence}
              className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:text-blue-700"
            >
              <Icon.Search className="h-3 w-3" />
              {e('evidence.showRepairs')}
            </button>
          )}
        </>
      )}
    </div>
  );
}
