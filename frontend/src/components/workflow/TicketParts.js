import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useToast } from '../ui/Toast';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { Input, Select, Textarea } from '../ui/Field';
import { SHOW_FINANCIALS } from '../../config/features';
import { fmtAgo, aed, num } from '../../lib/format';
import { canOrderParts } from './meta';

// Envelope-aware unwrap: the API wraps most payloads in { data: … }.
const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

// Lifecycle → badge tone/label (kept in sync with the standalone Parts page).
const STATUS_TONE = {
  requested: 'slate', under_review: 'blue', approved: 'cyan',
  purchased: 'violet', installed: 'amber', completed: 'green',
  rejected: 'red', cancelled: 'gray',
};
const STATUS_LABEL = {
  requested: 'Requested', under_review: 'Under review', approved: 'Approved',
  purchased: 'Purchased', installed: 'Installed', completed: 'Completed',
  rejected: 'Rejected', cancelled: 'Cancelled',
};
const CLASS_TONE = { consumable: 'gray', standard: 'blue', major: 'amber' };
const CLASS_LABEL = { consumable: 'Consumable', standard: 'Standard', major: 'Major' };

// A read-only "comes from the ticket" fact.
function ContextFact({ label, value }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="truncate text-sm font-semibold text-slate-800">{value || '—'}</dd>
    </div>
  );
}

