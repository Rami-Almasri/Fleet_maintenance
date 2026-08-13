// The chart strip for Maintenance History. The table ranks cars one metric at a
// time; these two charts answer the question behind the table — is workshop time
// concentrated in a few problem cars, or spread across the whole fleet?
//
//   1. Worst offenders  → ranked bar, switchable between shop days and visit count
//   2. Visit spread     → how many cars fall in each visit-count band
//
// Both read the same windowed items the table does, so they move with the date
// range picker and always reconcile with the KPIs above.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import Segmented from '../ui/Segmented';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const VIEWS = {
  days: {
    label: 'Time in shop',
    subtitle: 'Cars that spent the most days off the road',
    color: 'red',
    valueLabel: 'In shop',
    key: 'days_in_shop',
    format: (n) => `${num(Math.round(n))}d`,
  },
  visits: {
    label: 'Visit count',
    subtitle: 'Cars that went back to the workshop most often',
    color: 'amber',
    valueLabel: 'Visits',
    key: 'visits',
    format: (n) => num(Math.round(n)),
  },
};

// Visit-count bands. Fixed rather than computed: "went in once" and "went in ten
// times" are different kinds of car, and that meaning shouldn't shift with the data.
const BANDS = [
  { label: '1', test: (v) => v === 1 },
  { label: '2', test: (v) => v === 2 },
  { label: '3–4', test: (v) => v >= 3 && v <= 4 },
  { label: '5–9', test: (v) => v >= 5 && v <= 9 },
  { label: '10+', test: (v) => v >= 10 },
];

export default function MaintenanceHistoryAnalytics({ items = [] }) {
  const { t } = useI18n();
  const [view, setView] = useState('days');
  const v = VIEWS[view];

  const board = useMemo(
    () =>
      items
        .filter((r) => r[v.key] != null && r[v.key] > 0)
        .sort((a, b) => (b[v.key] || 0) - (a[v.key] || 0))
        .slice(0, 10)
        .map((r) => ({
          key: r.id,
          label: r.plate || `#${r.id}`,
          sub: r.car || undefined,
          to: `/vehicles/${r.id}`,
          value: Number(r[v.key]) || 0,
          visits: r.visits || 0,
          days: r.days_in_shop,
          inShop: r.currently_in_shop,
        })),
    [items, v],
  );

  const spread = useMemo(() => {
    const buckets = BANDS.map((b) => ({ label: b.label, value: 0 }));
    items.forEach((r) => {
      const i = BANDS.findIndex((b) => b.test(r.visits || 0));
      if (i >= 0) buckets[i].value += 1;
    });
    return buckets;
  }, [items]);

  const hasSpread = spread.some((b) => b.value > 0);
  const repeat = items.filter((r) => (r.visits || 0) >= 3).length;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <SectionCard
        title={t('Worst offenders')}
        subtitle={t(v.subtitle)}
        actions={
          <Segmented
            value={view}
            onChange={setView}
            options={Object.entries(VIEWS).map(([key, o]) => ({ key, label: t(o.label) }))}
          />
        }
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color={v.color}
          format={v.format}
          valueLabel={t(v.valueLabel)}
          labelWidth={120}
          valueWidth={64}
          tooltip={(r) =>
            (r.visits === 1
              ? t('{n} visit', { n: num(r.visits) })
              : t('{n} visits', { n: num(r.visits) })) +
            (r.days != null ? ` · ${t('{n} days in shop', { n: num(r.days) })}` : '') +
            (r.inShop ? ` · ${t('in the shop now')}` : '')
          }
          empty={t('No workshop activity in this window.')}
        />
      </SectionCard>

      <SectionCard
        title={t('How often cars go back')}
        subtitle={t('Cars grouped by number of workshop visits')}
        bodyClass="px-3 pb-3 pt-2"
      >
        {hasSpread ? (
          <>
            <BarChart
              data={spread}
              color="violet"
              height={230}
              yTicks={3}
              valueLabel={t('Cars')}
              format={(n) => num(Math.round(n))}
              tooltip={(d) =>
                d.label === '1'
                  ? t('{n} visit in this window', { n: d.label })
                  : t('{n} visits in this window', { n: d.label })
              }
            />
            <p className="px-3 pb-1 text-xs leading-relaxed text-slate-500">
              {items.length === 1
                ? t('{n} of {total} car went back three or more times — those are the ones worth investigating.', { n: num(repeat), total: num(items.length) })
                : t('{n} of {total} cars went back three or more times — those are the ones worth investigating.', { n: num(repeat), total: num(items.length) })}
            </p>
          </>
        ) : (
          <div className="flex h-[230px] items-center justify-center text-sm text-slate-400">
            {t('No workshop visits in this window.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
