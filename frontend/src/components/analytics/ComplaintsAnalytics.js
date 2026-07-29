// The chart strip for the Complaints Center. The stage tabs count each lifecycle
// step; these charts add the two things a tab strip can't say:
//
//   1. Where complaints pile up  → the funnel, in LIFECYCLE order (not ranked —
//      the order is the process, so sorting it by size would destroy the meaning)
//   2. Are complaints rising?    → 12-month intake trend, split by severity so a
//      rise in critical cases isn't hidden inside a flat total
//
// Derived from the /complaints rows already on the page and scoped to the current
// filters, so the charts and the table always describe the same complaints.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import GroupedBarChart from '../ui/GroupedBarChart';
import { STAGE_ORDER, STAGE_META } from '../complaints/stages';
import { num } from '../../lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WINDOW = 12;

// Stage tone → chart palette, so a chip and its bar are the same colour.
const TONE = { slate: 'slate', amber: 'amber', blue: 'blue', violet: 'purple', emerald: 'emerald' };

export default function ComplaintsAnalytics({ rows = [] }) {
  const byStage = useMemo(() => {
    const totals = {};
    rows.forEach((r) => { totals[r.status] = (totals[r.status] || 0) + 1; });
    return STAGE_ORDER.map((key) => {
      const meta = STAGE_META[key] || { label: key, tone: 'slate' };
      return { key, label: meta.label, value: totals[key] || 0, color: TONE[meta.tone] || 'slate' };
    });
  }, [rows]);

  // Intake per month, split serious vs the rest. Both series are counts of
  // complaints, so they share one axis honestly.
  const trend = useMemo(() => {
    const now = new Date();
    const index = {};
    const buckets = [];
    for (let i = WINDOW - 1; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      index[`${d.getFullYear()}-${d.getMonth()}`] = buckets.length;
      buckets.push({ label: MONTHS[d.getMonth()], serious: 0, other: 0 });
    }
    rows.forEach((r) => {
      if (!r.created_at) return;
      const d = new Date(r.created_at);
      if (isNaN(d.getTime())) return;
      const b = index[`${d.getFullYear()}-${d.getMonth()}`];
      if (b == null) return;
      if (r.severity === 'critical' || r.severity === 'high') buckets[b].serious += 1;
      else buckets[b].other += 1;
    });
    return buckets;
  }, [rows]);

  if (!rows.length) return null;

  const hasTrend = trend.some((b) => b.serious || b.other);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Where complaints sit"
        subtitle="Open cases per lifecycle stage"
        bodyClass="p-5"
      >
        <RankedBar
          items={byStage}
          format={(n) => num(Math.round(n))}
          valueLabel="Complaints"
          labelWidth={116}
          valueWidth={44}
          empty="No complaints in this filter."
        />
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title="Complaints over time"
        subtitle="Intake per month, last 12 months — serious cases called out separately"
        bodyClass="px-3 pb-3 pt-2"
      >
        {hasTrend ? (
          <GroupedBarChart
            data={trend}
            series={[
              { key: 'serious', label: 'Critical / high', color: 'red' },
              { key: 'other', label: 'Moderate / routine', color: 'blue' },
            ]}
            height={240}
            integer
            format={(n) => num(Math.round(n))}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
            No complaints raised in the last 12 months.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
