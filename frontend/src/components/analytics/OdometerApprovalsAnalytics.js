// The chart strip for Odometer Change Approvals. A review queue is usually read one
// row at a time; these charts turn the history into a data-quality signal:
//
//   1. Who is requesting manual edits → a name that dominates this list usually
//      means a broken capture step, not a careless person
//   2. What gets approved → how much of the queue survives review
//
// Derived from the pending + recent requests already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';

export default function OdometerApprovalsAnalytics({ pending = [], recent = [] }) {
  const all = useMemo(() => [...pending, ...recent], [pending, recent]);

  const requesters = useMemo(() => {
    const groups = new Map();
    all.forEach((r) => {
      const name = r.requested_by || 'Unknown';
      const g = groups.get(name) || { key: name, label: name, value: 0, pending: 0, totalKm: 0 };
      g.value += 1;
      g.totalKm += Math.abs(Number(r.delta) || 0);
      groups.set(name, g);
    });
    pending.forEach((r) => {
      const g = groups.get(r.requested_by || 'Unknown');
      if (g) g.pending += 1;
    });
    return [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
  }, [all, pending]);

  const outcomes = useMemo(() => {
    let approved = 0, rejected = 0;
    recent.forEach((r) => {
      if (r.status === 'approved') approved += 1;
      else if (r.status === 'rejected') rejected += 1;
    });
    return [
      { label: 'Approved', value: approved, color: 'emerald' },
      { label: 'Rejected', value: rejected, color: 'red' },
      { label: 'Awaiting review', value: pending.length, color: 'amber' },
    ].filter((s) => s.value > 0);
  }, [recent, pending]);

  if (!all.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Who requests odometer edits"
        subtitle="Manual corrections raised per person — a dominant name points at a broken capture step"
        bodyClass="p-5"
      >
        <RankedBar
          items={requesters}
          showRank
          color="violet"
          format={(n) => num(Math.round(n))}
          valueLabel="Requests"
          labelWidth={160}
          valueWidth={56}
          tooltip={(r) =>
            `${num(Math.round(r.totalKm))} km adjusted in total` +
            (r.pending > 0 ? ` · ${num(r.pending)} still awaiting review` : '')
          }
          empty="No odometer edits recorded."
        />
      </SectionCard>

      <SectionCard
        title="Review outcomes"
        subtitle="What happens to a request"
        bodyClass="flex items-center justify-center p-5"
      >
        {outcomes.length ? (
          <PieChart segments={outcomes} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            Nothing reviewed yet.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
