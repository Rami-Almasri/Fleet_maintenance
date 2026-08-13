// Maintenance Progress (/maintenance-progress) — the daily operational queue for the maintenance
// supervisors (Waleed & Abdullah). One row per car currently in the workshop, answering at a glance:
// where it is, when it's due, whether it's on schedule, what the workshop last reported, and who owns the
// follow-up. From here they file a progress update (new ETA, the reason it moved, a note, and photo/video
// evidence) via the shared CheckpointModal. The progress status is DERIVED from the ETA, never chosen.
//
// Notifications (checkpoints:scan) deep-link here with ?ticket=<id> so the reminder opens the matching
// car's checkpoint form straight away. Data: GET /Dashboard/maintenance-progress
// (DashboardService::maintenanceProgress).

import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import CheckpointsAnalytics from '../components/analytics/CheckpointsAnalytics';
import { Link, useSearchParams } from 'react-router-dom';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import { useToast } from '../components/ui/Toast';
import { fmtDate } from '../lib/format';
import {
  getMaintenanceProgress, useCheckpointVocab, resolveCheckpointTicket,
} from '../lib/maintenanceCheckpoints';
import CheckpointModal from '../components/maintenance/CheckpointModal';
import DelayExplanation from '../components/maintenance/DelayExplanation';
import { useI18n } from '../i18n/I18nContext';

// Roll-up chips, worst-first — the queue's health at a glance (all DERIVED from the ETA / workflow stage).
const SUMMARY_CHIPS = [
  { key: 'overdue', label: 'Overdue', cls: 'bg-red-50 text-red-700 ring-red-200' },
  { key: 'needs_update', label: 'Checkpoint due', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  { key: 'on_schedule', label: 'On schedule', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
];

// The in-shop workflow stages (Maintenance::CHECKPOINT_TRACKED_STATES) → a friendly "current maintenance
// status" label for the queue.
const WF_STAGE_LABEL = {
  inspection_pending: 'Awaiting dispatch',
  awaiting_dispatch: 'Awaiting pickup',
  in_transit: 'En route to garage',
  under_repair: 'In workshop',
  repair_review: 'Video review',
  ready_for_reinspection: 'Ready — re-inspection',
  reinspection_failed: 'QA failed — re-dispatch',
  ready_for_pickup: 'Ready for pickup',
};

function StatusChip({ status }) {
  const { progressStatus } = useCheckpointVocab();
  const s = progressStatus[status] || progressStatus.on_schedule;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ${s.chip}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${s.dot} ${status === 'needs_update' || status === 'overdue' ? 'animate-pulse' : ''}`} />
      {s.label}
    </span>
  );
}

// Where a row came from — 'Contract' (open type-U contract) vs 'Workshop' (app workflow ticket) — so a
// mixed queue stays legible at a glance.
function SourceBadge({ source }) {
  const { sourceMeta } = useCheckpointVocab();
  const s = sourceMeta[source];
  if (!s) return null;
  return (
    <span title={s.tip} className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ${s.chip}`}>
      {s.label}
    </span>
  );
}

function EtaCell({ r }) {
  const { t } = useI18n();
  return (
    <div className="leading-tight">
      {r.overdue
        ? <span className="font-semibold text-red-600 tabular-nums">{t('checkpointsPage.overdue', { n: r.days_over })}</span>
        : r.eta_status === 'due_today'
          ? <span className="font-semibold text-amber-600">{t('checkpointsPage.dueToday')}</span>
          : r.expected_on
            ? <span className="tabular-nums text-slate-700">{t('checkpointsPage.daysLeft', { n: r.days_left })}</span>
            : <span className="text-slate-400">{t('checkpointsPage.noEta')}</span>}
      {r.expected_on && (
        <p className="text-[11px] text-slate-400">{fmtDate(r.expected_on)}{r.is_estimated ? ` ${t('(est.)')}` : ''}</p>
      )}
    </div>
  );
}

// WHY the car is in the shop — the fault(s)/reason behind the visit (headline + type; full list on hover).
function ProblemCell({ row }) {
  const items = row.problem_items || [];
  const label = row.problem || (items[0] ?? null);
  if (!label) return <span className="text-slate-300">—</span>;
  return (
    <div className="max-w-[240px] leading-tight">
      <p className="truncate font-medium text-slate-700" title={items.length > 1 ? items.join(' · ') : label}>{label}</p>
      {row.problem_type && <p className="text-[11px] text-slate-400">{row.problem_type}</p>}
    </div>
  );
}

