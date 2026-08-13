import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import PartPurchaseHistory from '../parts/PartPurchaseHistory';
import PartRecordModal from '../parts/PartRecordModal';
import { useI18n } from '../../i18n/I18nContext';

// Envelope-aware unwrap: the API wraps most payloads in { data: … }.
const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

// Lifecycle → badge tone/label (kept in sync with the standalone Parts page).
const STATUS_TONE = {
  requested: 'slate', under_review: 'blue', approved: 'cyan',
  purchased: 'violet', installed: 'amber', completed: 'green',
  rejected: 'red', cancelled: 'gray',
};
// Labels are resolved through the translator at render time, so `t` is threaded in rather than the
// English sitting frozen in a module-level table.
const statusLabel = (t, status) => ({
  requested: t('Requested'), under_review: t('Under review'), approved: t('Approved'),
  purchased: t('Purchased'), installed: t('Installed'), completed: t('Completed'),
  rejected: t('Rejected'), cancelled: t('Cancelled'),
}[status] || status);
const CLASS_TONE = { consumable: 'gray', standard: 'blue', major: 'amber' };
const classLabel = (t, cls) => ({
  consumable: t('Consumable'), standard: t('Standard'), major: t('Major'),
}[cls] || cls);

// A stable empty default — an inline `tasks = []` would mint a new array on every render, re-running
// every memo/effect keyed on it for a ticket that simply has no faults yet.
const NO_TASKS = [];

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
  const { t } = useI18n();

  // Only OPEN faults are worth requesting a part against (a cancelled/mis-diagnosed one isn't repaired).
  const faultOptions = useMemo(
    () => (tasks || []).filter((task) => !task.is_incorrect && task.status !== 'cancelled'),
    [tasks],
  );

  const [form, setForm] = useState(null);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [dup, setDup] = useState(null);       // live duplicate verdict for the current part name
  const [checking, setChecking] = useState(false);

  // Read at reset time without making the reset depend on the array's identity (see below).
  const faultOptionsRef = useRef(faultOptions);
  faultOptionsRef.current = faultOptions;

  // Reset ON OPEN — and ONLY on open — pre-selecting the sole/first open fault so a single-fault ticket
  // needs no picking. This deliberately does NOT depend on faultOptions: the parent drawer hands down a
  // fresh `tasks` array every time it reloads, which gives faultOptions a new identity and would re-fire
  // this reset mid-typing, blanking the part name the technician had entered and the purchase record
  // shown underneath it. The modal resets when it opens; nothing else may reset it.
  useEffect(() => {
    if (!open) return;
    const opts = faultOptionsRef.current;
    setForm({
      maintenance_task_id: opts.length === 1 ? String(opts[0].id) : '',
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
  }, [open]);

  const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  // The fault the part is for — drives the stronger Vehicle + Part + Fault duplicate signal.
  const selectedFault = useMemo(() => {
    if (form?.maintenance_task_id) return faultOptions.find((task) => String(task.id) === String(form.maintenance_task_id)) || null;
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
      toast.success(dup?.duplicate ? t('Request created — admins notified of a possible duplicate') : t('Part request created'));
      onCreated?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(res?.message || res?.msg || t('Could not submit the request'));
    } finally {
      setSaving(false);
    }
  };

  if (!form) return null;

  const prev = dup?.duplicate ? dup?.context?.previous : null;
  // Arabic clause order differs, so the whole warning is ONE interpolated sentence rather than English
  // fragments stitched around <span>s. The "when" clause is resolved first and passed in as a token.
  const whenAgo = dup?.days_between != null
    ? (Number(dup.days_between) === 1 ? t('1 day ago') : t('{n} days ago', { n: num(dup.days_between) }))
    : t('recently');

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={t('Request a Part')}
      subtitle={ticket ? `${ticket.plate || `#${ticket.id}`}${ticket.car ? ` · ${ticket.car}` : ''}` : ''}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving}>{t('Create request')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Context — everything here is pulled straight from the ticket and cannot be edited. */}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl bg-slate-50 px-4 py-3.5 ring-1 ring-inset ring-slate-100 sm:grid-cols-4">
          <ContextFact label={t('Vehicle')} value={ticket?.car} />
          <ContextFact label={t('Plate')} value={ticket?.plate} />
          <ContextFact label={t('Maintenance Ticket')} value={`#${ticket?.id}`} />
          <ContextFact label={t('Workshop')} value={ticket?.garage} />
        </dl>

        {/* Fault — from the ticket. A single-fault ticket is pre-selected; a multi-fault one must pick. */}
        {faultOptions.length <= 1 ? (
          <ContextFact label={t('Fault')} value={faultOptions[0]?.symptom || t('General (no specific fault)')} />
        ) : (
          <Select
            label={t('Fault')}
            required
            value={form.maintenance_task_id}
            error={errors.maintenance_task_id?.[0]}
            onChange={(e) => set('maintenance_task_id', e.target.value)}
          >
            <option value="">{t('Select the related fault…')}</option>
            {faultOptions.map((task) => (
              <option key={task.id} value={task.id}>{task.symptom || t('Fault #{id}', { id: task.id })}</option>
            ))}
          </Select>
        )}

        {/* Duplicate-purchase intelligence — surfaced BEFORE the request is created. */}
        {checking && partName.trim().length >= 2 && !dup && (
          <p className="text-xs text-slate-400">{t('Checking this vehicle’s purchase record for this part…')}</p>
        )}
        {prev && (
          <div className={`rounded-xl px-4 py-3 ring-1 ring-inset ${dup.priority === 'high' ? 'bg-red-50 text-red-800 ring-red-600/25' : 'bg-amber-50 text-amber-800 ring-amber-600/25'}`}>
            {dup.same_fault ? (
              <>
                <p className="flex items-center gap-1.5 font-bold">{t('⚠️ Same Part — Same Fault')}</p>
                <p className="mt-1 text-sm">
                  {selectedFault?.symptom
                    ? t('This {part} was already bought for the same fault ({fault}) {when}. This likely means the previous repair failed — high priority.', { part: prev.part_name, fault: selectedFault.symptom, when: whenAgo })
                    : t('This {part} was already bought for the same fault {when}. This likely means the previous repair failed — high priority.', { part: prev.part_name, when: whenAgo })}
                </p>
              </>
            ) : (
              <>
                <p className="flex items-center gap-1.5 font-bold">{t('⚠️ Duplicate Purchase Detected')}</p>
                <p className="mt-1 text-sm">
                  {t('This vehicle already received {part} {when}.', { part: prev.part_name, when: whenAgo })}
                </p>
              </>
            )}
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
              {prev.source_name && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">{t('Supplier')}</dt><dd>{prev.source_name}</dd></div>
              )}
              {/* The prior-purchase price is the crux of the warning ("you already spent this") — a recorded
                  figure, not a computed roll-up, so it is shown even while SHOW_FINANCIALS hides other money. */}
              {prev.purchase_price != null && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">{t('Cost')}</dt><dd>{aed(prev.purchase_price)}{prev.currency && prev.currency !== 'AED' ? ` ${prev.currency}` : ''}</dd></div>
              )}
              {prev.maintenance_id && (
                <div><dt className="font-semibold uppercase tracking-wide opacity-70">{t('Ticket')}</dt><dd>#{prev.maintenance_id}</dd></div>
              )}
            </dl>
            <p className="mt-2 text-[11px] font-medium opacity-80">{t('Admins will be notified to review this before approval.')}</p>
          </div>
        )}

        {/* Every prior purchase of this part on this car — no date cutoff. The banner above only fires
            inside the alert window; this shows the rest of the story, including when there is no alert. */}
        {dup && <PartPurchaseHistory history={dup.history} partName={partName.trim()} />}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('Part name')}
            required
            placeholder={t('e.g. Brake master cylinder')}
            value={form.part_name}
            error={errors.part_name?.[0]}
            onChange={(e) => set('part_name', e.target.value)}
          />
          <Input
            label={t('Part number')}
            placeholder={t('Optional')}
            value={form.part_number}
            error={errors.part_number?.[0]}
            onChange={(e) => set('part_number', e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('Quantity')}
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
              label={t('Estimated price')}
              type="number"
              min={0}
              step="0.01"
              placeholder={t('Optional')}
              value={form.estimated_price}
              error={errors.estimated_price?.[0]}
              onChange={(e) => set('estimated_price', e.target.value)}
            />
            <Input label={t('Cur.')} className="w-20" value={form.currency} onChange={(e) => set('currency', e.target.value)} />
          </div>
        </div>

        <Textarea
          label={t('Reason')}
          required
          rows={3}
          placeholder={t('Why is this part needed?')}
          value={form.reason}
          error={errors.reason?.[0]}
          onChange={(e) => set('reason', e.target.value)}
        />
        <Textarea
          label={t('Notes')}
          rows={2}
          placeholder={t('Anything else (optional)')}
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
  ticketId, ticket, tasks = NO_TASKS, canView = true, canRequest = false,
  openSignal = 0, onChanged, variant = 'drawer',
}) {
  const { t } = useI18n();
  const [rows, setRows] = useState(null);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [recordFor, setRecordFor] = useState(null);  // the row whose full purchase record is open

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
          {t('Parts')}
          {count > 0 && (
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">{count}</span>
          )}
        </h3>
        {canRequestNow && (
          <Button size="sm" variant="secondary" onClick={() => setModalOpen(true)}>
            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
            {t('Request Part')}
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
            {canRequestNow
              ? t('No parts requested for this ticket yet. Use “Request Part” to order one.')
              : t('No parts requested for this ticket yet.')}
          </p>
        ) : (
          <ul className="space-y-2">
            {rows.map((r) => (
              // Each row deep-links to its own row on the Parts board (?focus=id) — the board jumps to the
              // right page, scrolls to it and highlights it, so "manage this part" is one click.
              <li key={r.id}>
                <Link
                  to={`/parts?focus=${r.id}`}
                  title={t('Open on the Parts board')}
                  className="block rounded-lg bg-slate-50/70 px-3 py-2.5 ring-1 ring-inset ring-slate-100 transition hover:bg-indigo-50/60 hover:ring-indigo-200"
                >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="font-medium text-slate-900">{r.part_name}</span>
                      {r.part_class && <Badge tone={CLASS_TONE[r.part_class] || 'gray'}>{classLabel(t, r.part_class)}</Badge>}
                      {r.quantity > 1 && <span className="text-xs text-slate-400">×{r.quantity}</span>}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {r.fault_symptom ? r.fault_symptom : t('General')}
                      {r.part_number ? ` · #${r.part_number}` : ''}
                      {SHOW_FINANCIALS && r.estimated_price != null ? ` · ${t('est. {amount}', { amount: aed(r.estimated_price) })}` : ''}
                    </p>
                    <p className="mt-0.5 text-[11px] text-slate-400">
                      {r.requested_by || '—'}{r.requested_at ? ` · ${fmtAgo(r.requested_at)}` : ''}
                    </p>
                  </div>
                  <Badge tone={STATUS_TONE[r.status] || 'gray'}>{statusLabel(t, r.status)}</Badge>
                </div>
                </Link>
                {/* Outside the Link — the row navigates to the Parts board, this opens the record in place.
                    Present on every row and every status: anyone working the ticket can ask "has this car
                    had this part before?" without needing approval rights or the Parts board. */}
                <button
                  type="button"
                  onClick={() => setRecordFor(r)}
                  className="mt-1 inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                >
                  <Icon.Clock className="h-3 w-3" />
                  {t('Purchase record')}
                </button>
              </li>
            ))}
          </ul>
        )}

        {/* The hub for approve → purchase → install stays the standalone board — scoped to this ticket. */}
        {count > 0 && (
          <Link to={`/parts?ticket=${ticketId}`} className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
            {t('Manage on Parts board')} <Icon.ArrowRight className="h-3.5 w-3.5 rtl:-scale-x-100" />
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
      <PartRecordModal
        open={!!recordFor}
        request={recordFor}
        onClose={() => setRecordFor(null)}
      />
    </section>
  );
}
