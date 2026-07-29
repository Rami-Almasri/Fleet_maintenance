// The chart strip for Fleet Utilization. Two questions, both answered from the
// /Vehicle/utilization cars already on the page — no extra API call.
//
//   1. Where does the fleet's owned time actually go?  → composition donut
//   2. Which cars are dragging the fleet down?          → ranked leaderboard,
//      switchable between workshop days, rent lost, and lowest utilization
//
// Both read the page's filtered rows, so they describe exactly the cars listed
// in the table below and move with the window/status filters above.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';

const dayFmt = (n) => `${num(Math.round(n || 0))}d`;
const pctFmt = (n) => `${Math.round(n || 0)}%`;

// The three leaderboard modes — each is a different definition of "worst".
const VIEWS = {
  maintenance: {
    label: 'Most workshop days',
    subtitle: 'Cars losing the most days to the workshop',
    color: 'red',
    valueLabel: 'Workshop days',
    format: dayFmt,
    pick: (c) => c.days_maintenance,
    sort: (a, b) => (b.days_maintenance ?? -1) - (a.days_maintenance ?? -1),
  },
  lost: {
    label: 'Most rent lost',
    subtitle: 'Estimated rent foregone while the car sat in the workshop',
    color: 'orange',
    valueLabel: 'Rent lost',
    format: aedCompact,
    pick: (c) => c.revenue_lost_downtime,
    sort: (a, b) => (b.revenue_lost_downtime ?? -1) - (a.revenue_lost_downtime ?? -1),
  },
  idle: {
    label: 'Lowest utilization',
    subtitle: 'Cars earning on the fewest of their in-service days',
    color: 'amber',
    valueLabel: 'Utilization',
    format: pctFmt,
    pick: (c) => c.utilization_pct,
    sort: (a, b) => (a.utilization_pct ?? 1e9) - (b.utilization_pct ?? 1e9),
  },
};

export default function FleetUtilizationAnalytics({ rows = [] }) {
  const [view, setView] = useState('maintenance');
  const v = VIEWS[view];

  // Fleet-wide day split. Summed from the rows rather than taken from `summary`
  // so it tracks the status/search filters the user has applied.
  const split = useMemo(() => {
    const sum = (k) => rows.reduce((a, c) => a + (Number(c[k]) || 0), 0);
    return {
      rented: sum('days_rented'),
      maintenance: sum('days_maintenance'),
      idle: sum('days_idle'),
    };
  }, [rows]);
  const totalDays = split.rented + split.maintenance + split.idle;

  const board = useMemo(() => {
    // Cars with no metric for this mode (never rented, or no rate on file) would
    // otherwise pad the leaderboard with meaningless zeroes.
    const metric = (c) => v.pick(c);
    return rows
      .filter((c) => metric(c) != null && !c.pending_service)
      .sort(v.sort)
      .slice(0, 10)
      .map((c) => ({
        key: c.vehicle_id,
        label: c.plate || c.code || `#${c.vehicle_id}`,
        sub: c.car || undefined,
        to: `/vehicles/${c.vehicle_id}`,
        value: Number(metric(c)) || 0,
        visits: c.maintenance_visits || 0,
        util: c.utilization_pct,
        down: c.downtime_pct,
      }));
  }, [rows, v]);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Where the fleet's time goes"
        subtitle="In-service days across the cars in this filter"
        bodyClass="p-5"
      >
        {totalDays > 0 ? (
          <>
            <CompositionDonut
              segments={[
                { label: 'Rented', value: split.rented, color: 'emerald' },
                { label: 'In maintenance', value: split.maintenance, color: 'red' },
                { label: 'Idle', value: split.idle, color: 'slate' },
              ]}
              total={totalDays}
              centerLabel="In-service days"
              format={dayFmt}
              size={150}
              stroke={20}
            />
            <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
              <span className="font-semibold text-emerald-600">
                {Math.round((split.rented / totalDays) * 100)}%
              </span>{' '}
              of fleet capacity earned. The other{' '}
              <span className="font-semibold text-slate-700">
                {num(Math.round(split.maintenance + split.idle))} days
              </span>{' '}
              sat in the workshop or idle.
            </p>
          </>
        ) : (
          <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
            No in-service days in this window.
          </div>
        )}
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title="Downtime leaderboard"
        subtitle={v.subtitle}
        actions={
          <Segmented
            value={view}
            onChange={setView}
            options={Object.entries(VIEWS).map(([key, o]) => ({ key, label: o.label }))}
          />
        }
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color={v.color}
          format={v.format}
          valueLabel={v.valueLabel}
          tooltip={(r) =>
            `${num(r.visits)} workshop visit${r.visits === 1 ? '' : 's'}` +
            (r.util != null ? ` · ${r.util}% utilized` : '') +
            (r.down != null ? ` · ${r.down}% downtime` : '')
          }
          empty="No cars with this metric in the current filter."
        />
      </SectionCard>
    </div>
  );
}
