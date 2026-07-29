// The chart strip for Recurring Fault Reviews. Each review assigns responsibility for
// a car that came back with the same fault; the aggregate is a scorecard nobody
// otherwise sees:
//
//   1. Where responsibility lands → the decision mix. "Workshop responsibility"
//      trending up is a supplier conversation; "customer misuse" is a contract one.
//   2. Which cars keep coming back → repeat offenders across reviews
//
// Derived from the /recurring-fault-reviews list already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';

// Decision → the page's own badge tone, mapped to the chart palette.
const DECISION = {
  same_repair_failed: { label: 'Same repair failed', color: 'red' },
  new_unrelated_failure: { label: 'New unrelated failure', color: 'blue' },
  workshop_responsibility: { label: 'Workshop responsibility', color: 'amber' },
  customer_misuse: { label: 'Customer misuse', color: 'purple' },
  investigation_required: { label: 'Investigation required', color: 'cyan' },
};

export default function RecurringFaultsAnalytics({ reviews = [] }) {
  const decisions = useMemo(() => {
    const totals = {};
    let undecided = 0;
    reviews.forEach((r) => {
      if (!r.decision) { undecided += 1; return; }
      totals[r.decision] = (totals[r.decision] || 0) + 1;
    });
    const rows = Object.entries(totals)
      .map(([k, value]) => ({
        label: DECISION[k]?.label || k,
        value,
        color: DECISION[k]?.color || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
    if (undecided) rows.push({ label: 'Awaiting a ruling', value: undecided, color: 'slate' });
    return rows;
  }, [reviews]);

  const repeat = useMemo(() => {
    const groups = new Map();
    reviews.forEach((r) => {
      const id = r.vehicle_id ?? r.vehicle?.id;
      const key = id ?? r.plate ?? 'unknown';
      const g = groups.get(key) || {
        key,
        label: r.plate || r.vehicle?.plate || (id ? `#${id}` : 'Unknown car'),
        sub: r.car || r.vehicle?.car || undefined,
        to: id ? `/vehicles/${id}` : undefined,
        value: 0,
        open: 0,
      };
      g.value += 1;
      if (r.status === 'open') g.open += 1;
      groups.set(key, g);
    });
    return [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
  }, [reviews]);

  if (!reviews.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Cars that keep coming back"
        subtitle="Recurring-fault reviews raised per car"
        bodyClass="p-5"
      >
        <RankedBar
          items={repeat}
          showRank
          color="red"
          format={(n) => num(Math.round(n))}
          valueLabel="Reviews"
          valueWidth={56}
          tooltip={(r) => (r.open > 0 ? `${num(r.open)} still awaiting a ruling` : 'All ruled on')}
          empty="No reviews raised."
        />
      </SectionCard>

      <SectionCard
        title="Where responsibility lands"
        subtitle="Management rulings across these reviews"
        bodyClass="flex items-center justify-center p-5"
      >
        <PieChart segments={decisions} size={150} />
      </SectionCard>
    </div>
  );
}
