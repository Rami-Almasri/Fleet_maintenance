import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Card, PageHeader, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select, Textarea } from '../components/ui/Field';
import { SHOW_FINANCIALS } from '../config/features';
import { aed, fmtAgo, fmtDate, num } from '../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const TYPE_META = {
  duplicate_purchase: { tone: 'violet', label: 'Duplicate purchase' },
  fault_recurrence: { tone: 'amber', label: 'Fault recurrence' },
};
const PRIORITY_META = {
  high: { tone: 'red', label: 'High' },
  medium: { tone: 'amber', label: 'Medium' },
  low: { tone: 'gray', label: 'Low' },
};
const STATUS_META = {
  open: { tone: 'blue', label: 'Open' },
  under_review: { tone: 'cyan', label: 'Under review' },
  reason_provided: { tone: 'violet', label: 'Reason provided' },
  approved: { tone: 'green', label: 'Approved' },
  rejected: { tone: 'red', label: 'Rejected' },
  closed: { tone: 'gray', label: 'Closed' },
};
const STATUS_FILTERS = ['open', 'under_review', 'reason_provided', 'approved', 'rejected', 'closed'];

// Same five reasons the buyer selects at purchase time.
const REASONS = [
  ['previous_part_failed', 'Previous part failed'],
  ['wrong_diagnosis', 'Wrong diagnosis'],
  ['customer_requested', 'Customer requested replacement'],
  ['accident_damage', 'Accident/damage'],
  ['other', 'Other'],
];
const reasonLabel = (code) => REASONS.find(([v]) => v === code)?.[1] || code || '—';

// One-line summary of what triggered the investigation.
function contextSummary(inv) {
  const prev = inv.context?.previous;
  const days = inv.context?.days_between;
  const name = prev?.part_name || 'the part';
  const verb = inv.type === 'fault_recurrence' ? 'recurred' : 're-bought';
  let s = `${name} ${verb}`;
  if (days != null) s += ` after ${num(days)} day(s)`;
  if (SHOW_FINANCIALS && prev?.purchase_price != null) s += ` · prev ${aed(prev.purchase_price)}${prev.currency && prev.currency !== 'AED' ? ` ${prev.currency}` : ''}`;
  return s;
}

