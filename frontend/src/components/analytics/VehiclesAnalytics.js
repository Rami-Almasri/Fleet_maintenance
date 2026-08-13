// The chart strip for the Fleet Registry. The KPI tiles above count the four live
// states; these charts describe the fleet itself — what it's made of and what
// condition it's in — which the tiles can't show.
//
//   1. Fleet composition → cars per make, the biggest blocks of the fleet
//   2. Age profile       → model-year spread, the replacement-planning view
//
// The "Condition grades" ring was removed on request — the Condition column on the
// registry below already states each car's grade, per-car, which is how people read it.
//
// Derived from the /Vehicle list already on the page and scoped to the IN-SERVICE
// fleet — cars whose status is ready or rented. Cars sitting in maintenance, out of
// order, suspended, on office use, returned, sold or disposed are not part of what the
// working fleet is made of, so they're excluded here (the KPI tiles above still count
// the whole active fleet).

import { useMemo } from 'react';
import AnalyticsCard from './AnalyticsCard';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

export default function VehiclesAnalytics({ vehicles = [] }) {
  const { t } = useI18n();
  const byMake = useMemo(() => {
    const totals = {};
    vehicles.forEach((v) => {
      const k = v.make || t('Unspecified');
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([label, value]) => ({ key: label, label, value }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [vehicles, t]);

  // Model-year spread. Only cars with a plausible year — a missing or garbage year
  // would otherwise invent a phantom bar at the left edge.
  const ages = useMemo(() => {
    const years = vehicles
      .map((v) => Number(v.year))
      .filter((y) => Number.isFinite(y) && y > 1980 && y < 2100);
    if (!years.length) return null;
    const min = Math.min(...years);
    const max = Math.max(...years);
    const buckets = [];
    const index = {};
    for (let y = min; y <= max; y++) {
      index[y] = buckets.length;
      buckets.push({ label: String(y).slice(2), value: 0, year: y });
    }
    years.forEach((y) => { buckets[index[y]].value += 1; });
    return buckets;
  }, [vehicles]);

  if (!vehicles.length) return null;

  return (
    <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AnalyticsCard
        variant="opx"
        dotColor="#2563eb"
        title={t('Fleet composition')}
        subtitle={t('In-service cars per make (ready or rented)')}
      >
        <RankedBar
          items={byMake}
          color="blue"
          format={(n) => num(Math.round(n))}
          valueLabel={t('Cars')}
          labelWidth={120}
          valueWidth={44}
          tooltip={(r) => t('{pct}% of the in-service fleet', { pct: Math.round((r.value / vehicles.length) * 100) })}
          empty={t('No vehicles to chart.')}
        />
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#22d3ee"
        title={t('Age profile')}
        subtitle={t('In-service cars by model year')}
      >
        {ages ? (
          <BarChart
            data={ages}
            color="cyan"
            height={210}
            yTicks={3}
            valueLabel={t('Cars')}
            format={(n) => num(Math.round(n))}
            tooltip={(d) => t('Model year {year}', { year: d.year })}
          />
        ) : (
          <div className="flex h-[210px] items-center justify-center text-sm text-slate-400">
            {t('No model years on file.')}
          </div>
        )}
      </AnalyticsCard>
    </div>
  );
}
