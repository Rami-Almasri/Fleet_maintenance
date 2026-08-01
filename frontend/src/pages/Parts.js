import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import Pagination from '../components/ui/Pagination';
import SearchSelect from '../components/ui/SearchSelect';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import PartsAnalytics from '../components/analytics/PartsAnalytics';
import { SHOW_FINANCIALS } from '../config/features';
import { aed, fmtAgo, num } from '../lib/format';

const PAGE_SIZE = 15;

// Envelope-aware unwrap: the API wraps most payloads in { data: … }.
const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

// Lifecycle → badge tone. Ordered from intake to done, then the two terminals.
const STATUS_TONE = {
  requested: 'slate',
  under_review: 'blue',
  approved: 'cyan',
  purchased: 'violet',
  installed: 'amber',
  completed: 'green',
  rejected: 'red',
  cancelled: 'gray',
};
const STATUS_LABEL = {
  requested: 'Requested',
  under_review: 'Under review',
  approved: 'Approved',
  purchased: 'Purchased',
  installed: 'Installed',
  completed: 'Completed',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};
// The tiles staff filter by (the terminals stay reachable via the status dropdown).
// 'under_review' is retired (the Review step was removed) so it's no longer a headline tile;
// STATUS_META/STATUS_LABEL keep it defined so any legacy row still renders its badge.
const TILE_STATUSES = ['requested', 'approved', 'purchased', 'installed', 'completed'];
const ALL_STATUSES = [...TILE_STATUSES, 'rejected', 'cancelled'];

const CLASS_TONE = { consumable: 'gray', standard: 'blue', major: 'amber' };
const CLASS_LABEL = { consumable: 'Consumable', standard: 'Standard', major: 'Major' };
const SOURCE_TONE = { customer: 'violet', garage: 'amber' };

// Where a prior part was bought, for the duplicate warning's "Bought from …" line.
const sourceLabel = (src) => (src === 'garage' ? 'Garage' : src === 'supplier' ? 'Parts supplier' : null);

// The five reasons the buyer must pick when a duplicate purchase is flagged.
const DUP_REASONS = [
  ['previous_part_failed', 'Previous part failed'],
  ['wrong_diagnosis', 'Wrong diagnosis'],
  ['customer_requested', 'Customer requested replacement'],
  ['accident_damage', 'Accident/damage'],
  ['other', 'Other'],
];


function StatusBadge({ status }) {
  return <Badge tone={STATUS_TONE[status] || 'gray'}>{STATUS_LABEL[status] || status}</Badge>;
}


