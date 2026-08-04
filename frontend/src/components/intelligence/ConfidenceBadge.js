// HOW MUCH TO BELIEVE THE NUMBER NEXT TO THIS BADGE.
//
// A rate over 43 repairs and a rate over 1,840 look identical on a page and are not the same claim.
// Sample size is the single most important thing a reader needs and the easiest thing for a
// dashboard to leave out — so it travels with every governed figure rather than living in a tooltip
// somebody has to go looking for.
//
// The bands come from the metric contract, not from this component. A UI that decides what counts as
// "strong evidence" is a second opinion about the metric, which is the class of problem this whole
// platform spent a convergence removing.
//
// Colour is never the only carrier: the band word and the count are always printed, so this reads in
// greyscale and to a colourblind reader.

import Badge from '../ui/Badge';
import Tooltip from '../ui/Tooltip';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const TONE = { high: 'green', medium: 'amber', low: 'gray' };

export default function ConfidenceBadge({ band, sampleSize, coverage, asOf, className = '' }) {
  const { t } = useI18n();
  const c = (k, v) => t(`intelligence.confidence.${k}`, v);

  if (!band && sampleSize == null) return null;

  // Coverage answers a different question from sample size: "enough of WHAT?". A figure measured
  // over 84% of the corpus is a different claim from one measured over all of it, and the gap is
  // the repairs too recent to have been judged yet.
  const pct = coverage?.total ? Math.round((coverage.covered / coverage.total) * 100) : null;

  const tip = (
    <span className="block text-start">
      <span className="block font-semibold">{c(`band.${band || 'low'}.title`)}</span>
      <span className="mt-0.5 block">{c(`band.${band || 'low'}.body`, { n: num(sampleSize || 0) })}</span>
      {pct != null && pct < 100 && (
        <span className="mt-1 block text-white/70">{c('coverage', { pct, missing: num(coverage.total - coverage.covered) })}</span>
      )}
      {asOf && <span className="mt-1 block text-white/70">{c('asOf', { date: asOf })}</span>}
    </span>
  );

  return (
    <Tooltip content={tip}>
      <span className={`inline-flex items-center gap-1.5 ${className}`}>
        <Badge tone={TONE[band] || 'gray'} dot>
          {c(`band.${band || 'low'}.label`)}
        </Badge>
        {sampleSize != null && (
          <span className="text-[11px] font-medium tabular-nums text-slate-500">
            {c('sample', { n: num(sampleSize) })}
          </span>
        )}
      </span>
    </Tooltip>
  );
}
