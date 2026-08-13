// The chart strip for Driver Observations. Handover notes are individually small,
// so the value is entirely in the aggregate:
//
//   1. What happens to a note → how many get escalated to a real inspection versus
//      dismissed. A dismissal rate near 100% means drivers are reporting noise; a
//      rate near 0% means nobody is triaging.
//   2. Which cars keep getting flagged → repeat mentions are an early fault signal
//      long before anything reaches a maintenance ticket.
//
// Derived from the /driver-observations rows already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const STATUS = {
  open: { label: 'Open', color: 'amber' },
  inspection_requested: { label: 'Escalated to inspection', color: 'purple' },
  dismissed: { label: 'Dismissed', color: 'slate' },
};

export default function DriverObservationsAnalytics({ rows = [] }) {
  const { t } = useI18n();

  const outcomes = useMemo(() => {
    const totals = {};
    rows.forEach((r) => {
      const k = STATUS[r.status] ? r.status : 'open';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({ label: t(STATUS[k].label), value, color: STATUS[k].color }))
      .sort((a, b) => b.value - a.value);
  }, [rows, t]);

  const byCar = useMemo(() => {
    const groups = new Map();
    rows.forEach((r) => {
      const key = r.vehicle_id ?? r.plate ?? 'unknown';
      const g = groups.get(key) || {
        key,
        label: r.plate || (r.vehicle_id ? `#${r.vehicle_id}` : t('Unknown car')),
        sub: r.car || undefined,
        to: r.vehicle_id ? `/car-status/${r.vehicle_id}` : undefined,
        value: 0,
        escalated: 0,
      };
      g.value += 1;
      if (r.status === 'inspection_requested') g.escalated += 1;
      groups.set(key, g);
    });
    return [...groups.values()]
      .filter((g) => g.value > 1) // one note is normal; repeats are the signal
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [rows, t]);

  if (!rows.length) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('Cars flagged more than once')}
        subtitle={t('Repeat driver mentions — an early fault signal before any ticket exists')}
        bodyClass="p-5"
      >
        <RankedBar
          items={byCar}
          showRank
          color="amber"
          format={(n) => num(Math.round(n))}
          valueLabel={t('Observations')}
          valueWidth={56}
          tooltip={(r) =>
            r.escalated > 0
              ? t('{n} escalated to an inspection', { n: num(r.escalated) })
              : t('None escalated yet')
          }
          empty={t('No car has been flagged twice — nothing repeating.')}
        />
      </SectionCard>

      <SectionCard
        title={t('What happens to a note')}
        subtitle={t('Triage outcomes')}
        bodyClass="p-5"
      >
        {/* stacked: this card is a third-width column, so the side-by-side legend
            squeezes "Escalated to inspection" into an ellipsis. */}
        <PieChart segments={outcomes} size={150} stacked />
      </SectionCard>
    </div>
  );
}
