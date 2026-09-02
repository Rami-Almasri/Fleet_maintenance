import { Fragment, useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { EmptyState } from '../ui/Misc';
import { useToast } from '../ui/Toast';
import { useCountUp } from '../ui/Gauge';
import FindingsList from './FindingsList';
import FindingApprovalPanel from './FindingApprovalPanel';
import TicketParts from './TicketParts';
import TicketContract from './TicketContract';
import FaultRecurrence, { hasFaultHistory } from './FaultRecurrence';
import SuggestedChecks from './SuggestedChecks';
import SystemChecksStatus from './SystemChecksStatus';
import CheckpointModal from '../maintenance/CheckpointModal';
import CheckpointTimeline from '../maintenance/CheckpointTimeline';
import { getTicketCheckpoints, isCheckpointStage } from '../../lib/maintenanceCheckpoints';
import { copyText } from '../../lib/clipboard';
import { resolveAction, allows, ctaLabel, ago, fmtDuration, fmtDateTime, REASON_TONE, ORIGIN_LABEL, ORIGIN_TONE, custodyBlocked, custodyHolderName, isAtGarage, canOrderParts } from './meta';
import { fmtDate } from '../../lib/format';
import { SHOW_FINANCIALS } from '../../config/features';

// ─────────────────────────────────────────────────────────────────────────────
// A FULL-PAGE "command deck" for a single maintenance ticket — the rich view a
// notification deep-link (/maintenance-workflow/:id) lands on. It tells the whole
// story of one car's repair journey at a glance: who has it, where it is in the
// pipeline, how long it's been down (live), what was found, and what to do next.
// Built entirely from the existing /maintenance-tickets/{id} payload + the shared
// design-system primitives, so it stays in lock-step with the board and drawer.
// ─────────────────────────────────────────────────────────────────────────────

// workflow_status → lane accent colour (matches the board lanes + drawer pill).
const STATUS_TONE = {
  inspection_requested: '#d946ef', inspection_diagnostic: '#8b5cf6',
  inspection_pending: '#a855f7', awaiting_dispatch: '#f59e0b',
  in_transit: '#f97316', under_repair: '#f97316',
  ready_for_pickup: '#0ea5e9', in_our_park: '#0ea5e9',
  ready_for_reinspection: '#10b981', reinspection_failed: '#dc2626', closed: '#10b981', diagnostic_cleared: '#10b981',
};

// Icon per unified live-position phase (Maintenance::livePosition().phase) — the moving part of the ticket.
const POSITION_ICON = { in_transit: '🚚', in_workshop: '🔧', awaiting_pickup: '📦', awaiting_dispatch: '📋', awaiting_reinspection: '✅', under_diagnosis: '🔍', inspection_requested: '🚩', reinspection_failed: '⛔', ready_for_pickup: '🧳', in_our_park: '🏁' };

// The canonical six-stage journey. Each node maps to a handoff timestamp (who + when)
// and an icon; `STATUS_STEP` resolves the live status onto the index that's "current".
const JOURNEY = [
  { key: 'requested',  handoffKey: 'requested',      Glyph: Icon.Flag },
  { key: 'inspected',  handoffKey: 'inspected',      Glyph: Icon.Search },
  { key: 'dispatched', handoffKey: 'dispatched',     Glyph: Icon.Truck },
  { key: 'repair',     handoffKey: 'repair_started', Glyph: Icon.Wrench },
  { key: 'ready',      handoffKey: 'ready',          Glyph: Icon.Check },
  { key: 'closed',     handoffKey: 'closed',         Glyph: Icon.Shield },
];
const STATUS_STEP = {
  inspection_requested: 0,
  inspection_diagnostic: 1,
  inspection_pending: 2,
  awaiting_dispatch: 2,
  in_transit: 2,
  under_repair: 3,
  ready_for_pickup: 4,
  in_our_park: 4,
  ready_for_reinspection: 4,
  // Failed re-inspection sends the car back to the supervisor's dispatch decision — the journey regresses.
  reinspection_failed: 2,
  closed: 5,
  diagnostic_cleared: 5,
};
const isTerminal = (s) => s === 'closed' || s === 'diagnostic_cleared';
// Latin digits + Gregorian conventions stay put under Arabic — these sit in tabular-nums columns.
const numLocale = (lang) => (lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined);
const fmtAED = (n, lang) => `AED ${Number(n || 0).toLocaleString(numLocale(lang), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// A live HH:MM:SS (with leading days) clock that ticks every second until `end`
// freezes it. Used for the headline "downtime so far" counter.
function LiveDuration({ start, end, className = '' }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (end) return undefined; // closed → frozen, no need to tick
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [end]);

  if (!start) return <span className={className}>—</span>;
  const to = end ? new Date(end).getTime() : now;
  let s = Math.max(0, Math.floor((to - new Date(start).getTime()) / 1000));
  const d = Math.floor(s / 86400); s -= d * 86400;
  const h = Math.floor(s / 3600); s -= h * 3600;
  const m = Math.floor(s / 60); const sec = s - m * 60;
  const pad = (n) => String(n).padStart(2, '0');
  return (
    <span className={`font-mono tabular-nums ${className}`}>
      {d > 0 && <span>{d}<span className="opacity-50">d</span> </span>}
      {pad(h)}<span className="opacity-50">:</span>{pad(m)}<span className="opacity-50">:</span>{pad(sec)}
    </span>
  );
}

// The horizontal journey stepper on the dark deck: glowing node per stage, with the
// handoff actor + relative time beneath, animated connectors, current step pulsing.
function JourneyTimeline({ tk, tone, t }) {
  const current = STATUS_STEP[tk.workflow_status] ?? 0;
  const terminal = isTerminal(tk.workflow_status);

  return (
    <div className="-mx-1 overflow-x-auto pb-1">
      <div className="flex min-w-[640px] items-start">
        {JOURNEY.map((step, i) => {
          const done = i < current || (terminal && i <= current);
          const active = i === current && !terminal;
          const reached = done || active;
          const h = tk.handoffs?.[step.handoffKey];
          const { Glyph } = step;
          return (
            <div key={step.key} className="flex flex-1 items-start last:flex-none">
              {/* node + caption */}
              <div className="flex w-24 shrink-0 flex-col items-center text-center">
                <span className="relative flex h-11 w-11 items-center justify-center">
                  {active && (
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" style={{ background: tone }} />
                  )}
                  <span
                    className="relative flex h-11 w-11 items-center justify-center rounded-full ring-1 transition"
                    style={{
                      background: reached ? tone : 'rgba(255,255,255,0.06)',
                      color: reached ? '#fff' : 'rgba(255,255,255,0.45)',
                      boxShadow: active ? `0 0 18px ${tone}` : reached ? `0 0 0 4px ${tone}22` : 'none',
                      borderColor: 'transparent',
                    }}
                  >
                    {done ? <Icon.Check className="h-5 w-5" strokeWidth={2.4} /> : <Glyph className="h-5 w-5" strokeWidth={2} />}
                  </span>
                </span>
                <p className={`mt-2 text-[11px] font-semibold leading-tight ${reached ? 'text-white' : 'text-slate-500'}`}>
                  {t(`workflow.detail.handoff.${step.handoffKey === 'dispatched' && h?.is_recovery ? 'dispatched_recovery' : step.handoffKey}`)}
                </p>
                {h?.at ? (
                  <p className="mt-0.5 text-[10px] leading-tight text-slate-400">
                    {h.name ? <span className="block truncate text-slate-300">{h.name}</span> : null}
                    {ago(h.at, t)}
                  </p>
                ) : active ? (
                  <p className="mt-0.5 text-[10px] font-medium" style={{ color: tone }}>{t('In progress')}</p>
                ) : null}
              </div>

              {/* connector */}
              {i < JOURNEY.length - 1 && (
                <div className="mt-5 h-1 flex-1 overflow-hidden rounded-full bg-white/10">
                  <div
                    className="h-full rounded-full"
                    style={{
                      width: i < current || terminal ? '100%' : '0%',
                      background: `linear-gradient(90deg, ${tone}, ${tone}aa)`,
                      transition: 'width .9s cubic-bezier(.22,1,.36,1)',
                    }}
                  />
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// A labelled fact with an icon bubble, used in the Overview grid.
function Fact({ icon, label, value, mono }) {
  if (value == null || value === '') return null;
  return (
    <div className="flex items-start gap-2.5">
      <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">{icon}</span>
      <div className="min-w-0">
        <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
        <dd className={`text-sm text-slate-800 ${mono ? 'font-mono font-semibold tracking-wide' : 'font-medium'}`}>{value}</dd>
      </div>
    </div>
  );
}

// A light content panel with a titled header — the body building block.
function Panel({ title, icon, accent = '#6366f1', action, children, className = '' }) {
  return (
    <section className={`overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft ${className}`}>
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <span className="flex h-6 w-6 items-center justify-center rounded-lg" style={{ background: `${accent}1a`, color: accent }}>{icon}</span>
          {title}
        </h3>
        {action}
      </div>
      <div className="px-5 py-4">{children}</div>
    </section>
  );
}

// A compact stat tile (timers / odometer readings).
function StatTile({ label, value, tone = 'slate', sub }) {
  const TONE = { slate: 'text-slate-800', indigo: 'text-indigo-600', emerald: 'text-emerald-600', amber: 'text-amber-600' };
  return (
    <div className="rounded-xl bg-slate-50 px-3 py-2.5 text-center ring-1 ring-inset ring-slate-100">
      <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-0.5 font-display text-base font-bold tabular-nums ${TONE[tone] || TONE.slate}`}>{value}</p>
      {sub && <p className="text-[10px] text-slate-400">{sub}</p>}
    </div>
  );
}

