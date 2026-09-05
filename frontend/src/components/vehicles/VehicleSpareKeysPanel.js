import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { usePermissions } from '../../hooks/usePermissions';
import { useToast } from '../ui/Toast';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import Modal from '../ui/Modal';
import { Input, Select, Textarea } from '../ui/Field';
import { SectionCard } from '../ui/Table';
import { EmptyState, ErrorState } from '../ui/Misc';
import { aed2, fmtDate, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

/**
 * Spare Keys — the one panel that has to keep two different numbers apart without either looking
 * like a correction of the other:
 *
 *   CURRENT   how many keys the car HOLDS. The asset ledger, today.
 *   HISTORY   how many were ever ASKED FOR and how many ever ARRIVED. Forever.
 *
 * On a car that has lost a key these disagree, and that disagreement is the useful fact — so both
 * are printed side by side with the reconciling figure ("1 lost or replaced") between them, rather
 * than one being quietly derived from the other.
 *
 * It sits above VehicleComponentsPanel on the Components tab because a key is a component, and the
 * question "does this car have a spare?" is asked far more often than any other question about the
 * car's hardware. Unlike that panel this one DOES carry write actions, and the reason the rule
 * differs is that a key is not fitted at a workshop: there is no ticket for it to be derived from,
 * so the requirement raised here IS the upstream evidence.
 */

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const STATUS_TONE = {
  required: 'slate',
  purchase_requested: 'blue',
  approved: 'cyan',
  ordered: 'violet',
  received: 'amber',
  completed: 'green',
  rejected: 'red',
  cancelled: 'gray',
};

// English here doubles as the i18n phrase key — every read site resolves it through t().
const STATUS_LABEL = {
  required: 'Required',
  purchase_requested: 'Purchase requested',
  approved: 'Approved',
  ordered: 'Ordered',
  received: 'Partly received',
  completed: 'Completed',
  rejected: 'Purchase rejected',
  cancelled: 'Cancelled',
};

const REASON_LABEL = {
  missing: 'No spare key',
  additional: 'Additional key',
  lost: 'Key lost',
  replacement: 'Replacement',
  other: 'Other',
};

/**
 * The same reason once the requirement is closed, said in the past tense.
 *
 * The reason is why the requirement was RAISED, not a claim about the car today. Beside a green
 * "Completed" badge the present tense reads as a contradiction of it, so a closed row states what
 * WAS true at the time. The dropdown keeps the present tense: there you are describing now.
 */
const CLOSED_REASON_LABEL = {
  missing: 'Was missing a spare key',
  additional: 'An extra key was wanted',
  lost: 'A key had been lost',
  replacement: 'A key needed replacing',
  other: 'Other',
};

const reasonLabel = (row, t) => {
  const map = row.is_open ? REASON_LABEL : CLOSED_REASON_LABEL;
  return map[row.reason_code] ? t(map[row.reason_code]) : row.reason_code;
};

const SLOT_LABEL = { unit_1: 'Spare Key #1', unit_2: 'Spare Key #2', unit_3: 'Spare Key #3', unit_4: 'Spare Key #4' };

/** The single next action a requirement is waiting for, in the words of the person who must do it. */
function nextStep(row, t) {
  switch (row.status) {
    case 'required':
      return t('Waiting for a purchase request');
    case 'purchase_requested':
      return t('Waiting for approval');
    case 'approved':
      return t('Approved — waiting for the buy to be recorded');
    case 'ordered':
      return t('Bought — waiting for the key to arrive');
    case 'received':
      return t('{n} of {total} received', { n: num(row.received_quantity), total: num(row.quantity) });
    case 'rejected':
      return t('The purchase was refused — the car still has no spare key');
    default:
      return null;
  }
}

export default function VehicleSpareKeysPanel({ vehicleId }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const toast = useToast();
  const [modal, setModal] = useState(null); // { kind, requirement }
  const [busy, setBusy] = useState(false);

  const fetcher = useCallback(
    async () => payload(await api.get(`/spare-keys/vehicle/${vehicleId}`)),
    [vehicleId]
  );
  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId], { refreshInterval: 60000 });

  const current = data?.current || { count: 0, keys: [] };
  const history = data?.history || { requirements_raised: 0, keys_ever_received: 0, keys_retired: 0, requirements: [] };
  const openRequirement = (history.requirements || []).find((r) => r.is_open) || null;

  const act = async (fn, successMessage) => {
    setBusy(true);
    try {
      await fn();
      toast.success(successMessage);
      setModal(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || t('That did not work.'));
    } finally {
      setBusy(false);
    }
  };

  if (error) return <ErrorState message={t("Could not load this vehicle's spare keys.")} onRetry={reload} />;

  return (
    <SectionCard
      title={t('Spare Keys')}
      subtitle={t('What the car holds today, and every requirement it has ever had. The two are different numbers on any car that has lost a key.')}
      actions={
        <div className="flex flex-wrap items-center justify-end gap-2">
          {can('parts.request') && !openRequirement && (
            <Button onClick={() => setModal({ kind: 'raise' })}>
              <Icon.Plus className="h-4 w-4" /> {t('Request Spare Key')}
            </Button>
          )}
          {openRequirement && can('parts.request') && openRequirement.status === 'required' && (
            <Button onClick={() => setModal({ kind: 'purchase-request', requirement: openRequirement })}>
              {t('Create Purchase Request')}
            </Button>
          )}
          {openRequirement && can('parts.request') && openRequirement.status === 'rejected' && (
            <Button onClick={() => setModal({ kind: 'purchase-request', requirement: openRequirement })}>
              {t('Request again')}
            </Button>
          )}
          {openRequirement && can('parts.purchase') && ['ordered', 'received'].includes(openRequirement.status) && (
            <Button onClick={() => setModal({ kind: 'receive', requirement: openRequirement })}>
              <Icon.Check className="h-4 w-4" /> {t('Mark Received')}
            </Button>
          )}
        </div>
      }
    >
      <div className="space-y-6">
        {/* ── The three numbers, and why they may differ ─────────────────────────────────── */}
        <div className="grid gap-3 sm:grid-cols-3">
          <CountTile
            label={t('Keys on this car')}
            value={num(current.count)}
            tone={current.count === 0 ? 'red' : 'green'}
            hint={t('Recorded as belonging to this vehicle right now')}
          />
          <CountTile
            label={t('Requirements ever raised')}
            value={num(history.requirements_raised)}
            hint={t('Including the ones already met')}
          />
          <CountTile
            label={t('Keys ever received')}
            value={num(history.keys_ever_received)}
            hint={
              history.keys_retired > 0
                ? t('{n} since lost or replaced', { n: num(history.keys_retired) })
                : t('All of them are still on the car')
            }
          />
        </div>

        {/* ── Current configuration ──────────────────────────────────────────────────────── */}
        <div>
          <h4 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            {t('Current keys')}
          </h4>
          {current.keys.length === 0 ? (
            <EmptyState
              title={t('No spare key on record')}
              message={
                history.requirements_raised > 0
                  ? t('This car has had a spare-key requirement before, but no physical key has ever been registered against it here. Imported sheet history records the process, not the key — see the history below.')
                  : t('Nobody has recorded a spare key for this vehicle. Raise a requirement to start the process.')
              }
            />
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {current.keys.map((k) => (
                <li key={k.id} className="rounded-xl border border-slate-200/70 bg-white p-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold text-slate-900">
                      {SLOT_LABEL[k.slot] ? t(SLOT_LABEL[k.slot]) : k.label || t('Spare Key')}
                    </span>
                    <Badge tone="green">{t('Active')}</Badge>
                  </div>
                  {/* WHAT KIND of key — physical, remote, card. The register states it for some cars
                      and it changes what "has a spare" means, so it is shown rather than flattened
                      into the slot number. Hidden when it says nothing beyond "a key". */}
                  {k.label && k.label !== 'Spare Key' && (
                    <div className="text-xs font-medium text-slate-600">{k.label}</div>
                  )}
                  {/* A key this system watched arrive has a date, a supplier and a price. One we were
                      simply told about in the register has none of the three — so it says where it
                      came from instead of printing three empty fields, which would read as missing
                      data somebody ought to go and find. */}
                  <div className="mt-1 text-xs text-slate-500">
                    {k.from_register
                      ? t('From the key register — arrival date not recorded')
                      : <>
                          {t('Received {date}', { date: fmtDate(k.received_at) })}
                          {k.supplier ? ` · ${k.supplier}` : ''}
                          {k.cost !== null && k.cost !== undefined ? ` · ${aed2(k.cost)}` : ''}
                        </>}
                  </div>
                  {k.serial_no && (
                    <div className="mt-0.5 font-mono text-xs text-slate-400">{k.serial_no}</div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* ── History: every requirement, met or not ─────────────────────────────────────── */}
        <div>
          <h4 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            {t('History')}
          </h4>
          {(history.requirements || []).length === 0 ? (
            <p className="py-2 text-sm text-slate-400">{t('No spare-key requirement has ever been raised for this car.')}</p>
          ) : (
            <ol className="space-y-3">
              {history.requirements.map((r) => (
                <RequirementRow
                  key={r.id}
                  row={r}
                  t={t}
                  onCancel={
                    can('parts.request') && r.is_open && r.received_quantity === 0
                      ? () => setModal({ kind: 'cancel', requirement: r })
                      : null
                  }
                />
              ))}
            </ol>
          )}
        </div>

        {loading && !data && <p className="text-sm text-slate-400">{t('Loading…')}</p>}
      </div>

      <RaiseModal
        open={modal?.kind === 'raise'}
        vehicleId={vehicleId}
        busy={busy}
        onClose={() => setModal(null)}
        onSubmit={(body) =>
          act(
            () => api.post('/spare-keys', { vehicle_id: vehicleId, ...body }),
            t('Spare Key Requirement created. Waleed and Abdullah have been notified.')
          )
        }
      />

      <PurchaseRequestModal
        open={modal?.kind === 'purchase-request'}
        requirement={modal?.requirement}
        busy={busy}
        onClose={() => setModal(null)}
        onSubmit={(body) =>
          act(
            () => api.post(`/spare-keys/${modal.requirement.id}/purchase-request`, body),
            t('Purchase request created — it is now on the parts board awaiting approval.')
          )
        }
      />

      <ReceiveModal
        open={modal?.kind === 'receive'}
        requirement={modal?.requirement}
        busy={busy}
        onClose={() => setModal(null)}
        onSubmit={(body) =>
          act(
            () => api.post(`/spare-keys/${modal.requirement.id}/receive`, body),
            t('Spare key received and registered to this vehicle.')
          )
        }
      />

      <CancelModal
        open={modal?.kind === 'cancel'}
        busy={busy}
        onClose={() => setModal(null)}
        onSubmit={(body) =>
          act(
            () => api.post(`/spare-keys/${modal.requirement.id}/cancel`, body),
            t('Requirement cancelled.')
          )
        }
      />
    </SectionCard>
  );
}

function CountTile({ label, value, hint, tone }) {
  const ring = tone === 'red' ? 'ring-rose-200' : tone === 'green' ? 'ring-emerald-200' : 'ring-slate-200/70';
  return (
    <div className={`rounded-xl bg-white p-4 ring-1 ring-inset ${ring}`}>
      <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</div>
      <div className="mt-1 text-2xl font-bold tabular-nums text-slate-900">{value}</div>
      <div className="mt-0.5 text-xs text-slate-400">{hint}</div>
    </div>
  );
}

/**
 * One requirement, with its whole procurement chain underneath it — request, approval or refusal,
 * purchase, arrival. A refused attempt is shown rather than hidden: it is part of why the car still
 * has no key.
 */
function RequirementRow({ row, t, onCancel }) {
  return (
    <li className="rounded-xl border border-slate-200/70 bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-semibold text-slate-900">
              {row.quantity > 1
                ? t('Spare Key Requirement — {n} keys', { n: num(row.quantity) })
                : t('Spare Key Requirement')}
            </span>
            <Badge tone={STATUS_TONE[row.status] || 'gray'}>
              {STATUS_LABEL[row.status] ? t(STATUS_LABEL[row.status]) : row.status}
            </Badge>
            {row.source === 'sheet_import' && (
              <Badge tone="gray">{t('From the sheet')}</Badge>
            )}
          </div>
          <div className="mt-1 text-xs text-slate-500">
            {reasonLabel(row, t)}
            {' · '}
            {/* An imported row has the sheet's own dates and no app timestamp — say which is which
                rather than presenting a spreadsheet date as something this system recorded. */}
            {row.started_on
              ? t('Sheet: {from} → {to}', { from: fmtDate(row.started_on), to: fmtDate(row.finished_on) || '—' })
              : t('Raised {date} by {who}', { date: fmtDate(row.requested_at), who: row.requested_by_name || '—' })}
          </div>
          {row.notes && <p className="mt-1 text-xs text-slate-500">{row.notes}</p>}
        </div>
        {onCancel && (
          <Button variant="ghost" onClick={onCancel}>{t('Cancel')}</Button>
        )}
      </div>

      {nextStep(row, t) && (
        <div className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600">
          {nextStep(row, t)}
        </div>
      )}

      <div className="mt-3 space-y-1 text-xs text-slate-500">
        {row.purchase_request && (
          <ChainLine
            label={t('Purchase request #{id}', { id: row.purchase_request.id })}
            detail={
              row.purchase_request.approved_at
                ? t('approved by {who} on {date}', {
                    who: row.purchase_request.approved_by_name || '—',
                    date: fmtDate(row.purchase_request.approved_at),
                  })
                : t('raised by {who} — awaiting approval', { who: row.purchase_request.requested_by_name || '—' })
            }
          />
        )}
        {(row.rejected_requests || []).map((q) => (
          <ChainLine
            key={q.id}
            tone="red"
            label={t('Purchase request #{id} rejected', { id: q.id })}
            detail={t('by {who} — {reason}', { who: q.rejected_by_name || '—', reason: q.reason || '—' })}
          />
        ))}
        {row.purchase && (
          <ChainLine
            label={t('Purchased')}
            detail={[
              row.purchase.supplier,
              aed2(row.purchase.price),
              row.purchase.delivered_at
                ? t('received {date}', { date: fmtDate(row.purchase.delivered_at) })
                : t('not yet received'),
            ].filter(Boolean).join(' · ')}
          />
        )}
        {row.source === 'sheet_import' && (
          <ChainLine
            label={t('No purchase on record')}
            detail={t('The sheet recorded the requirement and its dates only — no supplier, price or key serial exists to import.')}
          />
        )}
      </div>
    </li>
  );
}

function ChainLine({ label, detail, tone }) {
  return (
    <div className="flex gap-2">
      <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${tone === 'red' ? 'bg-rose-400' : 'bg-indigo-400'}`} />
      <span>
        <span className="font-medium text-slate-700">{label}</span>
        {detail ? ` — ${detail}` : ''}
      </span>
    </div>
  );
}

// ── Modals ────────────────────────────────────────────────────────────────────────────────────

function RaiseModal({ open, busy, onClose, onSubmit }) {
  const { t } = useI18n();
  const [form, setForm] = useState({ reason_code: 'missing', quantity: 1, notes: '' });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Request Spare Key')}
      subtitle={t('This records a requirement and notifies the supervisors. Nothing is bought yet.')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{t('Cancel')}</Button>
          <Button onClick={() => onSubmit(form)} disabled={busy}>{t('Create Requirement')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Select
          label={t('Reason')}
          value={form.reason_code}
          onChange={(e) => setForm({ ...form, reason_code: e.target.value })}
        >
          {Object.entries(REASON_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{t(v)}</option>
          ))}
        </Select>
        <Input
          label={t('How many keys')}
          type="number"
          min={1}
          max={4}
          value={form.quantity}
          onChange={(e) => setForm({ ...form, quantity: Number(e.target.value) })}
        />
        <Textarea
          label={t('Notes')}
          value={form.notes}
          onChange={(e) => setForm({ ...form, notes: e.target.value })}
          placeholder={t('Anything the buyer should know — key code, dealer, urgency…')}
        />
      </div>
    </Modal>
  );
}

function PurchaseRequestModal({ open, requirement, busy, onClose, onSubmit }) {
  const { t } = useI18n();
  const [form, setForm] = useState({ estimated_price: '', currency: 'AED', notes: '' });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Create Purchase Request')}
      subtitle={t('From here the normal parts workflow takes over — approval, purchase, then receipt.')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{t('Cancel')}</Button>
          <Button
            onClick={() => onSubmit({
              ...form,
              estimated_price: form.estimated_price === '' ? undefined : Number(form.estimated_price),
            })}
            disabled={busy}
          >
            {t('Create Purchase Request')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
          {t('{n} key(s) will be requested against this requirement. It goes to the parts board for approval like any other part.', {
            n: num(requirement?.outstanding ?? 1),
          })}
        </p>
        <Input
          label={t('Estimated price (optional)')}
          type="number"
          min={0}
          step="0.01"
          value={form.estimated_price}
          onChange={(e) => setForm({ ...form, estimated_price: e.target.value })}
        />
        <Textarea
          label={t('Notes for the buyer')}
          value={form.notes}
          onChange={(e) => setForm({ ...form, notes: e.target.value })}
        />
      </div>
    </Modal>
  );
}

function ReceiveModal({ open, requirement, busy, onClose, onSubmit }) {
  const { t } = useI18n();
  const outstanding = requirement?.outstanding ?? 1;
  const [form, setForm] = useState({ quantity: 1, serial_no: '', brand: '', note: '' });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Mark Received')}
      subtitle={t('The key is physically here. This registers it as a component belonging to the vehicle.')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{t('Cancel')}</Button>
          <Button onClick={() => onSubmit(form)} disabled={busy}>{t('Mark Received')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Input
          label={t('How many keys arrived')}
          type="number"
          min={1}
          max={outstanding}
          value={form.quantity}
          onChange={(e) => setForm({ ...form, quantity: Number(e.target.value) })}
        />
        {/* Only offered for a single key: a key code identifies ONE object, and copying it onto two
            would assert an identity for a key nobody read. */}
        {form.quantity === 1 && (
          <Input
            label={t('Key code / serial (if the key carries one)')}
            value={form.serial_no}
            onChange={(e) => setForm({ ...form, serial_no: e.target.value })}
          />
        )}
        <Input
          label={t('Brand (optional)')}
          value={form.brand}
          onChange={(e) => setForm({ ...form, brand: e.target.value })}
        />
        <Textarea
          label={t('Note')}
          value={form.note}
          onChange={(e) => setForm({ ...form, note: e.target.value })}
        />
      </div>
    </Modal>
  );
}

function CancelModal({ open, busy, onClose, onSubmit }) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('Cancel this requirement')}
      subtitle={t('Use this when the key turned up or the requirement was raised in error.')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{t('Keep it')}</Button>
          <Button variant="danger" onClick={() => onSubmit({ reason })} disabled={busy || !reason.trim()}>
            {t('Cancel requirement')}
          </Button>
        </>
      }
    >
      <Textarea
        label={t('Why?')}
        required
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder={t('The second key was found in the office.')}
      />
    </Modal>
  );
}
