import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import Badge, { OperationalBadge } from '../components/ui/Badge';
import { Card, EmptyState, ErrorState, PageHeader } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';

// The pre-maintenance Recommendation queue. A vehicle lands here when the inspector files an in-shop
// "requires maintenance" report — a recommendation is only a proposal, NOT an active maintenance job.
// The Supervisor triages it here (approve / schedule / order parts / dismiss); only "Start Maintenance"
// promotes it into the existing workflow board. See MaintenanceWorkflowService recommendation* methods.

const SEV_TONE = { critical: 'red', moderate: 'amber', routine: 'green' };

function fmtDate(iso) {
  if (!iso) return null;
  try {
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  } catch {
    return null;
  }
}

// One recommendation card — everything a supervisor needs to decide, plus the triage actions.
function RecommendationCard({ tk, canAct, onAction }) {
  const report = tk.test_drive_report || {};
  const recommendedAction = report.recommended_action;
  const findings = (tk.findings || []).map((f) => f.text).filter(Boolean);
  const inspected = tk.handoffs?.inspected || {};
  const rec = tk.recommendation || {};
  const isParts = tk.workflow_status === 'awaiting_parts';
  const attachments = tk.video_count || 0;

  return (
    <Card className="p-5">
      <div className="flex flex-col gap-4">
        {/* Header: vehicle + live status + state */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <Link to={`/maintenance-workflow/${tk.id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
                {tk.plate || 'No plate'}
              </Link>
              {tk.car && <span className="truncate text-sm text-slate-500">{tk.car}</span>}
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              {tk.operational_status && <OperationalBadge status={tk.operational_status} />}
              {tk.fault_severity && (
                <Badge tone={SEV_TONE[tk.fault_severity] || 'slate'} dot>
                  {tk.fault_severity_emoji ? `${tk.fault_severity_emoji} ` : ''}
                  {tk.fault_severity_label || tk.fault_severity}
                </Badge>
              )}
            </div>
          </div>
          <div className="flex flex-col items-end gap-1.5">
            {isParts ? (
              <Badge tone="amber" dot>Waiting for parts</Badge>
            ) : rec.parts_ready ? (
              <Badge tone="green" dot>Parts ready</Badge>
            ) : (
              <Badge tone="violet" dot>Pending recommendation</Badge>
            )}
            {rec.scheduled_for && (
              <span className="text-xs text-slate-500">Scheduled · {fmtDate(rec.scheduled_for)}</span>
            )}
          </div>
        </div>

        {/* Recommended action + findings */}
        <div className="rounded-xl bg-slate-50 p-3.5 ring-1 ring-inset ring-slate-200/70">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Recommended action</p>
          <p className="mt-1 text-sm text-slate-800">
            {recommendedAction || <span className="text-slate-400">No recommended action recorded</span>}
          </p>
          {findings.length > 0 && (
            <div className="mt-3 border-t border-slate-200/70 pt-2.5">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                Fault{findings.length > 1 ? 's' : ''} identified
              </p>
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {findings.map((f, i) => (
                  <span key={i} className="rounded-lg bg-white px-2 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                    {f}
                  </span>
                ))}
              </div>
            </div>
          )}
          {report.notes && <p className="mt-2 text-xs text-slate-500">{report.notes}</p>}
          {isParts && rec.note && (
            <p className="mt-2 text-xs text-amber-700">On order: {rec.note}</p>
          )}
        </div>

        {/* Meta line: inspector, date, attachments */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          {inspected.by_name && <span>Inspected by <span className="font-medium text-slate-700">{inspected.by_name}</span></span>}
          {inspected.at && <span>{fmtDate(inspected.at)}</span>}
          {attachments > 0 && (
            <span className="inline-flex items-center gap-1">
              <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M15.5 8.5 10 14a2 2 0 0 1-3-3l6-6a3.5 3.5 0 0 1 5 5l-6.5 6.5a5 5 0 1 1-7-7L11 4" /></svg>
              {attachments} attachment{attachments > 1 ? 's' : ''}
            </span>
          )}
        </div>

        {/* Actions */}
        {canAct && (
          <div className="space-y-2 border-t border-slate-100 pt-3.5">
            <div className="flex flex-wrap gap-2">
              {isParts ? (
                <>
                  <Button size="sm" variant="success" onClick={() => onAction('parts-ready', tk)}>Parts arrived</Button>
                  <Button size="sm" variant="primary" onClick={() => onAction('start', tk)}>Start maintenance</Button>
                  <Button size="sm" variant="ghost" onClick={() => onAction('reject', tk)}>Dismiss</Button>
                </>
              ) : (
                <>
                  <Button size="sm" variant="primary" onClick={() => onAction('start', tk)}>Start maintenance</Button>
                  <Button size="sm" variant="secondary" onClick={() => onAction('order-parts', tk)}>Order parts first</Button>
                  <Button size="sm" variant="secondary" onClick={() => onAction('schedule', tk)}>Schedule for later</Button>
                  <Button size="sm" variant="ghost" onClick={() => onAction('reject', tk)}>Reject / Not required</Button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </Card>
  );
}

// One triage-routing-approval card — Abu Maroof recommended sending the car in; a supervisor approves or
// rejects the routing here. Distinct source from an inspection recommendation, same approval hub.
function TriageApprovalCard({ tk, canAct, onAction }) {
  const route = tk.triage_route || {};
  const destGarage = route.destination === 'garage';
  const complaint = tk.customer_complaint;

  return (
    <Card className="p-5">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <Link to={`/maintenance-workflow/${tk.id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
                {tk.plate || 'No plate'}
              </Link>
              {tk.car && <span className="truncate text-sm text-slate-500">{tk.car}</span>}
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              {tk.operational_status && <OperationalBadge status={tk.operational_status} />}
              <Badge tone="red" dot>📣 Customer complaint</Badge>
            </div>
          </div>
          <Badge tone="violet" dot>Awaiting routing approval</Badge>
        </div>

        {complaint && (
          <p className="text-sm text-slate-700">“{complaint}”</p>
        )}

        {/* What Abu Maroof recommended */}
        <div className="rounded-xl bg-slate-50 p-3.5 ring-1 ring-inset ring-slate-200/70">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Recommended routing</p>
          <p className="mt-1 flex items-center gap-2 text-sm font-medium text-slate-800">
            <Badge tone={destGarage ? 'amber' : 'violet'}>
              {destGarage ? 'Garage dispatch' : 'Diagnostic inspection'}
            </Badge>
            {route.replacement_vehicle_id && (
              <span className="text-xs text-slate-500">+ replacement offered</span>
            )}
          </p>
          {route.note && <p className="mt-2 text-xs text-slate-500">{route.note}</p>}
        </div>

        {/* Meta */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          {route.recommended_by_name && (
            <span>Recommended by <span className="font-medium text-slate-700">{route.recommended_by_name}</span></span>
          )}
          {route.recommended_at && <span>{fmtDate(route.recommended_at)}</span>}
        </div>

        {/* Actions */}
        {canAct && (
          <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3.5">
            <Button size="sm" variant="primary" onClick={() => onAction('triage-approve', tk)}>
              Approve — send to {destGarage ? 'garage' : 'diagnostic'}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => onAction('triage-reject', tk)}>Reject</Button>
          </div>
        )}
      </div>
    </Card>
  );
}

export default function MaintenanceRecommendations() {
  const { can } = usePermissions();
  const toast = useToast();
  const canAct = can('maintenance.delegate') || can('maintenance.manage');

  const [action, setAction] = useState(null); // { kind, ticket }
  const [busy, setBusy] = useState(false);
  // Form state for the action modals.
  const [disposition, setDisposition] = useState('rejected');
  const [reason, setReason] = useState('');
  const [scheduledFor, setScheduledFor] = useState('');
  const [partsNote, setPartsNote] = useState('');

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/recommendations')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 15000,
    paused: () => !!action,
  });

  const list = useMemo(() => data?.recommendations || [], [data]);
  const triageApprovals = useMemo(() => data?.triage_approvals || [], [data]);
  const counts = data?.counts || { pending: 0, awaiting_parts: 0, triage_approvals: 0, total: 0 };
  const pending = list.filter((t) => t.workflow_status === 'recommendation_pending');
  const waiting = list.filter((t) => t.workflow_status === 'awaiting_parts');
  const isEmpty = list.length === 0 && triageApprovals.length === 0;

  const openAction = (kind, ticket) => {
    // Simple, confirm-less actions post immediately; the rest open a modal for their inputs.
    if (kind === 'start' || kind === 'parts-ready' || kind === 'triage-approve') {
      runAction(kind, ticket, {});
      return;
    }
    setDisposition('rejected');
    setReason('');
    setScheduledFor('');
    setPartsNote('');
    setAction({ kind, ticket });
  };

  const runAction = async (kind, ticket, body) => {
    setBusy(true);
    try {
      const url = {
        start: `/maintenance-tickets/${ticket.id}/recommendation/start`,
        reject: `/maintenance-tickets/${ticket.id}/recommendation/reject`,
        schedule: `/maintenance-tickets/${ticket.id}/recommendation/schedule`,
        'order-parts': `/maintenance-tickets/${ticket.id}/recommendation/order-parts`,
        'parts-ready': `/maintenance-tickets/${ticket.id}/recommendation/parts-ready`,
        'triage-approve': `/maintenance-tickets/${ticket.id}/triage/approve-route`,
        'triage-reject': `/maintenance-tickets/${ticket.id}/triage/reject-route`,
      }[kind];
      const res = await api.post(url, body);
      toast.success(res.data?.message || 'Done');
      setAction(null);
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Action failed');
    } finally {
      setBusy(false);
    }
  };

  const submitModal = () => {
    const { kind, ticket } = action;
    if (kind === 'reject') return runAction('reject', ticket, { disposition, reason: reason || null });
    if (kind === 'triage-reject') return runAction('triage-reject', ticket, { reason: reason || null });
    if (kind === 'schedule') {
      if (!scheduledFor) { toast.error('Pick a date'); return; }
      return runAction('schedule', ticket, { scheduled_for: scheduledFor, note: partsNote || null });
    }
    if (kind === 'order-parts') return runAction('order-parts', ticket, { note: partsNote || null });
  };

  const modalTitle = action && {
    reject: 'Dismiss recommendation',
    'triage-reject': 'Reject routing',
    schedule: 'Schedule for later',
    'order-parts': 'Order parts first',
  }[action.kind];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Maintenance Recommendations"
        subtitle="Inspection recommendations and Abu Maroof's complaint-routing recommendations awaiting supervisor approval. A recommendation is only a proposal — approve it before any car moves or work starts."
      >
        <Button variant="secondary" size="sm" onClick={() => reload()}>Refresh</Button>
      </PageHeader>

      <div className="flex flex-wrap gap-3">
        <Badge tone="violet" dot>{counts.pending} pending</Badge>
        <Badge tone="amber" dot>{counts.awaiting_parts} waiting for parts</Badge>
        {counts.triage_approvals > 0 && <Badge tone="red" dot>{counts.triage_approvals} routing approval{counts.triage_approvals > 1 ? 's' : ''}</Badge>}
      </div>

      {loading ? (
        <div className="grid gap-4 lg:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-56 rounded-2xl" />)}
        </div>
      ) : error ? (
        <ErrorState onRetry={() => reload()} />
      ) : isEmpty ? (
        <Card>
          <EmptyState
            title="No recommendations"
            message="When an inspection recommends maintenance — or Abu Maroof recommends sending a complaint car in — it will appear here for approval before any work starts."
          />
        </Card>
      ) : (
        <div className="space-y-8">
          {triageApprovals.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-semibold text-slate-700">Triage routing approvals · {triageApprovals.length}</h2>
              <div className="grid gap-4 lg:grid-cols-2">
                {triageApprovals.map((tk) => <TriageApprovalCard key={tk.id} tk={tk} canAct={canAct} onAction={openAction} />)}
              </div>
            </section>
          )}
          {pending.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-semibold text-slate-700">Pending review · {pending.length}</h2>
              <div className="grid gap-4 lg:grid-cols-2">
                {pending.map((tk) => <RecommendationCard key={tk.id} tk={tk} canAct={canAct} onAction={openAction} />)}
              </div>
            </section>
          )}
          {waiting.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-semibold text-slate-700">Waiting for parts · {waiting.length}</h2>
              <div className="grid gap-4 lg:grid-cols-2">
                {waiting.map((tk) => <RecommendationCard key={tk.id} tk={tk} canAct={canAct} onAction={openAction} />)}
              </div>
            </section>
          )}
        </div>
      )}

      <Modal
        open={!!action}
        onClose={() => !busy && setAction(null)}
        title={modalTitle}
        subtitle={action?.ticket?.plate}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)} disabled={busy}>Cancel</Button>
            <Button variant={action?.kind === 'reject' || action?.kind === 'triage-reject' ? 'danger' : 'primary'} onClick={submitModal} loading={busy}>
              {action?.kind === 'reject' ? 'Dismiss' : action?.kind === 'triage-reject' ? 'Reject routing' : action?.kind === 'schedule' ? 'Schedule' : 'Order parts'}
            </Button>
          </>
        }
      >
        {action?.kind === 'reject' && (
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-slate-700">Outcome</label>
              <div className="mt-2 flex gap-2">
                {[
                  { v: 'rejected', l: 'Reject' },
                  { v: 'not_required', l: 'Not required' },
                ].map((o) => (
                  <button
                    key={o.v}
                    type="button"
                    onClick={() => setDisposition(o.v)}
                    className={`rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition ${
                      disposition === o.v ? 'bg-indigo-50 text-indigo-700 ring-indigo-300' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    {o.l}
                  </button>
                ))}
              </div>
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Reason <span className="text-slate-400">(optional)</span></label>
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={3}
                className="mt-1.5 w-full rounded-lg border border-slate-200 p-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                placeholder="Why is no maintenance needed?"
              />
            </div>
          </div>
        )}
        {action?.kind === 'triage-reject' && (
          <div>
            <label className="text-sm font-medium text-slate-700">Reason <span className="text-slate-400">(optional)</span></label>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              className="mt-1.5 w-full rounded-lg border border-slate-200 p-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
              placeholder="Why not send the car in? (sent back to Abu Maroof)"
            />
            <p className="mt-2 text-xs text-slate-500">The complaint returns to triage for Abu Maroof to reconsider.</p>
          </div>
        )}
        {action?.kind === 'schedule' && (
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-slate-700">Review on</label>
              <input
                type="date"
                value={scheduledFor}
                onChange={(e) => setScheduledFor(e.target.value)}
                className="mt-1.5 w-full rounded-lg border border-slate-200 p-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
              />
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Note <span className="text-slate-400">(optional)</span></label>
              <textarea
                value={partsNote}
                onChange={(e) => setPartsNote(e.target.value)}
                rows={2}
                className="mt-1.5 w-full rounded-lg border border-slate-200 p-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
                placeholder="Why defer?"
              />
            </div>
          </div>
        )}
        {action?.kind === 'order-parts' && (
          <div>
            <label className="text-sm font-medium text-slate-700">What's on order? <span className="text-slate-400">(optional)</span></label>
            <textarea
              value={partsNote}
              onChange={(e) => setPartsNote(e.target.value)}
              rows={3}
              className="mt-1.5 w-full rounded-lg border border-slate-200 p-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
              placeholder="e.g. gearbox, brake pads…"
            />
            <p className="mt-2 text-xs text-slate-500">The car stays available and in this queue until you mark the parts ready.</p>
          </div>
        )}
      </Modal>
    </div>
  );
}