function REASON_LABEL(t, reason) {
  const known = ['test_drive', 'customer_reported', 'periodic'];
  return known.includes(reason) ? t(`workflow.reasonShort.${reason}`) : reason;
}
function hasReport(r) {
  if (!r) return false;
  if (typeof r === 'string') return r.trim().length > 0;
  return !!(r.symptoms?.length || r.severity || r.recommended_action || r.notes);
}
function TestDriveReport({ report }) {
  if (typeof report === 'string') return <p className="text-sm text-slate-700">{report}</p>;
  return (
    <div className="space-y-2 text-sm text-slate-700">
      {report.symptoms?.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {report.symptoms.map((s, i) => (
            <span key={i} className="inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-xs text-violet-700 ring-1 ring-inset ring-violet-200">{s}</span>
          ))}
        </div>
      )}
      {report.recommended_action && (
        <p className="rounded-lg bg-indigo-50/60 px-3 py-2 text-indigo-900 ring-1 ring-inset ring-indigo-100">
          <span className="font-semibold text-indigo-500">→ </span>{report.recommended_action}
        </p>
      )}
      {report.notes && <p className="text-slate-600">{report.notes}</p>}
    </div>
  );
}

// Count-up wrapper for odometer figures.
function CountUp({ value }) {
  const { lang } = useI18n();
  const v = useCountUp(Number(value) || 0);
  return <>{Math.round(v).toLocaleString(numLocale(lang))}</>;
}

