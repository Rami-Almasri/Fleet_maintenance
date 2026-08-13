// The chart strip for Damage & Accidents. The stat row counts records; these charts
// answer the three questions that decide whether damage is costing the business:
//
//   1. Is damage rising or falling?      → 12-month trend (incidents or cost)
//   2. Who ends up paying for it?        → fault split
//   3. Which cars keep getting damaged?  → per-car ranking
//
// Derived from the /Maintenance/incidents rows already on the page, and scoped to
// the current filters so the charts and the table always describe the same records.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import PieChart from '../ui/PieChart';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const monthNames = (t) => [
  t('Jan'), t('Feb'), t('Mar'), t('Apr'), t('May'), t('Jun'),
  t('Jul'), t('Aug'), t('Sep'), t('Oct'), t('Nov'), t('Dec'),
];
const WINDOW = 12;

// Fault → the page's own red/green/grey key, kept identical so the charts and the
// legend above them never tell different stories.
const FAULT_COLOR = { renter: 'red', third_party: 'emerald' };
const faultLabel = (k, t) => ({
  renter: t('Renter at fault'),
  third_party: t('Third party'),
}[k] || t('Not specified'));

export default function DamageAnalytics({ incidents = [] }) {
  const { t } = useI18n();
  const [view, setView] = useState('count');

  const trend = useMemo(() => {
    const months = monthNames(t);
    const now = new Date();
    const index = {};
    const buckets = [];
    for (let i = WINDOW - 1; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      index[`${d.getFullYear()}-${d.getMonth()}`] = buckets.length;
      buckets.push({ label: months[d.getMonth()], count: 0, cost: 0 });
    }
    incidents.forEach((i) => {
      if (!i.date) return;
      const d = new Date(i.date);
      if (isNaN(d.getTime())) return;
      const b = index[`${d.getFullYear()}-${d.getMonth()}`];
      if (b == null) return; // outside the window
      buckets[b].count += 1;
      buckets[b].cost += Number(i.cost) || 0;
    });
    return buckets;
  }, [incidents, t]);

  const series = view === 'cost'
    ? { key: 'cost', color: 'orange', valueLabel: t('Repair cost'), format: aedCompact, subtitle: t('Damage repair cost per month, last 12 months') }
    : { key: 'count', color: 'red', valueLabel: t('Incidents'), format: (n) => num(Math.round(n)), subtitle: t('Damage and accident records per month, last 12 months') };

  const chartData = useMemo(
    () => trend.map((b) => ({ label: b.label, value: b[series.key], count: b.count, cost: b.cost })),
    [trend, series.key],
  );
  const hasTrend = chartData.some((d) => d.value > 0);

  const faults = useMemo(() => {
    const totals = {};
    incidents.forEach((i) => {
      const k = FAULT_COLOR[i.fault] ? i.fault : 'unspecified';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: faultLabel(k, t),
        value,
        color: FAULT_COLOR[k] || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [incidents, t]);

  const worst = useMemo(() => {
    const groups = new Map();
    incidents.forEach((i) => {
      const id = i.vehicle_id;
      const key = id ?? i.plate ?? 'unknown';
      const g = groups.get(key) || {
        key,
        label: i.plate || (id ? `#${id}` : t('Unknown car')),
        sub: i.car || undefined,
        to: id ? `/vehicles/${id}` : undefined,
        value: 0,
        cost: 0,
      };
      g.value += 1;
      g.cost += Number(i.cost) || 0;
      groups.set(key, g);
    });
    return [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
  }, [incidents, t]);

  if (!incidents.length) return null;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard
          className="lg:col-span-2"
          title={t('Damage over time')}
          subtitle={series.subtitle}
          actions={
            <Segmented
              value={view}
              onChange={setView}
              options={[
                { key: 'count', label: t('Incidents') },
                { key: 'cost', label: t('Cost') },
              ]}
            />
          }
          bodyClass="px-3 pb-3 pt-2"
        >
          {hasTrend ? (
            <BarChart
              data={chartData}
              color={series.color}
              height={240}
              yTicks={3}
              valueLabel={series.valueLabel}
              format={series.format}
              tooltip={(d) => (d.count === 1
                ? t('{n} record · {cost}', { n: num(d.count), cost: aedCompact(d.cost) })
                : t('{n} records · {cost}', { n: num(d.count), cost: aedCompact(d.cost) }))}
            />
          ) : (
            <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
              {t('No damage recorded in the last 12 months.')}
            </div>
          )}
        </SectionCard>

        <SectionCard
          title={t("Who's at fault")}
          subtitle={t('Liability split across these records')}
          bodyClass="flex items-center justify-center p-5"
        >
          <PieChart segments={faults} size={150} />
        </SectionCard>
      </div>

      <SectionCard
        title={t('Cars with the most damage')}
        subtitle={t('Incident count per car, with what the repairs cost')}
        bodyClass="p-5"
      >
        <RankedBar
          items={worst}
          showRank
          color="red"
          format={(n) => num(Math.round(n))}
          valueLabel={t('Incidents')}
          valueWidth={64}
          tooltip={(r) => (r.cost > 0
            ? t('{cost} in repairs', { cost: aedCompact(r.cost) })
            : t('No cost recorded'))}
          empty={t('No incidents in this filter.')}
        />
      </SectionCard>
    </div>
  );
}
