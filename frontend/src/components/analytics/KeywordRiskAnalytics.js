// The chart strip for the Keyword Risk library. The tiles count each grade; these
// charts show whether the LIBRARY itself is well built:
//
//   1. Risk balance      → a library that is mostly "critical" grades nothing, because
//      everything alarms. The mix is a quality signal about the catalogue.
//   2. Coverage by category → which fault areas are richly described and which are
//      thin, so gaps in the vocabulary are visible.
//
// Derived from the /finding-keywords list already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Risk → the app's reserved status palette, mirroring the backend RISK_META.
const RISK = {
  critical: { label: 'Critical', color: 'red' },
  moderate: { label: 'Moderate', color: 'amber' },
  routine: { label: 'Routine', color: 'emerald' },
};
const ORDER = ['critical', 'moderate', 'routine'];

export default function KeywordRiskAnalytics({ keywords = [] }) {
  const { t } = useI18n();

  const mix = useMemo(() => {
    const totals = {};
    keywords.forEach((k) => { totals[k.risk] = (totals[k.risk] || 0) + 1; });
    return ORDER
      .filter((r) => totals[r] > 0)
      .map((r) => ({ label: t(RISK[r].label), value: totals[r], color: RISK[r].color }));
  }, [keywords, t]);

  // Keywords per category, split by grade. All three series are counts of keywords,
  // so they share one axis honestly.
  const byCategory = useMemo(() => {
    const groups = new Map();
    keywords.forEach((k) => {
      const key = k.category_key || 'uncategorized';
      const g = groups.get(key) || {
        label: k.category_label || t('Uncategorised'),
        critical: 0,
        moderate: 0,
        routine: 0,
      };
      if (g[k.risk] != null) g[k.risk] += 1;
      groups.set(key, g);
    });
    return [...groups.values()]
      .sort((a, b) => (b.critical + b.moderate + b.routine) - (a.critical + a.moderate + a.routine))
      .slice(0, 10);
  }, [keywords, t]);

  if (!keywords.length) return null;

  const critical = keywords.filter((k) => k.risk === 'critical').length;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title={t('Risk balance')}
        subtitle={t('How the keyword library grades faults')}
        bodyClass="flex flex-col items-center justify-center p-5"
      >
        <PieChart segments={mix} size={150} />
        <p className="mt-4 w-full border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
          {t('{pct}% of keywords are graded critical. A library where most terms are critical stops being a filter.', {
            pct: Math.round((critical / keywords.length) * 100),
          })}
        </p>
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title={t('Coverage by category')}
        subtitle={t('Keywords defined per fault area, by grade — thin bars are gaps in the vocabulary')}
        bodyClass="px-3 pb-3 pt-2"
      >
        {byCategory.length ? (
          <GroupedBarChart
            data={byCategory}
            series={[
              { key: 'critical', label: t('Critical'), color: 'red' },
              { key: 'moderate', label: t('Moderate'), color: 'amber' },
              { key: 'routine', label: t('Routine'), color: 'emerald' },
            ]}
            height={240}
            integer
            format={(n) => num(Math.round(n))}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
            {t('No keywords match this filter.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