// ─── Purchase modal (with duplicate-purchase intelligence) ───────────────────
function PurchaseModal({ open, request, onClose, onDone, vendors }) {
  const toast = useToast();
  const [form, setForm] = useState({
    purchase_source: 'supplier',
    source_vendor_id: '',
    source_name: '',
    repair_location: 'garage',
    purchase_price: '',
    currency: 'AED',
    quantity: 1,
    po_number: '',
    notes: '',
    duplicate_reason_code: '',
    duplicate_reason_note: '',
  });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [dup, setDup] = useState(null);        // duplicate-check result
  const [checking, setChecking] = useState(false);

  const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  // Reset + run the duplicate check whenever the modal opens for a request.
  useEffect(() => {
    if (!open || !request) return undefined;
    setForm({
      purchase_source: request.repair_location === 'onsite' ? 'supplier' : 'garage',
      source_vendor_id: '',
      source_name: '',
      repair_location: request.repair_location || 'garage',
      purchase_price: request.estimated_price ?? '',
      currency: request.currency || 'AED',
      quantity: request.quantity || 1,
      po_number: '',
      notes: '',
      duplicate_reason_code: '',
      duplicate_reason_note: '',
    });
    setErrors({});
    setDup(null);

    let alive = true;
    setChecking(true);
    api.get('/part-purchases/duplicate-check', {
      params: {
        vehicle_id: request.vehicle?.id,
        part_name: request.part_name,
        part_number: request.part_number || undefined,
      },
    })
      .then((r) => { if (alive) setDup(payload(r)); })
      .catch(() => { if (alive) setDup(null); })
      .finally(() => { if (alive) setChecking(false); });
    return () => { alive = false; };
  }, [open, request]);

  // Vendors filtered by the purchase-source toggle (garage vs parts supplier).
  const vendorOptions = useMemo(() => {
    const wanted = form.purchase_source === 'garage' ? 'garage' : 'parts_supplier';
    const list = vendors.filter((v) => v.type === wanted);
    return (list.length ? list : vendors).map((v) => ({ id: v.id, label: v.name || `#${v.id}`, sub: v.type }));
  }, [vendors, form.purchase_source]);

  const isDuplicate = !!dup?.duplicate;
  const needsReason = isDuplicate && !form.duplicate_reason_code;
  const priceValid = form.purchase_price !== '' && Number(form.purchase_price) > 0;
  const canSubmit = priceValid && !needsReason && !saving;

  const submit = async () => {
    if (!canSubmit) return;
    setSaving(true);
    setErrors({});
    try {
      const body = {
        purchase_source: form.purchase_source,
        source_vendor_id: form.source_vendor_id || null,
        source_name: form.source_name.trim() || null,
        repair_location: form.repair_location,
        purchase_price: Number(form.purchase_price),
        currency: form.currency || 'AED',
        quantity: Number(form.quantity) || 1,
        po_number: form.po_number.trim() || null,
        notes: form.notes.trim() || null,
      };
      if (isDuplicate) {
        body.duplicate_reason_code = form.duplicate_reason_code;
        body.duplicate_reason_note = form.duplicate_reason_note.trim() || null;
      }
      const res = payload(await api.post(`/part-requests/${request.id}/purchase`, body));
      if (res?.duplicate) toast.error('Purchase recorded — flagged for admin review');
      else toast.success('Purchase recorded');
      onDone();
      onClose();
    } catch (err) {
      const r = err.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not record the purchase');
    } finally {
      setSaving(false);
    }
  };

  const prev = dup?.context?.previous;

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Record Purchase"
      subtitle={request ? `${request.part_name} · ${request.vehicle?.plate || `#${request.vehicle?.id}`}` : ''}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving} disabled={!canSubmit}>Record purchase</Button>
        </>
      }
    >
      <div className="space-y-4">
        {checking && <div className="text-xs text-slate-400">Checking recent purchase history…</div>}

        {/* Duplicate-purchase warning — prominent, and it gates the submit button. */}
        {isDuplicate && (
          <div className={`rounded-xl px-4 py-3 text-sm ring-1 ring-inset ${dup.priority === 'high' ? 'bg-red-50 text-red-800 ring-red-600/25' : 'bg-amber-50 text-amber-800 ring-amber-600/25'}`}>
            <p className="font-semibold">
              ⚠ Attention: this vehicle already received {prev?.part_name || 'this part'} {num(dup.days_between)} day(s) ago.
            </p>
            <ul className="mt-1.5 space-y-0.5 text-xs">
              {/* Prior-purchase price shown even while SHOW_FINANCIALS hides other money — it is the recorded
                  spend the duplicate warning is about (accountability context), not a computed roll-up. */}
              {prev?.purchase_price != null && (
                <li>Previous cost {aed(prev.purchase_price)} {prev.currency && prev.currency !== 'AED' ? `(${prev.currency})` : ''}.</li>
              )}
              {prev?.purchased_by && <li>Previous purchase by {prev.purchased_by}.</li>}
              {prev?.source && (
                <li>Bought from {prev.source_name ? <span className="font-medium">{prev.source_name}</span> : sourceLabel(prev.source)}{prev.source_name && sourceLabel(prev.source) ? ` (${sourceLabel(prev.source)})` : ''}.</li>
              )}
              {dup.part_class && <li>Part class: <span className="font-medium capitalize">{dup.part_class}</span> · window {num(dup.window_days)} day(s).</li>}
            </ul>
            <div className="mt-3">
              <Select
                label="Why buy it again?"
                required
                value={form.duplicate_reason_code}
                error={errors.duplicate_reason_code?.[0]}
                onChange={(e) => set('duplicate_reason_code', e.target.value)}
              >
                <option value="">Select a reason…</option>
                {DUP_REASONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
              <Textarea
                className="mt-2"
                rows={2}
                placeholder="Add a note (optional)"
                value={form.duplicate_reason_note}
                onChange={(e) => set('duplicate_reason_note', e.target.value)}
              />
            </div>
          </div>
        )}

        {/* Purchase source toggle */}
        <div>
          <span className="mb-1 block text-sm font-medium text-slate-700">Bought from</span>
          <div className="inline-flex rounded-lg border border-slate-300 p-0.5">
            {['garage', 'supplier'].map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => { set('purchase_source', s); set('source_vendor_id', ''); }}
                className={`rounded-md px-4 py-1.5 text-sm font-semibold capitalize transition ${form.purchase_source === s ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'}`}
              >
                {s === 'garage' ? 'Garage' : 'Parts supplier'}
              </button>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">{form.purchase_source === 'garage' ? 'Garage' : 'Supplier'}</span>
            <SearchSelect
              value={form.source_vendor_id}
              onChange={(v) => set('source_vendor_id', v)}
              options={vendorOptions}
              placeholder="Pick a vendor…"
            />
          </div>
          <Input
            label="…or type a source name"
            placeholder="Free-text vendor name"
            value={form.source_name}
            error={errors.source_name?.[0]}
            onChange={(e) => set('source_name', e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {/* Price entry is a record — always visible even with financials hidden. */}
          <div className="grid grid-cols-[1fr_auto] gap-2">
            <Input
              label="Purchase price"
              required
              type="number"
              min={0}
              step="0.01"
              value={form.purchase_price}
              error={errors.purchase_price?.[0] || (form.purchase_price !== '' && !priceValid ? 'Must be greater than 0' : undefined)}
              onChange={(e) => set('purchase_price', e.target.value)}
            />
            <Input label="Cur." className="w-20" value={form.currency} onChange={(e) => set('currency', e.target.value)} />
          </div>
          <Input
            label="Quantity"
            type="number"
            min={1}
            value={form.quantity}
            error={errors.quantity?.[0]}
            onChange={(e) => set('quantity', e.target.value)}
          />
          {/* The supplier's PO / invoice reference. Carried onto the component when the part is
              fitted, so the Installed Components dossier can link a part back to the paperwork
              that bought it. Optional — a cash counter buy legitimately has none. */}
          <Input
            label="PO / invoice reference"
            placeholder="Optional — e.g. PO-2026-0481"
            value={form.po_number}
            error={errors.po_number?.[0]}
            onChange={(e) => set('po_number', e.target.value)}
          />
          <Select
            label="Repair location"
            value={form.repair_location}
            error={errors.repair_location?.[0]}
            onChange={(e) => set('repair_location', e.target.value)}
          >
            <option value="garage">In garage</option>
            <option value="onsite">On-site</option>
          </Select>
        </div>

        <Textarea
          label="Notes"
          rows={2}
          placeholder="Optional"
          value={form.notes}
          error={errors.notes?.[0]}
          onChange={(e) => set('notes', e.target.value)}
        />
      </div>
    </Modal>
  );
}

// ─── Install modal ───────────────────────────────────────────────────────────
function InstallModal({ open, request, onClose, onDone }) {
  const toast = useToast();
  const purchase = request?.purchases?.find((p) => !p.installed_at) || request?.purchases?.[request.purchases.length - 1];
  // The Asset Layer fields ride along with the install: this step is the ONLY moment the system can
  // learn what physically went on the car and what happened to the part it displaced. Asking here is
  // why no "Add Component" screen has to exist anywhere else.
  const BLANK = {
    installed_odometer: '', warranty_months: '', result: 'success', notes: '',
    component_catalog_id: '', brand: '', serial_no: '', position: '',
    removal_reason: '', disposition: '',
  };
  const [form, setForm] = useState(BLANK);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [catalog, setCatalog] = useState([]);

  // The component TYPE dictionary. Without an explicit pick here the asset write fails: a free-text
  // part name ("Radiator") cannot be resolved to a catalog entry, and a ticket-raised request carries
  // no category at all. Under shadow mode that failure is swallowed — the part installs and bills
  // correctly while the vehicle's configuration silently never updates.
  useEffect(() => {
    if (!open) return;
    let alive = true;
    api.get('/components/catalog')
      .then((r) => { if (alive) setCatalog(r.data.data || []); })
      .catch(() => { if (alive) setCatalog([]); });
    return () => { alive = false; };
  }, [open]);

  useEffect(() => {
    if (open) { setForm(BLANK); setErrors({}); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // Best-guess the type from the part name so the common case is one confirming glance, not a hunt
  // through 30 options. Longest catalog name that appears in the part name wins ("Brake Discs (set)"
  // beats "Brake Pads (set)" for "front brake discs"), so a partial match can't shadow a fuller one.
  useEffect(() => {
    if (!open || !catalog.length || form.component_catalog_id) return;
    const name = `${request?.part_name || ''} ${purchase?.part_name || ''}`.toLowerCase();
    const hit = catalog
      .filter((c) => name.includes(c.name.toLowerCase().replace(/\s*\(set\)\s*/, '').trim()))
      .sort((a, b) => b.name.length - a.name.length)[0];
    if (hit) setForm((f) => ({ ...f, component_catalog_id: String(hit.id) }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, catalog, request?.part_name, purchase?.part_name]);

  const selectedType = catalog.find((c) => String(c.id) === String(form.component_catalog_id)) || null;
  // Positions are per type: a radiator takes none, a tyre takes four corners. Offering all of them
  // always invites a 422 ("… does not take a position") that shadow mode would swallow.
  const positions = selectedType?.positions || [];

  const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  const submit = async () => {
    if (!purchase) { toast.error('No purchase found to install'); return; }
    setSaving(true);
    setErrors({});
    try {
      await api.post(`/part-purchases/${purchase.id}/install`, {
        installed_odometer: form.installed_odometer === '' ? null : Number(form.installed_odometer),
        warranty_months: form.warranty_months === '' ? null : Number(form.warranty_months),
        result: form.result,
        notes: form.notes.trim() || null,

        // Asset Layer. Blank fields are omitted rather than sent as empty strings so the backend's
        // "nullable" rules see a genuinely absent value and its own defaults apply.
        component: {
          component_catalog_id: form.component_catalog_id ? Number(form.component_catalog_id) : null,
          brand: form.brand.trim() || null,
          serial_no: form.serial_no.trim() || null,
          position: form.position || null,
        },
        // Only claim a removal when the fitter actually told us what happened to the old part.
        // Sending a half-filled block would trip the "no disposition, no removal" guard.
        predecessor: form.removal_reason && form.disposition
          ? { removal_reason: form.removal_reason, disposition: form.disposition }
          : null,
      });
      toast.success('Part marked installed');
      onDone();
      onClose();
    } catch (err) {
      const r = err.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not record the installation');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Install Part"
      subtitle={request ? `${request.part_name} · ${request.vehicle?.plate || `#${request.vehicle?.id}`}` : ''}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Mark installed</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label="Installed odometer (km)"
            type="number"
            min={0}
            placeholder="Optional"
            value={form.installed_odometer}
            error={errors.installed_odometer?.[0]}
            onChange={(e) => set('installed_odometer', e.target.value)}
          />
          <Input
            label="Warranty (months)"
            type="number"
            min={0}
            placeholder="Optional"
            value={form.warranty_months}
            error={errors.warranty_months?.[0]}
            onChange={(e) => set('warranty_months', e.target.value)}
          />
        </div>
        <Select label="Result" value={form.result} error={errors.result?.[0]} onChange={(e) => set('result', e.target.value)}>
          <option value="success">Success</option>
          <option value="failed">Failed</option>
          <option value="pending">Pending</option>
        </Select>
        <Textarea
          label="Notes"
          rows={2}
          placeholder="Optional"
          value={form.notes}
          error={errors.notes?.[0]}
          onChange={(e) => set('notes', e.target.value)}
        />

        {/* ── Vehicle configuration ─────────────────────────────────────────────────────────────
            Recording the install here is what puts the part on the vehicle's Installed Components
            tab — there is no separate screen to add it, and no list to keep in sync afterwards. */}
        <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
          <h4 className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Vehicle configuration</h4>
          <p className="mt-0.5 text-xs text-slate-400">
            Fitting this part updates the vehicle's installed components automatically. Nothing else needs updating.
          </p>

          {/* The type is what turns a free-text part name into a tracked asset. Without it the
              vehicle's configuration cannot be updated at all. */}
          <div className="mt-3">
            <Select
              label="Component type *"
              value={form.component_catalog_id}
              error={errors['component.component_catalog_id']?.[0]}
              onChange={(e) => set('component_catalog_id', e.target.value)}
            >
              <option value="">Select what kind of part this is…</option>
              {catalog.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </Select>
            {!form.component_catalog_id && (
              <p className="mt-1 text-xs text-amber-600">
                Pick a type, or this part will be billed but won’t appear on the vehicle’s Installed Components tab.
              </p>
            )}
          </div>

          <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input
              label="Brand"
              placeholder="e.g. Bosch"
              value={form.brand}
              error={errors['component.brand']?.[0]}
              onChange={(e) => set('brand', e.target.value)}
            />
            <Input
              label={selectedType?.requires_serial ? 'Serial number *' : 'Serial number'}
              placeholder={selectedType?.requires_serial ? 'Required for this type' : 'If the part has one'}
              value={form.serial_no}
              error={errors['component.serial_no']?.[0]}
              onChange={(e) => set('serial_no', e.target.value)}
            />
            {/* Only offered when the chosen type actually has slots — sending a position to a
                positionless type is rejected outright. */}
            <Select
              label="Position"
              value={form.position}
              disabled={positions.length === 0}
              error={errors['component.position']?.[0]}
              onChange={(e) => set('position', e.target.value)}
            >
              <option value="">{positions.length ? 'Select a position…' : 'Not applicable'}</option>
              {positions.map((p) => (
                <option key={p} value={p}>
                  {{ front_left: 'Front left', front_right: 'Front right', rear_left: 'Rear left', rear_right: 'Rear right', front: 'Front axle', rear: 'Rear axle' }[p] || p}
                </option>
              ))}
            </Select>
          </div>

          {/* The old part is never allowed to just vanish: if this fitting replaces something, the
              system needs a reason AND a destination before it will retire the previous record. */}
          <div className="mt-4 border-t border-slate-200 pt-3">
            <p className="text-xs font-medium text-slate-600">Replacing an existing part?</p>
            <p className="mt-0.5 text-xs text-slate-400">
              Answer both and the old part is retired automatically, linked to this one as its successor. Leave blank if nothing was removed.
            </p>
            <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select
                label="Why it came off"
                value={form.removal_reason}
                error={errors['predecessor.removal_reason']?.[0]}
                onChange={(e) => set('removal_reason', e.target.value)}
              >
                <option value="">Nothing was removed</option>
                <option value="worn_out">Worn out</option>
                <option value="failed">Failed</option>
                <option value="accident">Accident damage</option>
                <option value="upgrade">Upgraded</option>
                <option value="recall">Recall</option>
              </Select>
              <Select
                label="Where the old part went"
                value={form.disposition}
                error={errors['predecessor.disposition']?.[0]}
                onChange={(e) => set('disposition', e.target.value)}
              >
                <option value="">—</option>
                <option value="scrapped">Scrapped</option>
                <option value="stored">Kept as a spare</option>
                <option value="returned_supplier">Returned to supplier</option>
                <option value="warranty_return">Returned under warranty</option>
              </Select>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  );
}

// ─── Reject modal ────────────────────────────────────────────────────────────
function RejectModal({ open, request, onClose, onDone }) {
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => { if (open) { setReason(''); setError(''); } }, [open]);

  const submit = async () => {
    if (!reason.trim()) { setError('A reason is required'); return; }
    setSaving(true);
    try {
      await api.post(`/part-requests/${request.id}/reject`, { reason: reason.trim() });
      toast.success('Request rejected');
      onDone();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not reject the request');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Reject Part Request"
      subtitle={request ? request.part_name : ''}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" onClick={submit} loading={saving}>Reject</Button>
        </>
      }
    >
      <Textarea
        label="Reason"
        required
        rows={3}
        placeholder="Why is this request being rejected?"
        value={reason}
        error={error}
        onChange={(e) => setReason(e.target.value)}
      />
    </Modal>
  );
}

// ─── Approve modal (duplicate heads-up BEFORE approval) ──────────────────────
// Only shown when the pre-approval duplicate check trips — a clean request is
// approved directly with no modal. Mirrors the purchase-step warning so the
// approver sees the prior spend before green-lighting a repeat buy. The full
// mandatory reason stays at the purchase step (where money is committed); here
// an optional note is enough and is kept on the request for the audit trail.
function ApproveModal({ open, request, dup, onClose, onDone }) {
  const toast = useToast();
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => { if (open) setNote(''); }, [open]);

  const submit = async () => {
    setSaving(true);
    try {
      await api.post(`/part-requests/${request.id}/approve`, { notes: note.trim() || null });
      toast.success('Request approved');
      onDone();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not approve the request');
    } finally {
      setSaving(false);
    }
  };

  const prev = dup?.context?.previous;
  const high = dup?.priority === 'high';

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Approve — possible duplicate"
      subtitle={request ? `${request.part_name} · ${request.vehicle?.plate || `#${request.vehicle?.id}`}` : ''}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant={high ? 'danger' : 'success'} onClick={submit} loading={saving}>Approve anyway</Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className={`rounded-xl px-4 py-3 text-sm ring-1 ring-inset ${high ? 'bg-red-50 text-red-800 ring-red-600/25' : 'bg-amber-50 text-amber-800 ring-amber-600/25'}`}>
          <p className="font-semibold">
            ⚠ Attention: this vehicle already received {prev?.part_name || 'this part'} {num(dup?.days_between)} day(s) ago.
          </p>
          <ul className="mt-1.5 space-y-0.5 text-xs">
            {prev?.purchase_price != null && (
              <li>Previous cost {aed(prev.purchase_price)} {prev.currency && prev.currency !== 'AED' ? `(${prev.currency})` : ''}.</li>
            )}
            {prev?.purchased_by && <li>Previous purchase by {prev.purchased_by}.</li>}
            {prev?.source && (
              <li>Bought from {prev.source_name ? <span className="font-medium">{prev.source_name}</span> : sourceLabel(prev.source)}{prev.source_name && sourceLabel(prev.source) ? ` (${sourceLabel(prev.source)})` : ''}.</li>
            )}
            {dup?.part_class && <li>Part class: <span className="font-medium capitalize">{dup.part_class}</span> · window {num(dup.window_days)} day(s).</li>}
            {dup?.same_fault && <li className="font-medium">Same fault as before — the earlier repair may have failed.</li>}
          </ul>
        </div>
        <Textarea
          label="Reason for approving again (optional)"
          rows={2}
          placeholder="e.g. previous part failed, wrong diagnosis, customer request…"
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
        <p className="text-xs text-slate-400">
          A full reason is still required at the purchase step — this note is kept on the request for the audit trail.
        </p>
      </div>
    </Modal>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────
export default function Parts() {
  const toast = useToast();
  const { can } = usePermissions();
  const canRequest = can('parts.request');
  const canPurchase = can('parts.purchase');
  const canReview = can('parts.investigate') || can('maintenance.manage');

  // Modals hold a request → pause background polling while any is open.
  const [purchaseFor, setPurchaseFor] = useState(null);
  const [installFor, setInstallFor] = useState(null);
  const [rejectFor, setRejectFor] = useState(null);
  const [approveFor, setApproveFor] = useState(null); // { request, dup } — set only when a duplicate trips
  const anyModal = !!purchaseFor || !!installFor || !!rejectFor || !!approveFor;

  const fetcher = useCallback(async () => {
    const r = await api.get('/part-requests', { params: { per_page: 200 } });
    return payload(r) || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 30000,
    paused: () => anyModal,
  });

  const requests = useMemo(() => data?.requests || [], [data]);

  // Vendor list for the purchase form (garages + parts suppliers).
  const [vendors, setVendors] = useState([]);
  useEffect(() => {
    let alive = true;
    api.get('/Vendor').catch(() => null).then((ve) => {
      if (!alive) return;
      const p = payload(ve);
      setVendors(Array.isArray(p) ? p : p?.items || []);
    });
    return () => { alive = false; };
  }, []);

  const [search, setSearch] = useState('');
  const [source, setSource] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  // ─── Deep links from the ticket's Parts section ───────────────────────────
  // ?focus=<request id> → jump to the page holding that row, scroll to it, highlight it.
  // ?ticket=<maintenance id> → scope the board to one ticket's parts.
  const [params, setParams] = useSearchParams();
  const focusId = Number(params.get('focus')) || null;
  const ticketId = Number(params.get('ticket')) || null;
  const [highlightId, setHighlightId] = useState(null);
  const focusHandled = useRef(false);
  useEffect(() => { focusHandled.current = false; }, [focusId]);

  const clearTicketFilter = () => {
    const next = new URLSearchParams(params);
    next.delete('ticket');
    setParams(next, { replace: true });
  };

  const counts = useMemo(() => {
    const acc = {};
    for (const s of ALL_STATUSES) acc[s] = 0;
    for (const r of requests) if (acc[r.status] != null) acc[r.status] += 1;
    return acc;
  }, [requests]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return requests.filter((r) => {
      const matchSearch =
        !q ||
        [r.part_name, r.part_number, r.vehicle?.plate, r.vehicle?.make, r.vehicle?.model, r.customer?.name, r.requested_by]
          .some((f) => (f || '').toLowerCase().includes(q));
      const matchSource = !source || r.source === source;
      const matchStatus = !status || r.status === status;
      const matchTicket = !ticketId || r.maintenance_id === ticketId;
      return matchSearch && matchSource && matchStatus && matchTicket;
    });
  }, [requests, search, source, status, ticketId]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  // Once the focused request is in the (filtered) list, page to it and arm the highlight. Runs once per
  // ?focus value so the 30s background refresh can't yank the page back or re-flash the row.
  useEffect(() => {
    if (!focusId || focusHandled.current || loading) return;
    const idx = filtered.findIndex((r) => r.id === focusId);
    if (idx < 0) return;
    focusHandled.current = true;
    setPage(Math.floor(idx / PAGE_SIZE) + 1);
    setHighlightId(focusId);
  }, [focusId, filtered, loading]);

  // Scroll to the row once it has actually rendered on the current page, then fade the highlight.
  useEffect(() => {
    if (!highlightId) return undefined;
    const el = document.getElementById(`part-row-${highlightId}`);
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const t = setTimeout(() => setHighlightId(null), 5000);
    return () => clearTimeout(t);
  }, [highlightId, safePage]);

  const toggleStatus = (s) => { setStatus(status === s ? '' : s); setPage(1); };

  // Direct (no-modal) lifecycle actions, keyed busy so rows don't double-fire.
  const [busy, setBusy] = useState(null); // `${id}:${action}`
  const runAction = async (req, action, okMsg) => {
    setBusy(`${req.id}:${action}`);
    try {
      await api.post(`/part-requests/${req.id}/${action}`, {});
      toast.success(okMsg);
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Action failed');
    } finally {
      setBusy(null);
    }
  };
  const isBusy = (req, action) => busy === `${req.id}:${action}`;

  // Approve runs a duplicate heads-up FIRST: a clean request approves straight through,
  // a flagged one opens the warning modal so the approver sees the prior spend before proceeding.
  const onApprove = async (req) => {
    setBusy(`${req.id}:approve`);
    let dup = null;
    try {
      dup = payload(await api.get('/part-purchases/duplicate-check', {
        params: { vehicle_id: req.vehicle?.id, part_name: req.part_name, part_number: req.part_number || undefined },
      }));
    } catch { dup = null; } // advisory only — never block approval on the check
    if (dup?.duplicate) { setApproveFor({ request: req, dup }); setBusy(null); return; }
    try {
      await api.post(`/part-requests/${req.id}/approve`, {});
      toast.success('Request approved');
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Action failed');
    } finally {
      setBusy(null);
    }
  };

  // Mark a purchased part as delivered to the workshop (before install). Targets the request's active
  // (uninstalled) purchase; the backend derives "waiting for parts" from delivered_at.
  const markDelivered = async (req, po) => {
    setBusy(`${req.id}:delivered`);
    try {
      await api.post(`/part-purchases/${po.id}/delivered`, {});
      toast.success('Part marked delivered');
      reload({ silent: true });
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not mark delivered');
    } finally {
      setBusy(null);
    }
  };

  const rowActions = (r) => {
    const actions = [];
    // 'under_review' kept in the guard so any legacy row in that state can still be actioned,
    // but the Review step itself is retired — a request goes straight to Approve/Reject.
    if (['requested', 'under_review'].includes(r.status) && canReview) {
      actions.push(
        <Button key="approve" variant="success" size="sm" loading={isBusy(r, 'approve')} onClick={() => onApprove(r)}>Approve</Button>,
        <Button key="reject" variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setRejectFor(r)}>Reject</Button>,
      );
    }
    if (r.status === 'approved' && canPurchase) {
      actions.push(<Button key="purchase" size="sm" onClick={() => setPurchaseFor(r)}>Record Purchase</Button>);
    }
    if (r.status === 'purchased' && canPurchase) {
      const po = r.purchases?.find((p) => !p.installed_at) || r.purchases?.[r.purchases.length - 1];
      if (po && !po.delivered_at) {
        actions.push(<Button key="delivered" variant="ghost" size="sm" className="text-cyan-700 hover:bg-cyan-50" loading={isBusy(r, 'delivered')} onClick={() => markDelivered(r, po)}>Mark delivered</Button>);
      } else if (po?.delivered_at) {
        actions.push(<span key="delivered-tag" className="inline-flex items-center rounded-md bg-cyan-50 px-2 py-1 text-xs font-medium text-cyan-700 ring-1 ring-inset ring-cyan-200">✓ Delivered</span>);
      }
      actions.push(<Button key="install" variant="secondary" size="sm" onClick={() => setInstallFor(r)}>Install</Button>);
    }
    if (r.status === 'installed' && canRequest) {
      actions.push(<Button key="complete" variant="success" size="sm" loading={isBusy(r, 'complete')} onClick={() => runAction(r, 'complete', 'Request completed')}>Complete</Button>);
    }
    return actions;
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Parts Purchase"
          subtitle={loading ? '…' : `${num(filtered.length)} of ${num(requests.length)} part requests`}
        >
          {/* Requests are initiated from inside the maintenance ticket (vehicle → ticket → fault), not here.
              This board is the purchasing hub: review → approve → purchase → install → investigations. */}
          <span className="text-xs text-slate-400">Requests start from a maintenance ticket</span>
        </PageHeader>

        {/* Status summary tiles (click to filter) */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          {TILE_STATUSES.map((s) => (
            <button
              key={s}
              onClick={() => toggleStatus(s)}
              className={`hover-lift flex flex-col rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${status === s ? 'ring-2 ring-indigo-500' : ''}`}
            >
              <span className="flex items-center gap-2">
                <span className={`h-1.5 w-1.5 rounded-full ${STATUS_TONE[s] === 'green' ? 'bg-emerald-500' : STATUS_TONE[s] === 'violet' ? 'bg-violet-500' : STATUS_TONE[s] === 'cyan' ? 'bg-cyan-500' : STATUS_TONE[s] === 'blue' ? 'bg-blue-500' : STATUS_TONE[s] === 'amber' ? 'bg-amber-500' : 'bg-slate-400'}`} />
                <span className="text-xs font-medium text-slate-500">{STATUS_LABEL[s]}</span>
              </span>
              <span className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts[s] || 0)}</span>
            </button>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search part, plate, customer…" />
          <Select className="sm:w-44" value={source} onChange={(e) => { setSource(e.target.value); setPage(1); }}>
            <option value="">All sources</option>
            <option value="garage">Garage</option>
          </Select>
          <Select className="sm:w-48" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">All statuses</option>
            {ALL_STATUSES.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
          </Select>
        </div>

        {/* Arrived from a ticket's Parts section — say so, and offer the way back to the full board. */}
        {ticketId && (
          <div className="flex items-center gap-2 rounded-lg bg-indigo-50 px-4 py-2 text-sm text-indigo-800 ring-1 ring-inset ring-indigo-600/20">
            <span>Showing parts for maintenance ticket <span className="font-semibold">#{ticketId}</span> only.</span>
            <button type="button" onClick={clearTicketFilter} className="font-semibold underline underline-offset-2 hover:text-indigo-900">
              Show all parts
            </button>
          </div>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Analytics — request VOLUME follows the filtered set so those charts agree with the
            table below; the money chart is fleet-wide (invoiced part lines are not requests),
            so it renders even when the filtered request list is empty. */}
        {!loading && (filtered.length > 0 || SHOW_FINANCIALS) && (
          <PartsAnalytics requests={filtered} showFinancials={SHOW_FINANCIALS} />
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Vehicle</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Part</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Source</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Location</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Status</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">Requested</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">Actions</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {paged.map((r) => (
                    <tr
                      key={r.id}
                      id={`part-row-${r.id}`}
                      className={`transition-colors hover:bg-indigo-50/40 ${highlightId === r.id ? 'bg-amber-100/80 ring-2 ring-inset ring-amber-400' : 'bg-white even:bg-slate-50/40'}`}
                    >
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{r.vehicle?.plate || (r.vehicle?.id ? `#${r.vehicle.id}` : '—')}</div>
                        <div className="text-xs text-slate-400">{[r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex items-center gap-2">
                          <span className="font-medium text-slate-900">{r.part_name}</span>
                          {r.part_class && <Badge tone={CLASS_TONE[r.part_class] || 'gray'}>{CLASS_LABEL[r.part_class] || r.part_class}</Badge>}
                        </div>
                        <div className="text-xs text-slate-400">
                          {r.part_number ? `#${r.part_number}` : ''}{r.quantity > 1 ? `${r.part_number ? ' · ' : ''}×${r.quantity}` : ''}
                          {SHOW_FINANCIALS && r.estimated_price != null ? ` · est. ${aed(r.estimated_price)}` : ''}
                        </div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <Badge tone={SOURCE_TONE[r.source] || 'gray'}>{r.source}</Badge>
                        {r.source === 'customer' && r.customer?.name && <div className="mt-0.5 text-xs text-slate-400">{r.customer.name}</div>}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600 capitalize">{r.repair_location === 'onsite' ? 'On-site' : (r.repair_location || '—')}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5"><StatusBadge status={r.status} /></td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        <div>{r.requested_by || '—'}</div>
                        <div className="text-xs text-slate-400">{fmtAgo(r.requested_at) || ''}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="flex justify-end gap-2">
                          {rowActions(r).length ? rowActions(r) : <span className="text-slate-300">—</span>}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && (
              <EmptyState title="No part requests" message="Nothing matches these filters yet." />
            )}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>
      </div>

      <PurchaseModal
        open={!!purchaseFor}
        request={purchaseFor}
        onClose={() => setPurchaseFor(null)}
        onDone={() => reload({ silent: true })}
        vendors={vendors}
      />
      <InstallModal
        open={!!installFor}
        request={installFor}
        onClose={() => setInstallFor(null)}
        onDone={() => reload({ silent: true })}
      />
      <RejectModal
        open={!!rejectFor}
        request={rejectFor}
        onClose={() => setRejectFor(null)}
        onDone={() => reload({ silent: true })}
      />
      <ApproveModal
        open={!!approveFor}
        request={approveFor?.request}
        dup={approveFor?.dup}
        onClose={() => setApproveFor(null)}
        onDone={() => reload({ silent: true })}
      />
    </div>
  );
}
