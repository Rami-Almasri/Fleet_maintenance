// Inspection Request Review Gate — the Controllers' (Lin & Marwa) queue of Driver/system-generated
// inspection requests awaiting approval before they are sent to the Inspector (Abu Maroof).
// Approve sends the request on exactly as before; reject terminates it (requires a reason).

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { Textarea } from '../components/ui/Field';
import { EmptyState } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import TestIntakeModal from '../components/workflow/TestIntakeModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';

const REASON_LABEL = { test_drive: 'Test drive', customer_reported: 'Customer complaint', periodic: 'Routine (system)' };
const REASON_TONE = { test_drive: 'violet', customer_reported: 'amber', periodic: 'blue' };

// Rule severity → the colour of the dot next to each system-detected rule.
const SEV_DOT = { critical: 'bg-red-500', moderate: 'bg-amber-500', routine: 'bg-emerald-500' };

function ago(iso) {
  if (!iso) return '';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.round(hrs / 24)}d ago`;
}

const km = (n) => (n === null || n === undefined ? null : `${Number(n).toLocaleString()} km`);

function dueDate(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// The "why the system flagged this" panel — only rendered for a system-generated request that carries a
// trigger_detail snapshot. Shows each rule that fired (with its human reason), the mileage/threshold/
// overdue/due values behind it, and the suggested checklist the Inspector will confirm at the Decide step.
function SystemDetail({ detail, suggested }) {
  const rules = Array.isArray(detail?.rules) ? detail.rules : [];
  const svc = detail?.service || null;

  const values = [
    ['Current mileage', km(svc?.current_km)],
    ['Service interval', km(svc?.interval_km)],
    ['Overdue by', km(svc?.overdue_km)],
    ['Next due', dueDate(svc?.next_due_at)],
  ].filter(([, v]) => v);

  const chips = (suggested && suggested.length
    ? suggested
    : rules.flatMap((r) => r.finding_keywords || []))
    .filter((v, i, a) => v && a.indexOf(v) === i);

  return (
    <div className="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/50 px-3 py-2.5">
      <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
        <span aria-hidden>🤖</span> Why the system flagged this
      </div>

      {rules.length > 0 && (
        <ul className="mt-1.5 space-y-1.5">
          {rules.map((r, i) => (
            <li key={r.key || i} className="flex items-start gap-2 text-xs text-slate-700">
              <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${SEV_DOT[r.severity] || 'bg-slate-400'}`} />
              <span>
                <span className="font-medium text-slate-800">{r.label}</span>
                {r.why && <span className="text-slate-500"> — {r.why}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}

      {values.length > 0 && (
        <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
          {values.map(([label, value]) => (
            <div key={label} className="flex items-center justify-between gap-2 text-[11px]">
              <dt className="text-slate-400">{label}</dt>
              <dd className="font-mono font-medium text-slate-700">{value}</dd>
            </div>
          ))}
        </dl>
      )}

      {chips.length > 0 && (
        <div className="mt-2">
          <p className="text-[11px] text-slate-400">Suggested checks for the inspector</p>
          <div className="mt-1 flex flex-wrap gap-1">
            {chips.map((c) => (
              <span key={c} className="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200">
                {c}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function RequestCard({ tk, onApprove, onReject, onAcknowledge, ackBusy }) {
  const reasonTone = REASON_TONE[tk.trigger_reason] || 'slate';
  const reasonLabel = REASON_LABEL[tk.trigger_reason] || tk.trigger_reason;
  const requested = tk.handoffs?.requested;
  // System-generated when there's no human requester on the request handoff (the mileage scanner raises
  // it with requested_by = null). trigger_detail carries the "why" snapshot for these.
  const isSystem = !requested?.user_id;
  // Raised by the Proactive Diagnostic Monitor before the review gate existed — already sitting in the
  // Inspector's actual queue (workflow_status = inspection_requested), never reviewed. Approve/Reject
  // don't apply (there's no stage left to send it to or pull it back from); Acknowledge just closes the
  // accountability gap. See MaintenanceWorkflowService::pendingReview().
  const isLegacy = !!tk.is_legacy_unreviewed;
  // The car is out on hire — it can't be sent for inspection until it's physically back, so approval is
  // held (the downtime clock still counts against it; see the 15-day test-based rule).
  const awaitingReturn = tk.operational_status === 'rented';

  return (
    <div className={`rounded-xl border bg-white p-4 shadow-soft ${isSystem ? 'border-indigo-200' : 'border-slate-200'}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/vehicles/${tk.vehicle_id}`} className="font-mono text-sm font-bold text-indigo-600 hover:text-indigo-700">
            {tk.plate || `#${tk.id}`}
          </Link>
          <p className="truncate text-xs text-slate-400">{tk.car || 'Vehicle'}</p>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          {isSystem && (
            <Badge tone="indigo"><span aria-hidden>🤖</span> System Auto-Check</Badge>
          )}
          <Badge tone={reasonTone}>{reasonLabel}</Badge>
        </div>
      </div>

      {/* Human-raised requests carry the requester's free-text complaint; a system request shows its
          agenda line here too, with the full rule breakdown in the panel below. */}
      {tk.customer_complaint && (
        <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">“{tk.customer_complaint}”</p>
      )}

      {isSystem && (tk.trigger_detail || (tk.suggested_findings || []).length > 0) && (
        <SystemDetail detail={tk.trigger_detail} suggested={tk.suggested_findings} />
      )}

      {isLegacy && (
        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
          Raised before this review queue existed — already in Abu Maroof's queue and actionable there.
          Acknowledge to close the sign-off gap; nothing else changes.
        </p>
      )}

      {/* Last real inspection this car had before this request — context for the reviewer. */}
      <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Last test</p>
        {tk.last_test ? (
          <div className="mt-0.5">
            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-slate-700">
              {tk.last_test.severity && (
                <span className={`h-1.5 w-1.5 rounded-full ${SEV_DOT[tk.last_test.severity] || 'bg-slate-400'}`} title={tk.last_test.severity} />
              )}
              <span className="font-medium">{dueDate(tk.last_test.at) || '—'}</span>
              <span className="text-slate-400">· {tk.last_test.ago_days}d ago</span>
              {tk.last_test.by && <span className="text-slate-400">· by {tk.last_test.by}</span>}
            </div>
            {tk.last_test.summary && <p className="mt-0.5 text-[11px] text-slate-500">{tk.last_test.summary}</p>}
          </div>
        ) : (
          <p className="mt-0.5 text-xs text-slate-400">Never inspected before</p>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between text-xs text-slate-400">
        <span>Requested by {isSystem ? 'the system' : (requested?.name || 'a driver')} · {ago(requested?.at)}</span>
      </div>

      {!isLegacy && awaitingReturn && (
        <p className="mt-3 flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
          <Icon.Clock className="h-3.5 w-3.5 shrink-0" />
          Waiting for return — the car is with a customer. Review it once it's back and available to inspect.
        </p>
      )}

      <div className="mt-3 flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
        {isLegacy ? (
          <Button size="sm" variant="secondary" loading={ackBusy} onClick={() => onAcknowledge(tk)}>
            <Icon.Check className="h-3.5 w-3.5" /> Acknowledge
          </Button>
        ) : (
          <>
            <Button size="sm" variant="danger" disabled={awaitingReturn} onClick={() => onReject(tk)}>
              <Icon.XCircle className="h-3.5 w-3.5" /> Reject
            </Button>
            <Button size="sm" variant="success" disabled={awaitingReturn} onClick={() => onApprove(tk)}>
              <Icon.Check className="h-3.5 w-3.5" /> Approve
            </Button>
          </>
        )}
      </div>
    </div>
  );
}

function ApproveModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      await api.post(`/maintenance-tickets/${ticket.id}/review/approve`, { notes: notes || undefined });
      onDone('Approved — sent to Abu Maroof');
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not approve this request');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Approve inspection request"
      subtitle={`${ticket.plate || `#${ticket.id}`} · will be sent to Abu Maroof`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="success" onClick={submit} loading={busy}>Approve &amp; send</Button>
        </>
      )}
    >
      <Textarea
        label="Notes (optional)"
        rows={3}
        value={notes}
        onChange={(e) => setNotes(e.target.value)}
        placeholder="Anything Abu Maroof should know…"
      />
    </Modal>
  );
}

function RejectModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!reason.trim()) {
      toast.error('Say why this request is being rejected');
      return;
    }
    setBusy(true);
    try {
      await api.post(`/maintenance-tickets/${ticket.id}/review/reject`, { rejection_reason: reason });
      onDone('Inspection request rejected');
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not reject this request');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Reject inspection request"
      subtitle={`${ticket.plate || `#${ticket.id}`} · nothing will be sent externally`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="danger" onClick={submit} loading={busy}>Reject</Button>
        </>
      )}
    >
      <Textarea
        label="Rejection reason"
        required
        rows={3}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Why is this request being rejected?"
      />
    </Modal>
  );
}

export default function InspectionReviewQueue() {
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('maintenance.manage');
  const [modal, setModal] = useState(null); // { action: 'approve'|'reject'|'test'|'complaint', ticket }
  const [vehicles, setVehicles] = useState([]);

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/pending-review')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 8000,
    paused: () => !!modal,
  });

  const tickets = data || [];

  // Pickers for the New Test / New Complaint intake modals — only Ready + Rented cars.
  useEffect(() => {
    let alive = true;
    api.get('/Vehicle')
      .then((v) => {
        if (!alive) return;
        const list = v.data?.data;
        const all = Array.isArray(list) ? list : list?.items || [];
        setVehicles(all.filter((veh) => ['ready', 'rented'].includes(veh.status)));
      })
      .catch(() => { /* pickers stay empty */ });
    return () => { alive = false; };
  }, []);

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });
  };

  const [ackBusyId, setAckBusyId] = useState(null);
  const onAcknowledge = async (tk) => {
    setAckBusyId(tk.id);
    try {
      await api.post(`/maintenance-tickets/${tk.id}/review/acknowledge-legacy`);
      toast.success('Acknowledged');
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not acknowledge this request');
    } finally {
      setAckBusyId(null);
    }
  };

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6, display: 'flex', alignItems: 'center', gap: 10 }}>
              Controller Approval Gate
              {!loading && <span style={{ color: 'var(--cyan)', fontWeight: 700 }}>· {tickets.length} awaiting</span>}
            </div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>Inspection Review Queue</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>Requests awaiting Controller approval before they reach Abu Maroof.</p>
          </div>
          <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
            {canManage && <button className="opx-btn primary" onClick={() => setModal({ action: 'test' })}>+ Request Inspection</button>}
            {canManage && <button className="opx-btn" onClick={() => setModal({ action: 'complaint' })}>📣 New complaint</button>}
          </div>
        </div>

        {error && <div style={{ borderRadius: 12, border: '1px solid rgba(251,113,133,.3)', background: 'rgba(251,113,133,.08)', color: '#fb7185', padding: '12px 16px', fontSize: 13 }}>{error}</div>}

        {loading ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Skeleton className="h-32 rounded-xl" />
            <Skeleton className="h-32 rounded-xl" />
          </div>
        ) : tickets.length === 0 ? (
          <EmptyState
            icon={<Icon.Check className="h-7 w-7" />}
            title="All caught up"
            message="No inspection requests are waiting for review."
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {tickets.map((tk) => (
              <RequestCard
                key={tk.id}
                tk={tk}
                onApprove={(t) => setModal({ action: 'approve', ticket: t })}
                onReject={(t) => setModal({ action: 'reject', ticket: t })}
                onAcknowledge={onAcknowledge}
                ackBusy={ackBusyId === tk.id}
              />
            ))}
          </div>
        )}
      </div>

      {modal?.action === 'approve' && (
        <ApproveModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'reject' && (
        <RejectModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Test Intake — the tabbed front door: Routine (oil/battery/tyres) · Scheduled (park-time +
          Breakdown) · Accidents (→ Damage & Accidents log). Same entry point as the full board. */}
      {modal?.action === 'test' && (
        <TestIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Complaint Intake — logs a customer complaint straight into Abu Maroof's triage lane. */}
      {modal?.action === 'complaint' && (
        <ComplaintIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
    </div>
  );
}
