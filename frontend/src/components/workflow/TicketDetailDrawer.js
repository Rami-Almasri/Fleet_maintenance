import { Fragment, useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Drawer from '../ui/Drawer';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import FindingsList from './FindingsList';
import WhyThisGarage from './WhyThisGarage';
import RepairQualityCheck from './RepairQualityCheck';
import RequiredPartsPanel from './RequiredPartsPanel';
import RepairIntelligencePanel from '../knowledge/RepairIntelligencePanel';
import VideoEvidence from './VideoEvidence';
import InvoicesPanel from './InvoicesPanel';
import TicketParts from './TicketParts';
import { resolveAction, allows, ctaLabel, ago, fmtDuration, fmtDateTime, SEVERITY_CHIP, custodyBlocked, custodyHolderName, isAtGarage, isPausable, isPaused, isPausedOut, isTempReleasable, isTemporarilyReleased, canOrderParts, ORIGIN_LABEL } from './meta';
import { SHOW_VIDEO_REVIEW } from '../../config/features';

// workflow_status → the lane colour, reused for the status pill so the drawer reads as the
// same ticket the user clicked on the board.
const STATUS_TONE = {
  inspection_requested: '#d946ef', inspection_diagnostic: '#8b5cf6',
  inspection_pending: '#a855f7', awaiting_dispatch: '#a855f7',
  in_transit: '#f97316', under_repair: '#f97316', repair_review: '#7c3aed',
  ready_for_pickup: '#0ea5e9', in_our_park: '#0ea5e9',
  ready_for_reinspection: '#10b981', closed: '#64748b', diagnostic_cleared: '#10b981',
  paused_returned_to_service: '#64748b',
};

// The audit trail, in lifecycle order. Label keys live under workflow.detail.handoff.<key>.
const HANDOFF_ORDER = ['requested', 'inspected', 'dispatched', 'repair_started', 'ready', 'closed'];

// Mileage-timeline dot colour by how the reading was produced (test drive = violet, garage moves =
// orange, contract handovers = blue, a human correction = amber) — mirrors the workflow lane tones.
const MILEAGE_TONE = {
  test_drive: '#8b5cf6', report: '#a855f7', dispatch: '#f97316', return: '#10b981', reinspect: '#0d9488',
  contract_out: '#3b82f6', contract_in: '#3b82f6', manual: '#f59e0b',
};
// …and the matching glyph for each action, so a reading is legible at a glance.
const MILEAGE_ICON = {
  test_drive: Icon.Gauge, report: Icon.Gauge, dispatch: Icon.Truck, return: Icon.Check, reinspect: Icon.Flag,
  contract_out: Icon.Car, contract_in: Icon.Car, manual: Icon.Refresh,
};
// Odometer Continuity badge colours — a backward "Discrepancy" reads loud (red), the legitimate-but-
// noteworthy garage test-drive / big-jump cases read amber. 'verified' shows no badge (clean handoff).
const MILEAGE_FLAG_CLS = {
  discrepancy: 'bg-red-100 text-red-700',
  test_drive: 'bg-amber-100 text-amber-700',
  check: 'bg-amber-100 text-amber-700',
};

// Diagnostic-condition status → chip classes (matches the SEVERITY_CHIP scale used elsewhere).
const DIAG_TONE = {
  red:   'bg-red-50 text-red-700 ring-red-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  gray:  'bg-slate-100 text-slate-500 ring-slate-200',
};
const DIAG_STATUS_WORD = { overdue: 'Overdue', due: 'Due', ok: 'OK', no_data: '—' };

// A short, locale-formatted day ("13 Jun 2026") from a 'YYYY-MM-DD' string.
const fmtDay = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    return d;
  }
};

// ── Small presentational helpers ──────────────────────────────────────────────
function Section({ title, icon, action, children }) {
  return (
    <section className="overflow-hidden rounded-xl border border-slate-200/70 bg-white shadow-soft">
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5">
        <h3 className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500">
          {icon}
          {title}
        </h3>
        {action}
      </div>
      <div className="px-4 py-4">{children}</div>
    </section>
  );
}

function Fact({ label, value, mono }) {
  if (value == null || value === '') return null;
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className={`text-sm text-slate-800 ${mono ? 'font-mono font-semibold tracking-wide' : ''}`}>{value}</dd>
    </div>
  );
}

