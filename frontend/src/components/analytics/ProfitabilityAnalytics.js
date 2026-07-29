// The chart strip for Fleet Profitability. Three questions a manager actually asks,
// each answered by one chart, all derived from the /Profitability rows the page has
// already fetched — no extra API call.
//
//   1. Which cars earn, and which bleed?          → diverging ranked bar (top / bottom)
//   2. How much of the revenue survives to profit? → composition donut of gross revenue
//   3. Which cars eat the repair budget?           → ranked bar of lifetime maintenance
//
// Charts read the page's CURRENTLY FILTERED rows, so they always agree with the
// table underneath rather than quietly describing a different fleet.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import Segmented from '../ui/Segmented';
import { aed2, aedCompact, num } from '../../lib/format';

const TOP_N = 8;

export default function ProfitabilityAnalytics({ rows = [], isPending }) {
  const [view, setView] = useState('top');

  // Cars that have never been rented carry a meaningless AED 0 net — ranking them
  // would bury the real leaders and laggards under a block of zeroes.
  const ranked = useMemo(
    () => rows.filter((r) => !isPending(r)).sort((a, b) => (b.net ?? 0) - (a.net ?? 0)),
    [rows, isPending],
  );

  const toItem = (r) => ({
    key: r.vehicle_id,
    label: r.plate || `#${r.vehicle_id}`,
    sub: r.car || undefined,
    to: `/vehicles/${r.vehicle_id}`,
    value: r.net ?? 0,
    rentals: r.rentals || 0,
  });

  // Top = best net profit first. Bottom = deepest loss first (so the worst car leads).
  const leaders = useMemo(() => ranked.slice(0, TOP_N).map(toItem), [ranked]);
  const laggards = useMemo(
    () => ranked.slice(-TOP_N).reverse().map(toItem),
    [ranked],
  );

  // Where the money went: gross revenue split into what it was spent on and what survived.
  const money = useMemo(() => {
    const sum = (k) => rows.reduce((a, r) => a + (Number(r[k]) || 0), 0);
    const gross = sum('gross_revenue');
    const operating = sum('operating_cost');
    const maintenance = sum('maintenance');
    return { gross, operating, maintenance, net: gross - operating - maintenance };
  }, [rows]);

  const burden = useMemo(
    () =>
      rows
        .filter((r) => (Number(r.maintenance) || 0) > 0)
        .sort((a, b) => (b.maintenance || 0) - (a.maintenance || 0))
        .slice(0, 10)
        .map((r) => ({
          key: r.vehicle_id,
          label: r.plate || `#${r.vehicle_id}`,
          sub: r.car || undefined,
          to: `/vehicles/${r.vehicle_id}`,
          value: Number(r.maintenance) || 0,
          gross: Number(r.gross_revenue) || 0,
        })),
    [rows],
  );

  const shown = view === 'top' ? leaders : laggards;
  const profitable = money.net >= 0;

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard
          className="lg:col-span-2"
          title="Profit leaders & laggards"
          subtitle={`Lifetime net profit per car — the ${TOP_N} best and the ${TOP_N} deepest losses in the current filter`}
          actions={
            <Segmented
              value={view}
              onChange={setView}
              options={[
                { key: 'top', label: 'Top earners' },
                { key: 'bottom', label: 'Biggest losses' },
              ]}
            />
          }
          bodyClass="p-5"
        >
          <RankedBar
            items={shown}
            diverging
            showRank
            format={aedCompact}
            valueLabel="Net profit"
            tooltip={(r) => `${num(r.rentals)} rental${r.rentals === 1 ? '' : 's'}`}
            empty="No rented cars in this filter yet."
          />
        </SectionCard>

        <SectionCard
          title="Where the revenue goes"
          subtitle="Gross rental revenue, split into cost and retained profit"
          bodyClass="p-5"
        >
          {money.gross > 0 ? (
            <>
              <CompositionDonut
                segments={
                  profitable
                    ? [
                        { label: 'Net profit', value: money.net, color: 'emerald' },
                        { label: 'Maintenance', value: money.maintenance, color: 'amber' },
                        { label: 'Operating cost', value: money.operating, color: 'slate' },
                      ]
                    : [
                        { label: 'Maintenance', value: money.maintenance, color: 'amber' },
                        { label: 'Operating cost', value: money.operating, color: 'slate' },
                      ]
                }
                total={profitable ? money.gross : money.maintenance + money.operating}
                centerLabel={profitable ? 'Gross revenue' : 'Total cost'}
                format={aedCompact}
                size={150}
                stroke={20}
              />
              <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
                {profitable ? (
                  <>
                    <span className="font-semibold text-emerald-600">
                      {Math.round((money.net / money.gross) * 100)}%
                    </span>{' '}
                    of every rental dirham survives as profit — {aed2(money.net)} on {aed2(money.gross)} earned.
                  </>
                ) : (
                  <>
                    Costs exceed revenue by{' '}
                    <span className="font-semibold text-red-600">{aed2(Math.abs(money.net))}</span> — this
                    selection of cars has not paid for itself.
                  </>
                )}
              </p>
            </>
          ) : (
            <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
              No revenue recorded in this filter.
            </div>
          )}
        </SectionCard>
      </div>

      <SectionCard
        title="Maintenance burden — the ten costliest cars"
        subtitle="Lifetime repair spend per car, with what each has earned back in rent"
        bodyClass="p-5"
      >
        <RankedBar
          items={burden}
          showRank
          color="amber"
          format={aedCompact}
          valueLabel="Maintenance"
          tooltip={(r) =>
            r.gross > 0
              ? `Earned ${aedCompact(r.gross)} — repairs are ${Math.round((r.value / r.gross) * 100)}% of revenue`
              : 'No rental revenue recorded'
          }
          empty="No maintenance cost recorded in this filter."
        />
      </SectionCard>
    </div>
  );
}