// ─── Request modal (pre-filled from the ticket) ──────────────────────────────
// A part is ALWAYS requested against a ticket: Vehicle → Maintenance Ticket → Fault all come straight
// from the ticket (read-only). The technician only types Part Name / Quantity / Price / Reason / Notes.
// As the part name is typed we check the vehicle's recent purchase history and warn on a likely repeat
// (the backend also alerts the admins on submit).
function TicketPartRequestModal({ open, onClose, onCreated, ticket, tasks }) {
  const toast = useToast();

  // Only OPEN faults are worth requesting a part against (a cancelled/mis-diagnosed one isn't repaired).
  const faultOptions = useMemo(
    () => (tasks || []).filter((t) => !t.is_incorrect && t.status !== 'cancelled'),
    [tasks],
  );

  const [form, setForm] = useState(null);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [dup, setDup] = useState(null);       // live duplicate verdict for the current part name
  const [checking, setChecking] = useState(false);

  // Reset on open, pre-selecting the sole/first open fault so a single-fault ticket needs no picking.
  useEffect(() => {
    if (!open) return;
    setForm({
      maintenance_task_id: faultOptions.length === 1 ? String(faultOptions[0].id) : '',
      part_name: '',
      part_number: '',
      quantity: 1,
      reason: '',
      estimated_price: '',
      currency: 'AED',
      notes: '',
    });
    setErrors({});
    setDup(null);
  }, [open, faultOptions]);

  const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  // The fault the part is for — drives the stronger Vehicle + Part + Fault duplicate signal.
  const selectedFault = useMemo(() => {
    if (form?.maintenance_task_id) return faultOptions.find((t) => String(t.id) === String(form.maintenance_task_id)) || null;
    return faultOptions.length === 1 ? faultOptions[0] : null;
  }, [faultOptions, form?.maintenance_task_id]);

  // Live duplicate check — debounced on the part name/number (and fault) for THIS vehicle.
  const partName = form?.part_name || '';
  const partNumber = form?.part_number || '';
  const faultCategory = selectedFault?.category_key || '';
  const faultSymptom = selectedFault?.symptom || '';
  useEffect(() => {
    if (!open || !ticket?.vehicle_id || partName.trim().length < 2) { setDup(null); return undefined; }
    let alive = true;
    setChecking(true);
    const timer = setTimeout(() => {
      api.get('/part-purchases/duplicate-check', {
        params: {
          vehicle_id: ticket.vehicle_id,
          part_name: partName.trim(),
          part_number: partNumber.trim() || undefined,
          fault_category_key: faultCategory || undefined,
          fault_symptom: faultSymptom || undefined,
        },
      })
        .then((r) => { if (alive) setDup(payload(r)); })
        .catch(() => { if (alive) setDup(null); })
        .finally(() => { if (alive) setChecking(false); });
    }, 500);
    return () => { alive = false; clearTimeout(timer); };
  }, [open, ticket?.vehicle_id, partName, partNumber, faultCategory, faultSymptom]);

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body = {
        vehicle_id: ticket?.vehicle_id || null,
        maintenance_id: ticket?.id ? Number(ticket.id) : null,
        maintenance_task_id: form.maintenance_task_id ? Number(form.maintenance_task_id) : null,
        part_name: form.part_name.trim(),
        part_number: form.part_number.trim() || null,
        quantity: Number(form.quantity) || 1,
        reason: form.reason.trim(),
        estimated_price: form.estimated_price === '' ? null : Number(form.estimated_price),
        currency: form.currency || 'AED',
        notes: form.notes.trim() || null,
      };
      await api.post('/part-requests', body);
      toast.success(dup?.duplicate ? 'Request created — admins notified of a possible duplicate' : 'Part request created');
      onCreated?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(res?.message || res?.msg || 'Could not submit the request');
    } finally {
      setSaving(false);
    }
  };

  if (!form) return null;

  const prev = dup?.duplicate ? dup?.context?.previous : null;

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Request a Part"
      subtitle={ticket ? `${ticket.plate || `#${ticket.id}`}${ticket.car ? ` · ${ticket.car}` : ''}` : ''}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Create request</Button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Context — everything here is pulled straight from the ticket and cannot be edited. */}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl bg-slate-50 px-4 py-3.5 ring-1 ring-inset ring-slate-100 sm:grid-cols-4">
          <ContextFact label="Vehicle" value={ticket?.car} />
          <ContextFact label="Plate" value={ticket?.plate} />
          <ContextFact label="Maintenance Ticket" value={`#${ticket?.id}`} />
          <ContextFact label="Workshop" value={ticket?.garage} />
        </dl>

        {/* Fault — from the ticket. A single-fault ticket is pre-selected; a multi-fault one must pick. */}
        {faultOptions.length <= 1 ? (
          <ContextFact label="Fault" value={faultOptions[0]?.symptom || 'General (no specific fault)'} />
        ) : (
          <Select
            label="Fault"
            required
            value={form.maintenance_task_id}
            error={errors.maintenance_task_id?.[0]}
            onChange={(e) => set('maintenance_task_id', e.target.value)}
          >
            <option value="">Select the related fault…</option>
            {faultOptions.map((t) => (
              <option key={t.id} value={t.id}>{t.symptom || `Fault #${t.id}`}</option>
            ))}
          </Select>
        )}

        {/* Duplicate-purchase intelligence — surfaced BEFORE the request is created. */}
        {checking && partName.trim().length >= 2 && !dup && (
          <p className="text-xs text-slate-400">Checking this vehicle’s recent purchase history…</p>
        )}
        {prev && (
          <div className={`rounded-xl px-4 py-3 ring-1 ring-inset ${dup.priority === 'high' ? 'bg-red-50 text-red-800 ring-red-600/25' : 'bg-amber-50 text-amber-800 ring-amber-600/25'}`}>
            {dup.same_fault ? (
              <>
                <p className="flex items-center gap-1.5 font-bold">⚠️ Same Part — Same Fault</p>
                <p className="mt-1 text-sm">
                  This <span className="font-semibold">{prev.part_name}</span> was already bought for the same fault
                  {selectedFault?.symptom ? <> (<span className="font-semibold">{selectedFault.symptom}</span>)</> : ''}
                  {dup.days_between != null ? <> <span className="font-semibold">{num(dup.days_between)} day(s) ago</span></> : ' recently'}.
                  This likely means the previous repair failed — <span className="font-semibold">High priority</span>.
                </p>
              </>
            ) : (
              <>
                <p className="flex items-center gap-1.5 font-bold">⚠️ Duplicate Purchase Detected</p>
                <p className="mt-1 text-sm">
                  This vehicle already received <span className="font-semibold">{prev.part_name}</span>
                  {dup.days_between != null ? <> <span className="font-semibold">{num(dup.days_between)} day(s) ago</span></> : ' recently'}.
                </p>
              </>
            )}
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
              {prev.source_name && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">Supplier</dt><dd>{prev.source_name}</dd></div>
              )}
              {/* The prior-purchase price is the crux of the warning ("you already spent this") — a recorded
                  figure, not a computed roll-up, so it is shown even while SHOW_FINANCIALS hides other money. */}
              {prev.purchase_price != null && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">Cost</dt><dd>{aed(prev.purchase_price)}{prev.currency && prev.currency !== 'AED' ? ` ${prev.currency}` : ''}</dd></div>
              )}
              {prev.maintenance_id && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">Ticket</dt><dd>#{prev.maintenance_id}</dd></div>
              )}
            </dl>
            <p className="mt-2 text-[11px] font-medium opacity-80">Admins will be notified to review this before approval.</p>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label="Part name"
            required
            placeholder="e.g. Brake master cylinder"
            value={form.part_name}
            error={errors.part_name?.[0]}
            onChange={(e) => set('part_name', e.target.value)}
          />
          <Input
            label="Part number"
            placeholder="Optional"
            value={form.part_number}
            error={errors.part_number?.[0]}
            onChange={(e) => set('part_number', e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label="Quantity"
            required
            type="number"
            min={1}
            value={form.quantity}
            error={errors.quantity?.[0]}
            onChange={(e) => set('quantity', e.target.value)}
          />
          {/* Price entry stays visible even while money DISPLAY is hidden — it is a record, not a total. */}
          <div className="grid grid-cols-[1fr_auto] gap-2">
            <Input
              label="Estimated price"
              type="number"
              min={0}
              step="0.01"
              placeholder="Optional"
              value={form.estimated_price}
              error={errors.estimated_price?.[0]}
              onChange={(e) => set('estimated_price', e.target.value)}
            />
            <Input label="Cur." className="w-20" value={form.currency} onChange={(e) => set('currency', e.target.value)} />
          </div>
        </div>

        <Textarea
          label="Reason"
          required
          rows={3}
          placeholder="Why is this part needed?"
          value={form.reason}
          error={errors.reason?.[0]}
          onChange={(e) => set('reason', e.target.value)}
        />
        <Textarea
          label="Notes"
          rows={2}
          placeholder="Anything else (optional)"
          value={form.notes}
          error={errors.notes?.[0]}
          onChange={(e) => set('notes', e.target.value)}
        />
      </div>
    </Modal>
  );
}

