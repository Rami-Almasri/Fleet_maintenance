// "My Queue" — the role-scoped maintenance dashboard. Each role opens a focused view of only the
// work that is theirs to act on, fed by GET /maintenance-tickets/my-queue:
//
//   Abu Maroof (Inspector, maintenance.initiate):
//     · Pending Inspections   — Driver requests + in-progress diagnostics (Start test drive / Submit report)
//     · Final Re-inspections  — cars back from the garage, awaiting sign-off (Re-inspect)
//
//   Driver (Logistics, maintenance.logistics):
//     · Active Trips / Dispatches  — tickets to dispatch + cars in transit (Dispatch / Mark received)
//     · Cars Waiting for Follow-up — cars at the garage (Log follow-up / Mark ready)
//     · Return to Base             — signed off at the garage: Collect from Garage, then Arrive at Park
//                                    (both mandatory-photo checkpoints)
//     · Back from the Garage       — READ-ONLY: major-repair cars already back, awaiting the
//                                    inspector's final QA sign-off (tracking; minor repairs auto-close
//                                    on arrival and never appear here)
//
// A user holding both roles (a manager) sees every section. Actions reuse the shared modal. Every
// visible string comes from the central i18n catalog via t() so the page is fully bilingual + RTL.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import TicketActionModal from '../components/workflow/TicketActionModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import { resolveAction, stageAge } from '../components/workflow/meta';

// Primary action per workflow_status now lives in components/workflow/meta.js (resolveAction) — this
// page used to keep its own duplicate map, which drifted from the board's. ready_for_pickup needs the
// dynamic collect/arrive resolution meta.js already provides, so this page reuses it directly.

// Reason → badge tone. The visible label comes from workflow.reasonShort.<value>.
const REASON_TONE = { test_drive: 'violet', customer_reported: 'amber', periodic: 'blue' };

// The role sections, in display order, tagged with the role that owns them. A `readonly` section is
// for tracking only — its cards show a status, never an action button. Titles/hints come from
// queue.section.<key> in the catalog.
const SECTIONS = [
  { key: 'pending_inspections',        role: 'inspector',  tone: '#8b5cf6' },
  // On-Site (mobile) lane — minor jobs to service where the car is parked (Mark as Serviced). Teal to
  // match the workflow status colour; the same shared inspector/supervisor authority backs the action.
  { key: 'on_site',                    role: 'inspector',  tone: '#14b8a6' },
  { key: 'final_reinspections',        role: 'inspector',  tone: '#10b981' },
  { key: 'awaiting_dispatch_decision', role: 'dispatcher', tone: '#a855f7' },
  // QC: cars that came back but failed re-inspection — the supervisor re-dispatches. Stands out (red).
  { key: 'reinspection_failed',        role: 'dispatcher', tone: '#dc2626' },
  // Supervisors may also re-check cars back from the garage (shared with the Inspector).
  { key: 'final_reinspections',        role: 'dispatcher', tone: '#10b981' },
  { key: 'active_dispatches',          role: 'driver',     tone: '#3b82f6' },
  { key: 'waiting_followup',           role: 'driver',     tone: '#f97316' },
  // Signed off at the garage — the driver's RETURN leg (collect from garage, then arrive at our park).
  { key: 'return_to_base',             role: 'driver',     tone: '#0ea5e9' },
  { key: 'back_from_garage',           role: 'driver',     tone: '#10b981', readonly: true },
];

// The role views, each shown as its own tab (label from queue.tab.<key>). Only the tabs the
// user's roles unlock are rendered.
const ROLE_TABS = [
  { key: 'inspector',  tone: '#8b5cf6' },
  { key: 'dispatcher', tone: '#a855f7' },
  { key: 'driver',     tone: '#3b82f6' },
];

const allows = (can, perm) => (Array.isArray(perm) ? perm.some(can) : can(perm));

// How long the card has sat in its current stage — replaces the (misleading) creation date. Reddens
// once a stage overstays its SLA (e.g. Pending Dispatch > 4h) so the owner sees they're slacking.
function TimeInStage({ tk, t }) {
  const age = stageAge(tk, t);
  if (!age) return <span />;
  return (
    <span
      className={`inline-flex items-center gap-1 text-[10px] font-semibold ${age.over ? 'text-red-600' : 'text-slate-400'}`}
      title={t('queue.timeInStage')}
    >
      <Icon.Clock className="h-3 w-3" />
      {age.label}
    </span>
  );
}