export default function TicketDetailDrawer({ ticketId, summary, can, userId, onAct, onClose, reloadKey = 0, garages = [], findingsCatalog = [] }) {
  const { t } = useI18n();
  const [tk, setTk] = useState(summary || null);
  const [photos, setPhotos] = useState([]);
  const [mileage, setMileage] = useState(null); // { current, baseline, synced_at, history[], total, distance }
  const [mileageLoading, setMileageLoading] = useState(true);
  const [mileageErr, setMileageErr] = useState(false);
  const [diag, setDiag] = useState(null); // { idle, last_check, conditions[] } — diagnostic context panel
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [ackBusyId, setAckBusyId] = useState(null); // Handover Comparison Report — incident id being acknowledged
  const [partsOpenSignal, setPartsOpenSignal] = useState(0); // footer "Request Part" → opens the Parts modal

  const load = useCallback(async () => {
    if (!ticketId) return;
    setError(null);
    try {
      const detail = (await api.get(`/maintenance-tickets/${ticketId}`)).data.data;
      setTk(detail);
      // Odometer photos live on the vehicle (inspection_records), not the ticket — pull them in
      // best-effort so a photo-fetch failure never blanks the rest of the drawer.
      if (detail?.vehicle_id) {
        api.get(`/maintenance-tickets/vehicle/${detail.vehicle_id}`)
          .then((r) => setPhotos((r.data?.data?.photos || []).filter((p) => p.body_part === 'odometer')))
          .catch(() => setPhotos([]));
      }
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.detail.loadError'));
    } finally {
      setLoading(false);
    }
  }, [ticketId, t]);

  // Enterprise Handover Workflow — a supervisor clears a flagged pause/resume discrepancy, finalizing
  // the resume that was held pending it. No re-capture: the resume handover was already saved.
  const acknowledgeIncident = useCallback(async (incidentId) => {
    setAckBusyId(incidentId);
    try {
      await api.post(`/maintenance-tickets/${ticketId}/incidents/${incidentId}/acknowledge`, {});
      await load();
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.detail.loadError'));
    } finally {
      setAckBusyId(null);
    }
  }, [ticketId, load, t]);

  // (Re)load when the target ticket changes or the parent bumps reloadKey after an action.
  useEffect(() => {
    setLoading(!summary);
    setTk(summary || null);
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticketId, reloadKey]);

  // Mileage story loads on its own track (independent of the ticket fetch) so it always resolves to a
  // real state — data, empty, or error — and never leaves the section stuck or silently missing.
  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    setMileage(null);
    setMileageErr(false);
    setMileageLoading(true);
    api.get(`/maintenance-tickets/${ticketId}/mileage`)
      .then((r) => { if (alive) setMileage(r.data?.data || null); })
      .catch(() => { if (alive) setMileageErr(true); })
      .finally(() => { if (alive) setMileageLoading(false); });
    return () => { alive = false; };
  }, [ticketId, reloadKey]);

  // Diagnostic context (idle duration, last check, live oil/battery/tyre status) — best-effort, its own
  // track so a failure never blanks the drawer.
  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    setDiag(null);
    api.get(`/maintenance-tickets/${ticketId}/diagnostic-context`)
      .then((r) => { if (alive) setDiag(r.data?.data || null); })
      .catch(() => { if (alive) setDiag(null); });
    return () => { alive = false; };
  }, [ticketId, reloadKey]);

  // Paused — Returned to Service splits into two visual sub-states on the ONE workflow_status: still
  // out with the customer (slate) vs physically back and the handover paperwork is due (orange).
  const tone = tk?.is_returned_pending_handover ? '#f97316' : (STATUS_TONE[tk?.workflow_status] || '#64748b');
  const act = tk && resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  // "Mark Ready" is gated: blocked until every fault on the ticket is fixed (or cancelled).
  const openFaults = tk?.tasks_progress?.open ?? 0;
  const readyBlocked = act?.action === 'ready' && openFaults > 0;
  const readyHint = `Fix all ${openFaults} open fault${openFaults > 1 ? 's' : ''} first`;
  // Custody gate: a return/arrival leg may only be completed by the same driver who took the car.
  // custodyBlocked() is scoped to the gated states, so no per-action guard is needed here.
  const custodyLocked = custodyBlocked(tk, userId);
  const custodyHolder = custodyHolderName(tk, userId);
  const custodyHint = act?.action === 'arriveAtPark'
    ? `Only ${custodyHolder || 'the driver who collected the car from the garage'} can complete the arrival at our park`
    : `Only ${custodyHolder || 'the driver who picked up the car'} can check it in`;
  // Follow-up is MANAGEMENT authority (Waleed/Abdullah) — they chase the garage, not the driver. Only
  // shown while the car is In Workshop (under_repair), i.e. actually at the garage being worked on.
  const canFollowUp = tk && tk.workflow_status === 'under_repair' && can('maintenance.delegate');
  // "Manage faults" (mark a fault fixed / reopen / transfer the car) — the Supervisor's dispatch
  // authority. Offered once the car is at the garage stage, but NOT at ready_for_pickup: the faults
  // are already fixed by then, so there's nothing left to mark done. Mirrors the board card guard.
  const canRoute = tk && can('maintenance.delegate') && isAtGarage(tk)
    && tk.workflow_status !== 'ready_for_pickup' && (tk.tasks?.length > 0);
  // Supervisor Video-Review: the secondary "request a re-fix" (the primary "approve" comes from ACTION).
  // The whole gate is hidden while parked (SHOW_VIDEO_REVIEW = false).
  const canRefix = SHOW_VIDEO_REVIEW && tk && tk.workflow_status === 'repair_review' && can('maintenance.delegate');
  // Pause Maintenance & Return to Service — pull a mid-repair car out for a customer (the controllers'
  // decision). Only offered once the car is physically IN THE WORKSHOP (at-garage stages: under_repair,
  // repair_review, ready_for_pickup) — isAtGarage ∩ isPausable drops the pre-arrival stages (pending
  // dispatch, in transit) and the post-garage QA stages, so you only "release" a car that's actually in
  // the shop. (isAtGarage includes closed, but isPausable excludes it.) The ticket keeps all its state
  // and Resume continues from here; the released car becomes rentable via the backend cascade. NOTE: the
  // backend PAUSABLE_STATES stays wider on purpose so the rental-creation "pull from maintenance" path can
  // still auto-pause an earlier-stage ticket.
  const canPause = tk && isPausable(tk) && isAtGarage(tk) && can('maintenance.manage');
  // Vehicle Physically Returned — a light checkpoint (no handover required yet) offered while the car
  // is paused and still out; once flagged, "Resume" (the primary action) takes over and captures the
  // full return handover. Mirrors isPausedOut() — controller, supervisor, or the driver/logistics claim.
  const canMarkReturn = tk && isPausedOut(tk) && (can('maintenance.manage') || can('maintenance.delegate') || can('logistics.claim'));
  // Temporary Vehicle Release — take the car OUT of the workshop mid-repair (road test / customer test /
  // external inspection / storage) WITHOUT pausing; the ticket stays at its stage. Releasing it is the
  // controllers' call; bringing it back may also be a supervisor or the driver/logistics claim role.
  // Only while the car is physically IN THE WORKSHOP (at-garage stages: under_repair, repair_review,
  // ready_for_pickup) — isAtGarage ∩ isTempReleasable, matching Pause & Release. You can't "take the car
  // out of the workshop" before it has arrived there. (isAtGarage includes closed; isTempReleasable excludes it.)
  const canTempRelease = tk && isTempReleasable(tk) && isAtGarage(tk) && can('maintenance.manage');
  const canReturnRelease = tk && isTemporarilyReleased(tk) && (can('maintenance.manage') || can('maintenance.delegate') || can('logistics.claim'));
  // Video Evidence is relevant once the car has reached the garage (or whenever any video already exists).
  const showVideo = SHOW_VIDEO_REVIEW && tk && (tk.has_video
    || ['under_repair', 'repair_review', 'ready_for_pickup', 'in_our_park', 'ready_for_reinspection', 'reinspection_failed', 'closed'].includes(tk.workflow_status));
  const timing = tk?.stage_timing?.durations || {};

  const footer = tk && (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {can('logistics.dispatch') && (
        <Button size="sm" variant="secondary" onClick={() => onAct('logistics', tk)}>
          <Icon.Truck className="h-3.5 w-3.5" /> {t('workflow.cardAction.dispatchCar')}
        </Button>
      )}
      {can('maintenance.delegate') && (
        <Button size="sm" variant="secondary" onClick={() => onAct('delegate', tk)}>{t('workflow.cardAction.delegate')}</Button>
      )}
      {tk.workflow_status === 'under_repair' && can('maintenance.logistics') && (
        <Button size="sm" variant="secondary" onClick={() => onAct('finding', tk)}>{t('workflow.board.addFinding')}</Button>
      )}
      {can('parts.request') && canOrderParts(tk) && (
        <Button size="sm" variant="secondary" onClick={() => setPartsOpenSignal((n) => n + 1)}>
          <Icon.Wrench className="h-3.5 w-3.5" /> Request Part
        </Button>
      )}
      {canRoute && (
        <Button size="sm" variant="secondary" onClick={() => onAct('route', tk)}>
          <Icon.Wrench className="h-3.5 w-3.5" /> {t('workflow.task.route')}
        </Button>
      )}
      {canFollowUp && (
        <Button size="sm" variant="secondary" onClick={() => onAct('followup', tk)}>{t('workflow.cardAction.followup')}</Button>
      )}
      {canRefix && (
        <Button size="sm" variant="secondary" onClick={() => onAct('requestRefix', tk)}>{t('workflow.cardAction.requestRefix')}</Button>
      )}
      {canPause && (
        <Button size="sm" variant="secondary" onClick={() => onAct('pause', tk)}>{t('workflow.cardAction.pause')}</Button>
      )}
      {canMarkReturn && (
        <Button size="sm" variant="secondary" onClick={() => onAct('markReturned', tk)}>{t('workflow.cardAction.markReturned')}</Button>
      )}
      {canTempRelease && (
        <Button size="sm" variant="secondary" onClick={() => onAct('temporarilyRelease', tk)}>
          <span aria-hidden>🚗</span> {t('workflow.cardAction.temporarilyRelease')}
        </Button>
      )}
      {canReturnRelease && (
        <Button size="sm" variant="secondary" onClick={() => onAct('returnFromRelease', tk)}>
          <span aria-hidden>🔧</span> {t('workflow.cardAction.returnFromRelease')}
        </Button>
      )}
      {/* Recovery (towing) — offered as a SECONDARY button only when it isn't already the PRIMARY action
          below (resolveAction() makes Recovery primary for any Breakdown ticket at inspection_pending or
          awaiting_dispatch — it can't be driven in, so towing outranks the classic garage assignment).
          This covers the rarer case: a NON-breakdown car, already classically assigned, that turns out to
          need towing after all. */}
      {tk.workflow_status === 'awaiting_dispatch' && tk.maintenance_type !== 'breakdown' && (can('maintenance.logistics') || can('maintenance.delegate')) && (
        <Button size="sm" variant="secondary" onClick={() => onAct('recovery', tk)}>
          <span aria-hidden>🛻</span> {t('workflow.cardAction.recovery')}
        </Button>
      )}
      {/* Assign Garage escape hatch — a Breakdown's PRIMARY action is now Recovery, but a supervisor who
          realizes on the spot that the car is actually driveable can still assign it the classic way
          without first reclassifying the ticket's type. */}
      {tk.workflow_status === 'inspection_pending' && tk.maintenance_type === 'breakdown' && can('maintenance.delegate') && (
        <Button size="sm" variant="secondary" onClick={() => onAct('assign', tk)}>{t('workflow.cardAction.assign')}</Button>
      )}
      {allowed && !custodyLocked && (
        <Button size="sm" variant={act.variant} disabled={readyBlocked} title={readyBlocked ? readyHint : undefined} onClick={() => onAct(act.action, tk)}>
          {ctaLabel(t, tk)}
        </Button>
      )}
      {allowed && custodyLocked && <span className="self-center text-[11px] font-medium text-amber-600">{custodyHint}</span>}
    </div>
  );

  return (
    <Drawer
      open
      onClose={onClose}
      eyebrow={t('workflow.detail.eyebrow', { id: ticketId })}
      title={tk?.plate || `#${ticketId}`}
      subtitle={tk?.car}
      footer={footer}
    >
      {error ? (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      ) : loading && !tk ? (
        <div className="space-y-4">
          <Skeleton className="h-20 rounded-xl" />
          <Skeleton className="h-32 rounded-xl" />
          <Skeleton className="h-40 rounded-xl" />
        </div>
      ) : tk ? (
        <div className="space-y-4">
          {/* Status banner — customer-complaint flag FIRST (top priority), then fault severity, status. */}
          <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-soft">
            {tk.trigger_reason === 'customer_reported' && (
              <span
                className="inline-flex items-center gap-1 rounded-full bg-rose-600 px-2.5 py-1 text-sm font-bold uppercase tracking-wide text-white shadow-sm ring-1 ring-inset ring-rose-700/40"
                title={tk.customer_complaint || ''}
              >
                📣 {t('workflow.complaint.badge')}
              </span>
            )}
            {tk.fault_severity && (
              <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-bold ring-1 ring-inset ${SEVERITY_CHIP[tk.fault_severity_tone] || SEVERITY_CHIP.amber}`}>
                {tk.fault_severity_emoji} {t(`workflow.faultSeverity.${tk.fault_severity}`)}
              </span>
            )}
            <span
              className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-bold"
              style={{ background: `${tone}1a`, color: tone }}
            >
              <span className="h-2 w-2 rounded-full" style={{ background: tone }} />
              {tk.status_label}
            </span>
            {/* Temporarily out — the car left the workshop mid-repair (road test / customer test). Its
                workflow_status stays at the repair stage, so without this the banner would read "In
                Workshop" while the car is physically gone. This makes the OUT state explicit, so the
                "Return to workshop" action reads correctly (it only shows while the car is out). */}
            {isTemporarilyReleased(tk) && (
              <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-sm font-bold text-amber-800 ring-1 ring-inset ring-amber-300">
                🚗 {t('workflow.tempRelease.outBadge')}
              </span>
            )}
            <div className="ms-auto flex items-center gap-3">
              {/* Jump from the quick drawer to the full-page command view. */}
              <Link
                to={`/maintenance-workflow/${ticketId}`}
                className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
              >
                <Icon.ArrowRight className="h-3.5 w-3.5" /> Open full view
              </Link>
              <Link
                to={`/vehicles/${tk.vehicle_id}`}
                className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-700"
              >
                <Icon.Car className="h-3.5 w-3.5" /> {t('workflow.detail.openVehicle')}
              </Link>
            </div>
          </div>

          {/* Audit trail — pinned to the TOP as the primary status reference. Sorted strictly by
              timestamp DESC (most recent action at the top); ties fall back to the canonical lifecycle
              order. Each stage links to the earlier one below with an up-arrow so the progression
              reads bottom-to-top (Inspection requested → … → the latest action on top). */}
          <Section title={t('workflow.detail.timeline')} icon={<Icon.Activity className="h-3.5 w-3.5 text-slate-400" />}>
            <ol className="relative">
              {(() => {
                const steps = HANDOFF_ORDER.filter((k) => tk.handoffs?.[k]);
                if (!steps.length) {
                  return <li className="text-xs text-slate-400">{t('workflow.detail.noTimeline')}</li>;
                }
                const at = (k) => { const d = tk.handoffs[k]?.at ? new Date(tk.handoffs[k].at).getTime() : 0; return Number.isNaN(d) ? 0 : d; };
                const sorted = steps.slice().sort((a, b) => (at(b) - at(a)) || (HANDOFF_ORDER.indexOf(b) - HANDOFF_ORDER.indexOf(a)));
                return sorted.map((key, si) => {
                  const h = tk.handoffs[key];
                  const hasEarlierBelow = si < sorted.length - 1; // an up-arrow links to the stage below
                  // 'dispatched'/'repair_started' carry odometer + garage/destination context so the
                  // timeline reads as pickup → transit → arrival instead of a bare timestamp.
                  const labelKey = key === 'dispatched' && h.is_recovery ? 'dispatched_recovery' : key;
                  const subline = [
                    h.odometer != null ? `${Number(h.odometer).toLocaleString()} km` : null,
                    key === 'dispatched' ? h.destination : (key === 'repair_started' ? h.garage : null),
                  ].filter(Boolean).join(' · ');
                  return (
                    <Fragment key={key}>
                      <li className="relative flex gap-2.5 px-2 py-1">
                        <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-indigo-500 ring-2 ring-white" />
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-semibold text-slate-800">{t(`workflow.detail.handoff.${labelKey}`)}</p>
                          <p className="text-xs text-slate-500">
                            {h.name ? `${h.name} · ` : ''}{fmtDateTime(h.at)}
                            {h.at && <span className="text-slate-400"> · {ago(h.at, t)}</span>}
                          </p>
                          {subline && <p className="text-xs text-slate-400">{subline}</p>}
                        </div>
                      </li>
                      {/* arrow FROM this stage TO the next one (above) → explicit workflow progression */}
                      {hasEarlierBelow && (
                        <li aria-hidden className="flex ps-0.5 py-0.5">
                          <svg className="h-4 w-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M6 11l6-6 6 6" /></svg>
                        </li>
                      )}
                    </Fragment>
                  );
                });
              })()}
            </ol>
          </Section>

          {/* Key facts */}
          <Section title={t('workflow.detail.overview')} icon={<Icon.Info className="h-3.5 w-3.5 text-slate-400" />}>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
              <Fact label={t('workflow.detail.reason')} value={tk.trigger_reason && (REASON_LABEL(t, tk.trigger_reason))} />
              {/* WHERE the request came from — kept next to, and independent of, the reason above. */}
              <Fact label={t('workflow.detail.source')} value={tk.request_origin_label || ORIGIN_LABEL[tk.request_origin] || null} />
              <Fact label={t('workflow.detail.type')} value={tk.maintenance_type_label} />
              <Fact label={t('workflow.detail.severity')} value={tk.severity} />
              <Fact label={t('workflow.detail.garage')} value={tk.garage} />
              {/* A recovery leg is a TOWING unit, not a driver — label it accordingly (+ operator phone). */}
              {tk.is_recovery ? (
                <Fact label={t('workflow.detail.recoveryUnit')} value={[tk.recovery_unit_name, tk.recovery_unit_phone].filter(Boolean).join(' · ')} />
              ) : (
                <Fact label={t('workflow.detail.driver')} value={tk.dispatched_by_name || tk.assigned_driver_name} />
              )}
              <Fact label={t('workflow.detail.contract')} value={tk.linked_contract_no} mono />
              {tk.cost != null && <Fact label={t('workflow.detail.cost')} value={`AED ${Number(tk.cost).toLocaleString()}`} />}
            </dl>
            {/* customer_complaint holds the issue text for every origin — label it by trigger_reason so a
                system routine agenda / driver note isn't mislabeled as a "Customer complaint". */}
            {tk.customer_complaint && (
              <div className="mt-3 border-t border-slate-100 pt-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                  {t(tk.trigger_reason === 'customer_reported' ? 'workflow.detail.complaint' : tk.trigger_reason === 'periodic' ? 'workflow.detail.agenda' : 'workflow.detail.driverNote')}
                </p>
                <p className="mt-1 text-sm text-slate-700">{tk.customer_complaint}</p>
              </div>
            )}
            {hasReport(tk.test_drive_report) && (
              <div className="mt-3 border-t border-slate-100 pt-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{t('workflow.detail.report')}</p>
                <TestDriveReport report={tk.test_drive_report} tasks={tk.tasks} />
              </div>
            )}
          </Section>

          {/* Invoices — One Ticket → Many Invoices: each garage's bill for the faults it fixed. Present
              once the ticket carries faults (there's something to bill against). */}
          {(tk.invoices?.length > 0 || (can('maintenance.manage') && tk.tasks?.length > 0)) && (
            <Section title={t('workflow.invoices.title')} icon={<Icon.Invoice className="h-3.5 w-3.5 text-slate-400" />}>
              <InvoicesPanel
                ticket={tk}
                garages={garages}
                findingsCatalog={findingsCatalog}
                canManage={can('maintenance.manage')}
                onChanged={load}
              />
            </Section>
          )}

          {/* Diagnostic Context — the "why" behind the flag: how long the car has been idle, when it was
              last checked (with a link to that visit), and the live oil / battery / tyre status against the
              chosen limits. Lazily fetched; shown once it resolves. */}
          {diag && (
            <Section title="Diagnostic Context" icon={<Icon.Gauge className="h-3.5 w-3.5 text-slate-400" />}>
              <div className="space-y-3">
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                  {diag.idle?.eligible && diag.idle.days != null && (
                    <Fact
                      label="Idle since last use"
                      value={`${diag.idle.days} day${diag.idle.days === 1 ? '' : 's'}${diag.idle.idle_since ? ` · since ${fmtDay(diag.idle.idle_since)}` : ''}${diag.idle.exceeded ? ` (limit ${diag.idle.limit})` : ''}`}
                    />
                  )}
                  <div className="flex flex-col gap-0.5">
                    <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Last check</dt>
                    <dd className="text-sm text-slate-800">
                      {diag.last_check ? (
                        <>
                          {fmtDay(diag.last_check.date)}
                          {diag.last_check.days_ago != null && <span className="text-slate-400"> · {diag.last_check.days_ago}d ago</span>}
                          {diag.last_check.link && (
                            <Link to={diag.last_check.link} className="ms-2 text-xs font-semibold text-indigo-600 hover:underline">View</Link>
                          )}
                          <div className="text-[11px] text-slate-400">{diag.last_check.label}</div>
                        </>
                      ) : (
                        <span className="text-slate-400">No prior check on file</span>
                      )}
                    </dd>
                  </div>
                </dl>

                {diag.conditions?.length > 0 && (
                  <div className="space-y-1.5">
                    {diag.conditions.map((c) => (
                      <div key={c.key} className="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-100">
                        <div className="min-w-0">
                          <p className="text-sm font-semibold text-slate-800">{c.label}</p>
                          <p className="text-xs text-slate-500">
                            {c.summary}
                            {c.current_km != null && <span className="text-slate-400"> · now {Number(c.current_km).toLocaleString()} km</span>}
                            {c.last_service_km != null && <span className="text-slate-400"> · serviced at {Number(c.last_service_km).toLocaleString()} km</span>}
                            {c.last_service_at && <span className="text-slate-400"> · on {fmtDay(c.last_service_at)}</span>}
                            {c.last_changed && <span className="text-slate-400"> · changed {fmtDay(c.last_changed)}</span>}
                          </p>
                        </div>
                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${DIAG_TONE[c.tone] || DIAG_TONE.gray}`}>
                          {DIAG_STATUS_WORD[c.status] || c.status}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </Section>
          )}

          {/* Delegation overlay */}
          {tk.delegation?.status === 'driver_assigned' && tk.delegation.driver_name && (
            <div className="flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50/60 px-4 py-3 text-sm font-medium text-indigo-700">
              <Icon.Car className="h-4 w-4 shrink-0" />
              <span>{t('workflow.delegation.assigned')} · {tk.delegation.driver_name}</span>
              {tk.delegation.task && (
                <span className="ms-auto rounded bg-indigo-100 px-2 py-0.5 text-xs">{t(`workflow.delegation.${tk.delegation.task}`)}</span>
              )}
            </div>
          )}

          {/* Findings */}
          <Section title={t('workflow.detail.findings')} icon={<Icon.Flag className="h-3.5 w-3.5 text-slate-400" />}>
            {tk.findings?.length ? <FindingsList findings={tk.findings} tasks={tk.tasks} paused={isPaused(tk)} /> : (
              <p className="text-xs text-slate-400">{t('workflow.detail.noFindings')}</p>
            )}
          </Section>

          {/* Repair intelligence — "Previous Similar Repairs + Recommendation" per fault. Read-only; the
              panel self-loads the frozen contract and degrades gracefully when history is thin. */}
          {tk.tasks?.some((task) => task.kind !== 'service' && task.kind !== 'inspection') && (
            <Section title={t('repairIntel.title')} icon={<Icon.Chart className="h-3.5 w-3.5 text-indigo-400" />}>
              <div className="space-y-2">
                {tk.tasks
                  .filter((task) => task.kind !== 'service' && task.kind !== 'inspection')
                  .map((task) => (
                    <div key={task.id} className="space-y-1">
                      <div className="text-xs font-medium text-slate-500">{task.symptom}</div>
                      <RepairIntelligencePanel taskId={task.id} />
                    </div>
                  ))}
              </div>
            </Section>
          )}

          {/* Required Parts — what the INSPECTOR said the repair would need. Sits directly above the Parts
              board because it is the step before it: the coordinator turns these technical lines into real
              part requests once the garage is chosen (or dismisses them with a reason). Self-fetches and
              renders nothing when the inspection listed none. */}
          <RequiredPartsPanel ticket={tk} />

          {/* Parts — every part requested against this ticket + a technician's in-context "Request Part".
              Approve/purchase/install still happen on the standalone /parts board. */}
          {can('parts.view') && (
            <TicketParts
              ticketId={ticketId}
              ticket={tk}
              tasks={tk.tasks}
              canView={can('parts.view')}
              canRequest={can('parts.request')}
              openSignal={partsOpenSignal}
              onChanged={load}
              variant="drawer"
            />
          )}

          {/* Repair Quality Check — the post-repair inspection verdicts (Fixed / Problem still exists /
              New problem found) recorded at sign-off. Self-fetches; renders nothing before the QC stage. */}
          <RepairQualityCheck ticketId={ticketId} workflowStatus={tk.workflow_status} reloadKey={reloadKey} />

          {/* Handover Comparison Report — pause vs. resume custody-handover snapshots, permanently
              attached to the ticket's history. A breach that exceeded the configured thresholds shows
              its linked Incident + an Acknowledge action (maintenance.manage only). */}
          {tk.handover_comparisons?.length > 0 && (
            <Section title={t('workflow.detail.handoverReport')} icon={<Icon.Shield className="h-3.5 w-3.5 text-slate-400" />}>
              <div className="space-y-3">
                {tk.handover_comparisons.map((c) => (
                  <div key={c.id} className={`rounded-xl border px-3.5 py-3 ${c.exceeds_threshold ? 'border-red-200 bg-red-50/50' : 'border-slate-200 bg-slate-50/60'}`}>
                    <div className="flex items-center justify-between gap-2">
                      <span className={`inline-flex items-center gap-1.5 text-xs font-bold ${c.exceeds_threshold ? 'text-red-700' : 'text-emerald-700'}`}>
                        <span className="h-2 w-2 rounded-full" style={{ background: c.exceeds_threshold ? '#ef4444' : '#10b981' }} />
                        {c.exceeds_threshold ? t('workflow.detail.handoverBreach') : t('workflow.detail.handoverClean')}
                      </span>
                      <span className="text-[11px] text-slate-400">{fmtDateTime(c.generated_at)}</span>
                    </div>
                    <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                      <Fact label={t('workflow.detail.handoverMileageDelta')} value={c.mileage_delta != null ? `${c.mileage_delta > 0 ? '+' : ''}${Number(c.mileage_delta).toLocaleString()} km` : null} />
                      <Fact label={t('workflow.detail.handoverFuelDelta')} value={c.fuel_delta} />
                      <Fact label={t('workflow.detail.handoverNewDamages')} value={c.new_damages?.length ? c.new_damages.map((d) => d.location || d).join(', ') : null} />
                      <Fact label={t('workflow.detail.handoverMissingAccessories')} value={c.missing_accessories?.length ? c.missing_accessories.join(', ') : null} />
                      <Fact label={t('workflow.detail.handoverConditionChanges')} value={c.condition_changes && Object.keys(c.condition_changes).length ? Object.entries(c.condition_changes).map(([k, v]) => `${k}: ${v}`).join(', ') : null} />
                    </dl>
                    {c.exceeds_threshold && tk.active_incident && tk.active_incident.status === 'open' && (
                      <div className="mt-3 flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 ring-1 ring-inset ring-red-200">
                        <div className="min-w-0">
                          <p className="text-xs font-semibold text-red-700">{tk.active_incident.description || t('workflow.detail.incidentStatus')}</p>
                          <p className="text-[11px] text-slate-400">{tk.active_incident.severity}</p>
                        </div>
                        {can('maintenance.manage') && (
                          <Button size="sm" variant="danger" loading={ackBusyId === tk.active_incident.id} onClick={() => acknowledgeIncident(tk.active_incident.id)}>
                            {t('workflow.detail.acknowledgeIncident')}
                          </Button>
                        )}
                      </div>
                    )}
                    {c.exceeds_threshold && tk.active_incident?.status === 'acknowledged' && (
                      <p className="mt-2 text-[11px] font-medium text-emerald-600">
                        {t('workflow.detail.acknowledged')} · {tk.active_incident.acknowledged_by_name} · {fmtDateTime(tk.active_incident.acknowledged_at)}
                      </p>
                    )}
                  </div>
                ))}
              </div>
            </Section>
          )}

          {/* Video Evidence — the garage's repair videos (the permanent video record). Supervisors upload +
              delete; anyone viewing the ticket can watch. Shown once the car has reached the garage. */}
          {showVideo && (
            <VideoEvidence ticketId={ticketId} canManage={can('maintenance.delegate')} reloadKey={reloadKey} onChange={load} />
          )}

          {/* Maintenance forecast — will this car need service soon? Km-to-interval + a projected date
              from the car's own usage rate, so a service is caught before it's missed on a long rental. */}
          {mileage?.forecast && mileage.forecast.status !== 'no_data' && (() => {
            const f = mileage.forecast;
            const tone = f.status === 'overdue'
              ? { box: 'bg-red-50 ring-red-200', text: 'text-red-700', dot: '#ef4444', label: t('workflow.detail.healthOverdue') }
              : f.status === 'due_soon'
                ? { box: 'bg-amber-50 ring-amber-200', text: 'text-amber-700', dot: '#f59e0b', label: t('workflow.detail.healthApproaching') }
                : { box: 'bg-emerald-50 ring-emerald-200', text: 'text-emerald-700', dot: '#10b981', label: t('workflow.detail.healthHealthy') };
            let projected = null;
            if (f.projected_date) {
              try { projected = new Date(f.projected_date).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }); } catch { projected = f.projected_date; }
            }
            return (
              <Section title={t('workflow.detail.forecast')} icon={<Icon.Activity className="h-3.5 w-3.5 text-slate-400" />}>
                <div className={`rounded-xl px-3.5 py-3 ring-1 ring-inset ${tone.box}`}>
                  <div className="flex items-center justify-between gap-2">
                    <span className={`inline-flex items-center gap-1.5 text-xs font-bold ${tone.text}`}>
                      <span className="h-2 w-2 rounded-full" style={{ backgroundColor: tone.dot }} />
                      {tone.label}
                    </span>
                    {f.usage_rate != null ? (
                      <span className="text-[11px] font-medium text-slate-500">{t('workflow.detail.forecastUsage', { rate: Number(f.usage_rate).toLocaleString() })}</span>
                    ) : (
                      <span className="text-[11px] text-slate-400">{t('workflow.detail.forecastNoRate')}</span>
                    )}
                  </div>
                  <p className={`mt-1.5 font-display text-lg font-bold ${tone.text}`}>
                    {f.status === 'overdue'
                      ? t('workflow.detail.forecastOverdueBy', { km: Number(Math.abs(f.overdue_km ?? f.remaining_km ?? 0)).toLocaleString() })
                      : t('workflow.detail.forecastDueIn', { km: Number(Math.max(0, f.remaining_km ?? 0)).toLocaleString() })}
                  </p>
                  {projected && (
                    <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                      <Icon.Calendar className="h-3.5 w-3.5 text-slate-400" />
                      {t('workflow.detail.forecastProjected', { date: projected })}
                    </p>
                  )}
                </div>
              </Section>
            );
          })()}

          {/* Mileage — the car's REAL odometer up top, then a forward story of every reading and how
              it happened (test drive → garage → rentals → manual fixes), this ticket highlighted. */}
          <Section
            title={t('workflow.detail.mileage')}
            icon={<Icon.Gauge className="h-3.5 w-3.5 text-slate-400" />}
            action={mileage?.total > 0 ? (
              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                {t('workflow.detail.mileageChanges', { n: mileage.total })}
              </span>
            ) : null}
          >
            {/* Headline — the real current odometer + the baseline anchor. */}
            <div className="flex items-stretch gap-3">
              <div className="flex-1 rounded-lg bg-indigo-50 px-3 py-2.5 text-center">
                <p className="text-[11px] uppercase tracking-wide text-indigo-400">{t('workflow.detail.currentMileage')}</p>
                <p className="font-mono text-lg font-bold text-indigo-700">
                  {mileage?.current != null ? `${Number(mileage.current).toLocaleString()} ${t('workflow.stage.kmShort')}` : '—'}
                </p>
              </div>
              {mileage?.baseline != null && (
                <div className="flex-1 rounded-lg bg-slate-50 px-3 py-2.5 text-center">
                  <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('workflow.detail.baselineMileage')}</p>
                  <p className="font-mono text-sm font-bold text-slate-700">{Number(mileage.baseline).toLocaleString()}</p>
                </div>
              )}
            </div>

            {mileage?.distance != null && (
              <p className="mt-2 text-[11px] text-slate-400">
                {t('workflow.detail.mileageSummary', { km: Number(mileage.distance).toLocaleString(), n: mileage.total })}
              </p>
            )}

            {/* Odometer photos captured on this ticket (pickup / return). */}
            {photos.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {photos.slice(0, 6).map((p) => (
                  <a
                    key={p.id}
                    href={p.url}
                    target="_blank"
                    rel="noreferrer"
                    className="group relative h-16 w-16 overflow-hidden rounded-lg ring-1 ring-slate-200"
                    title={`${p.phase === 'post' ? t('workflow.detail.back') : t('workflow.detail.out')} · ${fmtDateTime(p.captured_at) || ''}`}
                  >
                    <img src={p.url} alt="odometer" className="h-full w-full object-cover transition group-hover:scale-105" />
                    <span className="absolute bottom-0 inset-x-0 bg-slate-900/60 px-1 py-0.5 text-center text-[9px] font-semibold uppercase text-white">
                      {p.phase === 'post' ? t('workflow.detail.back') : t('workflow.detail.out')}
                    </span>
                  </a>
                ))}
              </div>
            )}

            {/* The story — one line per reading, read BOTTOM-TO-TOP: the first reading sits at the
                bottom and each later one stacks above it, with an up-arrow between steps so the flow
                reads upward. Each row is labelled by how the reading came to be. */}
            {mileageLoading ? (
              <div className="mt-4 space-y-2">
                <Skeleton className="h-4 w-2/3" />
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-4 w-3/5" />
              </div>
            ) : mileageErr ? (
              <p className="mt-3 text-xs text-red-500">{t('workflow.detail.mileageLoadError')}</p>
            ) : mileage?.history?.length > 0 ? (
              <>
                {mileage.truncated && (
                  <p className="mt-3 text-[11px] text-slate-400">
                    {t('workflow.detail.mileageTruncated', { n: mileage.shown, total: mileage.total })}
                  </p>
                )}
                <ol className="mt-3">
                  {mileage.history.map((_, ri) => {
                    // Render in REVERSE so the story flows bottom-to-top: the oldest reading is at the
                    // bottom, the newest at the top. `i` stays the chronological index (0 = oldest) so
                    // the "then …" connector and every label read correctly up the chain.
                    const i = mileage.history.length - 1 - ri;
                    const e = mileage.history[i];
                    const Ico = MILEAGE_ICON[e.how] || Icon.Gauge;
                    const hasEarlierBelow = ri < mileage.history.length - 1; // an up-arrow links to the step below
                    return (
                      <Fragment key={i}>
                        <li className={`relative flex gap-2.5 rounded-lg px-2 py-1.5 ${e.this_ticket ? 'bg-indigo-50/70 ring-1 ring-inset ring-indigo-100' : ''}`}>
                          {/* colour-coded dot marking how the reading was produced */}
                          <span
                            className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white"
                            style={{ backgroundColor: MILEAGE_TONE[e.how] || '#94a3b8' }}
                          />
                          <div className="min-w-0 flex-1">
                            <div className="flex items-baseline justify-between gap-2">
                              <p className="flex min-w-0 items-center gap-1.5 text-xs font-semibold text-slate-700">
                                <Ico className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                <span className="truncate">
                                  {i > 0 && <span className="font-normal text-slate-400">{t('workflow.detail.mileageThen')} </span>}
                                  {t(`workflow.detail.mileageHow.${e.how}`)}
                                  {e.ref && <span className="ms-1.5 font-normal text-slate-400">{e.ref}</span>}
                                </span>
                              </p>
                              <span className="shrink-0 text-[11px] text-slate-400">{fmtDateTime(e.at) || '—'}</span>
                            </div>
                            <div className="mt-0.5 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                              <span className="text-sm text-slate-600">
                                {t('workflow.detail.mileageReadKm', { km: Number(e.value).toLocaleString() })}
                              </span>
                              {e.delta != null && e.delta !== 0 && (
                                <span className={`font-mono text-[11px] font-semibold ${e.delta > 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                                  {e.delta > 0 ? '+' : ''}{Number(e.delta).toLocaleString()} {t('workflow.stage.kmShort')}
                                </span>
                              )}
                              {e.by && <span className="text-[11px] text-slate-500">{t('workflow.detail.mileageBy', { who: e.by })}</span>}
                              {e.this_ticket && (
                                <span className="rounded-full bg-indigo-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-indigo-600">
                                  {t('workflow.detail.mileageThisTicket')}
                                </span>
                              )}
                              {/* Odometer Continuity verdict — a Discrepancy (backward reading) or garage
                                  test-drive the Supervisor should eyeball. 'verified' is clean → no badge. */}
                              {e.flag?.status && e.flag.status !== 'verified' && (
                                <span className={`rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ${MILEAGE_FLAG_CLS[e.flag.status] || 'bg-slate-100 text-slate-600'}`}>
                                  {t(`workflow.odo.status.${e.flag.status}`)}
                                </span>
                              )}
                            </div>
                            {e.note && <p className="mt-0.5 text-[11px] italic text-slate-500">“{e.note}”</p>}
                          </div>
                        </li>
                        {/* up-arrow to the earlier step below → the story reads bottom-to-top */}
                        {hasEarlierBelow && (
                          <li aria-hidden className="flex justify-center py-0.5">
                            <svg className="h-3.5 w-3.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M6 11l6-6 6 6" /></svg>
                          </li>
                        )}
                      </Fragment>
                    );
                  })}
                </ol>
              </>
            ) : (
              <p className="mt-3 text-xs text-slate-400">{t('workflow.detail.noMileageHistory')}</p>
            )}
          </Section>

          {/* Timing */}
          {(timing.test_drive != null || timing.at_garage != null || timing.total_downtime != null) && (
            <Section title={t('workflow.detail.timing')} icon={<Icon.Clock className="h-3.5 w-3.5 text-slate-400" />}>
              <div className="grid grid-cols-3 gap-2 text-center">
                {[['testDrive', timing.test_drive], ['atGarage', timing.at_garage], ['totalDowntime', timing.total_downtime]].map(([k, v]) => (
                  <div key={k} className="rounded-lg bg-slate-50 px-2 py-2">
                    <p className="text-[10px] uppercase tracking-wide text-slate-400">{t(`workflow.detail.${k}`)}</p>
                    <p className="mt-0.5 font-display text-sm font-bold text-slate-800">{fmtDuration(v)}</p>
                  </div>
                ))}
              </div>
            </Section>
          )}

          {/* Why this garage was chosen — the durable data-driven decision record */}
          {tk.garage_recommendation && (
            <Section title={t('workflow.garageRec.why.title')} icon={<Icon.Wrench className="h-3.5 w-3.5 text-slate-400" />}>
              <WhyThisGarage rec={tk.garage_recommendation} t={t} />
            </Section>
          )}

          {/* Follow-up log */}
          {tk.follow_ups?.length > 0 && (
            <Section title={t('workflow.detail.followLog')} icon={<Icon.Users className="h-3.5 w-3.5 text-slate-400" />}>
              <ul className="space-y-2">
                {tk.follow_ups.map((f, i) => {
                  const text = typeof f === 'string' ? f : (f.note || f.text || '');
                  const by = typeof f === 'object' ? f.by : null;
                  const at = typeof f === 'object' ? f.at : null;
                  return (
                    <li key={i} className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                      {text}
                      {(by || at) && (
                        <span className="mt-0.5 block text-[11px] text-slate-400">
                          {by ? `${by}` : ''}{by && at ? ' · ' : ''}{at ? fmtDateTime(at) : ''}
                        </span>
                      )}
                    </li>
                  );
                })}
              </ul>
            </Section>
          )}

          {/* Watchers */}
          {tk.watchers?.length > 0 && (
            <Section title={t('workflow.detail.watchers')} icon={<Icon.Shield className="h-3.5 w-3.5 text-slate-400" />}>
              <div className="flex flex-wrap gap-1.5">
                {tk.watchers.map((w) => <Badge key={w.id} tone="slate">{w.name}</Badge>)}
              </div>
            </Section>
          )}
        </div>
      ) : null}
    </Drawer>
  );
}

// The board uses a short reason label (workflow.reasonShort.*) for the known reasons; fall back to
// the raw value for anything custom.
function REASON_LABEL(t, reason) {
  const known = ['test_drive', 'customer_reported', 'periodic'];
  return known.includes(reason) ? t(`workflow.reasonShort.${reason}`) : reason;
}

// The inspector's test-drive report is a STRUCTURED object ({symptoms[], severity,
// recommended_action, notes}) — not a string. It may also arrive as plain text on older tickets.
function hasReport(r) {
  if (!r) return false;
  if (typeof r === 'string') return r.trim().length > 0;
  return !!(r.symptoms?.length || r.severity || r.recommended_action || r.notes);
}

// Per-symptom fix status, matched to the fault-tasks by symptom text — so the Inspector report shows a
// "✓ Fixed" (or In progress / Cancelled) badge the moment the ticket is opened, not just in Manage faults.
const REPORT_STATUS_BADGE = {
  completed:   { label: '✓ Fixed',     cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  in_progress: { label: 'In progress', cls: 'bg-blue-50 text-blue-700 ring-blue-200' },
  cancelled:   { label: 'Cancelled',   cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
};
const normSymptom = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');

function TestDriveReport({ report, tasks = [] }) {
  if (typeof report === 'string') return <p className="mt-1 text-sm text-slate-700">{report}</p>;
  const taskBySymptom = {};
  tasks.forEach((tk) => { if (tk?.symptom) taskBySymptom[normSymptom(tk.symptom)] = tk; });
  const badgeFor = (s) => {
    const tk = taskBySymptom[normSymptom(s)];
    if (!tk) return null;
    if (tk.is_incorrect) return REPORT_STATUS_BADGE.cancelled;
    return REPORT_STATUS_BADGE[tk.status] || null;
  };
  return (
    <div className="mt-1 space-y-1.5 text-sm text-slate-700">
      {report.symptoms?.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {report.symptoms.map((s, i) => {
            const badge = badgeFor(s);
            return (
              <span key={i} className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-0.5 text-xs text-slate-600 ring-1 ring-inset ring-slate-200">
                {s}
                {badge && (
                  <span className={`ml-0.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ring-1 ring-inset ${badge.cls}`}>
                    {badge.label}
                  </span>
                )}
              </span>
            );
          })}
        </div>
      )}
      {report.recommended_action && (
        <p><span className="font-medium text-slate-500">→ </span>{report.recommended_action}</p>
      )}
      {report.notes && <p className="text-slate-600">{report.notes}</p>}
    </div>
  );
}
