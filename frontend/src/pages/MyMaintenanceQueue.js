// "My Queue" — the role-scoped maintenance dashboard, rebuilt on the Cockpit+ (.opx) command-center
// design language (theme-aware: light "Platinum" / dark "Cockpit"). Each role opens a focused view of
// only the work that is theirs to act on, fed by GET /maintenance-tickets/my-queue:
//
//   Abu Maroof (Inspector, maintenance.initiate):
//     · Pending Inspections   — Driver requests + in-progress diagnostics (Start test drive / Submit report)
//     · On-Site Service       — minor jobs done where the car is parked (Mark as Serviced)
//     · Final Re-inspections  — cars back from the garage, awaiting sign-off (Re-inspect)
//
//   Supervisor (maintenance.delegate): Awaiting Dispatch / Review the Video / Came Back Broken / Final Re-inspections
//   Driver (Logistics, maintenance.logistics): Active Trips / Cars Waiting for Follow-up / Return to Base / Back from Garage
//
// A user holding several roles sees a tab per role. Actions reuse the shared modal. Every visible
// string comes from the central i18n catalog via t() so the page stays fully bilingual + RTL.
//
// The premium surface (KPI strip, role selector, glassmorphism cards, per-ticket timeline, live
// activity) is composed ENTIRELY from real ticket data — fault_severity for the priority rail,
// `handoffs` for the timeline, workflow_status for the progress bar, `position` for the live location,
// and the section `counts` for the KPIs. No fabricated telemetry.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import {
  CommandPanel, KpiTile, LiveActivityFeed, JourneyMap, OpsClock, severityTone,
} from '../components/ops';
import TicketActionModal from '../components/workflow/TicketActionModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import { resolveAction, stageAge, ago } from '../components/workflow/meta';
import './MyMaintenanceQueue.css';

// Reason → chip class. The visible label comes from workflow.reasonShort.<value>.
const REASON_CHIP = { test_drive: 'reserved', customer_reported: 'paused', periodic: 'rented' };

// The role sections, in display order, tagged with the role that owns them. A `readonly` section is
// for tracking only — its cards show a status, never an action button. Titles/hints come from
// queue.section.<key> in the catalog. `tone` drives the panel dot + card severity fallback.
const SECTIONS = [
  { key: 'pending_inspections',        role: 'inspector',  tone: '#8b5cf6' },
  { key: 'on_site',                    role: 'inspector',  tone: '#14b8a6' },
  { key: 'final_reinspections',        role: 'inspector',  tone: '#10b981' },
  { key: 'awaiting_dispatch_decision', role: 'dispatcher', tone: '#a855f7' },
  { key: 'reinspection_failed',        role: 'dispatcher', tone: '#dc2626' },
  { key: 'final_reinspections',        role: 'dispatcher', tone: '#10b981' },
  { key: 'active_dispatches',          role: 'driver',     tone: '#3b82f6' },
  { key: 'waiting_followup',           role: 'driver',     tone: '#f97316' },
  { key: 'return_to_base',             role: 'driver',     tone: '#0ea5e9' },
  { key: 'back_from_garage',           role: 'driver',     tone: '#10b981', readonly: true },
];

// The role views, each shown as its own tab. Only the tabs the user's roles unlock are rendered.
// `icon` = the Icon component; `rail` colors the selected role tile.
const ROLE_TABS = [
  { key: 'inspector',  icon: Icon.Search, rail: '#a78bfa' },
  { key: 'dispatcher', icon: Icon.Route,  rail: '#8b5cf6' },
  { key: 'driver',     icon: Icon.Truck,  rail: '#60a5fa' },
];

// workflow_status → how far along the repair pipeline (%). Drives the card's progress bar — a real,
// data-backed indicator of ticket lifecycle position (replacing the mockup's invented health meters).
const STAGE_PCT = {
  complaint_triage: 8, inspection_requested: 12, inspection_diagnostic: 22,
  inspection_pending: 32, on_site_pending: 40, awaiting_dispatch: 44,
  in_transit: 56, under_repair: 68, repair_review: 78, ready_for_pickup: 90,
  ready_for_reinspection: 84, reinspection_failed: 52, paused_returned_to_service: 46,
};