function QueueCard({ tk, can, onAct, readonly = false }) {
  const { t } = useI18n();
  const reasonTone = REASON_TONE[tk.trigger_reason] || 'slate';
  const reasonLabel = REASON_TONE[tk.trigger_reason] ? t(`workflow.reasonShort.${tk.trigger_reason}`) : tk.trigger_reason;
  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  const canFollowUp = ['in_transit', 'under_repair'].includes(tk.workflow_status) && can('maintenance.logistics');

  return (
    <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3 transition hover:border-slate-200 hover:bg-white">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/vehicles/${tk.vehicle_id}`} className="font-mono text-sm font-bold text-indigo-600 hover:text-indigo-700">
            {tk.plate || `#${tk.id}`}
          </Link>
          <p className="truncate text-[11px] text-slate-400">{tk.car || t('common.none')}</p>
        </div>
        <Badge tone={reasonTone}>{reasonLabel}</Badge>
      </div>

      {tk.customer_complaint && <p className="mt-2 line-clamp-2 text-[11px] text-slate-500" title={tk.customer_complaint}>“{tk.customer_complaint}”</p>}
      {tk.status_label && (
        <p className="mt-2 text-[11px] font-medium text-slate-500">{tk.status_label}{tk.garage ? ` · ${tk.garage}` : ''}</p>
      )}

      <div className="mt-3 flex items-center justify-between gap-2">
        <TimeInStage tk={tk} t={t} />
        <div className="flex items-center gap-1.5">
          {readonly ? (
            // Tracking-only card (driver's "Back from the Garage"): the close/reopen is inspector-only,
            // so we show a status pill instead of an action the driver can't take.
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-600/20">
              <Icon.Check className="h-3 w-3" /> {t('queue.awaitingInspector')}
            </span>
          ) : (
            <>
              {canFollowUp && <Button size="sm" variant="ghost" onClick={() => onAct('followup', tk)}>{t('workflow.cardAction.followup')}</Button>}
              {allowed ? (
                <Button size="sm" variant={act.variant} onClick={() => onAct(act.action, tk)}>{t(`workflow.cardAction.${act.action}`)}</Button>
              ) : act ? (
                <span className="text-[10px] italic text-slate-300">{t(`workflow.cardAction.${act.action}`)} · {t('queue.noAccess')}</span>
              ) : null}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export default function MyMaintenanceQueue() {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();

  const [modal, setModal] = useState(null);
  const [vehicles, setVehicles] = useState([]);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);
  const [maintTypes, setMaintTypes] = useState([]); // permission-scoped classification values (technician → routine/breakdown)
  const [keywordMeta, setKeywordMeta] = useState({}); // keyword → { risk, tone, emoji, ar } (bilingual + risk chips)
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({}); // symptom → probable root causes (diagnostic step)
  const [drivers, setDrivers] = useState([]); // assign-dispatch picker — logistics drivers (dispatchers only)
  const [activeTab, setActiveTab] = useState(''); // 'inspector' | 'dispatcher' | 'driver'

  // Role-scoped queue: silent background revalidation every 8s (no skeleton flash, tab &
  // scroll preserved), paused while a modal is open so an in-flight action never shifts under us.
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/my-queue')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 8000,
    paused: () => !!modal,
  });

  const canDelegate = can('maintenance.delegate');

  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vehicle'), api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([v, g, f]) => {
        if (!alive) return;
        const vlist = v.data?.data;
        const allVehicles = Array.isArray(vlist) ? vlist : vlist?.items || [];
        // Only Ready + Rented cars can be flagged for inspection — never Sold/disposed
        // (the backend rejects them; mirrors the Maintenance Workflow board's filter).
        setVehicles(allVehicles.filter((veh) => ['ready', 'rented'].includes(veh.status)));
        const glist = g.data?.data;
        const all = Array.isArray(glist) ? glist : glist?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
        setFindingsCatalog(f.data?.data?.categories || []);
        setKeywordMeta(f.data?.data?.keyword_risk || {});
        setFaultCausesCatalog(f.data?.data?.fault_causes || {});
        setMaintTypes((f.data?.data?.maintenance_types || []).map((x) => x.value));
      })
      .catch(() => { /* pickers stay empty */ });
    return () => { alive = false; };
  }, []);

  // The assign-dispatch picker's driver list — only a dispatcher (maintenance.delegate) may load it.
  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => { /* leave the picker empty on failure */ });
    return () => { alive = false; };
  }, [canDelegate]);

  const roles = data?.roles || {};
  const sections = data?.sections || {};
  const counts = data?.counts || {};

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true }); // swap in the new queue with no skeleton flash
  };

  const isInspector = !!roles.inspector;
  const isDispatcher = !!roles.dispatcher;
  const isDriver = !!roles.driver;
  const canRequest = can('maintenance.logistics');

  // Default the active tab to the user's first available role; keep it valid as roles load.
  useEffect(() => {
    const first = isInspector ? 'inspector' : isDispatcher ? 'dispatcher' : isDriver ? 'driver' : '';
    setActiveTab((cur) => {
      const stillValid = (cur === 'inspector' && isInspector) || (cur === 'dispatcher' && isDispatcher) || (cur === 'driver' && isDriver);
      return stillValid ? cur : first;
    });
  }, [isInspector, isDispatcher, isDriver]);

  // The role tabs this user can open, and the sections that belong to the active tab.
  const availableTabs = ROLE_TABS.filter((tab) => roles[tab.key]);
  const activeSections = SECTIONS.filter((s) => s.role === activeTab);
  const sectionCount = (s) => counts[s.key] ?? (sections[s.key]?.length || 0);
  const tabCount = (roleKey) => SECTIONS.filter((s) => s.role === roleKey).reduce((n, s) => n + sectionCount(s), 0);
  const hasAnyTicket = activeSections.some((s) => sectionCount(s) > 0);

  const roleCount = [isInspector, isDispatcher, isDriver].filter(Boolean).length;
  const subtitleKey = roleCount > 1 ? 'both' : isInspector ? 'inspector' : isDispatcher ? 'dispatcher' : isDriver ? 'driver' : 'none';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('queue.title')} subtitle={t(`queue.subtitle.${subtitleKey}`)}>
          <Link to="/maintenance-workflow" className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('queue.fullPipeline')}</Link>
          {canRequest && (
            <Button onClick={() => setModal({ action: 'request' })}>
              <Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}
            </Button>
          )}
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {!loading && !error && availableTabs.length === 0 && (
          <EmptyState
            icon={<Icon.Wrench className="h-7 w-7" />}
            title={t('queue.noRoleTitle')}
            message={t('queue.noRoleMsg')}
          />
        )}

        {/* Role tabs — one per role the user holds. A single-role user sees just their own tab. */}
        {availableTabs.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {availableTabs.map((tab) => {
              const active = activeTab === tab.key;
              const count = tabCount(tab.key);
              return (
                <button
                  key={tab.key}
                  onClick={() => setActiveTab(tab.key)}
                  className={`inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition ${
                    active ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                  }`}
                >
                  <span className="h-2 w-2 rounded-full" style={{ background: tab.tone }} />
                  {t(`queue.tab.${tab.key}`)}
                  <span className={`rounded-full px-1.5 text-xs font-semibold ${active ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'}`}>{count}</span>
                </button>
              );
            })}
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {activeSections.map((s) => {
            const tickets = sections[s.key] || [];
            const count = counts[s.key] ?? tickets.length;
            return (
              <div key={s.key} className="flex flex-col rounded-2xl border border-slate-200/70 bg-white p-4 shadow-soft">
                <div className="flex items-center gap-2 pb-3">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ background: s.tone, boxShadow: `0 0 8px ${s.tone}66` }} />
                  <div className="min-w-0">
                    <p className="truncate font-display text-sm font-bold text-slate-900">{t(`queue.section.${s.key}.title`)}</p>
                    <p className="truncate text-[11px] text-slate-400">{t(`queue.section.${s.key}.hint`)}</p>
                  </div>
                  <span className="ms-auto font-display text-lg font-bold tabular-nums text-slate-900">{count}</span>
                </div>
                <div className="flex flex-col gap-2">
                  {loading ? (
                    <><Skeleton className="h-20 rounded-xl" /><Skeleton className="h-20 rounded-xl" /></>
                  ) : tickets.length === 0 ? (
                    <p className="py-6 text-center text-[11px] text-slate-300">{t('queue.nothingHere')}</p>
                  ) : (
                    tickets.map((tk) => <QueueCard key={tk.id} tk={tk} can={can} readonly={s.readonly} onAct={(action, ticket) => setModal({ action, ticket })} />)
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {!loading && availableTabs.length > 0 && !hasAnyTicket && (
          <p className="text-center text-sm text-slate-400">{t('queue.allCaught')}</p>
        )}
      </div>

      {/* Breakdown Intake — a technician reports a not-driveable car → ticket opens grounded in the
          dispatch queue, car set RED. */}
      {modal?.action === 'breakdown' && (
        <BreakdownIntakeModal
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {/* Complaint Triage — Abu Maroof handles a logged complaint (talk / resolve on-site / send in). */}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal
          ticket={modal.ticket}
          vehicles={vehicles}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}

      {modal && !['breakdown', 'triage'].includes(modal.action) && (
        <TicketActionModal
          action={modal.action}
          ticket={modal.ticket || null}
          vehicles={vehicles}
          garages={garages}
          findingsCatalog={findingsCatalog}
          keywordMeta={keywordMeta}
          faultCausesCatalog={faultCausesCatalog}
          assignableDrivers={drivers}
          allowedTypes={maintTypes}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}
    </div>
  );
}
