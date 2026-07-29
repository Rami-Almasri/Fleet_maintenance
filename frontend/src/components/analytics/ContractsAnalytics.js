// The chart strip for Contracts.
//
// IMPORTANT: this page paginates SERVER-side (50 rows a request), so these charts
// describe the page currently in front of you — not the whole contract book. The
// subtitles say so explicitly rather than implying a fleet-wide total, and because
// the type / state / balance filters run server-side, narrowing them makes the
// charts a genuine slice of the book rather than an arbitrary sample.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import BarChart from '../ui/BarChart';
import { aedCompact, num } from '../../lib/format';

const TYPE_META = {
  C: { label: 'Rental', color: 'purple' },
  U: { label: 'Maintenance', color: 'amber' },
  R: { label: 'Booking', color: 'teal' },
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export default function ContractsAnalytics({ rows = [], showFinancials = false }) {
  const mix = useMemo(() => {
    const totals = {};
    rows.forEach((c) => {
      const k = c.contract_type || '?';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: TYPE_META[k]?.label || 'Other',
        value,
        color: TYPE_META[k]?.color || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [rows]);

  // Who owes the most on this page — the collections shortlist.
  const owing = useMemo(
    () =>
      rows
        .filter((c) => Number(c.contract_balance || 0) > 0)
        .sort((a, b) => Number(b.contract_balance) - Number(a.contract_balance))
        .slice(0, 10)
        .map((c) => ({
          key: c.id,
          label: `#${c.contract_no || c.id}`,
          sub: c.customer?.name_en || (c.customer ? `#${c.customer.customer_no}` : undefined),
          to: `/contracts/${c.id}`,
          value: Number(c.contract_balance) || 0,
          plate: c.vehicle?.plate_no,
          state: c.state,
        })),
    [rows],
  );

  // Contracts started per month, oldest → newest, over whatever span this page covers.
  const byMonth = useMemo(() => {
    const dates = rows
      .map((c) => (c.out_date ? new Date(c.out_date) : null))
      .filter((d) => d && !isNaN(d.getTime()));
    if (!dates.length) return null;
    const key = (d) => `${d.getFullYear()}-${d.getMonth()}`;
    const totals = {};
    dates.forEach((d) => { totals[key(d)] = (totals[key(d)] || 0) + 1; });
    return Object.entries(totals)
      .map(([k, value]) => {
        const [y, m] = k.split('-').map(Number);
        return { label: MONTHS[m], value, sortKey: y * 12 + m, year: y };
      })
      .sort((a, b) => a.sortKey - b.sortKey)
      .slice(-12);
  }, [rows]);

  if (!rows.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title="Contract mix"
        subtitle={`Types on this page · ${num(rows.length)} contracts`}
        bodyClass="flex items-center justify-center p-5"
      >
        {mix.length ? (
          <PieChart segments={mix} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            Nothing to chart.
          </div>
        )}
      </SectionCard>

      {showFinancials ? (
        <SectionCard
          className="lg:col-span-2"
          title="Biggest outstanding balances"
          subtitle="Contracts on this page where the customer still owes"
          bodyClass="p-5"
        >
          <RankedBar
            items={owing}
            showRank
            color="red"
            format={aedCompact}
            valueLabel="Owed"
            labelWidth={150}
            tooltip={(r) => [r.plate, r.state].filter(Boolean).join(' · ') || 'No vehicle on file'}
            empty="Nothing outstanding on this page."
          />
        </SectionCard>
      ) : (
        <SectionCard
          className="lg:col-span-2"
          title="Contracts started"
          subtitle="Out-dates on this page, by month"
          bodyClass="px-3 pb-3 pt-2"
        >
          {byMonth ? (
            <BarChart
              data={byMonth}
              color="indigo"
              height={230}
              yTicks={3}
              valueLabel="Contracts"
              format={(n) => num(Math.round(n))}
              tooltip={(d) => `${d.label} ${d.year}`}
            />
          ) : (
            <div className="flex h-[230px] items-center justify-center text-sm text-slate-400">
              No out-dates on this page.
            </div>
          )}
        </SectionCard>
      )}
    </div>
  );
}