// ─── Parts section (list + request) ──────────────────────────────────────────
// A self-contained card the ticket drawer / command view drops in. Lists every part requested against
// this ticket (with its lifecycle status) and lets a technician file a new one without leaving the
// ticket. The standalone /parts page stays the hub for review → approve → purchase → install.
export default function TicketParts({
  ticketId, ticket, tasks = [], canView = true, canRequest = false,
  openSignal = 0, onChanged, variant = 'drawer',
}) {
  const [rows, setRows] = useState(null);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);

  const load = useCallback(async () => {
    if (!ticketId) return;
    try {
      const r = await api.get('/part-requests', { params: { maintenance_id: ticketId, per_page: 100 } });
      setRows(payload(r)?.requests || []);
    } catch {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => { setLoading(true); load(); }, [load]);

  // A part can only be ordered while the car is being inspected or in the workshop. Folds the stage rule
  // into the permission so EVERY call site (drawer, command view) is gated the same way, regardless of
  // what its own footer button checks.
  const canRequestNow = canRequest && canOrderParts(ticket);

  // The host's footer "Request Part" button bumps openSignal → open the modal. (Ignore the initial 0.)
  // Guard on canRequestNow too so a stray signal can never open the modal at a stage that can't order.
  useEffect(() => { if (openSignal && canRequestNow) setModalOpen(true); }, [openSignal]); // eslint-disable-line react-hooks/exhaustive-deps

  const onCreated = () => { load(); onChanged?.(); };

  if (!canView) return null;

  const isCommand = variant === 'command';
  const card = isCommand
    ? 'overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft'
    : 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-soft';
  const header = isCommand
    ? 'flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-3'
    : 'flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5';
  const titleCls = isCommand
    ? 'flex items-center gap-2 text-sm font-semibold text-slate-900'
    : 'flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500';
  const bodyCls = isCommand ? 'px-5 py-4' : 'px-4 py-3.5';
  const count = rows?.length || 0;

  return (
    <section className={card}>
      <div className={header}>
        <h3 className={titleCls}>
          {isCommand
            ? <span className="flex h-6 w-6 items-center justify-center rounded-lg" style={{ background: '#9869071a', color: '#986907' }}><Icon.Wrench className="h-4 w-4" /></span>
            : <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" />}
          Parts
          {count > 0 && (
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">{count}</span>
          )}
        </h3>
        {canRequestNow && (
          <Button size="sm" variant="secondary" onClick={() => setModalOpen(true)}>
            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
            Request Part
          </Button>
        )}
      </div>

      <div className={bodyCls}>
        {loading ? (
          <div className="space-y-2">
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="h-4 w-1/2" />
          </div>
        ) : count === 0 ? (
          <p className="text-xs text-slate-400">
            No parts requested for this ticket yet.
            {canRequestNow && ' Use “Request Part” to order one.'}
          </p>
        ) : (
          <ul className="space-y-2">
            {rows.map((r) => (
              <li key={r.id} className="rounded-lg bg-slate-50/70 px-3 py-2.5 ring-1 ring-inset ring-slate-100">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="font-medium text-slate-900">{r.part_name}</span>
                      {r.part_class && <Badge tone={CLASS_TONE[r.part_class] || 'gray'}>{CLASS_LABEL[r.part_class] || r.part_class}</Badge>}
                      {r.quantity > 1 && <span className="text-xs text-slate-400">×{r.quantity}</span>}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {r.fault_symptom ? r.fault_symptom : 'General'}
                      {r.part_number ? ` · #${r.part_number}` : ''}
                      {SHOW_FINANCIALS && r.estimated_price != null ? ` · est. ${aed(r.estimated_price)}` : ''}
                    </p>
                    <p className="mt-0.5 text-[11px] text-slate-400">
                      {r.requested_by || '—'}{r.requested_at ? ` · ${fmtAgo(r.requested_at)}` : ''}
                    </p>
                  </div>
                  <Badge tone={STATUS_TONE[r.status] || 'gray'}>{STATUS_LABEL[r.status] || r.status}</Badge>
                </div>
              </li>
            ))}
          </ul>
        )}

        {/* The hub for approve → purchase → install stays the standalone board. */}
        {count > 0 && (
          <Link to="/parts" className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
            Manage on Parts board <Icon.ArrowRight className="h-3.5 w-3.5" />
          </Link>
        )}
      </div>

      <TicketPartRequestModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        onCreated={onCreated}
        ticket={ticket}
        tasks={tasks}
      />
    </section>
  );
}
