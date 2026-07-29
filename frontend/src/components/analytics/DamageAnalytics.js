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

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WINDOW = 12;

// Fault → the page's own red/green/grey key, kept identical so the charts and the
// legend above them never tell different stories.
const FAULT = {
  renter: { label: 'Renter at fault', color: 'red' },
  third_party: { label: 'Third party', color: 'emerald' },
};
const FAULT_UNKNOWN = { label: 'Not specified', color: 'slate' };

export default function DamageAnalytics({ incidents = [] }) {
  const [view, setView] = useState('count');

  const trend = useMemo(() => {
    const now = new Date();
    const index = {};
    const buckets = [];
    for (let i = WINDOW - 1; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      index[`${d.getFullYear()}-${d.getMonth()}`] = buckets.length;
      buckets.push({ label: MONTHS[d.getMonth()], count: 0, cost: 0 });
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
  }, [incidents]);

  const series = view === 'cost'
    ? { key: 'cost', color: 'orange', valueLabel: 'Repair cost', format: aedCompact, subtitle: 'Damage repair cost per month, last 12 months' }
    : { key: 'count', color: 'red', valueLabel: 'Incidents', format: (n) => num(Math.round(n)), subtitle: 'Damage and accident records per month, last 12 months' };

  const chartData = useMemo(
    () => trend.map((b) => ({ label: b.label, value: b[series.key], count: b.count, cost: b.cost })),
    [trend, series.key],
  );
  const hasTrend = chartData.some((d) => d.value > 0);

  const faults = useMemo(() => {
    const totals = {};
    incidents.forEach((i) => {
      const k = FAULT[i.fault] ? i.fault : 'unspecified';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => {
        const meta = FAULT[k] || FAULT_UNKNOWN;
        return { label: meta.label, value, color: meta.color };
      })
      .sort((a, b) => b.value - a.value);
  }, [incidents]);

  const worst = useMemo(() => {
    const groups = new Map();
    incidents.forEach((i) => {
      const id = i.vehicle_id;
      const key = id ?? i.plate ?? 'unknown';
      const g = groups.get(key) || {
        key,
        label: i.plate || (id ? `#${id}` : 'Unknown car'),
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
  }, [incidents]);

  if (!incidents.length) return null;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard
          className="lg:col-span-2"
          title="Damage over time"
          subtitle={series.subtitle}
          actions={
            <Segmented
              value={view}
              onChange={setView}
              options={[
                { key: 'count', label: 'Incidents' },
                { key: 'cost', label: 'Cost' },
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
              tooltip={(d) => `${num(d.count)} record${d.count === 1 ? '' : 's'} · ${aedCompact(d.cost)}`}
            />
          ) : (
            <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
              No damage recorded in the last 12 months.
            </div>
          )}
        </SectionCard>

        <SectionCard
          title="Who's at fault"
          subtitle="Liability split across these records"
          bodyClass="flex items-center justify-center p-5"
        >
          <PieChart segments={faults} size={150} />
        </SectionCard>
      </div>

      <SectionCard
        title="Cars with the most damage"
        subtitle="Incident count per car, with what the repairs cost"
        bodyClass="p-5"
      >
        <RankedBar
          items={worst}
          showRank
          color="red"
          format={(n) => num(Math.round(n))}
          valueLabel="Incidents"
          valueWidth={64}
          tooltip={(r) => (r.cost > 0 ? `${aedCompact(r.cost)} in repairs` : 'No cost recorded')}
          empty="No incidents in this filter."
        />
      </SectionCard>
    </div>
  );
}
