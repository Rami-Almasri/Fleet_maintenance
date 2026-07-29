// The vehicle profile's maintenance audit trail: a HEALTH STATUS banner (+ last odometer photo),
// the car's MAINTENANCE WORKFLOW tickets/diagnostics (lifecycle stage, reason, dispatch vs return
// odometer), and a CONDITION TIMELINE of pre/post photos. One call to
// GET /maintenance-tickets/vehicle/{id} feeds all three. Hidden entirely if the user can't view
// maintenance, so the profile degrades cleanly.

import { Fragment, useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { usePermissions } from '../../hooks/usePermissions';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { SectionCard } from '../ui/Table';
import { Skeleton } from '../ui/Skeleton';
import FindingsList from '../workflow/FindingsList';
import { fmtDate, num, aed2 } from '../../lib/format';

// workflow_status → chip label + colour (the "lifecycle stage with colour coding").
const STAGE = {
  inspection_diagnostic:  { label: 'Diagnostic',       tone: 'violet' },
  inspection_pending:     { label: 'Needs Dispatch',   tone: 'indigo' },
  awaiting_dispatch:      { label: 'Ready for Pickup', tone: 'indigo' },
  in_transit:             { label: 'En Route to Garage', tone: 'blue' },
  under_repair:           { label: 'In Workshop',       tone: 'amber' },
  ready_for_reinspection: { label: 'Ready',             tone: 'cyan' },
  closed:                 { label: 'Closed',            tone: 'green' },
  diagnostic_cleared:     { label: 'No Maintenance',    tone: 'slate' },
};
const REASON = { test_drive: 'Test Drive', customer_reported: 'Complaint', periodic: 'Routine', driver_reported: 'Driver reported' };

const odo = (n) => (n ? `${num(n)} km` : '—');

// The backend origin behind the API client (e.g. http://127.0.0.1:8000), so local `public`-disk
// photo URLs resolve to the dev backend regardless of APP_URL. S3 signed URLs (different host,
// carrying a signature) are left exactly as-is.
const API_ORIGIN = (api.defaults.baseURL || '').replace(/\/api\/?$/, '');
function photoSrc(url) {
  if (!url) return null;
  try {
    const u = new URL(url, API_ORIGIN || window.location.origin);
    if (API_ORIGIN && u.pathname.startsWith('/storage/')) return `${API_ORIGIN}${u.pathname}`;
    return u.href;
  } catch {
    return url;
  }
}

// Which blocks to render. Lets the tabbed Vehicle Profile put Health/Findings/Workflow on the
// Maintenance tab and the Condition Timeline photos on the Media tab, from the same component.
const ALL_SECTIONS = ['health', 'findings', 'workflow', 'photos'];

export default function VehicleWorkflowPanel({ vehicleId, sections = ALL_SECTIONS }) {
  const { can } = usePermissions();
  const allowed = can('maintenance.view');
  const show = (s) => sections.includes(s);

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [lightbox, setLightbox] = useState(null);

  const load = useCallback(() => {
    if (!allowed || !vehicleId) { setLoading(false); return; }
    setLoading(true);
    api.get(`/maintenance-tickets/vehicle/${vehicleId}`)
      .then((r) => setData(r.data.data))
      .catch((e) => setErr(e.response?.data?.message || 'Could not load the maintenance workflow.'))
      .finally(() => setLoading(false));
  }, [allowed, vehicleId]);

  useEffect(() => { load(); }, [load]);

  if (!allowed) return null;

  const health = data?.health;
  const tickets = data?.tickets || [];
  const photos = data?.photos || [];
  const lastOdo = data?.last_odometer_photo;

  // Consolidated accountability trail: every finding across all of this car's tickets, so the
  // Inspector-vs-Garage split shows at a glance (the same grouping the board card & ticket modal
  // use, just rolled up to the whole car). FindingsList does the grouping.
  const allFindings = tickets.flatMap((t) => t.findings || []);

  return (
    <div className="space-y-4">
      {/* HEALTH STATUS — seen immediately, with the last odometer photo */}
      {show('health') && (
      <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">
        <div className="flex items-center gap-4 p-4">
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Health Status</p>
            {loading ? (
              <Skeleton className="mt-2 h-7 w-44" />
            ) : (
              <div className="mt-1.5 flex items-center gap-2">
                <Badge tone={health?.tone || 'slate'}>{health?.label || 'Unknown'}</Badge>
              </div>
            )}
            {!loading && health?.detail && <p className="mt-1.5 text-sm text-slate-500">{health.detail}</p>}
          </div>

          <div className="shrink-0 text-right">
            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-400">Last odometer</p>
            {loading ? (
              <Skeleton className="h-20 w-28 rounded-lg" />
            ) : lastOdo?.url ? (
              <button type="button" onClick={() => setLightbox(lastOdo)} className="block">
                <img src={photoSrc(lastOdo.url)} alt="Last odometer" className="h-20 w-28 rounded-lg object-cover ring-1 ring-slate-200 transition hover:ring-indigo-400" />
              </button>
            ) : (
              <div className="flex h-20 w-28 items-center justify-center rounded-lg border border-dashed border-slate-200 text-slate-300">
                <Icon.Gauge className="h-6 w-6" />
              </div>
            )}
          </div>
        </div>
      </div>
      )}

      {err && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{err}</div>}

      {/* FINDINGS — consolidated Inspector vs Garage trail for the whole car */}
      {show('findings') && !loading && allFindings.length > 0 && (
        <SectionCard title="Findings" subtitle="Every issue recorded for this car, grouped by who identified it.">
          <div className="px-4 py-4">
            <FindingsList findings={allFindings} />
          </div>
        </SectionCard>
      )}

      {/* MAINTENANCE WORKFLOW — tickets & diagnostics */}
      {show('workflow') && (
      <SectionCard title="Maintenance Workflow" subtitle="Tickets & diagnostics for this car — newest first.">
        {loading ? (
          <div className="space-y-2 p-3"><Skeleton className="h-10" /><Skeleton className="h-10" /></div>
        ) : tickets.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-slate-400">No workflow tickets yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2">Stage</th>
                  <th className="px-3 py-2">Reason</th>
                  <th className="px-3 py-2">Dispatch odo</th>
                  <th className="px-3 py-2">Return odo</th>
                  <th className="px-3 py-2">Garage</th>
                  <th className="px-3 py-2 text-right">Cost</th>
                  <th className="px-3 py-2">Opened</th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((t) => {
                  const st = STAGE[t.workflow_status] || { label: t.status_label || t.workflow_status, tone: 'slate' };
                  const hasFindings = t.findings?.length > 0;
                  return (
                    <Fragment key={t.id}>
                      <tr className={`hover:bg-slate-50/60 ${hasFindings ? '' : 'border-b border-slate-50 last:border-0'}`}>
                        <td className="px-3 py-2"><Badge tone={st.tone}>{st.label}</Badge></td>
                        <td className="px-3 py-2 text-slate-600">{REASON[t.trigger_reason] || t.trigger_reason || '—'}</td>
                        <td className="px-3 py-2 tabular-nums text-slate-600">{odo(t.dispatch_odometer)}</td>
                        <td className="px-3 py-2 tabular-nums text-slate-600">{odo(t.return_odometer)}</td>
                        <td className="px-3 py-2 text-slate-500">{t.garage || '—'}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-slate-600">{t.cost != null ? aed2(t.cost) : '—'}</td>
                        <td className="px-3 py-2 text-slate-400">{t.created_at ? fmtDate(t.created_at) : '—'}</td>
                      </tr>
                      {hasFindings && (
                        <tr className="border-b border-slate-50 last:border-0">
                          <td colSpan={7} className="px-3 pb-3 pt-0">
                            <FindingsList findings={t.findings} tasks={t.tasks} compact />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </SectionCard>
      )}

      {/* CONDITION TIMELINE — pre/post photos in a horizontal scroll */}
      {show('photos') && (
      <SectionCard title="Condition Timeline" subtitle="Pre/post odometer & inspection photos — most recent first.">
        {loading ? (
          <div className="flex gap-3 p-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-32 w-44 shrink-0 rounded-xl" />)}</div>
        ) : photos.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-slate-400">No condition photos captured yet.</p>
        ) : (
          <div className="flex gap-3 overflow-x-auto p-1 pb-3">
            {photos.map((p) => (
              <button key={p.id} type="button" onClick={() => p.url && setLightbox(p)} className="group shrink-0 text-left">
                <div className="relative h-32 w-44 overflow-hidden rounded-xl ring-1 ring-slate-200">
                  {p.url ? (
                    <img src={photoSrc(p.url)} alt={p.body_part} className="h-full w-full object-cover transition group-hover:scale-105" />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center bg-slate-50 text-slate-300"><Icon.Gauge className="h-7 w-7" /></div>
                  )}
                  <span className="absolute left-1.5 top-1.5">
                    <Badge tone={p.phase === 'post' ? 'cyan' : 'indigo'}>{p.phase === 'post' ? 'After' : 'Before'}</Badge>
                  </span>
                </div>
                <p className="mt-1 truncate text-xs font-medium capitalize text-slate-600">{(p.body_part || 'photo').replace(/_/g, ' ')}</p>
                <p className="text-[11px] text-slate-400">{p.captured_at ? fmtDate(p.captured_at) : ''}</p>
              </button>
            ))}
          </div>
        )}
      </SectionCard>
      )}

      {/* full-size viewer */}
      {lightbox?.url && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6" onClick={() => setLightbox(null)}>
          <img src={photoSrc(lightbox.url)} alt="" className="max-h-[85vh] max-w-[90vw] rounded-xl object-contain shadow-2xl" />
        </div>
      )}
    </div>
  );
}