// ─── Provide-reason modal ────────────────────────────────────────────────────
function ReasonModal({ open, inv, onClose, onDone }) {
  const toast = useToast();
  const [code, setCode] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  // Reset when the modal opens for a (new) investigation.
  useEffect(() => {
    if (!open) return;
    setCode(inv?.reason_code || '');
    setNote(inv?.reason_note || '');
    setError('');
  }, [open, inv]);

  const submit = async () => {
    if (!code) { setError('Pick a reason'); return; }
    setSaving(true);
    try {
      await api.post(`/part-investigations/${inv.id}/provide-reason`, { reason_code: code, reason_note: note.trim() || null });
      toast.success('Reason recorded');
      onDone();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not save the reason');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Provide Reason"
      subtitle={inv ? inv.context?.previous?.part_name : ''}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Save reason</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Select label="Reason" required value={code} error={error} onChange={(e) => setCode(e.target.value)}>
          <option value="">Select a reason…</option>
          {REASONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </Select>
        <Textarea label="Note" rows={3} placeholder="Optional context" value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
    </Modal>
  );
}

// ─── Approve / reject note modal ─────────────────────────────────────────────
function DecisionModal({ open, inv, action, onClose, onDone }) {
  const toast = useToast();
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const isReject = action === 'reject';

  useEffect(() => { if (open) setNote(''); }, [open, inv, action]);

  const submit = async () => {
    setSaving(true);
    try {
      await api.post(`/part-investigations/${inv.id}/${action}`, { note: note.trim() || null });
      toast.success(isReject ? 'Investigation rejected' : 'Investigation approved');
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
      title={isReject ? 'Reject Investigation' : 'Approve Investigation'}
      subtitle={inv ? contextSummary(inv) : ''}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant={isReject ? 'danger' : 'success'} onClick={submit} loading={saving}>{isReject ? 'Reject' : 'Approve'}</Button>
        </>
      }
    >
      <Textarea
        label="Note"
        rows={3}
        placeholder={isReject ? 'Why is this being rejected? (optional)' : 'Add a note (optional)'}
        value={note}
        onChange={(e) => setNote(e.target.value)}
      />
    </Modal>
  );
}

// ─── Detail modal ────────────────────────────────────────────────────────────
function DetailModal({ open, inv, onClose }) {
  if (!inv) return null;
  const prev = inv.context?.previous;
  const cur = inv.purchase;
  const money = (p) => (SHOW_FINANCIALS && p?.purchase_price != null ? `${aed(p.purchase_price)}${p.currency && p.currency !== 'AED' ? ` ${p.currency}` : ''}` : null);

  const PurchaseCard = ({ title, tone, p, extra }) => (
    <div className={`rounded-xl p-4 ring-1 ring-inset ${tone}`}>
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>
      {p ? (
        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between gap-4"><dt className="text-slate-500">Part</dt><dd className="font-medium text-slate-900">{p.part_name || '—'}</dd></div>
          <div className="flex justify-between gap-4"><dt className="text-slate-500">When</dt><dd className="text-slate-700">{fmtDate(p.purchased_at)}</dd></div>
          <div className="flex justify-between gap-4"><dt className="text-slate-500">Source</dt><dd className="text-slate-700 capitalize">{p.source || '—'}</dd></div>
          <div className="flex justify-between gap-4"><dt className="text-slate-500">By</dt><dd className="text-slate-700">{p.purchased_by || '—'}</dd></div>
          {money(p) && <div className="flex justify-between gap-4"><dt className="text-slate-500">Cost</dt><dd className="text-slate-700">{money(p)}</dd></div>}
        </dl>
      ) : <p className="mt-2 text-sm text-slate-400">—</p>}
      {extra}
    </div>
  );

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Investigation Detail"
      subtitle={`${TYPE_META[inv.type]?.label || inv.type} · ${inv.vehicle?.plate || `#${inv.vehicle?.id}`}`}
      size="lg"
      footer={<Button variant="secondary" onClick={onClose}>Close</Button>}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={TYPE_META[inv.type]?.tone || 'gray'}>{TYPE_META[inv.type]?.label || inv.type}</Badge>
          <Badge tone={PRIORITY_META[inv.priority]?.tone || 'gray'}>{PRIORITY_META[inv.priority]?.label || inv.priority} priority</Badge>
          <Badge tone={STATUS_META[inv.status]?.tone || 'gray'}>{STATUS_META[inv.status]?.label || inv.status}</Badge>
          {inv.context?.part_class && <Badge tone="slate">Class: {inv.context.part_class}</Badge>}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <PurchaseCard title="This purchase" tone="bg-amber-50 ring-amber-600/15" p={cur} />
          <PurchaseCard title="Previous purchase" tone="bg-slate-50 ring-slate-200" p={prev} />
        </div>

        <div className="rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-inset ring-slate-200">
          <div className="flex justify-between gap-4"><span className="text-slate-500">Days between</span><span className="font-medium text-slate-900">{inv.context?.days_between != null ? `${num(inv.context.days_between)} day(s)` : '—'}</span></div>
          <div className="flex justify-between gap-4"><span className="text-slate-500">Window</span><span className="text-slate-700">{inv.context?.window_days != null ? `${num(inv.context.window_days)} day(s)` : '—'}</span></div>
          <div className="flex justify-between gap-4"><span className="text-slate-500">Opened by</span><span className="text-slate-700">{inv.opened_by || '—'} · {fmtAgo(inv.opened_at) || '—'}</span></div>
          {inv.reason_code && <div className="flex justify-between gap-4"><span className="text-slate-500">Reason</span><span className="text-slate-700">{reasonLabel(inv.reason_code)}{inv.reason_by ? ` · ${inv.reason_by}` : ''}</span></div>}
          {inv.reason_note && <p className="mt-1 text-slate-600">“{inv.reason_note}”</p>}
          {inv.resolution && <div className="flex justify-between gap-4"><span className="text-slate-500">Resolution</span><span className="text-slate-700">{inv.resolution}{inv.resolved_by ? ` · ${inv.resolved_by}` : ''}</span></div>}
        </div>
      </div>
    </Modal>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────
export default function PartInvestigations() {
  const toast = useToast();
  const { can } = usePermissions();
  const allowed = can('parts.investigate');

  const [status, setStatus] = useState('open');
  const [type, setType] = useState('');
  const [priority, setPriority] = useState('');

  const [reasonFor, setReasonFor] = useState(null);
  const [decision, setDecision] = useState(null); // { inv, action }
  const [detail, setDetail] = useState(null);
  const anyModal = !!reasonFor || !!decision || !!detail;

  // `all=1` pulls resolved rows too when the status filter targets a terminal state.
  const fetcher = useCallback(async () => {
    const includeAll = ['approved', 'rejected', 'closed', ''].includes(status);
    const r = await api.get('/part-investigations', { params: { status: status || undefined, type: type || undefined, all: includeAll ? 1 : undefined } });
    return payload(r) || {};
  }, [status, type]);
  const { data, loading, error, reload } = useFetch(fetcher, [status, type], {
    refreshInterval: 30000,
    paused: () => anyModal,
  });

  const investigations = useMemo(() => data?.investigations || [], [data]);

  const priorityCounts = useMemo(() => {
    const acc = { high: 0, medium: 0, low: 0 };
    for (const i of investigations) if (acc[i.priority] != null) acc[i.priority] += 1;
    return acc;
  }, [investigations]);

  const filtered = useMemo(
    () => (priority ? investigations.filter((i) => i.priority === priority) : investigations),
    [investigations, priority],
  );

  const [busy, setBusy] = useState(null);
  const startReview = async (inv) => {
    setBusy(`${inv.id}:review`);
    try {
      await api.post(`/part-investigations/${inv.id}/review`, {});
      toast.success('Review started');
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not start review');
    } finally {
      setBusy(null);
    }
  };

  if (!allowed) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <PageHeader title="Part Investigations" />
          <Card className="mt-6">
            <EmptyState title="You don't have access" message="This inbox is limited to staff with the parts investigation permission." />
          </Card>
        </div>
      </div>
    );
  }

  const rowActions = (inv) => {
    const actions = [];
    if (inv.status === 'open') {
      actions.push(<Button key="review" variant="secondary" size="sm" loading={busy === `${inv.id}:review`} onClick={() => startReview(inv)}>Start Review</Button>);
    }
    if (['open', 'under_review'].includes(inv.status)) {
      actions.push(<Button key="reason" variant="ghost" size="sm" onClick={() => setReasonFor(inv)}>Provide Reason</Button>);
    }
    if (['under_review', 'reason_provided'].includes(inv.status)) {
      actions.push(
        <Button key="approve" variant="success" size="sm" onClick={() => setDecision({ inv, action: 'approve' })}>Approve</Button>,
        <Button key="reject" variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setDecision({ inv, action: 'reject' })}>Reject</Button>,
      );
    }
    return actions;
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Part Investigations"
          subtitle={loading ? '…' : `${num(filtered.length)} open item${filtered.length === 1 ? '' : 's'} for review`}
        />

        {/* Priority tiles (click to filter) */}
        <div className="grid grid-cols-3 gap-4">
          {['high', 'medium', 'low'].map((p) => (
            <button
              key={p}
              onClick={() => setPriority(priority === p ? '' : p)}
              className={`hover-lift flex flex-col rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${priority === p ? 'ring-2 ring-indigo-500' : ''}`}
            >
              <span className="flex items-center gap-2">
                <span className={`h-1.5 w-1.5 rounded-full ${p === 'high' ? 'bg-red-500' : p === 'medium' ? 'bg-amber-500' : 'bg-slate-400'}`} />
                <span className="text-xs font-medium text-slate-500">{PRIORITY_META[p].label} priority</span>
              </span>
              <span className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(priorityCounts[p])}</span>
            </button>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <Select className="sm:w-52" value={status} onChange={(e) => setStatus(e.target.value)}>
            {STATUS_FILTERS.map((s) => <option key={s} value={s}>{STATUS_META[s].label}</option>)}
            <option value="">All statuses</option>
          </Select>
          <Select className="sm:w-52" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">All types</option>
            <option value="duplicate_purchase">Duplicate purchase</option>
            <option value="fault_recurrence">Fault recurrence</option>
          </Select>
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
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Type</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Priority</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">What happened</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Status</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Opened</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">Actions</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {filtered.map((inv) => (
                    <tr key={inv.id} className="cursor-pointer bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40" onClick={() => setDetail(inv)}>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{inv.vehicle?.plate || (inv.vehicle?.id ? `#${inv.vehicle.id}` : '—')}</div>
                        <div className="text-xs text-slate-400">{[inv.vehicle?.make, inv.vehicle?.model].filter(Boolean).join(' ') || '—'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={TYPE_META[inv.type]?.tone || 'gray'}>{TYPE_META[inv.type]?.label || inv.type}</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={PRIORITY_META[inv.priority]?.tone || 'gray'}>{PRIORITY_META[inv.priority]?.label || inv.priority}</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 max-w-sm text-slate-600">{contextSummary(inv)}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={STATUS_META[inv.status]?.tone || 'gray'}>{STATUS_META[inv.status]?.label || inv.status}</Badge>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{inv.opened_by || '—'}</div>
                        <div className="text-xs text-slate-400">{fmtAgo(inv.opened_at) || ''}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5" onClick={(e) => e.stopPropagation()}>
                        <div className="flex justify-end gap-2">
                          {rowActions(inv).length ? rowActions(inv) : <span className="text-slate-300">—</span>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && (
              <EmptyState title="Nothing to investigate" message="No investigations match these filters." />
            )}
          </div>
        </Card>
      </div>

      <ReasonModal open={!!reasonFor} inv={reasonFor} onClose={() => setReasonFor(null)} onDone={() => reload({ silent: true })} />
      <DecisionModal
        open={!!decision}
        inv={decision?.inv}
        action={decision?.action}
        onClose={() => setDecision(null)}
        onDone={() => reload({ silent: true })}
      />
      <DetailModal open={!!detail} inv={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
