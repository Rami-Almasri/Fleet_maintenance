import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select, Textarea } from '../components/ui/Field';
import { fmtAgo, fmtDate, num } from '../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const STATUS_META = {
  open: { tone: 'amber', label: 'Open' },
  decided: { tone: 'gray', label: 'Decided' },
};
const STATUS_FILTERS = ['open', 'decided'];

// The five management decisions — the ONLY place responsibility is assigned (by a human, never the system).
const DECISIONS = [
  ['same_repair_failed', 'Same repair failed'],
  ['new_unrelated_failure', 'New unrelated failure'],
  ['workshop_responsibility', 'Workshop responsibility'],
  ['customer_misuse', 'Customer misuse'],
  ['investigation_required', 'Investigation required'],
];
const DECISION_META = {
  same_repair_failed: { tone: 'red', label: 'Same repair failed' },
  new_unrelated_failure: { tone: 'blue', label: 'New unrelated failure' },
  workshop_responsibility: { tone: 'amber', label: 'Workshop responsibility' },
  customer_misuse: { tone: 'violet', label: 'Customer misuse' },
  investigation_required: { tone: 'cyan', label: 'Investigation required' },
};
const decisionLabel = (code) => DECISION_META[code]?.label || code || '—';

const PREV_RESULT_META = {
  verified_fixed: { tone: 'green', label: 'Verified fixed' },
  fixed: { tone: 'slate', label: 'Fixed' },
};

const plate = (v) => v?.plate || (v?.id ? `#${v.id}` : '—');
const carLine = (v) => [v?.make, v?.model].filter(Boolean).join(' ') || '—';
const km = (n) => (n != null ? `${num(n)} km` : '—');
const days = (n) => (n != null ? `${num(n)} day${n === 1 ? '' : 's'}` : '—');

// ─── Decision modal ──────────────────────────────────────────────────────────
function DecisionModal({ open, review, onClose, onDone }) {
  const toast = useToast();
  const [code, setCode] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!code) { setError('Pick a decision'); return; }
    setSaving(true);
    try {
      await api.post(`/recurring-fault-reviews/${review.id}/decide`, { decision: code, note: note.trim() || null });
      toast.success('Decision recorded');
      onDone();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not save the decision');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Record Decision"
      subtitle={review ? `${review.symptom} · ${plate(review.vehicle)}` : ''}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Save decision</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Select label="Decision" required value={code} error={error} onChange={(e) => setCode(e.target.value)}>
          <option value="">Select a decision…</option>
          {DECISIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </Select>
        <Textarea label="Note" rows={3} placeholder="Optional context for this decision" value={note} onChange={(e) => setNote(e.target.value)} />
        <p className="text-xs text-slate-400">
          Recording a decision does not blame the garage automatically — it is your judgement on why this fault came back.
        </p>
      </div>
    </Modal>
  );
}

