import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { aed2, fmtDate, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

export default function MaintenanceAnalytics() {
  const { t } = useI18n();
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/analytics');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const averages = data?.service_averages || [];
  const comparison = data?.vendor_comparison || [];
  const recurring = data?.recurring_faults || [];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('Maintenance Analytics')} subtitle={t('Average service costs and which garage gives the best price — spot overcharging and rising part costs.')} />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Recurring faults — same car in for the same issue 3+ times (scenario step 8) */}
        {recurring.length > 0 && (
          <Card className="ring-1 ring-red-200">
            <div className="flex items-center justify-between border-b border-red-100 bg-red-50/60 px-6 py-4">
              <div>
                <h3 className="text-base font-semibold text-red-700">🔁 {t('Recurring Faults')}</h3>
                <p className="mt-0.5 text-xs text-slate-500">{t('Cars that came back for the SAME fault 3+ times — chronic problems worth investigating.')}</p>
              </div>
              <Badge tone="red">{num(recurring.length)}</Badge>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50/90">
                  <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-5 py-3.5">{t('Car')}</th>
                    <th className="px-5 py-3.5">{t('Recurring fault')}</th>
                    <th className="px-5 py-3.5 text-center">{t('Times')}</th>
                    <th className="px-5 py-3.5">{t('Last')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {recurring.slice(0, 30).map((r, i) => (
                    <tr key={i} className="hover:bg-indigo-50/40 transition-colors">
                      <td className="px-5 py-3.5">
                        <Link to={`/vehicles/${r.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                        <div className="text-xs text-slate-400">{r.car || '—'}</div>
                      </td>
                      <td className="px-5 py-3.5">
                        <Badge tone={r.level === 'critical' ? 'red' : 'amber'}>{r.reason}</Badge>
                      </td>
                      <td className="px-5 py-3.5 text-center font-semibold text-red-600">{r.visits}×</td>
                      <td className="px-5 py-3.5 text-slate-500">{fmtDate(r.last_date)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {recurring.length > 30 && (
              <div className="border-t border-slate-100 px-6 py-2 text-xs text-slate-400">{t('Showing the top 30 of {total}.', { total: num(recurring.length) })}</div>
            )}
          </Card>
        )}

        {/* Average cost per service (fleet-wide) */}
        <Card>
          <div className="border-b border-slate-100 px-6 py-4">
            <h3 className="text-base font-semibold text-slate-900">{t('Average Cost per Service')}</h3>
            <p className="mt-0.5 text-xs text-slate-400">{t('Across all vehicles (items with a recorded cost).')}</p>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-100 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3.5">{t('Service')}</th>
                  <th className="px-5 py-3.5 text-center">{t('Visits')}</th>
                  <th className="px-5 py-3.5 text-end">{t('Average')}</th>
                  <th className="px-5 py-3.5 text-end">{t('Lowest')}</th>
                  <th className="px-5 py-3.5 text-end">{t('Highest')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {averages.map((s) => (
                  <tr key={s.service} className="hover:bg-indigo-50/40 transition-colors">
                    <td className="px-5 py-3.5 font-medium text-slate-900">{s.service}</td>
                    <td className="px-5 py-3.5 text-center text-slate-500">{num(s.visits)}</td>
                    <td className="px-5 py-3.5 text-end font-medium text-slate-900">{aed2(s.avg_cost)}</td>
                    <td className="px-5 py-3.5 text-end text-emerald-600">{aed2(s.min_cost)}</td>
                    <td className="px-5 py-3.5 text-end text-red-600">{aed2(s.max_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {averages.length === 0 && <EmptyState title={t('No cost data yet')} message={t('Add maintenance items with a cost (or import the maintenance log) to see averages.')} />}
        </Card>

        {/* Vendor price comparison per service */}
        <div>
          <h3 className="mb-1 text-base font-semibold text-slate-900">{t('Vendor Price Comparison')}</h3>
          <p className="mb-4 text-xs text-slate-400">{t('For each service, which garage is cheapest vs most expensive. Biggest price gaps first.')}</p>

          {comparison.length === 0 && (
            <Card><EmptyState title={t('Not enough data')} message={t('Vendor comparison needs maintenance items with costs and a garage assigned.')} /></Card>
          )}

          <div className="space-y-4">
            {comparison.map((c) => (
              <Card key={c.service}>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-6 py-4">
                  <h4 className="font-semibold text-slate-900">{c.service}</h4>
                  <div className="flex items-center gap-2 text-xs">
                    <Badge tone="gray">{c.vendor_count === 1 ? t('1 garage') : t('{n} garages', { n: num(c.vendor_count) })}</Badge>
                    {c.spread > 0 && <Badge tone="amber">{t('spread {amount}', { amount: aed2(c.spread) })}</Badge>}
                  </div>
                </div>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-slate-100 text-sm">
                    <thead className="bg-slate-50/90">
                      <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th className="px-5 py-3.5">{t('Garage')}</th>
                        <th className="px-5 py-3.5 text-center">{t('Times')}</th>
                        <th className="px-5 py-3.5 text-end">{t('Avg')}</th>
                        <th className="px-5 py-3.5 text-end">{t('Lowest')}</th>
                        <th className="px-5 py-3.5 text-end">{t('Highest')}</th>
                        <th className="px-5 py-3.5"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {c.vendors.map((v, idx) => {
                        const isCheapest = idx === 0 && c.vendor_count > 1;
                        const isDearest = idx === c.vendors.length - 1 && c.vendor_count > 1;
                        return (
                          <tr key={`${v.vendor_id}`} className={isCheapest ? 'bg-emerald-50/50' : ''}>
                            <td className="px-5 py-3.5 font-medium text-slate-900">{v.vendor || '—'}</td>
                            <td className="px-5 py-3.5 text-center text-slate-500">{num(v.visits)}</td>
                            <td className="px-5 py-3.5 text-end font-medium text-slate-900">{aed2(v.avg_cost)}</td>
                            <td className="px-5 py-3.5 text-end text-slate-600">{aed2(v.min_cost)}</td>
                            <td className="px-5 py-3.5 text-end text-slate-600">{aed2(v.max_cost)}</td>
                            <td className="px-5 py-3.5">
                              {isCheapest && <Badge tone="green">{t('Best price')}</Badge>}
                              {isDearest && <Badge tone="red">{t('Most expensive')}</Badge>}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </Card>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
