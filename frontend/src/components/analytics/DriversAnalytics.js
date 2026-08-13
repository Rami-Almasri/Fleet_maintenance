// The chart strip for Drivers. The table flags a licence only once it's inside 30
// days; these charts show the whole runway, so an expiry wave is visible months
// before it becomes a scramble.
//
//   1. Licence expiry runway → drivers per remaining-validity band
//   2. Driver status          → active vs suspended
//
// Derived from the /Driver list already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import BarChart from '../ui/BarChart';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Remaining-validity bands, worst first. Fixed rather than data-derived: "expired"
// and "good for a year" are different kinds of problem, and that meaning must not
// drift with the dataset. `key` is the stable identity; `label` is display only.
const BANDS = [
  { key: 'expired', label: (t) => t('Expired'), color: 'red', test: (d) => d < 0 },
  { key: 'lte30', label: (t) => t('≤30d'), color: 'orange', test: (d) => d >= 0 && d <= 30 },
  { key: '31to90', label: (t) => t('31–90d'), color: 'amber', test: (d) => d > 30 && d <= 90 },
  { key: '91to365', label: (t) => t('91–365d'), color: 'blue', test: (d) => d > 90 && d <= 365 },
  { key: '1yPlus', label: (t) => t('1y+'), color: 'emerald', test: (d) => d > 365 },
];

const STATUS_COLOR = { active: 'emerald', suspended: 'red' };

const statusLabel = (k, t) => ({
  active: t('Active'),
  suspended: t('Suspended'),
  unknown: t('Unknown'),
}[k] || k.charAt(0).toUpperCase() + k.slice(1));

const daysToExpiry = (d) => {
  if (!d) return null;
  const diff = new Date(d).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0);
  return Math.round(diff / 86400000);
};

export default function DriversAnalytics({ drivers = [] }) {
  const { t } = useI18n();

  const runway = useMemo(() => {
    const buckets = BANDS.map((b) => ({ key: b.key, label: b.label(t), value: 0, color: b.color }));
    let unknown = 0;
    drivers.forEach((d) => {
      const days = daysToExpiry(d.license_expiry);
      if (days == null) { unknown += 1; return; }
      const i = BANDS.findIndex((b) => b.test(days));
      if (i >= 0) buckets[i].value += 1;
    });
    return { buckets, unknown, any: buckets.some((b) => b.value > 0) };
  }, [drivers, t]);

  const status = useMemo(() => {
    const totals = {};
    drivers.forEach((d) => {
      const k = d.status || 'unknown';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: statusLabel(k, t),
        value,
        color: STATUS_COLOR[k] || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [drivers, t]);

  if (!drivers.length) return null;

  const urgent = runway.buckets[0].value + runway.buckets[1].value;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('Licence expiry runway')}
        subtitle={t('Drivers by how long their licence is still valid')}
        bodyClass="px-3 pb-3 pt-2"
      >
        {runway.any ? (
          <>
            {/* Bars are individually coloured because each band is a STATUS, not a
                position on a scale — expired must always read red. */}
            <BarChart
              data={runway.buckets}
              height={230}
              yTicks={3}
              valueLabel={t('Drivers')}
              format={(n) => num(Math.round(n))}
              tooltip={(d) => (d.key === 'expired' ? t('Cannot legally drive') : t('Valid'))}
            />
            <p className="px-3 pb-1 text-xs leading-relaxed text-slate-500">
              {urgent > 0
                ? (urgent === 1
                  ? t('{n} driver expired or expiring within 30 days.', { n: num(urgent) })
                  : t('{n} drivers expired or expiring within 30 days.', { n: num(urgent) }))
                : t('No licence expires in the next 30 days.')}
              {runway.unknown > 0 && (
                <>
                  {' '}
                  {runway.unknown === 1
                    ? t('{n} driver has no expiry date on file.', { n: num(runway.unknown) })
                    : t('{n} drivers have no expiry date on file.', { n: num(runway.unknown) })}
                </>
              )}
            </p>
          </>
        ) : (
          <div className="flex h-[230px] items-center justify-center text-sm text-slate-400">
            {t('No licence expiry dates on file.')}
          </div>
        )}
      </SectionCard>

      <SectionCard
        title={t('Driver status')}
        subtitle={t('Who is available to dispatch')}
        bodyClass="flex items-center justify-center p-5"
      >
        <PieChart segments={status} size={150} />
      </SectionCard>
    </div>
  );
}
