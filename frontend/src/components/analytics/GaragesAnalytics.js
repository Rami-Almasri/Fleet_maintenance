// The chart strip for Garages. The cards below give each garage its own detail
// panel; these two charts put them side by side, which is the only way to answer
// the questions that matter when choosing where to send the next car:
//
//   1. Who carries the load, and who is slow?  → ranked bar, switchable between
//      total jobs, cars in now, spend and average delay
//   2. Where does the repair money actually go? → share-of-spend donut
//
// Derived from the /Maintenance/garages payload already on the page.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';

const VIEWS = {
  jobs:  { label: 'Jobs',      color: 'indigo', valueLabel: 'Total jobs',   subtitle: 'Total jobs handled, all time',                  key: 'jobs',            format: (n) => num(Math.round(n)) },
  now:   { label: 'In now',    color: 'blue',   valueLabel: 'Cars in now',  subtitle: 'Cars sitting in each garage right now',         key: 'in_garage_now',   format: (n) => num(Math.round(n)) },
  spend: { label: 'Spend',     color: 'amber',  valueLabel: 'Total spent',  subtitle: 'Total repair spend routed to each garage',      key: 'total_spent',     format: aedCompact },
  delay: { label: 'Avg delay', color: 'red',    valueLabel: 'Avg delay',    subtitle: 'Average days late — the slowest garages first', key: 'avg_delay_days',  format: (n) => `${Math.round(n * 10) / 10}d` },
};

// A fixed hue order for the spend donut. Assigned by position and never cycled —
// past the last hue, garages fold into "Other" rather than reusing a colour.
const HUES = ['indigo', 'teal', 'purple', 'orange', 'cyan'];

export default function GaragesAnalytics({ garages = [] }) {
  const [view, setView] = useState('jobs');
  const v = VIEWS[view];

  const board = useMemo(
    () =>
      garages
        .filter((g) => (Number(g[v.key]) || 0) > 0)
        .sort((a, b) => (Number(b[v.key]) || 0) - (Number(a[v.key]) || 0))
        .slice(0, 10)
        .map((g) => ({
          key: g.vendor_id,
          label: g.garage,
          sub:
            g.on_time_rate != null
              ? `${g.on_time_rate}% on-time · ${num(g.jobs)} job${g.jobs === 1 ? '' : 's'}`
              : `${num(g.jobs)} job${g.jobs === 1 ? '' : 's'}`,
          value: Number(g[v.key]) || 0,
          late: g.late_returns || 0,
          overdue: g.overdue_now || 0,
        })),
    [garages, v],
  );

  // Share of spend — the top five garages by name, everything else folded into one
  // neutral "Other" slice so the ring stays readable however many vendors exist.
  const spendMix = useMemo(() => {
    const paid = garages.filter((g) => (Number(g.total_spent) || 0) > 0)
      .sort((a, b) => (b.total_spent || 0) - (a.total_spent || 0));
    if (!paid.length) return [];
    const head = paid.slice(0, HUES.length).map((g, i) => ({
      label: g.garage,
      value: Number(g.total_spent) || 0,
      color: HUES[i],
    }));
    const rest = paid.slice(HUES.length).reduce((a, g) => a + (Number(g.total_spent) || 0), 0);
    return rest > 0 ? [...head, { label: `Other (${paid.length - HUES.length})`, value: rest, color: 'slate' }] : head;
  }, [garages]);

  const totalSpend = spendMix.reduce((a, s) => a + s.value, 0);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Garage comparison"
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
          labelWidth={150}
          tooltip={(r) =>
            `${num(r.late)} late return${r.late === 1 ? '' : 's'}` +
            (r.overdue > 0 ? ` · ${num(r.overdue)} overdue right now` : '')
          }
          empty="No garage has this measure yet."
        />
      </SectionCard>

      <SectionCard
        title="Share of repair spend"
        subtitle="Which garages the money goes to"
        bodyClass="p-5"
      >
        {totalSpend > 0 ? (
          <>
            <CompositionDonut
              segments={spendMix}
              total={totalSpend}
              centerLabel="Total spend"
              format={aedCompact}
              size={150}
              stroke={20}
            />
            <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
              <span className="font-semibold text-slate-700">{spendMix[0].label}</span> takes{' '}
              <span className="font-semibold text-amber-600">
                {Math.round((spendMix[0].value / totalSpend) * 100)}%
              </span>{' '}
              of everything the fleet spends on repairs.
            </p>
          </>
        ) : (
          <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
            No repair spend recorded yet.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
