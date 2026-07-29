// The chart strip for the Fleet Registry. The KPI tiles above count the four live
// states; these charts describe the fleet itself — what it's made of and what
// condition it's in — which the tiles can't show.
//
//   1. Fleet composition → cars per make, the biggest blocks of the fleet
//   2. Condition grades  → how much of the fleet is flagged, and how badly
//   3. Age profile       → model-year spread, the replacement-planning view
//
// Derived from the /Vehicle list already on the page and scoped to the ACTIVE
// fleet (sold / disposed excluded), matching the KPI tiles above.

import { useMemo } from 'react';
import AnalyticsCard from './AnalyticsCard';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import BarChart from '../ui/BarChart';
import { num } from '../../lib/format';

// Condition grade → label + the app's reserved status colour. Grades are a state,
// never an ordinary series, so these hues aren't reused elsewhere in the strip.
const GRADE = {
  red: { label: 'Critical', color: 'red' },
  yellow: { label: 'Maintenance needed', color: 'amber' },
  orange: { label: 'Watch', color: 'orange' },
};
const GRADE_OK = { label: 'OK', color: 'emerald' };

export default function VehiclesAnalytics({ vehicles = [] }) {
  const byMake = useMemo(() => {
    const totals = {};
    vehicles.forEach((v) => {
      const k = v.make || 'Unspecified';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([label, value]) => ({ key: label, label, value }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [vehicles]);

  const condition = useMemo(() => {
    const totals = {};
    vehicles.forEach((v) => {
      const g = GRADE[v.condition_grade];
      const key = g ? v.condition_grade : 'ok';
      totals[key] = (totals[key] || 0) + 1;
    });
    // Worst first, so a red slice always leads the ring.
    return ['red', 'yellow', 'orange', 'ok']
      .filter((k) => totals[k] > 0)
      .map((k) => {
        const meta = k === 'ok' ? GRADE_OK : GRADE[k];
        return { label: meta.label, value: totals[k], color: meta.color };
      });
  }, [vehicles]);

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

  const flagged = condition.filter((c) => c.label !== 'OK').reduce((a, c) => a + c.value, 0);

  return (
    <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <AnalyticsCard
        variant="opx"
        dotColor="#2563eb"
        title="Fleet composition"
        subtitle="Active cars per make"
      >
        <RankedBar
          items={byMake}
          color="blue"
          format={(n) => num(Math.round(n))}
          valueLabel="Cars"
          labelWidth={120}
          valueWidth={44}
          tooltip={(r) => `${Math.round((r.value / vehicles.length) * 100)}% of the active fleet`}
          empty="No vehicles to chart."
        />
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#e11d48"
        title="Condition grades"
        subtitle={`${num(flagged)} of ${num(vehicles.length)} cars flagged`}
      >
        {condition.length ? (
          <div className="flex items-center justify-center">
            <PieChart segments={condition} size={150} />
          </div>
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            No condition grades recorded.
          </div>
        )}
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#22d3ee"
        title="Age profile"
        subtitle="Active cars by model year"
      >
        {ages ? (
          <BarChart
            data={ages}
            color="cyan"
            height={210}
            yTicks={3}
            valueLabel="Cars"
            format={(n) => num(Math.round(n))}
            tooltip={(d) => `Model year ${d.year}`}
          />
        ) : (
          <div className="flex h-[210px] items-center justify-center text-sm text-slate-400">
            No model years on file.
          </div>
        )}
      </AnalyticsCard>
    </div>
  );
}
