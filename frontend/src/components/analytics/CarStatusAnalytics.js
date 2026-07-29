// The chart strip for the Car Status board. The lanes below already say WHERE every
// car is; these three charts say how the pipeline is shaped, what's stuck, and whose
// desk the work is sitting on — the three things a manager asks about a board they
// aren't going to scroll through car by car.
//
//   1. Pipeline by stage   → the funnel profile, in journey order (not ranked —
//                            the order IS the process, so it must not be re-sorted)
//   2. Longest in stage    → the specific cars that have stalled
//   3. Who's holding it    → load per responsible role
//
// Everything derives from the same lanes the board renders, so the charts move with
// the search box and can never disagree with the columns underneath.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { fmtDuration, stageSeconds } from '../workflow/meta';
import { num } from '../../lib/format';

// Role → the chart palette key matching its chip colour on the cards below.
const ROLE_COLOR = {
  inspector: 'amber',
  supervisor: 'purple',
  driver: 'blue',
  garage: 'orange',
  none: 'slate',
};
const ROLE_LABEL = {
  inspector: 'Inspector',
  supervisor: 'Supervisor',
  driver: 'Driver',
  garage: 'Garage',
  none: 'On hold',
};

// The lane that IS the workshop — the only one where "how long has it sat here" measures real repair
// dwell time rather than a handover step. This is the board COLUMN key (see config/maintenanceLanes.js),
// which is stable; the lane's display name is not.
const WORKSHOP_LANE = 'under_repair';

export default function CarStatusAnalytics({ lanes = [] }) {
  // The funnel profile — kept in the lanes' own order, because that order is the
  // repair journey. Sorting it by size would destroy the meaning.
  const byStage = useMemo(
    () =>
      lanes.map((l) => ({
        key: l.key,
        label: l.name,
        value: l.tickets.length,
        color: l.tone,
        role: ROLE_LABEL[l.role] || l.role,
      })),
    [lanes],
  );

  // The cars that have sat longest AT THE GARAGE. Deliberately scoped to the In-Workshop lane: the
  // other lanes are transitional (a car is "in transit" or "awaiting pickup" for hours, not weeks),
  // so ranking them together buried the repairs that are genuinely stuck behind short-lived handover
  // steps. Time in the workshop lane IS the repair's dwell time — the number worth chasing a garage over.
  const stalled = useMemo(() => {
    const workshop = lanes.find((l) => l.key === WORKSHOP_LANE);
    return (workshop?.tickets || [])
      .map((tk) => ({
        key: tk.id,
        label: tk.plate || `#${tk.id}`,
        sub: tk.ops?.responsibility?.garage || workshop.name,
        to: `/maintenance-workflow/${tk.id}`,
        value: stageSeconds(tk) ?? 0,
        color: workshop.tone,
        car: tk.car,
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 8);
  }, [lanes]);

  // Load per responsible role — who the pipeline is currently waiting on.
  const byRole = useMemo(() => {
    const totals = {};
    lanes.forEach((l) => {
      if (!l.tickets.length) return;
      totals[l.role] = (totals[l.role] || 0) + l.tickets.length;
    });
    return Object.entries(totals)
      .map(([role, value]) => ({ label: ROLE_LABEL[role] || role, value, color: ROLE_COLOR[role] || 'slate' }))
      .sort((a, b) => b.value - a.value);
  }, [lanes]);

  const total = byStage.reduce((n, s) => n + s.value, 0);
  if (!total) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Pipeline by stage"
        subtitle="Cars at each step, in journey order"
        bodyClass="p-5"
      >
        <RankedBar
          items={byStage}
          format={(n) => num(Math.round(n))}
          valueLabel="Cars"
          labelWidth={128}
          valueWidth={44}
          tooltip={(r) => `Owned by ${r.role}`}
          empty="No cars in the pipeline."
        />
      </SectionCard>

      <SectionCard
        title="Longest in the workshop"
        subtitle="Cars that have stalled at the garage"
        bodyClass="p-5"
      >
        <RankedBar
          items={stalled}
          showRank
          format={fmtDuration}
          valueLabel="At garage"
          labelWidth={116}
          valueWidth={68}
          tooltip={(r) => [r.car, r.sub].filter(Boolean).join(' · ')}
          empty="No cars are in the workshop right now."
        />
      </SectionCard>

      <SectionCard
        title="Who's holding the work"
        subtitle="Open cars by responsible role"
        bodyClass="flex items-center justify-center p-5"
      >
        {byRole.length ? (
          <PieChart segments={byRole} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            Nobody has work assigned.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
