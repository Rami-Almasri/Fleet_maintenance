// The chart strip for Predictive Maintenance. The cards below explain one car at a
// time; these charts show whether the fleet's early-warning load is manageable and
// WHY cars are being flagged:
//
//   1. Urgency mix    → how the flagged fleet splits across fix-now / plan / watch
//   2. What's driving the warnings → the signal types firing most often. One signal
//      dominating usually means a threshold to tune, not fifty separate problems.
//
// Derived from the /Maintenance/foresight cars already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import { num } from '../../lib/format';

// Tier → label + the app's reserved status palette, matching the page's TIER map.
const TIER = {
  act_now: { label: 'Fix now', color: 'red' },
  plan_soon: { label: 'Plan soon', color: 'amber' },
  watch: { label: 'Keep an eye', color: 'slate' },
};
const ORDER = ['act_now', 'plan_soon', 'watch'];

// "service_overdue" → "Service overdue". Signals carry a label when the API supplies
// one; prettifying the type keeps this correct if a new signal is added server-side.
const pretty = (s) =>
  String(s || 'Other').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export default function ForesightAnalytics({ cars = [] }) {
  const tiers = useMemo(() => {
    const totals = {};
    cars.forEach((c) => {
      const k = TIER[c.tier] ? c.tier : 'watch';
      totals[k] = (totals[k] || 0) + 1;
    });
    return ORDER
      .filter((k) => totals[k] > 0)
      .map((k) => ({ label: TIER[k].label, value: totals[k], color: TIER[k].color }));
  }, [cars]);

  const signals = useMemo(() => {
    const groups = new Map();
    cars.forEach((c) => {
      (c.signals || []).forEach((s) => {
        const key = s?.type || s?.key || String(s);
        const g = groups.get(key) || { key, label: s?.label || pretty(key), value: 0, cars: new Set() };
        g.value += 1;
        g.cars.add(c.vehicle_id);
        groups.set(key, g);
      });
    });
    return [...groups.values()]
      .map((g) => ({ ...g, carCount: g.cars.size }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [cars]);

  if (!cars.length) return null;

  const actNow = cars.filter((c) => c.tier === 'act_now').length;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Urgency mix"
        subtitle="How the flagged fleet splits"
        bodyClass="p-5"
      >
        <CompositionDonut
          segments={tiers}
          total={cars.length}
          centerLabel="Cars flagged"
          format={(n) => num(Math.round(n))}
          size={150}
          stroke={20}
        />
        <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
          {actNow > 0 ? (
            <>
              <span className="font-semibold text-red-600">{num(actNow)}</span> car
              {actNow === 1 ? '' : 's'} need work now — the rest can be planned into normal
              scheduling rather than pulled off the road.
            </>
          ) : (
            <>Nothing needs immediate work — every warning can be planned.</>
          )}
        </p>
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title="What's driving the warnings"
        subtitle="Signal types firing most often across the flagged fleet"
        bodyClass="p-5"
      >
        <RankedBar
          items={signals}
          showRank
          color="violet"
          format={(n) => num(Math.round(n))}
          valueLabel="Times fired"
          labelWidth={170}
          valueWidth={56}
          tooltip={(r) => `Across ${num(r.carCount)} car${r.carCount === 1 ? '' : 's'}`}
          empty="No signals recorded on the flagged cars."
        />
      </SectionCard>
    </div>
  );
}
