import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import GaragesAnalytics from '../components/analytics/GaragesAnalytics';
import { aed2, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

const DOT = { on_track: 'bg-emerald-500', at_risk: 'bg-amber-500', breached: 'bg-red-500', unknown: 'bg-slate-300' };
const PRIO_DOT = { critical: 'bg-red-500', special: 'bg-violet-500', minor: 'bg-amber-500', routine: 'bg-emerald-500' };

function Stat({ label, value, tone = 'text-slate-900' }) {
  return (
    <div>
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-0.5 text-lg font-bold tracking-tight ${tone}`}>{value}</p>
    </div>
  );
}

export default function Garages() {
  const { t, isRTL } = useI18n();
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/garages');
    return data.data?.garages || [];
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const garages = data || [];
  const totals = garages.reduce(
    (a, g) => ({ inNow: a.inNow + g.in_garage_now, overdue: a.overdue + g.overdue_now }),
    { inNow: 0, overdue: 0 },
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('garages.title')} subtitle={t('garages.subtitle')}>
          <Link to="/maintenance-workflow" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">{t('garages.board')} {isRTL ? '←' : '→'}</Link>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {/* top KPIs */}
        <div className="grid grid-cols-3 gap-4">
          <Card className="px-5 py-4"><Stat label={t('garages.kpi.garages')} value={num(garages.length)} /></Card>
          <Card className="px-5 py-4"><Stat label={t('garages.kpi.inNow')} value={num(totals.inNow)} /></Card>
          <Card className="px-5 py-4"><Stat label={t('garages.kpi.overdue')} value={num(totals.overdue)} tone={totals.overdue > 0 ? 'text-red-600' : 'text-slate-900'} /></Card>
        </div>

        {garages.length === 0 && <Card><EmptyState title={t('garages.emptyTitle')} message={t('garages.emptyMessage')} /></Card>}

        {/* Analytics — every garage side by side, before the per-garage detail cards. */}
        {garages.length > 0 && <GaragesAnalytics garages={garages} />}

        <div className="space-y-4">
          {garages.map((g) => (
            <Card key={g.vendor_id}>
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                <h3 className="text-base font-semibold text-slate-900">{g.garage}</h3>
                <div className="flex flex-wrap items-center gap-2">
                  {g.in_garage_now > 0 && <Badge tone="blue">{t('garages.badge.inGarage', { n: g.in_garage_now })}</Badge>}
                  {g.overdue_now > 0 && <Badge tone="red">{t('garages.badge.overdue', { n: g.overdue_now })}</Badge>}
                  {g.on_time_rate != null && (
                    <Badge tone={g.on_time_rate >= 80 ? 'green' : g.on_time_rate >= 50 ? 'amber' : 'red'}>{t('garages.badge.onTime', { pct: g.on_time_rate })}</Badge>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 px-6 py-4 sm:grid-cols-5">
                <Stat label={t('garages.stat.jobs')} value={num(g.jobs)} />
                <Stat label={t('garages.stat.inNow')} value={num(g.in_garage_now)} tone={g.in_garage_now > 0 ? 'text-blue-600' : 'text-slate-900'} />
                <Stat label={t('garages.stat.lateReturns')} value={num(g.late_returns)} tone={g.late_returns > 0 ? 'text-red-600' : 'text-slate-900'} />
                <Stat label={t('garages.stat.avgDelay')} value={g.avg_delay_days != null ? `${g.avg_delay_days}${t('dash.unit.d')}` : '—'} tone={g.avg_delay_days ? 'text-red-600' : 'text-slate-900'} />
                <Stat label={t('garages.stat.totalSpent')} value={aed2(g.total_spent)} />
              </div>

              {g.current_cars.length > 0 && (
                <div className="border-t border-slate-100 px-6 py-3">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('garages.carsHereNow')}</p>
                  <div className="flex flex-wrap gap-2">
                    {g.current_cars.map((car) => (
                      <Link
                        key={car.id}
                        to={`/contracts/${car.id}`}
                        className="inline-flex flex-col gap-0.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
                      >
                        <span className="flex items-center gap-2">
                          <span className={`h-2 w-2 rounded-full ${DOT[car.status] || DOT.unknown}`} />
                          <span className="font-medium text-slate-800">{car.plate || `#${car.id}`}</span>
                          <span className="text-xs text-slate-400">{car.days_out}{t('dash.unit.d')}{car.overdue_days > 0 ? ` · ${t('garages.lateBy', { n: car.overdue_days })}` : ''}</span>
                        </span>
                        {car.car && <span className="ps-4 text-xs text-slate-500">{car.car}</span>}
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {g.current_cars.length === 0 && g.recent_cars?.length > 0 && (
                <div className="border-t border-slate-100 px-6 py-3">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('garages.recentCars')}</p>
                  <div className="flex flex-wrap gap-2">
                    {g.recent_cars.map((car) => (
                      <Link
                        key={car.id}
                        to={`/vehicles/${car.id}`}
                        className="inline-flex flex-col gap-0.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
                      >
                        <span className="flex items-center gap-2">
                          <span className={`h-2 w-2 rounded-full ${PRIO_DOT[car.priority] || PRIO_DOT.routine}`} />
                          <span className="font-medium text-slate-800">{car.plate || `#${car.id}`}</span>
                          <span className="text-xs text-slate-400">{car.date || ''}</span>
                        </span>
                        {car.car && <span className="ps-4 text-xs text-slate-500">{car.car}</span>}
                      </Link>
                    ))}
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      </div>
    </div>
  );
}
