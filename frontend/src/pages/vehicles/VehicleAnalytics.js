// Overview analytics for a single vehicle — two cards that mirror the classic
// "analysis" dashboard tile: a smooth multi-line activity trend and a composition
// pie. Both are built from data already on the profile payload (contracts +
// maintenance visits), so they need no extra API call and no money — they render
// regardless of the SHOW_FINANCIALS flag.

import { useMemo, useState } from 'react';
import { SectionCard } from '../../components/ui/Table';
import GroupedBarChart from '../../components/ui/GroupedBarChart';
import PieChart from '../../components/ui/PieChart';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const RANGES = [
  { key: 6, label: 'Last 6 months' },
  { key: 12, label: 'Last 12 months' },
  { key: 24, label: 'Last 24 months' },
];

// Bucket key for a date string, e.g. "2026-07". Returns null for unparseable dates.
function monthKey(str) {
  if (!str) return null;
  const d = new Date(str);
  if (isNaN(d.getTime())) return null;
  return `${d.getFullYear()}-${d.getMonth()}`;
}

export default function VehicleAnalytics({ contracts = [], maintenance = [] }) {
  const [range, setRange] = useState(6);

  // ── Activity trend: rentals started vs. service visits, per month ──────────
  const trend = useMemo(() => {
    const now = new Date();
    const buckets = [];
    const index = {};
    for (let i = range - 1; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const key = `${d.getFullYear()}-${d.getMonth()}`;
      const label = MONTHS[d.getMonth()] + (range > 12 ? ` '${String(d.getFullYear()).slice(2)}` : '');
      index[key] = buckets.length;
      buckets.push({ label, rentals: 0, service: 0 });
    }
    contracts.forEach((c) => {
      if (c.contract_type === 'U') return; // maintenance contracts counted as visits below
      const k = index[monthKey(c.out_date || c.in_date)];
      if (k != null) buckets[k].rentals += 1;
    });
    maintenance.forEach((m) => {
      const k = index[monthKey(m.date || m.in_date)];
      if (k != null) buckets[k].service += 1;
    });
    return buckets;
  }, [contracts, maintenance, range]);

  const hasTrend = trend.some((b) => b.rentals || b.service);

  // ── Composition: lifetime contract mix (rental / booking / maintenance) ────
  const mix = useMemo(() => {
    const counts = { C: 0, R: 0, U: 0 };
    contracts.forEach((c) => { if (counts[c.contract_type] != null) counts[c.contract_type] += 1; });
    return [
      { label: 'Rentals', value: counts.C, color: 'purple' },
      { label: 'Bookings', value: counts.R, color: 'teal' },
      { label: 'Maintenance', value: counts.U, color: 'amber' },
      { label: 'Service visits', value: maintenance.length, color: 'slate' },
    ].filter((s) => s.value > 0);
  }, [contracts, maintenance]);

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Activity analysis"
        subtitle="Rentals started vs. workshop visits, per month"
        actions={
          <select
            value={range}
            onChange={(e) => setRange(Number(e.target.value))}
            className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600 outline-none transition hover:bg-slate-100 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/15"
          >
            {RANGES.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
          </select>
        }
        bodyClass="px-4 pb-4 pt-2"
      >
        {hasTrend ? (
          <GroupedBarChart
            data={trend}
            series={[
              { key: 'rentals', label: 'Rentals', color: 'purple' },
              { key: 'service', label: 'Service visits', color: 'teal' },
            ]}
            height={280}
            integer
            format={(n) => Math.round(n).toLocaleString()}
          />
        ) : (
          <div className="flex h-[280px] items-center justify-center text-sm text-slate-400">
            No rental or workshop activity in this window.
          </div>
        )}
      </SectionCard>

      <SectionCard
        title="Contract analysis"
        subtitle="Lifetime activity mix"
        bodyClass="flex items-center justify-center p-6"
      >
        {mix.length ? (
          <PieChart segments={mix} size={190} />
        ) : (
          <div className="flex h-[190px] items-center justify-center text-sm text-slate-400">
            No contracts recorded yet.
          </div>
        )}
      </SectionCard>
    </div>
  );
}
