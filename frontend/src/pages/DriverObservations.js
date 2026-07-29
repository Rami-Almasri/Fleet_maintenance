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

const STATUS_META = {
  open:                  { label: 'Open', tone: 'amber' },
  inspection_requested:  { label: 'Inspection requested', tone: 'violet' },
  dismissed:             { label: 'Dismissed', tone: 'slate' },
};

function ago(iso) {
  if (!iso) return '—';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.round(hrs / 24)}d ago`;
}

export default function DriverObservations() {
  const { can } = usePermissions();
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
      toast.success('Inspection requested');
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not request inspection');
    } finally {
      setBusyId(null);
    }
  };

  const dismiss = async (row) => {
    if (busyId) return;
    setBusyId(row.id);
    try {
      await api.post(`/driver-observations/${row.id}/dismiss`);
      toast.success('Dismissed');
      reload({ silent: true });
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not dismiss');
    } finally {
      setBusyId(null);
    }
  };

  const columns = [
    {
      key: 'vehicle',
      header: 'Vehicle',
      render: (r) => (
        <Link to={`/car-status/${r.vehicle_id}`} className="font-mono text-sm font-bold text-slate-800 hover:text-indigo-600">
          {r.plate || `#${r.vehicle_id}`}
        </Link>
      ),
    },
    { key: 'note', header: 'Observation', render: (r) => <p className="max-w-md truncate text-sm text-slate-700" title={r.note}>{r.note}</p> },
    { key: 'driver', header: 'By', render: (r) => <span className="text-sm text-slate-500">{r.driver || '—'}</span> },
    { key: 'created_at', header: 'When', render: (r) => <span className="whitespace-nowrap text-xs text-slate-500">{ago(r.created_at)}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (r) => {
        const m = STATUS_META[r.status] || { label: r.status, tone: 'slate' };
        return (
          <div className="flex items-center gap-2">
            <Badge tone={m.tone}>{m.label}</Badge>
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
            <Icon.Search className="h-3.5 w-3.5" /> Request inspection
          </Button>
          {can('maintenance.manage') && (
            <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); dismiss(r); }} disabled={busyId === r.id}>Dismiss</Button>
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
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>Handover</div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>Driver Observations</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>Internal notes from drivers when a car comes back. Not a customer complaint — escalate to an inspection only if it needs a look.</p>
          </div>
          {can('maintenance.logistics') && (
            <Button onClick={() => setModal(true)}><Icon.Plus className="h-4 w-4" /> Log observation</Button>
          )}
        </div>

        {error && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</div>}

        {/* Analytics — the whole observation log, before the table. */}
        {!loading && rows.length > 0 && <DriverObservationsAnalytics rows={rows} />}

        <SectionCard title="Observations" subtitle={`${rows.length} logged`}>
          <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} loading={loading} empty="No observations logged yet." />
        </SectionCard>
      </div>

      {modal && (
        <DriverObservationModal
          vehicles={vehicles}
          onClose={() => setModal(false)}
          onDone={(msg) => { setModal(false); toast.success(msg || 'Observation logged'); reload({ silent: true }); }}
        />
      )}
    </div>
  );
}
