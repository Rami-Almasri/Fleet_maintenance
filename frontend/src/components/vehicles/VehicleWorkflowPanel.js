// The vehicle profile's maintenance audit trail: a HEALTH STATUS banner (+ last odometer photo),
// the car's MAINTENANCE WORKFLOW tickets/diagnostics (lifecycle stage, reason, dispatch vs return
// odometer), and a CONDITION TIMELINE of pre/post photos. One call to
// GET /maintenance-tickets/vehicle/{id} feeds all three. Hidden entirely if the user can't view
// maintenance, so the profile degrades cleanly.

import { Fragment, useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { SectionCard } from '../ui/Table';
import { Skeleton } from '../ui/Skeleton';
import FindingsList from '../workflow/FindingsList';
import { fmtDate, num, aed2 } from '../../lib/format';
import storageSrc from '../../lib/storageUrl';

// workflow_status → chip label + colour (the "lifecycle stage with colour coding").
// `t` is threaded in because these live outside the component body.
const stageMap = (t) => ({
  inspection_diagnostic:  { label: t('Diagnostic'),         tone: 'violet' },
  inspection_pending:     { label: t('Needs Dispatch'),     tone: 'indigo' },
  awaiting_dispatch:      { label: t('Ready for Pickup'),   tone: 'indigo' },
  in_transit:             { label: t('En Route to Garage'), tone: 'blue' },
  under_repair:           { label: t('In Workshop'),        tone: 'amber' },
  ready_for_reinspection: { label: t('Ready'),              tone: 'cyan' },
  closed:                 { label: t('Closed'),             tone: 'green' },
  diagnostic_cleared:     { label: t('No Maintenance'),     tone: 'slate' },
});
const reasonMap = (t) => ({
  test_drive: t('Test Drive'),
  customer_reported: t('Complaint'),
  periodic: t('Routine'),
  driver_reported: t('Driver reported'),
});

const odo = (n) => (n ? `${num(n)} km` : '—');

// Garage-behaviour attention level → Badge tone. Mirrors App\Support\GarageSeverity's ladder; the
// grade itself is decided server-side (config/garage_intelligence.php holds the thresholds) so this
// map only ever colours a verdict, it never forms one.
const GARAGE_TONE = { normal: 'green', warning: 'amber', high: 'orange', critical: 'red' };

// Resolving a stored photo's URL onto the API origin lives in lib/storageUrl — the Mulkiya card
// needs the same rule, and a subtly-different copy of it would eventually drift.
const photoSrc = storageSrc;

// Which blocks to render. Lets the tabbed Vehicle Profile put Health/Findings/Workflow on the
// Maintenance tab and the Condition Timeline photos on the Media tab, from the same component.
const ALL_SECTIONS = ['health', 'findings', 'workflow', 'photos'];

export default function VehicleWorkflowPanel({ vehicleId, sections = ALL_SECTIONS }) {
  const { t, tf } = useI18n();
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
      .catch((e) => setErr(e.response?.data?.message || t('Could not load the maintenance workflow.')))
      .finally(() => setLoading(false));
  }, [allowed, vehicleId, t]);

  useEffect(() => { load(); }, [load]);

  if (!allowed) return null;

  const health = data?.health;
  const garage = data?.garage_intelligence;   // null when the feature is off or the car is unevaluated
  const tickets = data?.tickets || [];
  const photos = data?.photos || [];
  const lastOdo = data?.last_odometer_photo;

  // Consolidated accountability trail: every finding across all of this car's tickets, so the
  // Inspector-vs-Garage split shows at a glance (the same grouping the board card & ticket modal
  // use, just rolled up to the whole car). FindingsList does the grouping.
  const allFindings = tickets.flatMap((ticket) => ticket.findings || []);
  const STAGE = stageMap(t);
  const REASON = reasonMap(t);

  return (
    <div className="space-y-4">
      {/* HEALTH STATUS — seen immediately, with the last odometer photo */}
      {show('health') && (
      <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">
        <div className="flex items-center gap-4 p-4">
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Health Status')}</p>
            {loading ? (
              <Skeleton className="mt-2 h-7 w-44" />
            ) : (
              <div className="mt-1.5 flex items-center gap-2">
                <Badge tone={health?.tone || 'slate'}>{health?.label || t('Unknown')}</Badge>
              </div>
            )}
            {!loading && health?.detail && <p className="mt-1.5 text-sm text-slate-500">{health.detail}</p>}
          </div>

          <div className="shrink-0 text-end">
            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-400">{t('Last odometer')}</p>
            {loading ? (
              <Skeleton className="h-20 w-28 rounded-lg" />
            ) : lastOdo?.url ? (
              <button type="button" onClick={() => setLightbox(lastOdo)} className="block">
                <img src={photoSrc(lastOdo.url)} alt={t('Last odometer')} className="h-20 w-28 rounded-lg object-cover ring-1 ring-slate-200 transition hover:ring-indigo-400" />
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

      {/* GARAGE BEHAVIOUR — how OFTEN this car goes in, and how LONG it stays. Sits directly under
          Health because the two answer different questions about the same car: Health is where it is
          right now, this is the pattern it has been showing. Both figures, their attention levels and
          the sentences under "Why" are exactly what the admin alert is raised from — one reading,
          rendered here and delivered to the bell, so the page and the notification cannot disagree. */}
      {show('health') && !loading && garage && (
      <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
              {tf('vehicleProfile.garage.title', 'Garage Behaviour')}
            </p>
            <p className="mt-0.5 text-xs text-slate-400">
              {tf('vehicleProfile.garage.subtitle', 'Last {days} days', { days: garage.window_days })}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {garage.currently_in_garage && (
              <Badge tone="blue">{tf('vehicleProfile.garage.inGarageNow', 'In a garage now')}</Badge>
            )}
            <Badge tone={GARAGE_TONE[garage.severity] || 'slate'}>
              {tf(`vehicleProfile.garage.level.${garage.severity}`, garage.severity)}
            </Badge>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-px bg-slate-100 sm:grid-cols-3">
          {[
            {
              k: 'visits',
              label: tf('vehicleProfile.garage.visits', 'Garage visits'),
              value: num(garage.visits),
              level: garage.visit_severity,
            },
            {
              k: 'downtime',
              label: tf('vehicleProfile.garage.downtime', 'Garage downtime'),
              value: tf('vehicleProfile.garage.days', '{n} days', { n: garage.downtime_days }),
              level: garage.downtime_severity,
            },
            {
              k: 'share',
              label: tf('vehicleProfile.garage.share', 'Share of the period'),
              value: `${garage.downtime_pct ?? 0}%`,
              // The percentage is the downtime figure expressed differently, not a third signal —
              // giving it its own chip would imply a grade nothing actually assigns.
              level: null,
            },
          ].map((tile) => (
            <div key={tile.k} className="bg-white px-4 py-3">
              <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{tile.label}</p>
              <div className="mt-1 flex items-baseline gap-2">
                <span className="text-xl font-semibold text-slate-800">{tile.value}</span>
                {tile.level && tile.level !== 'normal' && (
                  <Badge tone={GARAGE_TONE[tile.level] || 'slate'}>
                    {tf(`vehicleProfile.garage.level.${tile.level}`, tile.level)}
                  </Badge>
                )}
              </div>
            </div>
          ))}
        </div>

        {/* WHY — the same plain sentences the notification carries. A car with nothing to report says
            so, rather than showing an empty heading. */}
        <div className="px-4 py-3">
          <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">
            {tf('vehicleProfile.garage.why', 'Why')}
          </p>
          {garage.reasons?.length ? (
            <ul className="mt-1.5 space-y-1">
              {garage.reasons.map((reason, i) => (
                <li key={i} className="flex gap-2 text-sm text-slate-600">
                  <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-slate-300" />
                  <span>{reason}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-1.5 text-sm text-slate-500">
              {tf('vehicleProfile.garage.settled', 'Nothing unusual — this car is going in about as often, and for about as long, as the fleet expects.')}
            </p>
          )}
          {garage.last_entry_at && (
            <p className="mt-2 text-xs text-slate-400">
              {tf('vehicleProfile.garage.lastEntry', 'Last garage entry')}: {fmtDate(garage.last_entry_at)}
            </p>
          )}
        </div>
      </div>
      )}

      {err && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{err}</div>}

      {/* FINDINGS — consolidated Inspector vs Garage trail for the whole car */}
      {show('findings') && !loading && allFindings.length > 0 && (
        <SectionCard title={t('Findings')} subtitle={t('Every issue recorded for this car, grouped by who identified it.')}>
          <div className="px-4 py-4">
            <FindingsList findings={allFindings} />
          </div>
        </SectionCard>
      )}

      {/* MAINTENANCE WORKFLOW — tickets & diagnostics */}
      {show('workflow') && (
      <SectionCard title={t('Maintenance Workflow')} subtitle={t('Tickets & diagnostics for this car — newest first.')}>
        {loading ? (
          <div className="space-y-2 p-3"><Skeleton className="h-10" /><Skeleton className="h-10" /></div>
        ) : tickets.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-slate-400">{t('No workflow tickets yet.')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-start text-xs font-semibold uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2">{t('Stage')}</th>
                  <th className="px-3 py-2">{t('Reason')}</th>
                  <th className="px-3 py-2">{t('Dispatch odo')}</th>
                  <th className="px-3 py-2">{t('Return odo')}</th>
                  <th className="px-3 py-2">{t('Garage')}</th>
                  <th className="px-3 py-2 text-end">{t('Cost')}</th>
                  <th className="px-3 py-2">{t('Opened')}</th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((ticket) => {
                  const st = STAGE[ticket.workflow_status] || { label: ticket.status_label || ticket.workflow_status, tone: 'slate' };
                  const hasFindings = ticket.findings?.length > 0;
                  return (
                    <Fragment key={ticket.id}>
                      <tr className={`hover:bg-slate-50/60 ${hasFindings ? '' : 'border-b border-slate-50 last:border-0'}`}>
                        <td className="px-3 py-2"><Badge tone={st.tone}>{st.label}</Badge></td>
                        <td className="px-3 py-2 text-slate-600">{REASON[ticket.trigger_reason] || ticket.trigger_reason || '—'}</td>
                        <td className="px-3 py-2 tabular-nums text-slate-600">{odo(ticket.dispatch_odometer)}</td>
                        <td className="px-3 py-2 tabular-nums text-slate-600">{odo(ticket.return_odometer)}</td>
                        <td className="px-3 py-2 text-slate-500">{ticket.garage || '—'}</td>
                        <td className="px-3 py-2 text-end tabular-nums text-slate-600">{ticket.cost != null ? aed2(ticket.cost) : '—'}</td>
                        <td className="px-3 py-2 text-slate-400">{ticket.created_at ? fmtDate(ticket.created_at) : '—'}</td>
                      </tr>
                      {hasFindings && (
                        <tr className="border-b border-slate-50 last:border-0">
                          <td colSpan={7} className="px-3 pb-3 pt-0">
                            <FindingsList findings={ticket.findings} tasks={ticket.tasks} compact />
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
      <SectionCard title={t('Condition Timeline')} subtitle={t('Pre/post odometer & inspection photos — most recent first.')}>
        {loading ? (
          <div className="flex gap-3 p-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-32 w-44 shrink-0 rounded-xl" />)}</div>
        ) : photos.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-slate-400">{t('No condition photos captured yet.')}</p>
        ) : (
          <div className="flex gap-3 overflow-x-auto p-1 pb-3">
            {photos.map((p) => (
              <button key={p.id} type="button" onClick={() => p.url && setLightbox(p)} className="group shrink-0 text-start">
                <div className="relative h-32 w-44 overflow-hidden rounded-xl ring-1 ring-slate-200">
                  {p.url ? (
                    <img src={photoSrc(p.url)} alt={p.body_part} className="h-full w-full object-cover transition group-hover:scale-105" />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center bg-slate-50 text-slate-300"><Icon.Gauge className="h-7 w-7" /></div>
                  )}
                  <span className="absolute start-1.5 top-1.5">
                    <Badge tone={p.phase === 'post' ? 'cyan' : 'indigo'}>{p.phase === 'post' ? t('After') : t('Before')}</Badge>
                  </span>
                </div>
                <p className="mt-1 truncate text-xs font-medium capitalize text-slate-600">{p.body_part ? p.body_part.replace(/_/g, ' ') : t('photo')}</p>
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