// Whole days between the previously promised ETA and the revised one (positive = slipped later).
function checkpointDelayDays(prev, next) {
  if (!prev || !next) return null;
  const a = new Date(`${prev}T00:00:00`);
  const b = new Date(`${next}T00:00:00`);
  if (isNaN(a) || isNaN(b)) return null;
  return Math.round((b - a) / 86400000);
}

// "What did the workshop last report?" — the latest update as a clearly-labelled, readable summary:
// the ETA change (Previous → New with the delay in days), the reason it moved, and who updated it when.
function LastCheckpointCell({ r }) {
  const { t } = useI18n();
  const { statusLabel, delayReasonLabel } = useCheckpointVocab();
  const c = r.last_checkpoint;
  if (!c) return <span className="text-amber-600">{t('checkpointsPage.noUpdate')}</span>;
  const workshop = statusLabel(c.status);
  const reason = c.delay_reason === 'other' ? (c.delay_reason_other || null) : delayReasonLabel(c.delay_reason);
  const etaMoved = !!c.next_expected_date
    && (!c.previous_expected_date || c.previous_expected_date !== c.next_expected_date);
  const dd = checkpointDelayDays(c.previous_expected_date, c.next_expected_date);
  return (
    <div className="min-w-0 max-w-[280px] leading-tight">
      {/* ETA change — labelled Previous → New so the two dates are never confused. */}
      {etaMoved ? (
        <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px]">
          {c.previous_expected_date && (
            <span className="text-slate-500">
              <span className="text-slate-400">{t('checkpointsPage.prev')}</span> <span className="line-through decoration-slate-300">{fmtDate(c.previous_expected_date)}</span>
            </span>
          )}
          <span aria-hidden className="text-amber-500">→</span>
          <span className="font-semibold text-slate-700">
            <span className="text-slate-400">{t('checkpointsPage.new')}</span> {fmtDate(c.next_expected_date)}
          </span>
          {dd != null && dd > 0 && (
            <span className="rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-600 ring-1 ring-red-200">+{dd}d</span>
          )}
        </div>
      ) : (
        <span className="inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
          {t('checkpointsPage.etaConfirmed')} {c.next_expected_date ? fmtDate(c.next_expected_date) : ''}
        </span>
      )}
      {/* Reason the ETA moved — the operationally important bit. */}
      {etaMoved && (
        reason
          ? <p className="mt-1 text-[11px] text-amber-700"><span className="font-semibold">{t('checkpointsPage.reason')}</span> {reason}</p>
          : <p className="mt-1 text-[11px] text-amber-600">{t('checkpointsPage.noReason')}</p>
      )}
      {workshop && <p className="mt-0.5 text-[11px] font-medium text-slate-500">{t('checkpointsPage.workshop')} {workshop}</p>}
      {c.summary && <p className="mt-0.5 truncate text-[11px] text-slate-500" title={c.summary}>“{c.summary}”</p>}
      <p className="mt-1 text-[11px] text-slate-400">
        {c.by ? t('Updated by {who}', { who: c.by }) : t('Updated')}{r.last_checkpoint_at ? ` · ${fmtDate(r.last_checkpoint_at)}` : ''}
      </p>
    </div>
  );
}

