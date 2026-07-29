// The chart strip for Cost Intelligence. The page already ranks SEGMENTS by spend;
// these two charts answer the questions that rollup can't:
//
//   1. Which individual cars are most expensive to run? → ranked bar, switchable
//      between per km, per day, per rental and total spend
//   2. Is the fleet uniformly cheap with a few offenders, or expensive throughout?
//      → distribution of cars across cost-per-km bands
//
// Both derive from the /intelligence/cost rows already on the page. Cost/km and
// Cost/day are lifetime-only concepts (the page says so too), so while a date
// window is active those views step aside rather than divide a period cost by a
// lifetime denominator.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import Segmented from '../ui/Segmented';
import { niceMax } from '../ui/chartUtils';
import { aed2, aedCompact, num } from '../../lib/format';

const VIEWS = {
  km:     { label: 'Per km',      key: 'cost_per_km',      format: aed2,       color: 'indigo', valueLabel: 'Cost / km',     lifetimeOnly: true,  subtitle: 'Maintenance spend per validated kilometre travelled' },
  day:    { label: 'Per day',     key: 'cost_per_day',     format: aed2,       color: 'blue',   valueLabel: 'Cost / day',    lifetimeOnly: true,  subtitle: 'Maintenance spend per in-service day' },
  rental: { label: 'Per rental',  key: 'cost_per_rental',  format: aed2,       color: 'violet', valueLabel: 'Cost / rental', lifetimeOnly: false, subtitle: 'Maintenance spend per rental contract' },
  total:  { label: 'Total spend', key: 'maintenance_cost', format: aedCompact, color: 'amber',  valueLabel: 'Maintenance',   lifetimeOnly: false, subtitle: 'Total logged maintenance spend' },
};

const BANDS = 5;

export default function CostIntelligenceAnalytics({ rows = [], windowed = false }) {
  const [view, setView] = useState('km');

  // A date filter nulls out the lifetime ratios — fall back rather than render a
  // leaderboard of dashes. Derived, not stored, so clearing the filter restores
  // the user's original pick.
  const active = windowed && VIEWS[view].lifetimeOnly ? 'total' : view;
  const v = VIEWS[active];

  const options = useMemo(
    () =>
      Object.entries(VIEWS)
        .filter(([, o]) => !(windowed && o.lifetimeOnly))
        .map(([key, o]) => ({ key, label: o.label })),
    [windowed],
  );

  const board = useMemo(
    () =>
      rows
        .filter((r) => r[v.key] != null && r[v.key] > 0)
        .sort((a, b) => (b[v.key] || 0) - (a[v.key] || 0))
        .slice(0, 10)
        .map((r) => ({
          key: r.vehicle_id,
          label: r.plate || `#${r.vehicle_id}`,
          sub: [r.car, r.category].filter(Boolean).join(' · ') || undefined,
          to: `/vehicles/${r.vehicle_id}`,
          value: Number(r[v.key]) || 0,
          spend: Number(r.maintenance_cost) || 0,
          km: r.distance_km,
          rentals: r.rentals || 0,
        })),
    [rows, v],
  );

  // Distribution of cost/km across the fleet — equal-width bands from 0 to a clean
  // ceiling, so "most cars sit in the cheap band, four don't" reads instantly.
  const spread = useMemo(() => {
    const vals = rows.map((r) => r.cost_per_km).filter((x) => x != null && x >= 0);
    if (!vals.length) return null;
    const top = niceMax(Math.max(...vals));
    const step = top / BANDS;
    if (!step) return null;
    const dec = step < 1 ? 2 : step < 10 ? 1 : 0;
    const buckets = Array.from({ length: BANDS }, (_, i) => ({
      label: `${(step * i).toFixed(dec)}–${(step * (i + 1)).toFixed(dec)}`,
      value: 0,
      lo: step * i,
      hi: step * (i + 1),
    }));
    vals.forEach((x) => {
      // Clamp the top edge so the most expensive car lands in the last band, not past it.
      const i = Math.min(BANDS - 1, Math.floor(x / step));
      buckets[i].value += 1;
    });
    return buckets;
  }, [rows]);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Costliest cars to run"
        subtitle={v.subtitle}
        actions={<Segmented value={active} onChange={setView} options={options} />}
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color={v.color}
          format={v.format}
          valueLabel={v.valueLabel}
          valueWidth={112}
          tooltip={(r) =>
            `${aedCompact(r.spend)} total` +
            (r.km != null ? ` · ${num(r.km)} km` : '') +
            ` · ${num(r.rentals)} rental${r.rentals === 1 ? '' : 's'}`
          }
          empty="No cars with this measure in the current filter."
        />
      </SectionCard>

      <SectionCard
        title="Cost per km — fleet spread"
        subtitle="How many cars fall in each cost band"
        bodyClass="px-3 pb-3 pt-2"
      >
        {windowed ? (
          <div className="flex h-[240px] items-center justify-center px-6 text-center text-sm text-slate-400">
            Cost per km is a lifetime measure — clear the date filter to see the spread.
          </div>
        ) : spread ? (
          <BarChart
            data={spread}
            color="indigo"
            height={240}
            yTicks={3}
            valueLabel="Cars"
            format={(n) => num(Math.round(n))}
            tooltip={(d) => `AED ${d.lo.toFixed(2)}–${d.hi.toFixed(2)} per km`}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center px-6 text-center text-sm text-slate-400">
            No car has both a maintenance cost and a validated distance yet.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