// ─── Detail modal ────────────────────────────────────────────────────────────
function DetailModal({ open, review, onClose }) {
  if (!review) return null;
  const parts = review.parts || [];

  const Row = ({ label, value }) => (
    <div className="flex justify-between gap-4 py-0.5"><dt className="text-slate-500">{label}</dt><dd className="text-end font-medium text-slate-900">{value}</dd></div>
  );

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Recurring Fault Detail"
      subtitle={`${review.symptom} · ${plate(review.vehicle)} ${carLine(review.vehicle) !== '—' ? `(${carLine(review.vehicle)})` : ''}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Close</Button>}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={STATUS_META[review.status]?.tone || 'gray'}>{STATUS_META[review.status]?.label || review.status}</Badge>
          {review.previous_result && (
            <Badge tone={PREV_RESULT_META[review.previous_result]?.tone || 'slate'}>
              Previous: {PREV_RESULT_META[review.previous_result]?.label || review.previous_result}
            </Badge>
          )}
          <Badge tone="slate">Happened {num(review.occurrence_count)}×</Badge>
          {review.decision && <Badge tone={DECISION_META[review.decision]?.tone || 'gray'}>{decisionLabel(review.decision)}</Badge>}
        </div>

        {/* Recurrence banner */}
        <div className="rounded-xl bg-red-50 p-4 text-sm ring-1 ring-inset ring-red-600/15">
          <p className="font-semibold text-red-800">Recurring fault detected</p>
          <p className="mt-0.5 text-red-700">
            This vehicle came back with “{review.symptom}” {days(review.days_since_repair)} after it was repaired
            {review.previous_garage ? ` at ${review.previous_garage}` : ''}
            {review.distance_since_repair != null ? `, having driven ${km(review.distance_since_repair)} since` : ''}.
          </p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {/* Previous repair */}
          <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Previous repair</p>
            <dl className="mt-2 text-sm">
              <Row label="Ticket" value={review.previous_maintenance_id ? `#${review.previous_maintenance_id}` : '—'} />
              <Row label="Garage" value={review.previous_garage || '—'} />
              <Row label="Repaired" value={fmtDate(review.previous_repaired_at) || '—'} />
              <Row label="Result" value={PREV_RESULT_META[review.previous_result]?.label || '—'} />
              <Row label="Odometer" value={km(review.previous_odometer)} />
            </dl>
            {parts.length > 0 && (
              <div className="mt-2">
                <p className="text-xs font-medium text-slate-500">Parts replaced</p>
                <div className="mt-1 flex flex-wrap gap-1.5">
                  {parts.map((p, i) => (
                    <span key={i} className="rounded-full bg-white px-2 py-0.5 text-xs text-slate-700 ring-1 ring-inset ring-slate-200">
                      {p.description || p.part_number || 'Part'}{p.part_number && p.description ? ` · ${p.part_number}` : ''}
                    </span>
                  ))}
                </div>
              </div>
            )}
          </div>

          {/* This occurrence */}
          <div className="rounded-xl bg-amber-50 p-4 ring-1 ring-inset ring-amber-600/15">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">This occurrence (confirmed)</p>
            <dl className="mt-2 text-sm">
              <Row label="Ticket" value={review.maintenance_id ? `#${review.maintenance_id}` : '—'} />
              <Row label="Current odometer" value={km(review.current_odometer)} />
              <Row label="Distance since repair" value={km(review.distance_since_repair)} />
              <Row label="Days since repair" value={days(review.days_since_repair)} />
              <Row label="Times fixed before" value={num(Math.max(0, (review.occurrence_count || 1) - 1))} />
            </dl>
          </div>
        </div>

        <div className="rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-inset ring-slate-200">
          <div className="flex justify-between gap-4"><span className="text-slate-500">Opened</span><span className="text-slate-700">{review.opened_by || '—'} · {fmtAgo(review.opened_at) || '—'}</span></div>
          {review.decision && <div className="flex justify-between gap-4"><span className="text-slate-500">Decision</span><span className="text-slate-700">{decisionLabel(review.decision)}{review.decided_by ? ` · ${review.decided_by}` : ''}</span></div>}
          {review.decision_note && <p className="mt-1 text-slate-600">“{review.decision_note}”</p>}
        </div>
      </div>
    </Modal>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────
export default function RecurringFaultReviews() {
  const toast = useToast();
  const { can } = usePermissions();
  const allowed = can('maintenance.recurring.view');
  const canDecide = can('maintenance.recurring.manage');
  const [busyGate, setBusyGate] = useState(null);

  const [status, setStatus] = useState('open');
  const [decisionFilter, setDecisionFilter] = useState('');

  const [decisionFor, setDecisionFor] = useState(null);
  const [detail, setDetail] = useState(null);
  const anyModal = !!decisionFor || !!detail;

  const fetcher = useCallback(async () => {
    const r = await api.get('/recurring-fault-reviews', {
      params: { status: status || undefined, decision: decisionFilter || undefined },
    });
    return payload(r) || {};
  }, [status, decisionFilter]);
  const { data, loading, error, reload } = useFetch(fetcher, [status, decisionFilter], {
    refreshInterval: 30000,
    paused: () => anyModal,
  });

  const reviews = useMemo(() => data?.reviews || [], [data]);
  const openCount = useMemo(() => reviews.filter((r) => r.status === 'open').length, [reviews]);

  // Clear (approve) or reject a blocked recurring-fault repair straight from the inbox.
  const gateAction = async (r, decision) => {
    setBusyGate(`${r.id}:${decision}`);
    try {
      await api.post(`/maintenance-tasks/${r.maintenance_task_id}/repair-approval`, { decision });
      toast.success(decision === 'approve' ? 'Repair approved' : 'Repair rejected');
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not update the repair gate');
    } finally {
      setBusyGate(null);
    }
  };

  if (!allowed) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <PageHeader title="Recurring Fault Reviews" />
          <Card className="mt-6">
            <EmptyState title="You don't have access" message="This inbox is limited to staff with the recurring-fault review permission." />
          </Card>
        </div>
      </div>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Recurring Fault Reviews"
          subtitle="Cars that returned with the SAME confirmed fault after a completed repair. Review the evidence and decide why it came back."
        />

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <Select className="sm:w-52" value={status} onChange={(e) => setStatus(e.target.value)}>
            {STATUS_FILTERS.map((s) => <option key={s} value={s}>{STATUS_META[s].label}</option>)}
            <option value="">All statuses</option>
          </Select>
          <Select className="sm:w-64" value={decisionFilter} onChange={(e) => setDecisionFilter(e.target.value)}>
            <option value="">All decisions</option>
            {DECISIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <div className="flex items-center text-sm text-slate-500 sm:ml-auto">
            {loading ? '…' : `${num(openCount)} open · ${num(reviews.length)} shown`}
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Vehicle</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Fault</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Previous repair</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Since repair</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Times</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Status</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">Actions</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {reviews.map((r) => (
                    <tr key={r.id} className="cursor-pointer bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40" onClick={() => setDetail(r)}>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{plate(r.vehicle)}</div>
                        <div className="text-xs text-slate-400">{carLine(r.vehicle)}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 max-w-xs">
                        <div className="truncate font-medium text-slate-800">{r.symptom}</div>
                        <div className="text-xs text-slate-400">{r.previous_result === 'verified_fixed' ? 'Previously verified fixed' : 'Previously fixed'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{r.previous_garage || '—'}</div>
                        <div className="text-xs text-slate-400">{r.previous_maintenance_id ? `#${r.previous_maintenance_id}` : ''} · {fmtDate(r.previous_repaired_at) || '—'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{days(r.days_since_repair)}</div>
                        <div className="text-xs text-slate-400">{km(r.distance_since_repair)}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={r.occurrence_count > 2 ? 'red' : 'amber'}>{num(r.occurrence_count)}×</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex flex-col items-start gap-1">
                          {r.decision
                            ? <Badge tone={DECISION_META[r.decision]?.tone || 'gray'}>{decisionLabel(r.decision)}</Badge>
                            : <Badge tone={STATUS_META[r.status]?.tone || 'gray'}>{STATUS_META[r.status]?.label || r.status}</Badge>}
                          {r.repair_gate === 'pending' && <Badge tone="red">Repair blocked</Badge>}
                          {r.repair_gate === 'approved' && <Badge tone="green">Repair approved</Badge>}
                          {r.repair_gate === 'rejected' && <Badge tone="gray">Repair rejected</Badge>}
                        </div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5" onClick={(e) => e.stopPropagation()}>
                        <div className="flex flex-wrap justify-end gap-2">
                          {r.repair_gate === 'pending' && canDecide && (
                            <>
                              <Button variant="success" size="sm" loading={busyGate === `${r.id}:approve`} onClick={() => gateAction(r, 'approve')}>Approve repair</Button>
                              <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" loading={busyGate === `${r.id}:reject`} onClick={() => gateAction(r, 'reject')}>Reject</Button>
                            </>
                          )}
                          {r.status === 'open' && canDecide && (
                            <Button variant="secondary" size="sm" onClick={() => setDecisionFor(r)}>Record Decision</Button>
                          )}
                          {!(r.repair_gate === 'pending' && canDecide) && !(r.status === 'open' && canDecide) && (
                            <span className="text-slate-300">—</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && reviews.length === 0 && (
              <EmptyState title="No recurring faults" message="No confirmed fault has recurred after a completed repair for these filters." />
            )}
          </div>
        </Card>
      </div>

      <DecisionModal open={!!decisionFor} review={decisionFor} onClose={() => setDecisionFor(null)} onDone={() => reload({ silent: true })} />
      <DetailModal open={!!detail} review={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
