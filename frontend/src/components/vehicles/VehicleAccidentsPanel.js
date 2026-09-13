import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import { Card, EmptyState, ErrorState, TableSkeleton } from '../ui/Misc';
import { fmtDate, num } from '../../lib/format';

/**
 * ONE CAR'S ACCIDENT HISTORY — every crash it has been in, and whether any of them is still
 * holding it out of the rental pool.
 *
 * The banner at the top is the reason this panel exists on the vehicle page at all. "This car
 * cannot be rented" is answered authoritatively by ContractEligibilityService at the point of
 * booking — but the person who needs to know it EARLIEST is whoever is looking at the car, and a
 * refusal discovered at the counter with a customer standing there is a refusal discovered too late.
 *
 * Each row keeps its FROZEN context: the customer who was driving at the time, not whoever has the
 * car now. That distinction is the whole point of the accident snapshot and it must survive being
 * rendered on a page that is otherwise entirely about the car's present state.
 */

const STAGE_TONE = {
  reported: 'red', awaiting_police: 'amber', assessment: 'orange', liability: 'violet',
  insurance: 'blue', repair: 'indigo', settlement: 'cyan', closed: 'gray',
};

export default function VehicleAccidentsPanel({ vehicleId }) {
  const { t } = useI18n();

  const fetcher = useCallback(
    () => api.get(`/accidents/vehicle/${vehicleId}`).then((r) => r.data.data),
    [vehicleId],
  );
  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId]);

  // TableSkeleton is a bare <tbody> — valid only inside a <table>. Wrapped rather than dropped into
  // the surrounding div, which React rejects as invalid nesting.
  if (loading) return <table className="w-full"><TableSkeleton cols={5} rows={3} /></table>;
  if (error) return <ErrorState message={error} onRetry={reload} />;

  const cases = data?.cases || [];

  return (
    <div className="space-y-4">
      {data?.restricted && (
        <div className="rounded-xl border-2 border-red-400 bg-red-50 p-4 text-red-900">
          <p className="flex items-center gap-2 text-sm font-bold">
            <span aria-hidden>🚫</span>{t('This vehicle is held out of the rental pool')}
          </p>
          <p className="mt-1 text-xs leading-relaxed">
            {t('An accident case on this car is still unresolved, so a new rental or booking will be refused. Resolving the case releases it — nothing here needs overriding.')}
          </p>
        </div>
      )}

      <Card>
        {cases.length === 0 ? (
          <EmptyState title={t('No accidents recorded')} message={t('This car has no accident case on file.')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  {['Reference', 'When', 'Who had it then', 'Stage', 'Police', 'Liability', 'Damage', 'Repairs'].map((h) => (
                    <th key={h} className="px-3 py-2 text-start font-semibold">{t(h)}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {cases.map((c) => (
                  <tr key={c.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2">
                      <Link to={`/accidents/${c.id}`} className="font-semibold text-rose-700 hover:underline">{c.reference}</Link>
                    </td>
                    <td className="px-3 py-2 text-slate-600">{fmtDate(c.occurred_at)}</td>
                    <td className="px-3 py-2">
                      {/* The FROZEN name, not whoever holds the car today. */}
                      {c.context?.was_with_customer
                        ? <span className="font-medium text-amber-700">{c.context.customer?.name || t('A customer')}</span>
                        : <span className="capitalize text-slate-500">{t(String(c.context?.responsible_party_type || '').replace(/_/g, ' '))}</span>}
                    </td>
                    <td className="px-3 py-2"><Badge tone={STAGE_TONE[c.stage] || 'gray'}>{t(String(c.stage).replace(/_/g, ' '))}</Badge></td>
                    <td className="px-3 py-2 text-xs capitalize text-slate-600">{t(String(c.police?.status || '').replace(/_/g, ' '))}</td>
                    <td className="px-3 py-2 text-xs capitalize text-slate-600">{t(String(c.liability?.status || '').replace(/_/g, ' '))}</td>
                    <td className="px-3 py-2 text-xs text-slate-600">{num(c.damage_items_count ?? 0)}</td>
                    <td className="px-3 py-2 text-xs text-slate-600">{num(c.repairs_count ?? 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
