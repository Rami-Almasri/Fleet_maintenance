// The chart strip for Part Investigations. Each case is a flagged duplicate spend or
// fault recurrence; the aggregate answers whether the flagging is working:
//
//   1. Case mix and priority → duplicate purchases vs fault recurrences, by urgency
//   2. Where cases stall     → the review pipeline, so a pile-up at one status is
//      visible (a stack of "reason provided" means nobody is approving)
//
// Derived from the /part-investigations list already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import GroupedBarChart from '../ui/GroupedBarChart';
import { num } from '../../lib/format';

const TYPE = {
  duplicate_purchase: { label: 'Duplicate purchase', color: 'purple' },
  fault_recurrence: { label: 'Fault recurrence', color: 'amber' },
};

// Review pipeline, in lifecycle order — the order is the process, so it isn't sorted.
const STATUS_ORDER = [
  { key: 'open', label: 'Open', color: 'blue' },
  { key: 'under_review', label: 'Under review', color: 'cyan' },
  { key: 'reason_provided', label: 'Reason provided', color: 'purple' },
  { key: 'approved', label: 'Approved', color: 'emerald' },
  { key: 'rejected', label: 'Rejected', color: 'red' },
];

export default function PartInvestigationsAnalytics({ investigations = [] }) {
  // Type × priority. Both series are counts of cases, so one axis is honest.
  const byType = useMemo(() => {
    const groups = new Map();
    investigations.forEach((inv) => {
      const k = inv.type || 'other';
      const g = groups.get(k) || { label: TYPE[k]?.label || 'Other', high: 0, medium: 0, low: 0 };
      const p = inv.priority || 'low';
      if (g[p] != null) g[p] += 1;
      groups.set(k, g);
    });
    return [...groups.values()];
  }, [investigations]);

  const pipeline = useMemo(() => {
    const totals = {};
    investigations.forEach((inv) => { totals[inv.status] = (totals[inv.status] || 0) + 1; });
    return STATUS_ORDER.map((s) => ({
      key: s.key,
      label: s.label,
      value: totals[s.key] || 0,
      color: s.color,
    }));
  }, [investigations]);

  if (!investigations.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Cases by type and urgency"
        subtitle="Duplicate spend vs fault recurrence, split by priority"
        bodyClass="px-3 pb-3 pt-2"
      >
        <GroupedBarChart
          data={byType}
          series={[
            { key: 'high', label: 'High', color: 'red' },
            { key: 'medium', label: 'Medium', color: 'amber' },
            { key: 'low', label: 'Low', color: 'slate' },
          ]}
          height={240}
          integer
          format={(n) => num(Math.round(n))}
        />
      </SectionCard>

      <SectionCard
        title="Where cases sit"
        subtitle="Review pipeline, in order"
        bodyClass="p-5"
      >
        <RankedBar
          items={pipeline}
          format={(n) => num(Math.round(n))}
          valueLabel="Cases"
          labelWidth={120}
          valueWidth={44}
          empty="No investigations open."
        />
      </SectionCard>
    </div>
  );
}
