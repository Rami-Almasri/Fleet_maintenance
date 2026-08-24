// Driver Observations — the lightweight log of what drivers noticed when cars came back. Deliberately
// spare next to the Complaints Center: no customer, no lifecycle stages, no analytics. Each observation is
// a note that can be escalated to an inspection request (→ the review queue) or dismissed. See
// DriverObservationController and [[driver-observation-entity]].

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import DriverObservationModal from '../components/workflow/DriverObservationModal';
import DriverObservationsAnalytics from '../components/analytics/DriverObservationsAnalytics';
import { useI18n } from '../i18n/I18nContext';

const STATUS_TONE = { open: 'amber', inspection_requested: 'violet', dismissed: 'slate' };

// Relative time, resolved through the shared `time.*` labels rather than a
// second English-only formatter.
function agoWith(t) {
  return (iso) => {
    if (!iso) return '—';
    const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (secs < 90) return t('time.justNow');
    const mins = Math.round(secs / 60);
    if (mins < 60) return t('time.minutesAgo', { n: mins });
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return t('time.hoursAgo', { n: hrs });
    return t('time.daysAgo', { n: Math.round(hrs / 24) });
  };
}

export default function DriverObservations() {
  const { can } = usePermissions();
  // `tf` gives an unknown status code a readable fallback instead of a raw key.
  const { t, tf } = useI18n();
  const ago = agoWith(t);
  const toast = useToast();
  const [modal, setModal] = useState(false);
  const [vehicles, setVehicles] = useState([]);
  const [busyId, setBusyId] = useState(null);

  const fetcher = useCallback(async () => (await api.get('/driver-observations')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 15000, paused: () => modal });
  const rows = data?.rows || [];

  useEffect(() => {
    if (!can('maintenance.logistics')) return undefined;
    let alive = true;
    api.get('/Vehicle')
      .then((v) => {
        if (!alive) return;
        const list = v.data?.data;
        const all = Array.isArray(list) ? list : list?.items || [];
        setVehicles(all.filter((veh) => ['ready', 'rented'].includes(veh.status)));
      })
      .catch(() => {});
    return () => { alive = false; };
  }, [can]);

  const escalate = async (row) => {
    if (busyId) return;
    setBusyId(row.id);
    try {
      await api.post(`/driver-observations/${row.id}/request-inspection`);
      toast.success(t('driverObs.inspectionRequested'));
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('driverObs.inspectionFailed'));
    } finally {
      setBusyId(null);
    }
  };

  const dismiss = async (row) => {
    if (busyId) return;
    setBusyId(row.id);
    try {
      await api.post(`/driver-observations/${row.id}/dismiss`);
      toast.success(t('driverObs.dismissed'));
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || t('driverObs.dismissFailed'));
    } finally {
      setBusyId(null);
    }
  };

  const columns = [
    {
      key: 'vehicle',
      header: t('driverObs.col.vehicle'),
      render: (r) => (
        <Link to={`/vehicles/${r.vehicle_id}`} className="font-mono text-sm font-bold text-slate-800 hover:text-indigo-600">
          {r.plate || `#${r.vehicle_id}`}
        </Link>
      ),
    },
    { key: 'note', header: t('driverObs.col.note'), render: (r) => <p className="max-w-md truncate text-sm text-slate-700" title={r.note}>{r.note}</p> },
    { key: 'driver', header: t('driverObs.col.by'), render: (r) => <span className="text-sm text-slate-500">{r.driver || '—'}</span> },
    { key: 'created_at', header: t('driverObs.col.when'), render: (r) => <span className="whitespace-nowrap text-xs text-slate-500">{ago(r.created_at)}</span> },
    {
      key: 'status',
      header: t('driverObs.col.status'),
      render: (r) => {
        const tone = STATUS_TONE[r.status] || 'slate';
        return (
          <div className="flex items-center gap-2">
            <Badge tone={tone}>{tf(`driverObs.status.${r.status}`, r.status)}</Badge>
            {r.inspection_request_id && (
              <Link to={`/inspection-review?ticket=${r.inspection_request_id}`} className="text-xs text-indigo-600 hover:underline">#{r.inspection_request_id}</Link>
            )}
          </div>
        );
      },
    },
    {
      key: 'actions',
      header: '',
      render: (r) => (r.status === 'open' && can('maintenance.logistics')) ? (
        <div className="flex justify-end gap-1.5">
          <Button variant="secondary" size="sm" onClick={(e) => { e.stopPropagation(); escalate(r); }} loading={busyId === r.id}>
            <Icon.Search className="h-3.5 w-3.5" /> {t('driverObs.requestInspection')}
          </Button>
          {can('maintenance.manage') && (
            <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); dismiss(r); }} disabled={busyId === r.id}>{t('driverObs.dismiss')}</Button>
          )}
        </div>
      ) : null,
    },
  ];

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>{t('driverObs.eyebrow')}</div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>{t('driverObs.title')}</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>{t('driverObs.subtitle')}</p>
          </div>
          {can('maintenance.logistics') && (
            <Button onClick={() => setModal(true)}><Icon.Plus className="h-4 w-4" /> {t('driverObs.log')}</Button>
          )}
        </div>

        {error && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</div>}

        {/* Analytics — the whole observation log, before the table. */}
        {!loading && rows.length > 0 && <DriverObservationsAnalytics rows={rows} />}

        <SectionCard title={t('driverObs.tableTitle')} subtitle={t('driverObs.logged', { n: rows.length })}>
          <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} loading={loading} empty={t('driverObs.empty')} />
        </SectionCard>
      </div>

      {modal && (
        <DriverObservationModal
          vehicles={vehicles}
          onClose={() => setModal(false)}
          onDone={(msg) => { setModal(false); toast.success(msg || t('driverObs.loggedToast')); reload({ silent: true }); }}
        />
      )}
    </div>
  );
}
