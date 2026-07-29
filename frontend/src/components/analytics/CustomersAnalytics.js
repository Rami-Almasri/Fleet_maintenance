// The chart strip for Customers. The KPI row totals what's owed and what's on
// account; these charts name the individuals behind those totals.
//
//   1. Biggest balances → who owes the most, or who is carrying the most credit
//   2. Account standing → how the customer base splits: owing / settled / in credit
//
// With the financial layer off, the ranking falls back to rental VOLUME — the same
// "who matters most" question, answered without the money.
//
// Derived from the /Customer list already on the page.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';

export default function CustomersAnalytics({ customers = [], showFinancials = false }) {
  const [view, setView] = useState('owed');

  const toItem = (c, value) => ({
    key: c.id,
    label: c.name_en || `#${c.customer_no || c.id}`,
    sub: c.name_en ? `#${c.customer_no || c.id}` : undefined,
    to: `/customers/${c.id}`,
    value,
    contracts: c.contracts_count || 0,
  });

  const board = useMemo(() => {
    if (!showFinancials) {
      return customers
        .filter((c) => (c.contracts_count || 0) > 0)
        .sort((a, b) => (b.contracts_count || 0) - (a.contracts_count || 0))
        .slice(0, 10)
        .map((c) => toItem(c, c.contracts_count || 0));
    }
    if (view === 'credit') {
      // Negative balance = money paid in advance, still available to the customer.
      return customers
        .filter((c) => Number(c.balance || 0) < 0)
        .sort((a, b) => Number(a.balance) - Number(b.balance))
        .slice(0, 10)
        .map((c) => toItem(c, Math.abs(Number(c.balance))));
    }
    return customers
      .filter((c) => Number(c.balance || 0) > 0)
      .sort((a, b) => Number(b.balance) - Number(a.balance))
      .slice(0, 10)
      .map((c) => toItem(c, Number(c.balance)));
  }, [customers, view, showFinancials]);

  const standing = useMemo(() => {
    let owes = 0, settled = 0, credit = 0;
    customers.forEach((c) => {
      const v = Number(c.balance || 0);
      if (v > 0) owes += 1; else if (v < 0) credit += 1; else settled += 1;
    });
    return [
      { label: 'Owes', value: owes, color: 'red' },
      { label: 'Settled', value: settled, color: 'slate' },
      { label: 'In credit', value: credit, color: 'emerald' },
    ].filter((s) => s.value > 0);
  }, [customers]);

  if (!customers.length) return null;

  const money = showFinancials;
  const credit = view === 'credit';

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={money ? 'Biggest balances' : 'Most active customers'}
        subtitle={
          money
            ? credit
              ? 'Customers carrying the most advance credit'
              : 'Customers who owe the most right now'
            : 'Customers with the most contracts on file'
        }
        actions={
          money && (
            <Segmented
              value={view}
              onChange={setView}
              options={[
                { key: 'owed', label: 'Owed' },
                { key: 'credit', label: 'Wallet credit' },
              ]}
            />
          )
        }
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color={money ? (credit ? 'emerald' : 'red') : 'indigo'}
          format={money ? aedCompact : (n) => num(Math.round(n))}
          valueLabel={money ? (credit ? 'Wallet' : 'Owed') : 'Contracts'}
          labelWidth={170}
          valueWidth={money ? 96 : 60}
          tooltip={(r) => `${num(r.contracts)} contract${r.contracts === 1 ? '' : 's'}`}
          empty={money ? (credit ? 'Nobody is carrying credit.' : 'Nobody owes anything.') : 'No contracts on file.'}
        />
      </SectionCard>

      <SectionCard
        title="Account standing"
        subtitle="How the customer base splits"
        bodyClass="flex items-center justify-center p-5"
      >
        {standing.length ? (
          <PieChart segments={standing} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            No balances recorded.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
