// The chart strip for Driver Dispatch. The gauges count queues and the board shows
// individual moves; these charts answer the coordinator's two standing questions:
//
//   1. Where is the movement pipeline bunched up? → moves per phase
//   2. Is the work spread evenly across drivers?  → moves per driver, with
//      unclaimed pooled work called out rather than hidden
//
// Derived from the /logistics task list already on the page. Wears the opx skin,
// because this page does.

import { useMemo } from 'react';
import AnalyticsCard from './AnalyticsCard';
import RankedBar from '../ui/RankedBar';
import { num } from '../../lib/format';

// Phase → chart palette, matching the page's own PHASE_TONE badges.
const PHASE_COLOR = {
  dispatched: 'slate',
  en_route: 'blue',
  picked_up: 'indigo',
  delivered: 'amber',
  returned: 'emerald',
  completed: 'emerald',
  cancelled: 'slate',
  in_transit: 'indigo',
  to_destination: 'indigo',
  at_destination: 'amber',
  to_base: 'amber',
};

export default function LogisticsAnalytics({ tasks = [], pool = [] }) {
  const byPhase = useMemo(() => {
    const groups = new Map();
    tasks.forEach((t) => {
      const key = t.status || 'unknown';
      const g = groups.get(key) || {
        key,
        label: t.status_label || key.replace(/_/g, ' '),
        value: 0,
        color: PHASE_COLOR[key] || 'slate',
      };
      g.value += 1;
      groups.set(key, g);
    });
    return [...groups.values()].sort((a, b) => b.value - a.value);
  }, [tasks]);

  // Load per driver. Unclaimed pooled moves are their own row — that backlog is the
  // point of the board, so it must not vanish into an "unknown" bucket.
  const byDriver = useMemo(() => {
    const groups = new Map();
    tasks.forEach((t) => {
      const name = t.assigned_to_name;
      if (!name) return;
      const g = groups.get(name) || { key: name, label: name, value: 0, awaiting: 0 };
      g.value += 1;
      if (t.awaiting_reply) g.awaiting += 1;
      groups.set(name, g);
    });
    const rows = [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
    if (pool.length) {
      rows.unshift({ key: '__pool', label: 'Unclaimed', value: pool.length, awaiting: 0, color: 'orange' });
    }
    return rows;
  }, [tasks, pool]);

  if (!tasks.length && !pool.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2" style={{ marginBottom: 4 }}>
      <AnalyticsCard
        variant="opx"
        dotColor="#60a5fa"
        title="Moves by phase"
        subtitle="Where the movement pipeline is bunched up"
      >
        <RankedBar
          items={byPhase}
          format={(n) => num(Math.round(n))}
          valueLabel="Moves"
          labelWidth={130}
          valueWidth={44}
          empty="No moves in flight."
        />
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#34d399"
        title="Load per driver"
        subtitle="Who is carrying the moves, and what nobody has claimed"
      >
        <RankedBar
          items={byDriver}
          color="blue"
          format={(n) => num(Math.round(n))}
          valueLabel="Moves"
          labelWidth={130}
          valueWidth={44}
          tooltip={(r) =>
            r.key === '__pool'
              ? 'Pooled moves still waiting for a driver'
              : r.awaiting > 0
                ? `${num(r.awaiting)} awaiting a reply`
                : 'All moves replied to'
          }
          empty="Nothing assigned."
        />
      </AnalyticsCard>
    </div>
  );
}
