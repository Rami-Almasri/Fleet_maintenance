// Vehicle Profile — the "Maintenance Progress" tab. Shows a car's full checkpoint timeline (across every
// ticket) plus the live monitoring state of its currently-open workshop ticket, with a Submit button that
// opens the Checkpoint modal for the active ticket. Data: GET /maintenance-tickets/vehicle/{id}/checkpoints.

import { useEffect, useState, useCallback } from 'react';
import { SectionCard } from '../ui/Table';
import { Skeleton } from '../ui/Skeleton';
import Button from '../ui/Button';
import CheckpointTimeline from '../maintenance/CheckpointTimeline';
import CheckpointModal from '../maintenance/CheckpointModal';
import { getVehicleCheckpoints } from '../../lib/maintenanceCheckpoints';
import { fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

function MonitorBanner({ monitor }) {
  const { t } = useI18n();
  if (!monitor) return null;
  const { expected_on, is_estimated, eta_status, days_left, days_over, overdue, needs_update } = monitor;
  const tone = overdue ? 'bg-red-50 text-red-700 ring-red-200'
    : needs_update ? 'bg-amber-50 text-amber-700 ring-amber-200'
    : 'bg-slate-50 text-slate-600 ring-slate-200';
  const label = !expected_on ? t('In workshop')
    : overdue ? t('{n} day(s) overdue', { n: days_over })
    : eta_status === 'due_today' ? t('Due today')
    : t('{n} day(s) left', { n: days_left });
  return (
    <div className={`flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-3 py-2 text-sm ring-1 ${tone}`}>
      <span className="font-semibold">{label}</span>
      {expected_on && (
        <span className="text-xs opacity-80">
          {is_estimated
            ? t('Expected {date} (estimated)', { date: fmtDate(expected_on) })
            : t('Expected {date}', { date: fmtDate(expected_on) })}
        </span>
      )}
      {needs_update && <span className="text-xs font-medium">{t('· Checkpoint required')}</span>}
    </div>
  );
}

export default function VehicleCheckpointsPanel({ vehicleId }) {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [toast, setToast] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    getVehicleCheckpoints(vehicleId)
      .then(setData)
      .catch(() => setData({ checkpoints: [], monitor: null, active_ticket_id: null }))
      .finally(() => setLoading(false));
  }, [vehicleId]);

  useEffect(() => { load(); }, [load]);

  const activeTicketId = data?.active_ticket_id;

  return (
    <SectionCard
      title={t('Maintenance Progress')}
      subtitle={t('Workshop checkpoints — dated progress updates with evidence')}
      actions={activeTicketId
        ? <Button size="sm" onClick={() => setOpen(true)}>{t('Submit checkpoint')}</Button>
        : null}
    >
      {loading ? (
        <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-16 rounded-xl" />)}</div>
      ) : (
        <div className="space-y-4">
          {activeTicketId
            ? <MonitorBanner monitor={data?.monitor} />
            : <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200">{t('This car is not currently in the workshop.')}</p>}

          {toast && <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">{toast}</p>}

          <CheckpointTimeline checkpoints={data?.checkpoints || []} />
        </div>
      )}

      {open && activeTicketId && (
        <CheckpointModal
          open={open}
          ticketId={activeTicketId}
          title={t('Maintenance Checkpoint')}
          onClose={() => setOpen(false)}
          onDone={(msg) => { setToast(msg); load(); setTimeout(() => setToast(''), 3000); }}
        />
      )}
    </SectionCard>
  );
}
