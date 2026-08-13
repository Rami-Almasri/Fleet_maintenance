// The chart strip for Fleet Utilization. Two questions, both answered from the
// /Vehicle/utilization cars already on the page — no extra API call.
//
//   1. Where does the fleet's owned time actually go?  → composition donut
//   2. Which cars lose the most days to the workshop?   → ranked leaderboard
//
// Both read the page's filtered rows, so they describe exactly the cars listed
// in the table below and move with the window/status filters above.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// "12d" — the unit letter is a word, so it goes through the translator.
const makeDayFmt = (t) => (n) => t('{n}d', { n: num(Math.round(n || 0)) });

export default function FleetUtilizationAnalytics({ rows = [] }) {
  const { t } = useI18n();
  const dayFmt = makeDayFmt(t);

  // Fleet-wide day split. Summed from the rows rather than taken from `summary`
  // so it tracks the status/search filters the user has applied.
  const split = useMemo(() => {
    const sum = (k) => rows.reduce((a, c) => a + (Number(c[k]) || 0), 0);
    return {
      rented: sum('days_rented'),
      maintenance: sum('days_maintenance'),
      idle: sum('days_idle'),
    };
  }, [rows]);
  const totalDays = split.rented + split.maintenance + split.idle;

  const board = useMemo(() => {
    // Cars with no workshop figure at all would otherwise pad the leaderboard
    // with meaningless zeroes.
    return rows
      .filter((c) => c.days_maintenance != null && !c.pending_service)
      .sort((a, b) => (b.days_maintenance ?? -1) - (a.days_maintenance ?? -1))
      .slice(0, 10)
      .map((c) => ({
        key: c.vehicle_id,
        label: c.plate || c.code || `#${c.vehicle_id}`,
        sub: c.car || undefined,
        to: `/vehicles/${c.vehicle_id}`,
        value: Number(c.days_maintenance) || 0,
        visits: c.maintenance_visits || 0,
        util: c.utilization_pct,
        down: c.downtime_pct,
      }));
  }, [rows]);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title={t("Where the fleet's time goes")}
        subtitle={t('In-service days across the cars in this filter')}
        bodyClass="p-5"
      >
        {totalDays > 0 ? (
          <>
            <CompositionDonut
              segments={[
                { label: t('Rented'), value: split.rented, color: 'emerald' },
                { label: t('In maintenance'), value: split.maintenance, color: 'red' },
                { label: t('Idle'), value: split.idle, color: 'slate' },
              ]}
              total={totalDays}
              centerLabel={t('In-service days')}
              format={dayFmt}
              size={150}
              stroke={20}
            />
            <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
              {t('{pct}% of fleet capacity earned. The other {days} days sat in the workshop or idle.', {
                pct: Math.round((split.rented / totalDays) * 100),
                days: num(Math.round(split.maintenance + split.idle)),
              })}
            </p>
          </>
        ) : (
          <div className="flex h-[176px] items-center justify-center text-sm text-slate-400">
            {t('No in-service days in this window.')}
          </div>
        )}
      </SectionCard>

      <SectionCard
        className="lg:col-span-2"
        title={t('Downtime leaderboard')}
        subtitle={t('Cars losing the most days to the workshop')}
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color="red"
          format={dayFmt}
          valueLabel={t('Workshop days')}
          tooltip={(r) => {
            const parts = [
              r.visits === 1
                ? t('1 workshop visit')
                : t('{n} workshop visits', { n: num(r.visits) }),
            ];
            if (r.util != null) parts.push(t('{n}% utilized', { n: r.util }));
            if (r.down != null) parts.push(t('{n}% downtime', { n: r.down }));
            return parts.join(' · ');
          }}
          empty={t('No cars with this metric in the current filter.')}
        />
      </SectionCard>
    </div>
  );
}