// The ordered handoff stamps that make up a ticket's real audit trail (the WhatsApp replacement). Each
// present stamp becomes a completed timeline node; the current stage caps it with a pulsing "now" node.
const HANDOFF_STEPS = [
  'requested', 'reviewed', 'inspected', 'dispatched', 'repair_started', 'ready', 'picked_up_from_garage', 'park_arrived', 'closed',
];

const allows = (can, perm) => (Array.isArray(perm) ? perm.some(can) : can(perm));

// Severity tone for a ticket: prefer the resource's explicit fault_severity_tone (red/orange/amber/
// green), else derive from the grade. Maps to the .opx severity vocabulary (crit/paused/ok).
function sevOf(tk) {
  const tone = tk.fault_severity_tone;
  if (tone === 'red') return 'crit';
  if (tone === 'orange' || tone === 'amber') return 'paused';
  if (tone === 'green') return 'ok';
  return severityTone(tk.fault_severity || tk.severity);
}
const SEV_COLOR = { crit: '#fb7185', paused: '#f5a524', ok: '#34d399', info: '#60a5fa' };

// How long the card has sat in its current stage — replaces the (misleading) creation date. Reddens
// once a stage overstays its SLA (e.g. Pending Dispatch > 4h) so the owner sees they're slacking.
function TimeInStage({ tk, t }) {
  const age = stageAge(tk, t);
  if (!age) return <span className="now" />;
  return (
    <span className="now" style={age.over ? { color: 'var(--crit)' } : undefined} title={t('queue.timeInStage')}>
      {age.label}
    </span>
  );
}

// The primary CTA(s) for a ticket, honoring permissions + custody. Uses .opx buttons so the controls
// stay in the command-center theme; variant maps to the ops button palette.
function CardActions({ tk, can, onAct, readonly, t }) {
  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  const canFollowUp = ['in_transit', 'under_repair'].includes(tk.workflow_status) && can('maintenance.logistics');

  if (readonly) {
    return (
      <span className="opx-chip avail"><span className="cd" /><Icon.Check className="h-3 w-3" /> {t('queue.awaitingInspector')}</span>
    );
  }
  return (
    <div className="qc-actions">
      {canFollowUp && (
        <button type="button" className="opx-btn" onClick={() => onAct('followup', tk)}>
          {t('workflow.cardAction.followup')}
        </button>
      )}
      {allowed ? (
        <button
          type="button"
          className={`opx-btn ${act.variant === 'danger' ? 'danger' : 'primary'}`}
          style={act.variant === 'success' ? { background: 'linear-gradient(135deg,#059669,#10b981)', color: '#04140c', border: 'none' } : undefined}
          onClick={() => onAct(act.action, tk)}
        >
          {t(`workflow.cardAction.${act.action}`)} <Icon.ArrowRight className="h-3.5 w-3.5" />
        </button>
      ) : act ? (
        <span className="opx-hint">{t(`workflow.cardAction.${act.action}`)} · {t('queue.noAccess')}</span>
      ) : null}
    </div>
  );
}

