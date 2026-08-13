// The chart strip for Maintenance Progress. The roll-up chips count each schedule
// state; these charts say WHERE the slippage is concentrated, which is the thing a
// supervisor actually acts on.
//
//   1. Schedule health   → the split across on-schedule / due / overdue / ready
//   2. Slippage by garage → cars in each garage, with the overdue ones called out.
//      One garage holding most of the overdue work is a conversation; overdue
//      spread evenly across all of them is a capacity problem.
//
// Derived from the checkpoint rows already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import CompositionDonut from '../ui/CompositionDonut';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Schedule state → the app's reserved status palette, matching the chips.
const STATE_COLOR = {
  overdue: 'red',
  needs_update: 'amber',
  on_schedule: 'emerald',
  ready_for_pickup: 'blue',
};
// Written out as literal t() calls so the phrase catalog can see every label.
const stateLabel = (k, t) => ({
  overdue: t('Overdue'),
  needs_update: t('Checkpoint due'),
  on_schedule: t('On schedule'),
  ready_for_pickup: t('Ready for pickup'),
}[k] || k);
const ORDER = ['overdue', 'needs_update', 'on_schedule', 'ready_for_pickup'];

export default function CheckpointsAnalytics({ rows = [] }) {
  const { t } = useI18n();

  const health = useMemo(() => {
    const totals = {};
    rows.forEach((r) => { totals[r.status] = (totals[r.status] || 0) + 1; });
    return ORDER
      .filter((k) => totals[k] > 0)
      .map((k) => ({ label: stateLabel(k, t), value: totals[k], color: STATE_COLOR[k] }));
  }, [rows, t]);

  const byGarage = useMemo(() => {
    const groups = new Map();
    rows.forEach((r) => {
      const key = r.garage || t('Not assigned');
      const g = groups.get(key) || { label: key, overdue: 0, onTrack: 0 };
      if (r.status === 'overdue') g.overdue += 1; else g.onTrack += 1;
      groups.set(key, g);
    });
    return [...groups.values()]
      .sort((a, b) => (b.overdue + b.onTrack) - (a.overdue + a.onTrack))
      .slice(0, 10);
  }, [rows, t]);

  if (!rows.length) return null;

  const overdue = rows.filter((r) => r.status === 'overdue').length;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title={t('Schedule health')}
        subtitle={t('Cars in the workshop, by promise status')}
        bodyClass="p-5"
      >
        <CompositionDonut
          segments={health}
          total={rows.length}
          centerLabel={t('In workshop')}
          format={(n) => num(Math.round(n))}
          size={150}
          stroke={20}
        />
        <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
          {overdue > 0
            ? (overdue === 1
              ? t('{n} car is past the date the garage promised — {pct}% of the workshop.', {
                n: num(overdue),
                pct: Math.round((overdue / rows.length) * 100),
              })
              : t('{n} cars are past the date the garage promised — {pct}% of the workshop.', {
                n: num(overdue),
                pct: Math.round((overdue / rows.length) * 100),
              }))
            : t('Nothing is past its promised date.')}
        </p>
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title={t('Slippage by garage')}
        subtitle={t('Cars held per garage, with the overdue ones separated out')}
        bodyClass="px-3 pb-3 pt-2"
      >
        {byGarage.length ? (
          <GroupedBarChart
            data={byGarage}
            series={[
              { key: 'onTrack', label: t('On track'), color: 'emerald' },
              { key: 'overdue', label: t('Overdue'), color: 'red' },
            ]}
            height={240}
            integer
            format={(n) => num(Math.round(n))}
          />
        ) : (
          <div className="flex h-[240px] items-center justify-center text-sm text-slate-400">
            {t('No cars in the workshop.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
