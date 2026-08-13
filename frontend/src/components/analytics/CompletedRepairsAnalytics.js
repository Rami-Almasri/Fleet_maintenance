// The chart strip for Completed Repairs. The ledger below is newest-first and one
// row per job; these charts turn it into the two shapes management asks for:
//
//   1. Is repair work (and spend) rising or falling? → 12-month trend
//   2. Who actually does the work?                    → share of jobs per garage
//
// Derived from the /maintenance-tickets/completed tickets already on the page.
// Count and money are deliberately a TOGGLE on one chart rather than two series on
// one plot — they have different units, and a second y-axis would be a lie.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import BarChart from '../ui/BarChart';
import PieChart from '../ui/PieChart';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WINDOW = 12;

// A fixed hue order for the garage mix — assigned by position, never cycled.
const HUES = ['indigo', 'teal', 'purple', 'orange', 'cyan'];

export default function CompletedRepairsAnalytics({ tickets = [], showFinancials = false }) {
  const { t } = useI18n();
  const [view, setView] = useState('count');
  // Money view only exists when the financial layer is on.
  const active = showFinancials ? view : 'count';

  // Twelve trailing months of closures, bucketed by the date the ticket was signed off.
  const trend = useMemo(() => {
    const now = new Date();
    const index = {};
    const buckets = [];
    for (let i = WINDOW - 1; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      index[`${d.getFullYear()}-${d.getMonth()}`] = buckets.length;
      buckets.push({ label: t(MONTHS[d.getMonth()]), repairs: 0, spend: 0 });
    }
    tickets.forEach((tk) => {
      const iso = tk.handoffs?.closed?.at;
      if (!iso) return;
      const d = new Date(iso);
      if (isNaN(d.getTime())) return;
      const b = index[`${d.getFullYear()}-${d.getMonth()}`];
      if (b == null) return; // older than the window
      buckets[b].repairs += 1;
      buckets[b].spend += Number(tk.cost || 0);
    });
    return buckets;
  }, [tickets, t]);

  const series = active === 'spend'
    ? { key: 'spend', color: 'emerald', valueLabel: t('Repair spend'), format: aedCompact, subtitle: t('Signed-off repair spend per month, last 12 months') }
    : { key: 'repairs', color: 'indigo', valueLabel: t('Repairs closed'), format: (n) => num(Math.round(n)), subtitle: t('Repairs signed off per month, last 12 months') };

  const chartData = useMemo(
    () => trend.map((b) => ({ label: b.label, value: b[series.key], repairs: b.repairs, spend: b.spend })),
    [trend, series.key],
  );
  const hasTrend = chartData.some((d) => d.value > 0);

  // Share of completed jobs per garage. On-site work has no garage, and it's a real
  // category rather than missing data — so it gets its own slice, not a dropped row.
  const mix = useMemo(() => {
    const totals = {};
    tickets.forEach((tk) => {
      const name = tk.garage || t('On-site');
      totals[name] = (totals[name] || 0) + 1;
    });
    const sorted = Object.entries(totals).sort((a, b) => b[1] - a[1]);
    const head = sorted.slice(0, HUES.length).map(([label, value], i) => ({ label, value, color: HUES[i] }));
    const rest = sorted.slice(HUES.length).reduce((a, [, v]) => a + v, 0);
    return rest > 0 ? [...head, { label: t('Other ({n})', { n: sorted.length - HUES.length }), value: rest, color: 'slate' }] : head;
  }, [tickets, t]);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('Repair throughput')}
        subtitle={series.subtitle}
        actions={
          showFinancials && (
            <Segmented
              value={active}
              onChange={setView}
              options={[
                { key: 'count', label: t('Repairs') },
                { key: 'spend', label: t('Spend') },
              ]}
            />
          )
        }
        bodyClass="px-3 pb-3 pt-2"
      >
        {hasTrend ? (
          <BarChart
            data={chartData}
            color={series.color}
            height={250}
            valueLabel={series.valueLabel}
            format={series.format}
            tooltip={(d) => {
              const jobs = d.repairs === 1 ? t('1 repair') : t('{n} repairs', { n: num(d.repairs) });
              return showFinancials ? `${jobs} · ${aedCompact(d.spend)}` : jobs;
            }}
          />
        ) : (
          <div className="flex h-[250px] items-center justify-center text-sm text-slate-400">
            {t('No repairs signed off in the last 12 months.')}
          </div>
        )}
      </SectionCard>

      <SectionCard
        title={t('Who did the work')}
        subtitle={t('Completed jobs by garage')}
        bodyClass="flex items-center justify-center p-5"
      >
        {mix.length ? (
          <PieChart segments={mix} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            {t('No completed repairs yet.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
