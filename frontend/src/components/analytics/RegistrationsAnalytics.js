// The chart strip for Registration & Insurance. The tiles count covered vs
// uncovered; the real risk is TIMING — a fleet that's 100% covered today can have
// forty cars falling off the road next month.
//
//   1. Expiry runway → registration and insurance side by side, per validity band.
//      Two series on ONE axis because both are counts of cars, so they're directly
//      comparable — never a second scale.
//   2. Insurer concentration → how much of the fleet sits with one insurer
//
// Derived from the /Registration/coverage rows already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import RankedBar from '../ui/RankedBar';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Validity bands, worst first. "No cover" is deliberately its own band rather than
// being folded into "expired" — one is a lapsed document, the other was never filed.
// `key` is the stable identity; `label` is display only.
const BANDS = [
  { key: 'none', label: (t) => t('No cover'), test: (d) => d == null },
  { key: 'expired', label: (t) => t('Expired'), test: (d) => d != null && d < 0 },
  { key: 'lte30', label: (t) => t('≤30d'), test: (d) => d != null && d >= 0 && d <= 30 },
  { key: '31to90', label: (t) => t('31–90d'), test: (d) => d != null && d > 30 && d <= 90 },
  { key: '90plus', label: (t) => t('90d+'), test: (d) => d != null && d > 90 },
];
const AT_RISK_KEYS = ['none', 'expired', 'lte30'];

export default function RegistrationsAnalytics({ rows = [] }) {
  const { t } = useI18n();

  const runway = useMemo(() => {
    const buckets = BANDS.map((b) => ({ key: b.key, label: b.label(t), registration: 0, insurance: 0 }));
    rows.forEach((r) => {
      const regDays = r.has_registration ? r.registration_days_left : null;
      const insDays = r.has_insurance ? r.insurance_days_left : null;
      const ri = BANDS.findIndex((b) => b.test(regDays));
      const ii = BANDS.findIndex((b) => b.test(insDays));
      if (ri >= 0) buckets[ri].registration += 1;
      if (ii >= 0) buckets[ii].insurance += 1;
    });
    return buckets;
  }, [rows, t]);

  const insurers = useMemo(() => {
    const totals = {};
    rows.forEach((r) => {
      if (!r.has_insurance) return;
      const k = r.insurer || t('Insurer not recorded');
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([label, value]) => ({ key: label, label, value }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [rows, t]);

  if (!rows.length) return null;

  // Cars that cannot legally be on the road, or fall off it within a month.
  const atRisk = runway
    .filter((b) => AT_RISK_KEYS.includes(b.key))
    .reduce((a, b) => a + Math.max(b.registration, b.insurance), 0);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('Document expiry runway')}
        subtitle={t('Cars by how long their registration and insurance stay valid')}
        bodyClass="px-3 pb-3 pt-2"
      >
        <GroupedBarChart
          data={runway}
          series={[
            { key: 'registration', label: t('Registration'), color: 'blue' },
            { key: 'insurance', label: t('Insurance'), color: 'violet' },
          ]}
          height={250}
          integer
          format={(n) => num(Math.round(n))}
        />
        <p className="px-3 pb-1 pt-1 text-xs leading-relaxed text-slate-500">
          {atRisk > 0
            ? (atRisk === 1
              ? t('Up to {n} car is uncovered, expired, or expires within 30 days.', { n: num(atRisk) })
              : t('Up to {n} cars are uncovered, expired, or expire within 30 days.', { n: num(atRisk) }))
            : t('Every car has both documents valid for more than 30 days.')}
        </p>
      </SectionCard>

      <SectionCard
        title={t('Insurer concentration')}
        subtitle={t('How the covered fleet is split between insurers')}
        bodyClass="p-5"
      >
        <RankedBar
          items={insurers}
          color="violet"
          format={(n) => num(Math.round(n))}
          valueLabel={t('Cars')}
          labelWidth={120}
          valueWidth={44}
          tooltip={(r) => t('{pct}% of the fleet', { pct: Math.round((r.value / rows.length) * 100) })}
          empty={t('No insured cars in this filter.')}
        />
      </SectionCard>
    </div>
  );
}
