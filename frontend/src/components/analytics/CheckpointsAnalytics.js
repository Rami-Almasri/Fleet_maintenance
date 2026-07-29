// The chart strip for Maintenance Progress. The roll-up chips count each schedule
// state; these charts say WHERE the slippage is concentrated, which is the thing a
// supervisor actually acts on.
//
//   1. Schedule health   → the split across on-schedule / due / overdue / ready
//   2. Slippage by garage → cars in each garage, with the overdue ones called out.
//      One garage holding most of the overdue work is a conversation; overdue
//      spread evenly across all of them is a capacity problem.
//
// Derived from the checkpoint rows already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import CompositionDonut from '../ui/CompositionDonut';
import { num } from '../../lib/format';

// Schedule state → label + the app's reserved status palette, matching the chips.
const STATE = {
  overdue: { label: 'Overdue', color: 'red' },
  needs_update: { label: 'Checkpoint due', color: 'amber' },
  on_schedule: { label: 'On schedule', color: 'emerald' },
  ready_for_pickup: { label: 'Ready for pickup', color: 'blue' },
};
const ORDER = ['overdue', 'needs_update', 'on_schedule', 'ready_for_pickup'];

export default function CheckpointsAnalytics({ rows = [] }) {
  const health = useMemo(() => {
    const totals = {};
    rows.forEach((r) => { totals[r.status] = (totals[r.status] || 0) + 1; });
    return ORDER
      .filter((k) => totals[k] > 0)
      .map((k) => ({ label: STATE[k].label, value: totals[k], color: STATE[k].color }));
  }, [rows]);

  const byGarage = useMemo(() => {
    const groups = new Map();
    rows.forEach((r) => {
      const key = r.garage || 'Not assigned';
      const g = groups.get(key) || { label: key, overdue: 0, onTrack: 0 };
      if (r.status === 'overdue') g.overdue += 1; else g.onTrack += 1;
      groups.set(key, g);
    });
    return [...groups.values()]
      .sort((a, b) => (b.overdue + b.onTrack) - (a.overdue + a.onTrack))
      .slice(0, 10);
  }, [rows]);

  if (!rows.length) return null;

  const overdue = rows.filter((r) => r.status === 'overdue').length;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Schedule health"
        subtitle="Cars in the workshop, by promise status"
        bodyClass="p-5"
      >
        <CompositionDonut
          segments={health}
          total={rows.length}
          centerLabel="In workshop"
          format={(n) => num(Math.round(n))}
          size={150}
          stroke={20}
        />
        <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
          {overdue > 0 ? (
            <>
              <span className="font-semibold text-red-600">{num(overdue)}</span> car
              {overdue === 1 ? ' is' : 's are'} past the date the garage promised —{' '}
              {Math.round((overdue / rows.length) * 100)}% of the workshop.
            </>
          ) : (
            <>Nothing is past its promised date.</>
          )}
        </p>
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title="Slippage by garage"
        subtitle="Cars held per garage, with the overdue ones separated out"
        bodyClass="px-3 pb-3 pt-2"
      >
        {byGarage.length ? (
          <GroupedBarChart
            data={byGarage}
            series={[
              { key: 'onTrack', label: 'On track', color: 'emerald' },
              { key: 'overdue', label: 'Overdue', color: 'red' },
            ]}
            height={240}
            integer
            format={(n) => num(Math.round(n))}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
            No cars in the workshop.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
