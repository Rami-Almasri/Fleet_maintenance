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

// Remaining-validity bands, worst first. Fixed rather than data-derived: "expired"
// and "good for a year" are different kinds of problem, and that meaning must not
// drift with the dataset.
const BANDS = [
  { label: 'Expired', color: 'red', test: (d) => d < 0 },
  { label: '≤30d', color: 'orange', test: (d) => d >= 0 && d <= 30 },
  { label: '31–90d', color: 'amber', test: (d) => d > 30 && d <= 90 },
  { label: '91–365d', color: 'blue', test: (d) => d > 90 && d <= 365 },
  { label: '1y+', color: 'emerald', test: (d) => d > 365 },
];

const STATUS_COLOR = { active: 'emerald', suspended: 'red' };

const daysToExpiry = (d) => {
  if (!d) return null;
  const diff = new Date(d).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0);
  return Math.round(diff / 86400000);
};

export default function DriversAnalytics({ drivers = [] }) {
  const runway = useMemo(() => {
    const buckets = BANDS.map((b) => ({ label: b.label, value: 0, color: b.color }));
    let unknown = 0;
    drivers.forEach((d) => {
      const days = daysToExpiry(d.license_expiry);
      if (days == null) { unknown += 1; return; }
      const i = BANDS.findIndex((b) => b.test(days));
      if (i >= 0) buckets[i].value += 1;
    });
    return { buckets, unknown, any: buckets.some((b) => b.value > 0) };
  }, [drivers]);

  const status = useMemo(() => {
    const totals = {};
    drivers.forEach((d) => {
      const k = d.status || 'unknown';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: k.charAt(0).toUpperCase() + k.slice(1),
        value,
        color: STATUS_COLOR[k] || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [drivers]);

  if (!drivers.length) return null;

  const urgent = runway.buckets[0].value + runway.buckets[1].value;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="Licence expiry runway"
        subtitle="Drivers by how long their licence is still valid"
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
              valueLabel="Drivers"
              format={(n) => num(Math.round(n))}
              tooltip={(d) => (d.label === 'Expired' ? 'Cannot legally drive' : 'Valid')}
            />
            <p className="px-3 pb-1 text-xs leading-relaxed text-slate-500">
              {urgent > 0 ? (
                <>
                  <span className="font-semibold text-red-600">{num(urgent)}</span> driver
                  {urgent === 1 ? '' : 's'} expired or expiring within 30 days.
                </>
              ) : (
                <>No licence expires in the next 30 days.</>
              )}
              {runway.unknown > 0 && (
                <> {num(runway.unknown)} driver{runway.unknown === 1 ? ' has' : 's have'} no expiry date on file.</>
              )}
            </p>
          </>
        ) : (
          <div className="flex h-[230px] items-center justify-center text-sm text-slate-400">
            No licence expiry dates on file.
          </div>
        )}
      </SectionCard>

      <SectionCard
        title="Driver status"
        subtitle="Who is available to dispatch"
        bodyClass="flex items-center justify-center p-5"
      >
        <PieChart segments={status} size={150} />
      </SectionCard>
    </div>
  );
}
