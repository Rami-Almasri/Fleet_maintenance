// The chart strip for Vendors. The table is one row per supplier; these charts show
// the shape of the supplier network instead:
//
//   1. Network by type → how many vendors of each kind, and how many have gone
//      inactive (a type that is mostly inactive is a single-point-of-failure risk)
//   2. Insurance coverage → which insurer carries how much of the fleet, when
//      insurance vendors exist; otherwise the overall active/inactive split
//
// Derived from the /Vendor list already on the page.

import { useMemo } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';

// Vendor type → its display label. The keys are the API's own enum values and never localize; the
// label is looked up through the translator at render time so the charts and the table agree.
const TYPE_LABEL = {
  garage: 'Garage',
  parts_supplier: 'Parts',
  insurance: 'Insurance',
  service_center: 'Service',
  fuel_station: 'Fuel',
  other: 'Other',
};

export default function VendorsAnalytics({ vendors = [] }) {
  const { t } = useI18n();

  const byType = useMemo(() => {
    const groups = new Map();
    vendors.forEach((v) => {
      const k = v.type || 'other';
      const g = groups.get(k) || { label: t(TYPE_LABEL[k] || 'Other'), active: 0, inactive: 0 };
      if (v.active) g.active += 1; else g.inactive += 1;
      groups.set(k, g);
    });
    return [...groups.values()].sort((a, b) => (b.active + b.inactive) - (a.active + a.inactive));
  }, [vendors, t]);

  const insurers = useMemo(
    () =>
      vendors
        .filter((v) => v.type === 'insurance' && (v.insured_vehicles_count || 0) > 0)
        .sort((a, b) => (b.insured_vehicles_count || 0) - (a.insured_vehicles_count || 0))
        .slice(0, 10)
        .map((v) => ({
          key: v.id,
          label: v.name,
          value: v.insured_vehicles_count || 0,
          active: v.active,
        })),
    [vendors],
  );

  const standing = useMemo(() => {
    const active = vendors.filter((v) => v.active).length;
    return [
      { label: t('Active'), value: active, color: 'emerald' },
      { label: t('Inactive'), value: vendors.length - active, color: 'slate' },
    ].filter((s) => s.value > 0);
  }, [vendors, t]);

  if (!vendors.length) return null;

  const insuredTotal = insurers.reduce((a, v) => a + v.value, 0);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('Supplier network by type')}
        subtitle={t('How many vendors of each kind, and how many have gone inactive')}
        bodyClass="px-3 pb-3 pt-2"
      >
        {byType.length ? (
          <GroupedBarChart
            data={byType}
            series={[
              { key: 'active', label: t('Active'), color: 'emerald' },
              { key: 'inactive', label: t('Inactive'), color: 'slate' },
            ]}
            height={240}
            integer
            format={(n) => num(Math.round(n))}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
            {t('No vendors match this filter.')}
          </div>
        )}
      </SectionCard>

      {insurers.length ? (
        <SectionCard
          title={t('Insurance coverage')}
          subtitle={t('{n} cars covered', { n: num(insuredTotal) })}
          bodyClass="p-5"
        >
          <RankedBar
            items={insurers}
            color="cyan"
            format={(n) => num(Math.round(n))}
            valueLabel={t('Cars insured')}
            labelWidth={120}
            valueWidth={44}
            tooltip={(r) => (r.active ? t('Active insurer') : t('Marked inactive — check renewals'))}
            empty={t('No insured vehicles recorded.')}
          />
        </SectionCard>
      ) : (
        <SectionCard
          title={t('Vendor standing')}
          subtitle={t('Active vs inactive suppliers')}
          bodyClass="flex items-center justify-center p-5"
        >
          <PieChart segments={standing} size={150} />
        </SectionCard>
      )}
    </div>
  );
}