export default function TicketCommandView({ ticketId, can, userId, onAct, reloadKey = 0, hideBack = false }) {
  const { t, tp, lang } = useI18n();
  const toast = useToast();
  const [tk, setTk] = useState(null);
  const [photos, setPhotos] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  // Garage Invoice Portal (team side): the freshly issued link + audit-in-flight guards.
  const [garageLink, setGarageLink] = useState(null); // whole-ticket link { path, expires_at }
  const [garages, setGarages] = useState([]);         // per-garage rows: { vendor_id, name, finding_count, link }
  const [linkBusy, setLinkBusy] = useState(null);     // scope currently generating: 'ticket' | vendor_id | null
  const [auditBusy, setAuditBusy] = useState(false);
  const [rejecting, setRejecting] = useState(false);  // toggles the reject-reason box
  const [rejectNote, setRejectNote] = useState('');
  const [partsOpenSignal, setPartsOpenSignal] = useState(0); // "Request Part" action → opens the Parts modal
  // Maintenance-progress checkpoints filed against THIS ticket — the same timeline shown on the dashboard
  // widget + vehicle profile, surfaced here so a checkpoint appears on the ticket's own command page.
  const [cp, setCp] = useState(null); // { monitor, responsibles, checkpoints, can_submit, can_manage, faults }
  const [cpOpen, setCpOpen] = useState(false);
  // What still stops this ticket closing, money-wise. The page used to be silent about it: a ticket with
  // three unbilled parts looked finished here and only confessed inside the drawer's Financial Story.
  const [blockers, setBlockers] = useState([]);

  const loadCheckpoints = useCallback(() => {
    if (!ticketId) return;
    getTicketCheckpoints(ticketId).then(setCp).catch(() => setCp(null));
  }, [ticketId]);

  const load = useCallback(async () => {
    if (!ticketId) return;
    setError(null);
    try {
      const detail = (await api.get(`/maintenance-tickets/${ticketId}`)).data.data;
      setTk(detail);
      if (detail?.vehicle_id) {
        api.get(`/maintenance-tickets/vehicle/${detail.vehicle_id}`)
          .then((r) => setPhotos((r.data?.data?.photos || []).filter((p) => p.body_part === 'odometer')))
          .catch(() => setPhotos([]));
      }
      loadCheckpoints();
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.detail.loadError'));
    } finally {
      setLoading(false);
    }
  }, [ticketId, t, loadCheckpoints]);

  // ── Garage Invoice Portal (team side) ──────────────────────────────────────
  // Issue a link. Pass a vendorId to scope it to one garage (it can then bill only its own faults); omit
  // it for a whole-ticket link. A car that passed through several garages gets one link per garage.
  const issueGarageLink = useCallback(async (vendorId = null) => {
    if (!tk) return;
    setLinkBusy(vendorId ?? 'ticket');
    try {
      const { data } = await api.post(
        `/maintenance-tickets/${tk.id}/garage-invoice-link`,
        vendorId ? { vendor_id: vendorId } : {},
      );
      if (vendorId) {
        setGarages((prev) => prev.map((g) => (g.vendor_id === vendorId
          ? { ...g, link: { path: data.data.path, expires_at: data.data.expires_at } }
          : g)));
      } else {
        setGarageLink(data.data);
      }
    } catch (e) {
      toast.error(e?.response?.data?.msg || t('workflow.garageInvoice.linkError'));
    } finally {
      setLinkBusy(null);
    }
  }, [tk, toast, t]);

  const auditGarageInvoice = useCallback(async (decision) => {
    const sub = tk?.pending_garage_invoice;
    if (!sub || auditBusy) return;
    setAuditBusy(true);
    try {
      const body = decision === 'reject' ? { note: rejectNote.trim() || null } : {};
      await api.post(`/garage-invoices/${sub.id}/${decision}`, body);
      toast.success(decision === 'accept' ? t('workflow.garageInvoice.accepted') : t('workflow.garageInvoice.rejected'));
      setRejecting(false);
      setRejectNote('');
      await load();
    } catch (e) {
      toast.error(e?.response?.data?.msg || t('workflow.garageInvoice.auditError'));
    } finally {
      setAuditBusy(false);
    }
  }, [tk, auditBusy, rejectNote, toast, t, load]);

  const markInvoiceReceived = useCallback(async () => {
    if (!tk || auditBusy) return;
    setAuditBusy(true);
    try {
      await api.post(`/maintenance-tickets/${tk.id}/finalize-invoice`);
      toast.success(t('workflow.awaitingInvoice.received'));
      await load();
    } catch (e) {
      toast.error(e?.response?.data?.msg || t('workflow.awaitingInvoice.error'));
    } finally {
      setAuditBusy(false);
    }
  }, [tk, auditBusy, toast, t, load]);

  // (Re)load on target change, after a parent action (reloadKey), and on a gentle
  // 12s heartbeat so a colleague advancing the ticket shows up without a refresh.
  useEffect(() => { load(); }, [load, reloadKey]);
  useEffect(() => {
    const id = setInterval(load, 12000);
    return () => clearInterval(id);
  }, [load]);

  // The closure blockers, on the same track as everything else here: best-effort, never blanking the page.
  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    api.get(`/maintenance-tickets/${ticketId}/financial-story`)
      .then((r) => { if (alive) setBlockers((r.data?.data ?? r.data)?.blockers || []); })
      .catch(() => { if (alive) setBlockers([]); });
    return () => { alive = false; };
  }, [ticketId, reloadKey]);

  // The garages that worked on this ticket — so a car that passed through several gets one link per garage.
  useEffect(() => {
    const inv = tk && (tk.is_ticket || tk.workflow_status === 'awaiting_invoice');
    if (!inv || !can('maintenance.manage')) { setGarages([]); return; }
    api.get(`/maintenance-tickets/${tk.id}/garage-invoice-garages`)
      .then((r) => setGarages(r.data?.data?.garages || []))
      .catch(() => setGarages([]));
  }, [tk, can]);

  // Shared copy/generate box for a garage-invoice link — reused for the whole-ticket link and each
  // per-garage link. `busyScope` matches linkBusy ('ticket' | vendor_id) so only its own button spins.
  const renderLinkBox = ({ link, busyScope, onGenerate, hint }) => {
    const busy = linkBusy === busyScope;
    const url = link ? `${window.location.origin}${link.path}` : '';
    return link ? (
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <input
            readOnly
            value={url}
            onFocus={(e) => e.target.select()}
            aria-label={t('workflow.garageInvoice.linkTitle')}
            className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
          />
          <Button
            size="sm"
            variant="secondary"
            onClick={async () => {
              // Over plain http there is no clipboard API — say what actually happened, and point at the
              // box above (the link is already on screen) rather than claiming a copy that never occurred.
              const ok = await copyText(url);
              if (ok) toast.success(t('workflow.garageInvoice.copied'));
              else toast.info(t('workflow.garageInvoice.copyBlocked'));
            }}
          >
            {t('workflow.garageInvoice.copy')}
          </Button>
        </div>
        {link.expires_at && <p className="text-[11px] text-slate-400">{t('workflow.garageInvoice.expires', { date: fmtDateTime(link.expires_at) })}</p>}
        <button onClick={onGenerate} disabled={busy} className="text-[11px] font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-700 disabled:cursor-not-allowed disabled:text-slate-300">{t('workflow.garageInvoice.regenerate')}</button>
      </div>
    ) : (
      <div className="space-y-2">
        {hint && <p className="text-xs text-slate-500">{hint}</p>}
        <Button size="sm" variant="primary" disabled={busy} onClick={onGenerate} className="w-full justify-center">
          {busy ? t('workflow.garageInvoice.generating') : t('workflow.garageInvoice.generate')}
        </Button>
      </div>
    );
  };

  // When embedded inside another page (e.g. the Car Status vehicle page) the host already provides a back
  // link, so hideBack suppresses this one to avoid a duplicate. Standalone route keeps it (default).
  const backLink = hideBack ? null : (
    <Link to="/maintenance-workflow" className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-slate-800">
      <Icon.ArrowRight className="h-4 w-4 rotate-180" />
      {t('workflow.board.title')}
    </Link>
  );

  if (loading && !tk) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
          {backLink}
          <Skeleton className="h-64 rounded-2xl" />
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Skeleton className="h-80 rounded-2xl lg:col-span-2" />
            <Skeleton className="h-80 rounded-2xl" />
          </div>
        </div>
      </div>
    );
  }

  if (error || !tk) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
          {backLink}
          <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            {error || t('workflow.detail.loadError')}
          </div>
        </div>
      </div>
    );
  }

  const tone = STATUS_TONE[tk.workflow_status] || '#64748b';
  const terminal = isTerminal(tk.workflow_status);
  const current = STATUS_STEP[tk.workflow_status] ?? 0;
  const pct = Math.round(((terminal ? JOURNEY.length : current) / JOURNEY.length) * 100);

  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  // "Mark Ready" is gated: the car can't be marked ready until every fault is fixed (or cancelled).
  const openFaults = tk.tasks_progress?.open ?? 0;
  const readyBlocked = act?.action === 'ready' && openFaults > 0;
  const readyHint = openFaults === 1
    ? t('Fix the 1 open fault first')
    : t('Fix all {n} open faults first', { n: openFaults });
  // Custody gate: a return/arrival leg may only be completed by the same driver who took the car.
  // custodyBlocked() is scoped to the gated states, so no per-action guard is needed here.
  const custodyLocked = custodyBlocked(tk, userId, can);
  const custodyHolder = custodyHolderName(tk, userId, can);
  const custodyHint = act?.action === 'arriveAtPark'
    ? t('Only {who} can complete the arrival at our park', { who: custodyHolder || t('the driver who collected the car from the garage') })
    : t('Only {who} can check it in', { who: custodyHolder || t('the driver who picked up the car') });
  // Follow-up is a supervisor (Waleed/Abdullah) monitoring action — an additive log they can file while
  // the car is out for repair, independent of the stage's primary garage-dispatch action. Same gate as
  // the detail drawer + the backend route (maintenance.delegate over the out-for-repair states).
  const canFollowUp = ['in_transit', 'under_repair', 'repair_review'].includes(tk.workflow_status) && can('maintenance.delegate');
  // "Manage faults" — same gate as the drawer/board card: Supervisor's dispatch authority once the
  // car is at the garage stage, but not once every fault is already fixed (ready_for_pickup).
  const canRoute = can('maintenance.delegate') && isAtGarage(tk)
    && tk.workflow_status !== 'ready_for_pickup' && (tk.tasks?.length > 0);
  // Invoice surfaces (request/enter/garage-link) apply to a committed ticket AND to one parked in
  // awaiting_invoice (repair done, invoice outstanding) — which is not a WF_TICKET_STATE so is_ticket is false.
  const invoicing = tk.is_ticket || tk.workflow_status === 'awaiting_invoice';
  const awaitingInvoice = tk.workflow_status === 'awaiting_invoice';

  // Maintenance progress: show the checkpoint timeline once there's history, the user may file one, or the
  // car is at a workshop stage where checkpoints are tracked. `cpMon` drives the at-a-glance ETA line.
  const cpMon = cp?.monitor;
  const hasAnyFaultHistory = (tk.tasks || []).some(hasFaultHistory);
  const showCheckpoints = !!cp && (cp.checkpoints?.length > 0 || cp.can_submit || isCheckpointStage(tk.workflow_status));
  const cpEtaTone = cpMon?.overdue ? 'bg-red-50 text-red-700 ring-red-200'
    : cpMon?.needs_update ? 'bg-amber-50 text-amber-700 ring-amber-200'
    : 'bg-slate-50 text-slate-600 ring-slate-200';
  const cpEtaText = !cpMon?.expected_on ? t('No ETA set')
    : cpMon.overdue
      ? (cpMon.days_over === 1 ? t('1 day overdue') : t('{n} days overdue', { n: cpMon.days_over }))
    : cpMon.eta_status === 'due_today' ? t('Due today')
    : (cpMon.days_left === 1 ? t('1 day left') : t('{n} days left', { n: cpMon.days_left }));

  const timing = tk.stage_timing?.durations || {};
  const odoDelta = tk.dispatch_odometer != null && tk.return_odometer != null ? tk.return_odometer - tk.dispatch_odometer : null;
  const downtimeStart = tk.handoffs?.requested?.at;
  const downtimeEnd = terminal ? tk.handoffs?.closed?.at || tk.handoffs?.ready?.at : null;
  const driverName = tk.dispatched_by_name || tk.assigned_driver_name;
  const delegated = tk.delegation?.status === 'driver_assigned' && tk.delegation.driver_name;
  const blockerTotal = blockers.reduce((s, b) => s + Number(b.amount || 0), 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between gap-3">
          {backLink}
          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600">
            <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-70" /><span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" /></span>
            {t('workflow.board.live')}
          </span>
        </div>

        {/* ── COMMAND DECK ─────────────────────────────────────────────────── */}
        <div className="relative overflow-hidden rounded-2xl bg-navy-950 px-6 py-7 ring-1 ring-white/10 sm:px-8">
          {/* subtle lane-tinted wash (flat, no drifting glow) */}
          <div className="pointer-events-none absolute inset-0" style={{ backgroundImage: `radial-gradient(38rem 26rem at 92% -20%, ${tone}22, transparent 60%)` }} />

          <div className="relative space-y-6">
            {/* identity row */}
            <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
              <div className="flex flex-wrap items-center gap-4">
                {/* license plate */}
                <span className="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/95 px-4 py-2 font-mono text-2xl font-bold tracking-[0.18em] text-navy-900 shadow-card">
                  <Icon.Car className="h-6 w-6 text-navy-500" strokeWidth={2} />
                  {tk.plate || `#${tk.id}`}
                </span>
                <div className="min-w-0">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-brand-300/90">{t('workflow.detail.eyebrow', { id: tk.id })}</p>
                  <h1 className="font-display text-2xl font-bold tracking-tight text-white">{tk.car || tk.plate || `#${tk.id}`}</h1>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {/* Customer Complaint — the top-priority flag, boldest chip so management dispatches
                        it first. Shown even before the severity grade. */}
                    {tk.trigger_reason === 'customer_reported' && (
                      <span
                        className="inline-flex items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-sm font-bold uppercase tracking-wide text-white shadow-sm ring-1 ring-inset ring-rose-700/40"
                        title={tk.customer_complaint || ''}
                      >
                        📣 {t('workflow.complaint.badge')}
                      </span>
                    )}
                    {/* Fault severity FIRST — the headline urgency the supervisor reads before all else. */}
                    {tk.fault_severity && (
                      <span className="inline-flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-1 text-sm font-bold text-slate-800 shadow-sm">
                        {tk.fault_severity_emoji} {t(`workflow.faultSeverity.${tk.fault_severity}`)}
                      </span>
                    )}
                    <span className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-bold" style={{ background: `${tone}26`, color: '#fff', boxShadow: `inset 0 0 0 1px ${tone}66` }}>
                      <span className="h-2 w-2 rounded-full" style={{ background: tone, boxShadow: `0 0 8px ${tone}` }} />
                      {tk.status_label}
                    </span>
                    {/* Live position — the unified "where is the car right now" (In Transit / In Workshop),
                        the moving part of THIS ticket. Pulses while the car is physically on the road. */}
                    {tk.position?.label && (
                      <span className={`inline-flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-1 text-sm font-bold text-slate-800 shadow-sm ${tk.position.moving ? 'animate-pulse' : ''}`} title={tk.position.detail || ''}>
                        {POSITION_ICON[tk.position.phase] || '📍'} {tk.position.label}{tk.position.garage ? ` · ${tk.position.garage}` : ''}
                        {tk.position.transfer && tk.position.destination ? ` → ${tk.position.destination}` : ''}
                      </span>
                    )}
                    {/* Transfer flag — a clear marker that this car is mid-move between garages. */}
                    {tk.position?.transfer && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-violet-100 px-3 py-1 text-sm font-bold text-violet-700 shadow-sm">
                        🔀 {t('workflow.position.transfer')}
                      </span>
                    )}
                    {tk.trigger_reason && (
                      <Badge tone={REASON_TONE[tk.trigger_reason] || 'slate'}>{REASON_LABEL(t, tk.trigger_reason)}</Badge>
                    )}
                    {/* Source — who found this. Independent of the reason chip beside it. */}
                    {tk.request_origin && (
                      <Badge tone={ORIGIN_TONE[tk.request_origin] || 'slate'}>
                        {tk.request_origin_label || ORIGIN_LABEL[tk.request_origin]}
                      </Badge>
                    )}
                  </div>
                </div>
              </div>

              {/* live downtime + progress + primary CTA */}
              <div className="flex items-center gap-5">
                <div className="text-end">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    {terminal ? t('workflow.detail.totalDowntime') : t('Downtime · live')}
                  </p>
                  <LiveDuration start={downtimeStart} end={downtimeEnd} className="font-display text-3xl font-bold text-white" />
                  <p className="mt-0.5 text-[11px] text-slate-400">
                    {pct}% · {t(`workflow.detail.handoff.${JOURNEY[Math.min(current, JOURNEY.length - 1)].handoffKey}`)}
                  </p>
                </div>
                {allowed && custodyLocked && (
                  <div className="flex flex-col items-end gap-1">
                    <span className="text-[11px] font-medium text-amber-300">{custodyHint}</span>
                  </div>
                )}
                {allowed && !custodyLocked && (
                  <div className="flex flex-col items-end gap-1">
                    <Button variant={act.variant} disabled={readyBlocked} title={readyBlocked ? readyHint : undefined} onClick={() => onAct(act.action, tk)}>
                      {ctaLabel(t, tk)}
                      <Icon.ArrowRight className="h-4 w-4" />
                    </Button>
                    {readyBlocked && <span className="text-[11px] font-medium text-amber-300">{readyHint}</span>}
                  </div>
                )}
              </div>
            </div>

            {/* progress meter */}
            <div className="h-1.5 overflow-hidden rounded-full bg-white/10">
              <div className="h-full rounded-full" style={{ width: `${pct}%`, background: `linear-gradient(90deg, ${tone}, ${tone}aa)`, boxShadow: `0 0 12px ${tone}99`, transition: 'width 1s cubic-bezier(.22,1,.36,1)' }} />
            </div>

            {/* the journey */}
            <JourneyTimeline tk={tk} tone={tone} t={t} />
          </div>
        </div>

        {/* THE HOLD — above even the money blockers, because it is the only one that stops the ticket
            dead rather than stopping it closing. A finding the car's own data disagrees with (an oil
            change on a car with most of its interval left, the same fault raised twice) is parked here
            until somebody with the authority approves or refuses it. See [[FindingApprovalService]]. */}
        <FindingApprovalPanel
          ticketId={tk.id}
          pending={tk.pending_finding_approvals || []}
          onDecided={load}
        />

        {/* Closure blockers — directly under the deck, because a ticket that cannot close is the most
            important thing on this page and it used to be invisible here. Each line names the amount and
            links to the screen that clears it, the same wording the drawer's Financial Story uses. */}
        {blockers.length > 0 && (
          <div className="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3">
            <div className="flex items-center gap-2">
              <Icon.Alert className="h-4 w-4 shrink-0 text-amber-700" />
              <p className="text-sm font-bold text-amber-900">
                {tp('workflow.detail.deck.moneyBlocked', blockers.length)}
                {SHOW_FINANCIALS && blockerTotal > 0 && <span className="ms-1 tabular-nums">· {fmtAED(blockerTotal, lang)}</span>}
              </p>
            </div>
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
              {blockers.map((b, i) => (
                <li key={`${b.code}-${i}`} className="rounded-lg bg-white/70 px-2.5 py-2">
                  <div className="flex items-start justify-between gap-3">
                    <p className="text-[12px] text-amber-900">{b.message}</p>
                    {SHOW_FINANCIALS && b.amount > 0 && (
                      <span className="shrink-0 text-[12px] font-semibold tabular-nums text-amber-900">{fmtAED(b.amount, lang)}</span>
                    )}
                  </div>
                  <p className="mt-0.5 text-[11px] font-medium text-amber-700">
                    {b.action}
                    {b.route && <Link to={b.route} className="ms-1 text-sky-700 underline hover:text-sky-800">{t('workflow.detail.deck.openBlocker')}</Link>}
                  </p>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* delegation ribbon */}
        {delegated && (
          <div className="flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50/70 px-4 py-3 text-sm font-medium text-indigo-700">
            <Icon.Car className="h-4 w-4 shrink-0" />
            <span>{t('workflow.delegation.assigned')} · {tk.delegation.driver_name}</span>
            {tk.delegation.task && <span className="ms-auto rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs">{t(`workflow.delegation.${tk.delegation.task}`)}</span>}
          </div>
        )}

        {/* ── BODY ─────────────────────────────────────────────────────────── */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {/* main column */}
          <div className="space-y-6 lg:col-span-2">
            <Panel title={t('workflow.detail.overview')} icon={<Icon.Info className="h-4 w-4" />} accent={tone}
              action={
                <Link to={`/vehicles/${tk.vehicle_id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                  <Icon.Car className="h-3.5 w-3.5" /> {t('workflow.detail.openVehicle')}
                </Link>
              }
            >
              <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Fact icon={<Icon.Spark className="h-4 w-4" />} label={t('workflow.detail.reason')} value={tk.trigger_reason && REASON_LABEL(t, tk.trigger_reason)} />
                <Fact icon={<Icon.Flag className="h-4 w-4" />} label={t('workflow.detail.source')} value={tk.request_origin_label || ORIGIN_LABEL[tk.request_origin] || null} />
                <Fact icon={<Icon.Wrench className="h-4 w-4" />} label={t('workflow.detail.type')} value={tk.maintenance_type_label} />
                <Fact icon={<Icon.Alert className="h-4 w-4" />} label={t('workflow.detail.severity')} value={tk.severity} />
                <Fact icon={<Icon.Shield className="h-4 w-4" />} label={t('workflow.detail.garage')} value={tk.garage} />
                <Fact icon={<Icon.Users className="h-4 w-4" />} label={t('workflow.detail.driver')} value={driverName} />
                <Fact icon={<Icon.Invoice className="h-4 w-4" />} label={t('workflow.detail.contract')} value={tk.linked_contract_no} mono />
                {SHOW_FINANCIALS && tk.cost != null && (
                  <Fact icon={<Icon.Coins className="h-4 w-4" />} label={t('workflow.detail.cost')} value={`AED ${Number(tk.cost).toLocaleString(numLocale(lang))}`} />
                )}
              </dl>
              {/* The issue text is stored in customer_complaint for EVERY origin (a real complaint, a
                  driver's test-drive note, or the system's routine agenda), so label it by trigger_reason —
                  only a genuine customer_reported ticket is a "Customer complaint" (amber); the rest are
                  neutral notes. */}
              {tk.customer_complaint && (
                tk.trigger_reason === 'customer_reported' ? (
                  <div className="mt-4 rounded-xl border border-amber-100 bg-amber-50/60 px-4 py-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-amber-500">{t('workflow.detail.complaint')}</p>
                    <p className="mt-1 text-sm text-amber-900">{tk.customer_complaint}</p>
                  </div>
                ) : (
                  <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{t(tk.trigger_reason === 'periodic' ? 'workflow.detail.agenda' : 'workflow.detail.driverNote')}</p>
                    <p className="mt-1 text-sm text-slate-700">{tk.customer_complaint}</p>
                    {/* The agenda is a frozen snapshot and, for a scheduled check, the same standing
                        safety list on every idle car. What THIS car keeps coming back for goes right
                        under it — same service as every other surface. */}
                    <SuggestedChecks vehicleId={tk.vehicle_id} />
                  </div>
                )
              )}
              {/* …and the ADDRESSABLE obligations behind that agenda sentence. Read-only: the Decide
                  step stays the only place a check can be answered. See [[SystemChecksStatus]] on why
                  a supervisor needs to see an unanswered check without being able to answer it. */}
              <SystemChecksStatus checks={tk.required_checks} t={t} />
            </Panel>

            {hasReport(tk.test_drive_report) && (
              <Panel title={t('workflow.detail.report')} icon={<Icon.Search className="h-4 w-4" />} accent="#8b5cf6">
                <TestDriveReport report={tk.test_drive_report} />
              </Panel>
            )}

            {/* Faults this car has had BEFORE, and the sign-off on repairing them again. Reads the
                ticket's TASKS (the authoritative fault records) — not the findings blob below, which is
                null on a large share of tickets. Renders nothing when no fault has history. */}
            {hasAnyFaultHistory && (
              <Panel title={t('workflow.detail.faultHistory')} icon={<Icon.Refresh className="h-4 w-4" />} accent="#f59e0b">
                <FaultRecurrence tasks={tk.tasks || []} />
              </Panel>
            )}

            <Panel title={t('workflow.detail.findings')} icon={<Icon.Flag className="h-4 w-4" />} accent="#f59e0b">
              {tk.findings?.length ? <FindingsList findings={tk.findings} tasks={tk.tasks} /> : (
                <EmptyState title={t('workflow.detail.noFindings')} icon={<Icon.Flag className="h-6 w-6" />} />
              )}
            </Panel>

            {/* The contract this visit was opened under — the office's record of the same visit, and
                every event the app logged inside its window. Renders nothing on a workshop-only ticket. */}
            {tk.contract && (
              <Panel title={t('workflow.contract.title')} icon={<Icon.Invoice className="h-4 w-4" />} accent="#0ea5e9">
                <TicketContract contract={tk.contract} vehicleId={tk.vehicle_id} />
              </Panel>
            )}

            {/* Parts — requested against this ticket, filed here in-context. The /parts board still owns
                the review → approve → purchase → install lifecycle. */}
            {can('parts.view') && (
              <TicketParts
                ticketId={ticketId}
                ticket={tk}
                tasks={tk.tasks}
                canView={can('parts.view')}
                canRequest={can('parts.request')}
                openSignal={partsOpenSignal}
                onChanged={load}
                variant="command"
              />
            )}

            {/* Maintenance Progress — the checkpoint timeline filed against this ticket. Read-only here;
                "File update" opens the same full checkpoint modal used by the dashboard + vehicle profile,
                so a checkpoint stored anywhere shows up on this ticket's command page. */}
            {showCheckpoints && (
              <Panel
                title={t('Maintenance Progress')}
                icon={<Icon.Clock className="h-4 w-4" />}
                accent="#6366f1"
                action={(cp.can_submit || cp.can_manage) ? (
                  <button
                    type="button"
                    onClick={() => setCpOpen(true)}
                    className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                  >
                    <Icon.Spark className="h-3.5 w-3.5" /> {cp.can_submit ? t('File update') : t('Manage')}
                  </button>
                ) : null}
              >
                <div className={`mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-3 py-2 text-sm ring-1 ${cpEtaTone}`}>
                  <span className="font-semibold">{cpEtaText}</span>
                  {cpMon?.expected_on && (
                    <span className="text-xs opacity-80">
                      {cpMon.is_estimated
                        ? t('Expected {date} (estimated)', { date: fmtDate(cpMon.expected_on) })
                        : t('Expected {date}', { date: fmtDate(cpMon.expected_on) })}
                    </span>
                  )}
                  {cpMon?.needs_update && <span className="text-xs font-medium">· {t('Checkpoint required')}</span>}
                </div>
                <CheckpointTimeline checkpoints={cp.checkpoints || []} />
              </Panel>
            )}

            {/* Audit trail — newest at the top, with an up-arrow FROM each stage TO the next one above,
                so the progression is explicit. Dwell time is computed in chronological order (the gap to
                the stage that came after), then the list is flipped for display. */}
            <Panel title={t('workflow.detail.timeline')} icon={<Icon.Activity className="h-4 w-4" />} accent={tone}>
              <ol className="relative">
                {(() => {
                  const reached = JOURNEY.filter((s) => tk.handoffs?.[s.handoffKey]); // chronological
                  if (!reached.length) {
                    return <li className="text-sm text-slate-400">{t('workflow.detail.noTimeline')}</li>;
                  }
                  // Dwell of each stage = the gap to the NEXT reached milestone (chronological), or up to
                  // now for the latest milestone on a still-open ticket ("so far").
                  const rows = reached.map((step, i) => {
                    const h = tk.handoffs[step.handoffKey];
                    const nextH = i + 1 < reached.length ? tk.handoffs[reached[i + 1].handoffKey] : null;
                    const endAt = nextH?.at || (terminal ? null : new Date().toISOString());
                    const dwell = h.at && endAt ? (new Date(endAt) - new Date(h.at)) / 1000 : null;
                    return { step, h, dwell, running: !nextH?.at && !terminal };
                  });
                  // Display newest-first (flip): the latest stage sits on top, each arrow points UP to it.
                  return rows.slice().reverse().map((r, ri) => {
                    const hasEarlierBelow = ri < rows.length - 1;
                    // 'dispatched'/'repair_started' carry odometer + garage/destination context so the
                    // timeline reads as pickup → transit → arrival instead of a bare timestamp.
                    const labelKey = r.step.handoffKey === 'dispatched' && r.h.is_recovery ? 'dispatched_recovery' : r.step.handoffKey;
                    const subline = [
                      r.h.odometer != null ? `${Number(r.h.odometer).toLocaleString(numLocale(lang))} km` : null,
                      r.step.handoffKey === 'dispatched' ? r.h.destination : (r.step.handoffKey === 'repair_started' ? r.h.garage : null),
                    ].filter(Boolean).join(' · ');
                    return (
                      <Fragment key={r.step.key}>
                        <li className="relative flex gap-3 px-1 py-1">
                          <span className="mt-1 h-3 w-3 shrink-0 rounded-full ring-4 ring-white" style={{ background: tone }} />
                          <div className="min-w-0 flex-1">
                            <div className="flex items-baseline justify-between gap-2">
                              <p className="text-sm font-semibold text-slate-800">{t(`workflow.detail.handoff.${labelKey}`)}</p>
                              {r.dwell != null && (
                                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${r.running ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-500'}`}>
                                  {r.running ? t('{d} so far', { d: fmtDuration(r.dwell) }) : fmtDuration(r.dwell)}
                                </span>
                              )}
                            </div>
                            <p className="text-xs text-slate-500">
                              {r.h.name ? `${r.h.name} · ` : ''}{fmtDateTime(r.h.at)}
                              {r.h.at && <span className="text-slate-400"> · {ago(r.h.at, t)}</span>}
                            </p>
                            {subline && <p className="text-xs text-slate-400">{subline}</p>}
                          </div>
                        </li>
                        {/* arrow FROM this stage TO the next one (above) → explicit workflow progression */}
                        {hasEarlierBelow && (
                          <li aria-hidden className="flex ps-1 py-0.5">
                            <svg className="h-4 w-4" style={{ color: tone }} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M6 11l6-6 6 6" /></svg>
                          </li>
                        )}
                      </Fragment>
                    );
                  });
                })()}
              </ol>
            </Panel>

            {tk.follow_ups?.length > 0 && (
              <Panel title={t('workflow.detail.followLog')} icon={<Icon.Users className="h-4 w-4" />} accent="#06b6d4">
                <ul className="space-y-2.5">
                  {tk.follow_ups.map((f, i) => {
                    const text = typeof f === 'string' ? f : (f.note || f.text || '');
                    const by = typeof f === 'object' ? f.by : null;
                    const at = typeof f === 'object' ? f.at : null;
                    return (
                      <li key={i} className="rounded-xl border border-slate-100 bg-slate-50/70 px-3.5 py-2.5 text-sm text-slate-700">
                        {text}
                        {(by || at) && (
                          <span className="mt-1 block text-[11px] text-slate-400">{by || ''}{by && at ? ' · ' : ''}{at ? fmtDateTime(at) : ''}</span>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </Panel>
            )}
          </div>

          {/* sidebar */}
          <div className="space-y-6">
            {/* Take action */}
            {(allowed || canFollowUp || canRoute || (can('parts.request') && canOrderParts(tk))) && (
              <Panel title={t('Take action')} icon={<Icon.Spark className="h-4 w-4" />} accent={tone}>
                <div className="flex flex-col gap-2">
                  {allowed && custodyLocked && (
                    <span className="text-center text-[11px] font-medium text-amber-600">{custodyHint}</span>
                  )}
                  {allowed && !custodyLocked && (
                    <>
                      <Button variant={act.variant} disabled={readyBlocked} title={readyBlocked ? readyHint : undefined} onClick={() => onAct(act.action, tk)} className="w-full justify-center">
                        {ctaLabel(t, tk)}
                      </Button>
                      {readyBlocked && <span className="text-center text-[11px] font-medium text-amber-600">{readyHint}</span>}
                    </>
                  )}
                  {/* Manage faults — mark a fault fixed/reopen/transfer the car. Same gate as the drawer
                      and board card so this full-page view has feature parity. */}
                  {canRoute && (
                    <Button variant="secondary" onClick={() => onAct('route', tk)} className="w-full justify-center">
                      <Icon.Wrench className="h-4 w-4" /> {t('workflow.task.route')}
                    </Button>
                  )}
                  {/* Follow-up — supervisors (Waleed/Abdullah) log a monitoring note while the car is in
                      the workshop. Additive: never blocks or replaces the garage-dispatch primary action. */}
                  {canFollowUp && (
                    <Button variant="secondary" onClick={() => onAct('followup', tk)} className="w-full justify-center">
                      {t('workflow.cardAction.followup')}
                    </Button>
                  )}
                  {/* Request Part — file a part request against this ticket without leaving the page. Only
                      while the car is being inspected or in the workshop. Mirrors the drawer + Parts-card gate. */}
                  {can('parts.request') && canOrderParts(tk) && (
                    <Button variant="secondary" onClick={() => setPartsOpenSignal((n) => n + 1)} className="w-full justify-center">
                      <Icon.Wrench className="h-4 w-4" /> {t('Request Part')}
                    </Button>
                  )}
                </div>
              </Panel>
            )}

            {/* ── Garage Invoice — AWAITING AUDIT review ─────────────────────────
                A garage self-submitted an invoice via its link; the team accepts (applies it to the
                ticket cost) or rejects it. Only the money owners audit. */}
            {tk.awaiting_audit && tk.pending_garage_invoice && can('maintenance.manage') && (() => {
              const s = tk.pending_garage_invoice;
              const hasVar = s.variance != null && Math.abs(Number(s.variance)) > 0.01;
              return (
                <Panel title={t('workflow.garageInvoice.auditTitle')} icon={<Icon.Invoice className="h-4 w-4" />} accent="#f59e0b">
                  <div className="space-y-3">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-slate-500">{t('workflow.garageInvoice.itemized')}</span>
                      <span className="font-semibold tabular-nums text-slate-800">{fmtAED(s.itemized_total, lang)}</span>
                    </div>
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-slate-500">{t('workflow.garageInvoice.receipt')}</span>
                      <span className="font-semibold tabular-nums text-slate-800">{fmtAED(s.receipt_total, lang)}</span>
                    </div>
                    {hasVar && (
                      <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/10">
                        <span className="font-semibold">{t('workflow.garageInvoice.variance')}: {fmtAED(s.variance, lang)}</span>
                        {s.variance_explanation && <p className="mt-0.5">{s.variance_explanation}</p>}
                      </div>
                    )}
                    {s.garage_note && <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">“{s.garage_note}”</p>}
                    <div className="flex flex-wrap items-center gap-3 text-xs text-slate-400">
                      <span>{t('workflow.garageInvoice.lines', { n: (s.line_items || []).length })}</span>
                      {s.receipt_photo_url && (
                        <a href={s.receipt_photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium text-indigo-600">
                          <Icon.Invoice className="h-3.5 w-3.5" />{t('workflow.garageInvoice.viewReceipt')}
                        </a>
                      )}
                    </div>

                    {rejecting ? (
                      <div className="space-y-2">
                        <textarea
                          value={rejectNote}
                          onChange={(e) => setRejectNote(e.target.value)}
                          rows={2}
                          placeholder={t('workflow.garageInvoice.rejectPlaceholder')}
                          className="w-full rounded-xl border border-slate-200 px-2.5 py-2 text-sm outline-none transition focus:border-red-400 focus:ring-4 focus:ring-red-500/10"
                        />
                        <div className="flex gap-2">
                          <Button size="sm" variant="danger" disabled={auditBusy} onClick={() => auditGarageInvoice('reject')} className="flex-1 justify-center">{t('workflow.garageInvoice.confirmReject')}</Button>
                          <Button size="sm" variant="secondary" onClick={() => { setRejecting(false); setRejectNote(''); }} className="flex-1 justify-center">{t('common.cancel')}</Button>
                        </div>
                      </div>
                    ) : (
                      <div className="flex gap-2 pt-1">
                        <Button size="sm" variant="primary" disabled={auditBusy} onClick={() => auditGarageInvoice('accept')} className="flex-1 justify-center">{t('workflow.garageInvoice.accept')}</Button>
                        <Button size="sm" variant="secondary" disabled={auditBusy} onClick={() => setRejecting(true)} className="flex-1 justify-center">{t('workflow.garageInvoice.reject')}</Button>
                      </div>
                    )}
                  </div>
                </Panel>
              );
            })()}

            {/* ── Awaiting invoice — the outstanding invoice landed ──────────────
                Repair done, car back in service, invoice pending. "Mark received" closes it out (entering
                line-items / accepting the garage portal also closes it automatically). */}
            {awaitingInvoice && can('maintenance.manage') && (
              <Panel title={t('workflow.awaitingInvoice.title')} icon={<Icon.Invoice className="h-4 w-4" />} accent="#f59e0b">
                <div className="space-y-2">
                  <p className="text-xs text-slate-500">
                    {t('workflow.awaitingInvoice.waiting', { n: tk.invoice_days_waiting ?? 0 })}
                    {tk.invoice_overdue && <span className="ms-1 font-semibold text-red-600">· {t('workflow.awaitingInvoice.overdue')}</span>}
                  </p>
                  <Button size="sm" variant="primary" disabled={auditBusy} onClick={markInvoiceReceived} className="w-full justify-center">
                    {t('workflow.awaitingInvoice.markReceived')}
                  </Button>
                </div>
              </Panel>
            )}

            {/* ── Garage Invoice — issue the secure link(s) ──────────────────────
                Generate a short-lived link the garage opens (no login) to submit its own invoice. If the
                car passed through several garages, issue one link per garage — each bills only its work. */}
            {invoicing && can('maintenance.manage') && (
              <Panel title={t('workflow.garageInvoice.linkTitle')} icon={<Icon.Truck className="h-4 w-4" />} accent="#6366f1">
                {garages.length > 1 ? (
                  <div className="space-y-3">
                    <p className="text-xs text-slate-500">{t('workflow.garageInvoice.perGarageHint')}</p>
                    {garages.map((g) => (
                      <div key={g.vendor_id} className="rounded-xl border border-slate-200 p-3">
                        <div className="mb-2 flex items-center justify-between gap-2">
                          <span className="truncate text-sm font-semibold text-slate-700">{g.name}</span>
                          <span className="shrink-0 text-[11px] text-slate-400">{t('workflow.garageInvoice.faultCount', { n: g.finding_count })}</span>
                        </div>
                        {renderLinkBox({ link: g.link, busyScope: g.vendor_id, onGenerate: () => issueGarageLink(g.vendor_id) })}
                      </div>
                    ))}
                  </div>
                ) : (
                  renderLinkBox({
                    link: garageLink,
                    busyScope: 'ticket',
                    onGenerate: () => issueGarageLink(),
                    hint: t('workflow.garageInvoice.linkHint'),
                  })
                )}
              </Panel>
            )}

            {/* Live timers */}
            {(timing.test_drive != null || timing.at_garage != null || timing.total_downtime != null || downtimeStart) && (
              <Panel title={t('workflow.detail.timing')} icon={<Icon.Clock className="h-4 w-4" />} accent="#3b82f6">
                <div className="grid grid-cols-3 gap-2">
                  <StatTile label={t('workflow.detail.testDrive')} value={fmtDuration(timing.test_drive)} />
                  <StatTile label={t('workflow.detail.atGarage')} value={fmtDuration(timing.at_garage)} tone="amber" />
                  <StatTile label={t('workflow.detail.totalDowntime')} value={fmtDuration(timing.total_downtime)} tone="indigo" />
                </div>
                <div className="mt-3 flex items-center justify-between rounded-xl bg-navy-900 px-4 py-3 text-white">
                  <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-300">
                    <Icon.Activity className="h-4 w-4" style={{ color: tone }} />
                    {terminal ? t('workflow.detail.totalDowntime') : t('Down so far')}
                  </span>
                  <LiveDuration start={downtimeStart} end={downtimeEnd} className="text-lg font-bold" />
                </div>
              </Panel>
            )}

            {/* Odometer */}
            {(tk.dispatch_odometer != null || tk.return_odometer != null || photos.length > 0) && (
              <Panel title={t('workflow.detail.odometer')} icon={<Icon.Gauge className="h-4 w-4" />} accent="#10b981">
                <div className="grid grid-cols-3 gap-2">
                  <StatTile label={t('workflow.detail.out')} value={tk.dispatch_odometer != null ? <CountUp value={tk.dispatch_odometer} /> : '—'} />
                  <StatTile label={t('workflow.detail.back')} value={tk.return_odometer != null ? <CountUp value={tk.return_odometer} /> : '—'} />
                  <StatTile label={t('Δ km')} value={odoDelta != null ? <>+<CountUp value={odoDelta} /></> : '—'} tone="emerald" />
                </div>
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
                        <img src={p.url} alt={t('Odometer reading')} className="h-full w-full object-cover transition group-hover:scale-110" />
                        <span className="absolute inset-x-0 bottom-0 bg-slate-900/60 px-1 py-0.5 text-center text-[9px] font-semibold uppercase text-white">
                          {p.phase === 'post' ? t('workflow.detail.back') : t('workflow.detail.out')}
                        </span>
                      </a>
                    ))}
                  </div>
                )}
              </Panel>
            )}

            {/* Watchers */}
            {tk.watchers?.length > 0 && (
              <Panel title={t('workflow.detail.watchers')} icon={<Icon.Shield className="h-4 w-4" />} accent="#64748b">
                <div className="flex flex-wrap gap-1.5">
                  {tk.watchers.map((w) => <Badge key={w.id} tone="slate">{w.name}</Badge>)}
                </div>
              </Panel>
            )}
          </div>
        </div>
      </div>

      {/* Checkpoint modal — file/manage a progress update; on save, refresh the ticket + timeline so the
          new checkpoint appears immediately in the panel above. */}
      <CheckpointModal
        open={cpOpen}
        ticketId={ticketId}
        title={t('Maintenance Checkpoint')}
        subtitle={tk.plate || tk.car || `#${tk.id}`}
        onClose={() => setCpOpen(false)}
        onDone={(msg) => { if (msg) toast.success(msg); load(); }}
      />
    </div>
  );
}