function QueueCard({ tk, can, onAct, readonly = false }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const sev = sevOf(tk);
  const sevClass = `sev-${sev === 'ok' ? 'ok' : sev === 'crit' ? 'crit' : sev === 'paused' ? 'paused' : 'info'}`;
  const reasonChip = REASON_CHIP[tk.trigger_reason];
  const reasonLabel = reasonChip ? t(`workflow.reasonShort.${tk.trigger_reason}`) : tk.trigger_reason;
  const pct = STAGE_PCT[tk.workflow_status] ?? 50;
  const pos = tk.position || {};

  // Real timeline nodes from the ticket's handoff audit trail, capped with a pulsing "now" node.
  const nodes = useMemo(() => {
    const hs = tk.handoffs || {};
    const done = HANDOFF_STEPS
      .filter((k) => hs[k] && (hs[k].at || hs[k].name))
      .map((k) => ({
        kind: 'done',
        title: t(`queue.step.${k}`),
        time: ago(hs[k].at, t),
        meta: [hs[k].name, hs[k].garage || hs[k].destination, hs[k].odometer != null ? `${Number(hs[k].odometer).toLocaleString()} km` : null]
          .filter(Boolean).map((x) => `<b>${x}</b>`).join(' · ') || null,
      }));
    if (!readonly) done.push({ kind: 'now', title: tk.status_label || t('queue.nowStage'), time: t('queue.nowStage') });
    return done;
  }, [tk, readonly, t]);

  return (
    <div className={`opx-card qc ${sevClass}`}>
      <span className="sev" />
      <div className="top">
        <Link to={`/vehicles/${tk.vehicle_id}`} className="opx-plate" onClick={(e) => e.stopPropagation()}>
          {tk.plate || `#${tk.id}`}
        </Link>
        <div className="model">{tk.car || t('common.none')}<span>{tk.fault_severity_label || tk.status_label}</span></div>
        <TimeInStage tk={tk} t={t} />
      </div>

      <div className="qc-chips">
        {tk.fault_severity_tone && (
          <span className={`opx-chip ${sev === 'ok' ? 'avail' : sev === 'crit' ? 'crit' : 'paused'}`}>
            <span className="cd" />{tk.fault_severity_emoji ? `${tk.fault_severity_emoji} ` : ''}{tk.fault_severity_label}
          </span>
        )}
        {reasonChip && <span className={`opx-chip ${reasonChip}`}><span className="cd" />{reasonLabel}</span>}
        {tk.is_recovery && <span className="opx-chip crit"><span className="cd" />{t('workflow.reasonShort.recovery') || 'Recovery'}</span>}
        {tk.temporarily_released && <span className="opx-chip paused"><span className="cd" />⤴</span>}
      </div>

      {tk.customer_complaint && <p className="qc-quote" title={tk.customer_complaint}>“{tk.customer_complaint}”</p>}

      {(pos.label || tk.status_label) && (
        <div className="qc-pos">
          <Icon.Gauge className="h-3.5 w-3.5" />
          <span>{pos.label || tk.status_label}{tk.garage ? ` · ${tk.garage}` : ''}</span>
        </div>
      )}

      <div className="qc-prog" title={t('queue.stageProgress')}>
        <div className="opx-prog"><i style={{ width: `${pct}%`, background: SEV_COLOR[sev] || SEV_COLOR.info }} /></div>
      </div>

      <div className="qc-foot">
        <CardActions tk={tk} can={can} onAct={onAct} readonly={readonly} t={t} />
        {nodes.length > 0 && (
          <button type="button" className="qc-tl-toggle" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
            <Icon.Clock className="h-3.5 w-3.5" /> {open ? t('queue.hideTimeline') : t('queue.showTimeline')}
          </button>
        )}
      </div>

      {open && nodes.length > 0 && (
        <div className="qc-timeline">
          <div className="qc-tl-h">{t('queue.timeline')}</div>
          <JourneyMap nodes={nodes} />
        </div>
      )}
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
  const [maintTypes, setMaintTypes] = useState([]);
  const [keywordMeta, setKeywordMeta] = useState({});
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({});
  const [drivers, setDrivers] = useState([]);
  const [activeTab, setActiveTab] = useState('');

  // Role-scoped queue: silent background revalidation every 8s (no skeleton flash, tab & scroll
  // preserved), paused while a modal is open so an in-flight action never shifts under us.
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

  const roles = useMemo(() => data?.roles || {}, [data]);
  const sections = useMemo(() => data?.sections || {}, [data]);
  const counts = useMemo(() => data?.counts || {}, [data]);

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });
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

  const availableTabs = ROLE_TABS.filter((tab) => roles[tab.key]);
  const activeSections = SECTIONS.filter((s) => s.role === activeTab);
  const sectionCount = (s) => counts[s.key] ?? (sections[s.key]?.length || 0);
  const tabCount = (roleKey) => SECTIONS.filter((s) => s.role === roleKey).reduce((n, s) => n + sectionCount(s), 0);
  const hasAnyTicket = activeSections.some((s) => sectionCount(s) > 0);

  // Every ticket in the active tab (deduped by id — final_reinspections appears under two roles).
  const activeTickets = useMemo(() => {
    const seen = new Set();
    const out = [];
    activeSections.forEach((s) => (sections[s.key] || []).forEach((tk) => {
      if (!seen.has(tk.id)) { seen.add(tk.id); out.push({ tk, section: s }); }
    }));
    return out;
  }, [activeSections, sections]);

  // KPIs — all derived from real queue data (no fabricated fleet-wide numbers).
  const kpi = useMemo(() => {
    const tickets = activeTickets.map((x) => x.tk);
    const vehicleIds = new Set(tickets.map((tk) => tk.vehicle_id));
    const critical = tickets.filter((tk) => sevOf(tk) === 'crit').length;
    const needsAction = activeTickets.filter(({ tk, section }) => {
      if (section.readonly) return false;
      const act = resolveAction(tk);
      return act && allows(can, act.perm);
    }).length;
    const awaitingQa = (counts.final_reinspections ?? (sections.final_reinspections?.length || 0));
    return { total: tickets.length, vehicles: vehicleIds.size, critical, needsAction, awaitingQa };
  }, [activeTickets, counts, sections, can]);

  // Live activity — the most recent handoff stamps across the active tab's tickets. Real audit trail.
  const feedEvents = useMemo(() => {
    const evs = [];
    activeTickets.forEach(({ tk }) => {
      const hs = tk.handoffs || {};
      HANDOFF_STEPS.forEach((k) => {
        if (hs[k]?.at) {
          evs.push({
            id: `${tk.id}-${k}`,
            plate: tk.plate || `#${tk.id}`,
            description: t(`queue.step.${k}`),
            stage: tk.status_label,
            actor_name: hs[k].name || undefined,
            time_label: ago(hs[k].at, t),
            _at: hs[k].at,
          });
        }
      });
    });
    return evs.sort((a, b) => new Date(b._at) - new Date(a._at)).slice(0, 12);
  }, [activeTickets, t]);

  const roleCount = [isInspector, isDispatcher, isDriver].filter(Boolean).length;
  const subtitleKey = roleCount > 1 ? 'both' : isInspector ? 'inspector' : isDispatcher ? 'dispatcher' : isDriver ? 'driver' : 'none';
  const maxLoad = Math.max(1, ...activeSections.map((s) => sectionCount(s)));

  return (
    <div className="opx">
      {/* Command header */}
      <div className="opx-head">
        <h1>
          {t('queue.title')}
          <span className="live"><span className="d" /> {t('queue.live')}</span>
        </h1>
        <OpsClock />
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <Link to="/maintenance-workflow" className="opx-btn">{t('queue.fullPipeline')}</Link>
          {canRequest && (
            <button type="button" className="opx-btn primary" onClick={() => setModal({ action: 'request' })}>
              <Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}
            </button>
          )}
        </div>
      </div>

      <div className="opx-body">
        <p className="opx-hint" style={{ marginTop: -6, marginBottom: 16 }}>{t(`queue.subtitle.${subtitleKey}`)}</p>

        {error && (
          <div className="opx-panel" style={{ marginBottom: 18 }}>
            <div className="opx-panel-bd" style={{ color: 'var(--crit)' }}>{error}</div>
          </div>
        )}

        {!loading && !error && availableTabs.length === 0 && (
          <div className="opx-panel">
            <div className="opx-empty">
              <div className="big">🔧</div>
              <div style={{ fontSize: 14, color: 'var(--ink-2)', fontWeight: 600 }}>{t('queue.noRoleTitle')}</div>
              <div style={{ marginTop: 6, maxWidth: 460, marginInline: 'auto' }}>{t('queue.noRoleMsg')}</div>
            </div>
          </div>
        )}

        {availableTabs.length > 0 && (
          <>
            {/* KPI strip — real queue metrics */}
            <div className="opx-grid opx-c12" style={{ marginBottom: 18 }}>
              <div className="opx-span-3">
                <KpiTile label={t('queue.kpi.inQueue')} value={kpi.total}
                  foot={t('queue.kpi.inQueueFoot', { vehicles: kpi.vehicles })} />
              </div>
              <div className="opx-span-3">
                <KpiTile label={t('queue.kpi.needsAction')} value={kpi.needsAction} tone="warm"
                  foot={t('queue.kpi.needsActionFoot')} footTone={kpi.needsAction ? 'flat' : 'up'} />
              </div>
              <div className="opx-span-3">
                <KpiTile label={t('queue.kpi.critical')} value={kpi.critical} tone={kpi.critical ? 'hot' : 'good'}
                  foot={t('queue.kpi.criticalFoot')} footTone={kpi.critical ? 'down' : 'up'} />
              </div>
              <div className="opx-span-3">
                <KpiTile label={t('queue.kpi.awaitingQa')} value={kpi.awaitingQa} tone="good"
                  foot={t('queue.kpi.awaitingQaFoot')} />
              </div>
            </div>

            {/* Role selector — icon + task counter per role the user holds */}
            <div className="qroles">
              {availableTabs.map((tab) => {
                const active = activeTab === tab.key;
                const IconCmp = tab.icon;
                return (
                  <button key={tab.key} type="button" className={`qrole ${active ? 'on' : ''}`}
                    style={{ '--rail': tab.rail }} onClick={() => setActiveTab(tab.key)} aria-pressed={active}>
                    <span className="qrole-ic"><IconCmp className="h-5 w-5" /></span>
                    <span className="qrole-txt">{t(`queue.tab.${tab.key}`)}</span>
                    <span className="qrole-ct">{tabCount(tab.key)}</span>
                  </button>
                );
              })}
            </div>

            {/* Main split: role sections + live side rail */}
            <div className="opx-grid opx-c12">
              <div className="opx-span-8">
                <div className="qsections">
                  {activeSections.map((s) => {
                    const tickets = sections[s.key] || [];
                    const count = counts[s.key] ?? tickets.length;
                    return (
                      <CommandPanel key={`${s.role}-${s.key}`} title={t(`queue.section.${s.key}.title`)}
                        dotColor={s.tone} meta={count}>
                        <p className="qsec-hint">{t(`queue.section.${s.key}.hint`)}</p>
                        <div className="qgrid">
                          {loading ? (
                            <><div className="opx-skel" style={{ height: 128 }} /><div className="opx-skel" style={{ height: 128 }} /></>
                          ) : tickets.length === 0 ? (
                            <p className="opx-empty" style={{ gridColumn: '1 / -1', padding: '26px 10px' }}>{t('queue.nothingHere')}</p>
                          ) : (
                            tickets.map((tk) => (
                              <QueueCard key={tk.id} tk={tk} can={can} readonly={s.readonly}
                                onAct={(action, ticket) => setModal({ action, ticket })} />
                            ))
                          )}
                        </div>
                      </CommandPanel>
                    );
                  })}
                  {!loading && !hasAnyTicket && (
                    <p style={{ textAlign: 'center', color: 'var(--ink-3)', fontSize: 13, padding: 12 }}>{t('queue.allCaught')}</p>
                  )}
                </div>
              </div>

              <div className="opx-span-4">
                <div className="qside">
                  {/* Queue load — real per-section counts as horizontal bars */}
                  <CommandPanel title={t('queue.queueLoad')} label={t(`queue.tab.${activeTab}`)}>
                    <div className="qload">
                      {activeSections.map((s) => {
                        const c = sectionCount(s);
                        return (
                          <div className="qload-row" key={`${s.role}-${s.key}`}>
                            <span className="qload-lbl">{t(`queue.section.${s.key}.title`)}</span>
                            <div className="qload-track"><i style={{ width: `${(c / maxLoad) * 100}%`, background: s.tone }} /></div>
                            <span className="qload-ct tnum">{c}</span>
                          </div>
                        );
                      })}
                    </div>
                  </CommandPanel>

                  {/* Live activity — real handoff stamps across the active tab */}
                  <CommandPanel title={t('queue.liveActivity')} dotColor="#34d399"
                    meta={feedEvents.length || null}>
                    {feedEvents.length === 0
                      ? <p className="opx-empty" style={{ padding: '22px 10px' }}>{t('queue.noActivity')}</p>
                      : <LiveActivityFeed events={feedEvents} freshCount={loading ? 0 : 2} />}
                  </CommandPanel>
                </div>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Breakdown Intake — a technician reports a not-driveable car → grounded ticket in the dispatch queue. */}
      {modal?.action === 'breakdown' && (
        <BreakdownIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}

      {/* Complaint Triage — Abu Maroof handles a logged complaint (talk / resolve on-site / send in). */}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal ticket={modal.ticket} vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
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
