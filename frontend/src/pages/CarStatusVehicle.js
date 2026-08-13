import { useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { SectionCard } from '../components/ui/Table';
import { Skeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import TicketWorkflowPanel from '../components/workflow/TicketWorkflowPanel';
import { openVehicleReport } from '../lib/vehicleReport';
import { useI18n } from '../i18n/I18nContext';

// The per-vehicle Car Status page — deliberately just the live Maintenance Workflow for the car's current
// ticket. Nothing else is rendered here (no tabs / KPIs / analytics): the page IS the workflow. The full
// maintenance intelligence (faults, journey, costs, documents, activity…) is available on demand via the
// History Report button, which builds it from the same /car-status/vehicle/{id} payload.

export default function CarStatusVehicle() {
  const { vehicleId } = useParams();
  const navigate = useNavigate();
  const { t } = useI18n();

  const fetcher = useCallback(async () => (await api.get(`/car-status/vehicle/${vehicleId}`)).data.data, [vehicleId]);
  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId]);

  const v = data?.vehicle;
  const lw = data?.live_workflow;

  if (error && !data) {
    return (
      <div className="py-8"><div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-12 text-center text-sm text-red-600">{error}</div>
      </div></div>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Top bar — back to the dashboard + the on-demand full-history document. */}
        <div className="flex items-center justify-between gap-3">
          <button onClick={() => navigate('/car-status')} className="inline-flex items-center gap-1 text-xs font-semibold text-slate-400 hover:text-slate-600">
            <Icon.ArrowRight className="h-3.5 w-3.5 rotate-180 rtl:-scale-x-100" /> {t('Car Status')}
          </button>
          {data && (
            <button onClick={() => openVehicleReport(data)}
              className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-600 shadow-soft hover:bg-slate-50">
              <Icon.Download className="h-3.5 w-3.5" /> {t('History Report')}
            </button>
          )}
        </div>

        {loading && !data ? (
          <Skeleton className="h-96 rounded-2xl" />
        ) : lw ? (
          // The existing Maintenance Workflow, shown natively (full-bleed so it renders exactly as it does
          // on its own /maintenance-workflow/:id page). This IS the page.
          <div className="-mx-4 sm:-mx-6 lg:-mx-8">
            <TicketWorkflowPanel ticketId={lw.ticket_id} hideBack onChanged={() => reload({ silent: true })} />
          </div>
        ) : (
          <SectionCard title={v?.car || t('Vehicle #{id}', { id: vehicleId })} subtitle={v?.plate_no || ''}>
            <div className="flex flex-col items-center gap-2 px-5 py-12 text-center">
              <Icon.Check className="h-8 w-8 text-emerald-500" />
              <p className="text-sm font-medium text-slate-600">{t('Not currently in maintenance')}</p>
              <p className="text-xs text-slate-400">{t('Use “History Report” above for this vehicle’s full maintenance history.')}</p>
            </div>
          </SectionCard>
        )}
      </div>
    </div>
  );
}
