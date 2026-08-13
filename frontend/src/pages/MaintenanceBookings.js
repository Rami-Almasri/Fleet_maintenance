import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import DataTable, { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { PageHeader } from '../components/ui/Misc';
import { fmtDate } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

// /maintenance-bookings — every car that is BOTH in the workshop AND has an upcoming booking
// (type-R reservation). The at-a-glance "a customer is expecting this car soon, but it's still in
// the shop" board. Data: GET /Maintenance/booking-conflicts (MaintenanceController::bookingConflicts),
// which reuses the canonical OperationsService in-maintenance set — so it never disagrees with the
// maintenance board, the dashboard or the "Booked car in maintenance" bell alert.

// Small day-countdown chip: red when the pickup is ≤2 days out (the danger window), amber ≤5, else slate.
function DaysChip({ days }) {
  const { t } = useI18n();
  const tone =
    days <= 2 ? 'bg-red-50 text-red-700 ring-red-200'
      : days <= 5 ? 'bg-amber-50 text-amber-700 ring-amber-200'
        : 'bg-slate-50 text-slate-600 ring-slate-200';
  const label = days <= 0 ? t('today') : t('{n}d', { n: days });
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${tone}`}>{label}</span>;
}

export default function MaintenanceBookings() {
  const { t } = useI18n();
  const fetcher = useCallback(() => api.get('/Maintenance/booking-conflicts').then((r) => r.data?.data), []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const cars = data?.cars || [];
  const summary = data?.summary || { total: 0, at_risk: 0 };

  const columns = [
    {
      key: 'car',
      header: t('Vehicle'),
      render: (r) => (
        <Link to={`/vehicles/${r.vehicle_id}`} className="group/car block">
          <span className="font-semibold text-slate-800 group-hover/car:text-indigo-600">{r.plate || `#${r.vehicle_id}`}</span>
          {r.car && <span className="block text-xs text-slate-400">{r.car}</span>}
        </Link>
      ),
    },
    {
      key: 'customer',
      header: t('Booking'),
      render: (r) => (
        <div>
          <span className="font-medium text-slate-700">{r.customer || '—'}</span>
          {r.booking_contract_no && <span className="block text-xs text-slate-400">{r.booking_contract_no}</span>}
        </div>
      ),
    },
    {
      key: 'booking_start',
      header: t('Pickup'),
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className="tabular-nums text-slate-700">{fmtDate(r.booking_start)}</span>
          <DaysChip days={r.days_left} />
          {r.upcoming_count > 1 && <span className="text-xs text-slate-400">{t('+{n} more', { n: r.upcoming_count - 1 })}</span>}
        </div>
      ),
    },
    {
      key: 'in_maintenance_since',
      header: t('In shop'),
      render: (r) => (
        <div>
          <span className="tabular-nums text-slate-600">{r.in_maintenance_since ? t('since {date}', { date: fmtDate(r.in_maintenance_since) }) : '—'}</span>
          {r.workflow_stage && <span className="block text-xs capitalize text-slate-400">{r.workflow_stage}</span>}
          {r.garage && <span className="block text-xs text-slate-400">{r.garage}</span>}
        </div>
      ),
    },
    {
      key: 'expected_return_date',
      header: t('Expected back'),
      render: (r) => (
        <span className={`tabular-nums ${r.expected_return_date ? 'text-slate-600' : 'text-slate-400'}`}>
          {r.expected_return_date ? fmtDate(r.expected_return_date) : t('unknown')}
        </span>
      ),
    },
    {
      key: 'at_risk',
      header: t('Status'),
      render: (r) =>
        r.at_risk ? (
          <div>
            <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">
              ⚠ {t('At risk')}
            </span>
            <ul className="mt-1 space-y-0.5">
              {r.risk_reasons.map((reason) => (
                <li key={reason} className="text-xs text-slate-500">· {reason}</li>
              ))}
            </ul>
          </div>
        ) : (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
            ✓ {t('On track')}
          </span>
        ),
    },
  ];

  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
      <PageHeader
        title={t('Booked cars in maintenance')}
        subtitle={t('Cars currently in the workshop that a customer has already booked — chase the garage or swap the car before the pickup slips.')}
      />

      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error} · <button type="button" onClick={() => reload()} className="font-semibold underline">{t('retry')}</button>
        </div>
      )}

      <MetricGrid cols={2}>
        <MetricCard label={t('Booked & in shop')} value={summary.total} tone="indigo" hint={t('Cars in maintenance with an upcoming booking')} />
        <MetricCard label={t('At risk')} value={summary.at_risk} tone={summary.at_risk > 0 ? 'red' : 'emerald'} hint={t('Pickup may slip — no/late return date or ≤2 days out')} />
      </MetricGrid>

      <SectionCard
        title={t('Conflicts')}
        subtitle={
          loading
            ? t('Loading…')
            : summary.total === 1
              ? t('1 car booked while in the workshop')
              : t('{n} cars booked while in the workshop', { n: summary.total })
        }
      >
        <DataTable
          columns={columns}
          rows={cars}
          rowKey={(r) => r.vehicle_id}
          loading={loading}
          highlightRow={(r) => r.at_risk}
          empty={t('No booked cars are currently in maintenance. 🎉')}
        />
      </SectionCard>
    </div>
  );
}
