// The chart strip for the Maintenance Cycle board. The KPI row above counts the
// shop and the lanes below hold the cards; these two charts are the middle layer
// — the shape of the pipeline and where it's parked.
//
//   1. Pipeline by stage → the funnel, in journey order (never re-sorted: the order
//      IS the repair process)
//   2. Open work by garage → which garages are holding the fleet's cars right now
//
// All derived from the same filtered lanes the board renders, so the charts always
// agree with the columns underneath.

import { useMemo } from 'react';
import AnalyticsCard from './AnalyticsCard';
import RankedBar from '../ui/RankedBar';
import { num } from '../../lib/format';

export default function MaintenanceWorkflowAnalytics({ lanes = [] }) {
  const byStage = useMemo(
    () =>
      lanes.map((l) => ({
        key: l.key,
        label: l.name,
        value: l.tickets.length,
        color: l.tone,
        hint: l.hint,
      })),
    [lanes],
  );

  const tickets = useMemo(() => lanes.flatMap((l) => l.tickets), [lanes]);

  // Where the cars physically are. A ticket only lands here once it has actually
  // reached a garage — undispatched cars have no garage to sit at yet.
  const byGarage = useMemo(() => {
    const totals = {};
    tickets.forEach((tk) => {
      if (!tk.garage) return;
      totals[tk.garage] = (totals[tk.garage] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([label, value]) => ({
        key: label,
        label,
        value,
        color: 'orange',
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [tickets]);

  if (!tickets.length) return null;

  return (
    <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AnalyticsCard
        variant="opx"
        dotColor="#8b5cf6"
        title="Pipeline by stage"
        subtitle="Open tickets at each step, in journey order"
      >
        <RankedBar
          items={byStage}
          format={(n) => num(Math.round(n))}
          valueLabel="Tickets"
          labelWidth={130}
          valueWidth={44}
          tooltip={(r) => r.hint}
          empty="No tickets in the pipeline."
        />
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#f97316"
        title="Open work by garage"
        subtitle="Where the fleet's cars are sitting right now"
      >
        <RankedBar
          items={byGarage}
          format={(n) => num(Math.round(n))}
          valueLabel="Cars"
          labelWidth={140}
          valueWidth={44}
          empty="Nothing dispatched."
        />
      </AnalyticsCard>
    </div>
  );
}