export default function MaintenanceCheckpoints() {
  const { t } = useI18n();
  const toast = useToast();
  const { sourceMeta } = useCheckpointVocab();
  const [searchParams, setSearchParams] = useSearchParams();
  const [data, setData] = useState({ summary: {}, items: [] });
  const [loading, setLoading] = useState(true);
  const [active, setActive] = useState(null); // { ticketId, label, sub }
  const [q, setQ] = useState('');
  const [fStatus, setFStatus] = useState('all');
  const deepLinkHandled = useRef(false);

  const load = useCallback((opts = {}) => {
    if (!opts.silent) setLoading(true);
    return getMaintenanceProgress()
      .then((d) => setData(d || { summary: {}, items: [] }))
      .catch(() => setData({ summary: {}, items: [] }))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  // Live queue — silently revalidate every 30s (paused while the checkpoint modal is open so an in-flight
  // submission isn't disrupted).
  useEffect(() => {
    if (active) return undefined;
    const id = setInterval(() => load({ silent: true }), 30000);
    return () => clearInterval(id);
  }, [load, active]);

  const { summary = {}, items = [] } = data;

  // Deep-link from a reminder notification: ?ticket=<id> (or ?vehicle=<id>) opens that car's checkpoint
  // form once the data has loaded. Handled once so closing the modal doesn't immediately reopen it.
  useEffect(() => {
    if (loading || deepLinkHandled.current) return;
    const ticketId = searchParams.get('ticket');
    const vehicleId = searchParams.get('vehicle');
    if (!ticketId && !vehicleId) return;
    const row = items.find((r) => (ticketId && String(r.ticket_id) === String(ticketId))
      || (vehicleId && String(r.vehicle_id) === String(vehicleId)));
    deepLinkHandled.current = true;
    if (row) setActive({ ticketId: row.ticket_id, label: row.plate || t('Ticket #{n}', { n: row.ticket_id }), sub: row.garage });
  }, [loading, items, searchParams, t]);

  // Open the checkpoint form for a row. Contract-sourced rows have no ticket until now — lazily link one
  // (via ensure-ticket) before opening the modal, so the write path is identical for both sources.
  const [opening, setOpening] = useState(null); // row key currently resolving its ticket
  const openCheckpoint = async (r) => {
    const key = `${r.source}-${r.ticket_id ?? r.contract_id}`;
    if (r.ticket_id) {
      setActive({ ticketId: r.ticket_id, label: r.plate || t('Ticket #{n}', { n: r.ticket_id }), sub: r.garage });
      return;
    }
    setOpening(key);
    try {
      const ticketId = await resolveCheckpointTicket(r);
      if (ticketId) setActive({ ticketId, label: r.plate || t('Ticket #{n}', { n: ticketId }), sub: r.garage });
      else toast.error(t('Could not open a checkpoint for this car.'));
    } catch (e) {
      toast.error(t('Could not open a checkpoint for this car.'));
    } finally {
      setOpening(null);
    }
  };

  const closeModal = () => {
    setActive(null);
    // Drop the deep-link params so a refresh / back doesn't reopen the modal.
    if (searchParams.get('ticket') || searchParams.get('vehicle')) {
      searchParams.delete('ticket');
      searchParams.delete('vehicle');
      setSearchParams(searchParams, { replace: true });
    }
  };

  const rows = useMemo(() => {
    let r = items;
    if (fStatus !== 'all') r = r.filter((x) => x.status === fStatus);
    const term = q.trim().toLowerCase();
    if (term) {
      r = r.filter((x) => `${x.plate || ''} ${x.car || ''} ${x.garage || ''} ${(x.problem_items || []).join(' ')} ${x.problem || ''} ${(sourceMeta[x.source]?.label || '')} ${(x.responsible || []).map((u) => u.name).join(' ')}`
        .toLowerCase().includes(term));
    }
    return r;
  }, [items, fStatus, q, sourceMeta]);

  const activeFilters = fStatus !== 'all' || q.trim();

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1360px] space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to="/apps/maintenance" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180 rtl:-scale-x-100" /> {t('Maintenance')}
            </Link>
            <h1 className="flex items-center gap-2 font-display text-2xl font-bold tracking-tight text-slate-900">
              {t('Maintenance Progress')}
              <InfoTip content={t('The daily operational queue for the maintenance supervisors. Each car in the workshop shows its ETA, a progress status derived automatically from the promised date (On Schedule / Overdue / Ready for Pickup), and what the workshop last reported. File an update to record the latest ETA, the reason it moved, a note, and photo/video evidence. Reminders arrive automatically before a car goes overdue.')} />
            </h1>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('Every car in the workshop · file checkpoints, track ETAs, and see why a job is delayed.')}
            </p>
          </div>
          <button
            type="button"
            onClick={() => load()}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
          >
            <Icon.Refresh className="h-4 w-4" /> {t('Refresh')}
          </button>
        </div>

        {/* Roll-up chips */}
        {!loading && (
          <div className="flex flex-wrap items-center gap-2">
            {SUMMARY_CHIPS.map((c) => (
              <button
                key={c.key}
                type="button"
                onClick={() => setFStatus((prev) => (prev === c.key ? 'all' : c.key))}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition ${c.cls} ${fStatus === c.key ? 'ring-2 ring-offset-1' : 'opacity-90 hover:opacity-100'}`}
              >
                {t(c.label)}
                <span className="tabular-nums">{summary[c.key] ?? 0}</span>
              </button>
            ))}
            <span className="ms-1 text-xs text-slate-400">
              {t('{n} in workshop', { n: summary.total ?? 0 })}
              {(summary.contract != null || summary.workshop != null)
                && ` · ${t('{n} contract', { n: summary.contract ?? 0 })} · ${t('{n} workshop', { n: summary.workshop ?? 0 })}`}
            </span>
          </div>
        )}

        {/* Analytics — the whole workshop queue, before the toolbar narrows the list. */}
        {!loading && items.length > 0 && <CheckpointsAnalytics rows={items} />}

        {/* Toolbar */}
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 min-w-[220px]">
            <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('checkpointsPage.searchPlaceholder')}
              className="w-full rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
            />
          </div>
          <select
            value={fStatus}
            onChange={(e) => setFStatus(e.target.value)}
            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 outline-none focus:border-indigo-500"
          >
            <option value="all">{t('checkpointsPage.allStatuses')}</option>
            {SUMMARY_CHIPS.map((c) => <option key={c.key} value={c.key}>{t(c.label)}</option>)}
          </select>
          {activeFilters && (
            <button type="button" onClick={() => { setQ(''); setFStatus('all'); }} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-500 hover:bg-slate-50">
              {t('Clear')}
            </button>
          )}
        </div>

        {/* Queue */}
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
          {loading ? (
            <div className="space-y-2 p-4">
              {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12 rounded-xl" />)}
            </div>
          ) : rows.length === 0 ? (
            <p className="py-16 text-center text-sm text-slate-400">
              {items.length === 0 ? t('No cars are in the workshop right now.') : t('No cars match the current filters.')}
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1080px] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50/60 text-start text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.vehicle')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.problem')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.workshop')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.maintStatus')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.expected')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.progress')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.lastCheckpoint')}</th>
                    <th className="px-4 py-2.5">{t('checkpointsPage.cols.responsible')}</th>
                    <th className="px-4 py-2.5 text-end">{t('checkpointsPage.cols.action')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {rows.map((r) => (
                    <Fragment key={`${r.source}-${r.ticket_id ?? r.contract_id}`}>
                    <tr className={`align-top hover:bg-slate-50/60 ${r.overdue ? 'bg-red-50/40' : ''}`}>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          {r.vehicle_id
                            ? <Link to={`/vehicles/${r.vehicle_id}?tab=checkpoints`} className="font-semibold text-slate-800 hover:text-indigo-600">{r.plate || `#${r.vehicle_id}`}</Link>
                            : <span className="font-semibold text-slate-800">{t('Ticket #{n}', { n: r.ticket_id })}</span>}
                          <SourceBadge source={r.source} />
                        </div>
                        {r.car && <p className="text-[11px] text-slate-400">{r.car}</p>}
                      </td>
                      <td className="px-4 py-3"><ProblemCell row={r} /></td>
                      <td className="px-4 py-3 text-slate-600">{r.garage || <span className="text-slate-300">—</span>}</td>
                      <td className="px-4 py-3 text-slate-600">{WF_STAGE_LABEL[r.workflow_status] ? t(WF_STAGE_LABEL[r.workflow_status]) : (r.workflow_status || '—')}</td>
                      <td className="px-4 py-3"><EtaCell r={r} /></td>
                      <td className="px-4 py-3"><StatusChip status={r.status} /></td>
                      <td className="px-4 py-3"><LastCheckpointCell r={r} /></td>
                      <td className="px-4 py-3 text-slate-600">
                        {(r.responsible || []).slice(0, 2).map((u) => u.name).join(', ') || '—'}
                        {(r.responsible || []).length > 2 ? ` +${r.responsible.length - 2}` : ''}
                      </td>
                      <td className="px-4 py-3 text-end">
                        <button
                          type="button"
                          disabled={opening === `${r.source}-${r.ticket_id ?? r.contract_id}`}
                          onClick={() => openCheckpoint(r)}
                          className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                          <Icon.Flag className="h-3.5 w-3.5" /> {t('Checkpoint')}
                        </button>
                      </td>
                    </tr>
                    {r.overdue && (
                      <tr className="bg-red-50/40">
                        <td colSpan={9} className="px-4 pb-3 pt-0">
                          <DelayExplanation checkpoint={r.last_checkpoint} className="max-w-3xl" />
                        </td>
                      </tr>
                    )}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {active && (
        <CheckpointModal
          open={!!active}
          ticketId={active.ticketId}
          title={`${t('Checkpoint')} · ${active.label}`}
          subtitle={active.sub || undefined}
          onClose={closeModal}
          onDone={(msg) => { if (msg) toast.success(msg); load({ silent: true }); }}
        />
      )}
    </div>
  );
}
