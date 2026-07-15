import { useCallback, useEffect, useMemo, useState } from 'react';
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
const TILE_STATUSES = ['requested', 'under_review', 'approved', 'purchased', 'installed', 'completed'];
const ALL_STATUSES = [...TILE_STATUSES, 'rejected', 'cancelled'];

const CLASS_TONE = { consumable: 'gray', standard: 'blue', major: 'amber' };
const CLASS_LABEL = { consumable: 'Consumable', standard: 'Standard', major: 'Major' };
const SOURCE_TONE = { customer: 'violet', garage: 'amber' };

// The five reasons the buyer must pick when a duplicate purchase is flagged.
const DUP_REASONS = [
  ['previous_part_failed', 'Previous part failed'],
  ['wrong_diagnosis', 'Wrong diagnosis'],
  ['customer_requested', 'Customer requested replacement'],
  ['accident_damage', 'Accident/damage'],
  ['other', 'Other'],
];

const vehLabel = (v) => `${v.plate_no || v.plate || `#${v.id}`} · ${[v.make, v.model].filter(Boolean).join(' ') || v.vin || ''}`.trim();

function StatusBadge({ status }) {
  return <Badge tone={STATUS_TONE[status] || 'gray'}>{STATUS_LABEL[status] || status}</Badge>;
}

const EMPTY_REQUEST = {
  source: 'garage',
  vehicle_id: '',
  customer_id: '',
  maintenance_id: '',
  maintenance_task_id: '',
  part_name: '',
  part_number: '',
  quantity: 1,
  repair_location: 'garage',
  reason: '',
  estimated_price: '',
  currency: 'AED',
  notes: '',
};

// ─── Create request modal ────────────────────────────────────────────────────
function CreateRequestModal({ open, onClose, onCreated, vehicles, customers }) {
  const toast = useToast();
  const [form, setForm] = useState(EMPTY_REQUEST);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => { if (open) { setForm(EMPTY_REQUEST); setErrors({}); } }, [open]);

  const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body = {
        source: form.source,
        vehicle_id: form.vehicle_id || null,
        part_name: form.part_name.trim(),
        part_number: form.part_number.trim() || null,
        repair_location: form.repair_location,
        quantity: Number(form.quantity) || 1,
        reason: form.reason.trim(),
        estimated_price: form.estimated_price === '' ? null : Number(form.estimated_price),
        currency: form.currency || 'AED',
        notes: form.notes.trim() || null,
      };
      if (form.source === 'customer') body.customer_id = form.customer_id || null;
      if (form.source === 'garage') {
        body.maintenance_id = form.maintenance_id ? Number(form.maintenance_id) : null;
        body.maintenance_task_id = form.maintenance_task_id ? Number(form.maintenance_task_id) : null;
      }
      await api.post('/part-requests', body);
      toast.success('Part request submitted');
      onCreated();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(res?.message || res?.msg || 'Could not submit the request');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="New Part Request"
      subtitle="Request a part for a customer car or an open garage ticket"
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Submit request</Button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Source toggle */}
        <div>
          <span className="mb-1 block text-sm font-medium text-slate-700">Source</span>
          <div className="inline-flex rounded-lg border border-slate-300 p-0.5">
            {['garage', 'customer'].map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => set('source', s)}
                className={`rounded-md px-4 py-1.5 text-sm font-semibold capitalize transition ${form.source === s ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'}`}
              >
                {s === 'garage' ? 'Garage ticket' : 'Customer'}
              </button>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">Vehicle<span className="ms-0.5 text-red-500">*</span></span>
            <SearchSelect
              value={form.vehicle_id}
              onChange={(v) => set('vehicle_id', v)}
              options={vehicles.map((v) => ({ id: v.id, label: vehLabel(v), sub: v.vin || undefined }))}
              placeholder="Pick the car…"
            />
            {errors.vehicle_id && <span className="mt-1 block text-xs text-red-600">{errors.vehicle_id[0]}</span>}
          </div>

          {form.source === 'customer' ? (
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">Customer<span className="ms-0.5 text-red-500">*</span></span>
              <SearchSelect
                value={form.customer_id}
                onChange={(v) => set('customer_id', v)}
                options={customers.map((c) => ({ id: c.id, label: c.name || `#${c.id}` }))}
                placeholder="Pick the customer…"
              />
              {errors.customer_id && <span className="mt-1 block text-xs text-red-600">{errors.customer_id[0]}</span>}
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-3">
              <Input
                label="Ticket #"
                type="number"
                placeholder="Maintenance ID"
                value={form.maintenance_id}
                error={errors.maintenance_id?.[0]}
                onChange={(e) => set('maintenance_id', e.target.value)}
              />
              <Input
                label="Fault task #"
                type="number"
                placeholder="Optional"
                value={form.maintenance_task_id}
                error={errors.maintenance_task_id?.[0]}
                onChange={(e) => set('maintenance_task_id', e.target.value)}
              />
            </div>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label="Part name"
            required
            placeholder="e.g. Front brake pads"
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

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Input
            label="Quantity"
            type="number"
            min={1}
            value={form.quantity}
            error={errors.quantity?.[0]}
            onChange={(e) => set('quantity', e.target.value)}
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
          {/* Price entry stays visible even while money DISPLAY is hidden — it is a record, not a rolled-up figure. */}
          <div className="grid grid-cols-[1fr_auto] gap-2">
            <Input
              label="Est. price"
              type="number"
              min={0}
              step="0.01"
              placeholder="Optional"
              value={form.estimated_price}
              error={errors.estimated_price?.[0]}
              onChange={(e) => set('estimated_price', e.target.value)}
            />
            <Input
              label="Cur."
              className="w-20"
              value={form.currency}
              onChange={(e) => set('currency', e.target.value)}
            />
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
              {SHOW_FINANCIALS && prev?.purchase_price != null && (
                <li>Previous cost {aed(prev.purchase_price)} {prev.currency && prev.currency !== 'AED' ? `(${prev.currency})` : ''}.</li>
              )}
              {prev?.purchased_by && <li>Previous purchase by {prev.purchased_by}.</li>}
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
  const [form, setForm] = useState({ installed_odometer: '', warranty_months: '', result: 'success', notes: '' });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) { setForm({ installed_odometer: '', warranty_months: '', result: 'success', notes: '' }); setErrors({}); }
  }, [open]);

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

// ─── Page ────────────────────────────────────────────────────────────────────
export default function Parts() {
  const toast = useToast();
  const { can } = usePermissions();
  const canRequest = can('parts.request');
  const canPurchase = can('parts.purchase');
  const canReview = can('parts.investigate') || can('maintenance.manage');

  // Modals hold a request → pause background polling while any is open.
  const [createOpen, setCreateOpen] = useState(false);
  const [purchaseFor, setPurchaseFor] = useState(null);
  const [installFor, setInstallFor] = useState(null);
  const [rejectFor, setRejectFor] = useState(null);
  const anyModal = createOpen || !!purchaseFor || !!installFor || !!rejectFor;

  const fetcher = useCallback(async () => {
    const r = await api.get('/part-requests', { params: { per_page: 200 } });
    return payload(r) || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 30000,
    paused: () => anyModal,
  });

  const requests = useMemo(() => data?.requests || [], [data]);

  // Option lists for the create / purchase forms.
  const [vehicles, setVehicles] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [vendors, setVendors] = useState([]);
  useEffect(() => {
    let alive = true;
    Promise.all([
      api.get('/Vehicle').catch(() => null),
      api.get('/Customer').catch(() => null),
      api.get('/Vendor').catch(() => null),
    ]).then(([v, c, ve]) => {
      if (!alive) return;
      const list = (r) => { const p = payload(r); return Array.isArray(p) ? p : p?.items || []; };
      setVehicles(list(v));
      setCustomers(list(c));
      setVendors(list(ve));
    });
    return () => { alive = false; };
  }, []);

  const [search, setSearch] = useState('');
  const [source, setSource] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

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
      return matchSearch && matchSource && matchStatus;
    });
  }, [requests, search, source, status]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

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

  const rowActions = (r) => {
    const actions = [];
    if (['requested', 'under_review'].includes(r.status) && canReview) {
      if (r.status === 'requested') {
        actions.push(
          <Button key="review" variant="ghost" size="sm" loading={isBusy(r, 'review')} onClick={() => runAction(r, 'review', 'Marked under review')}>Review</Button>,
        );
      }
      actions.push(
        <Button key="approve" variant="success" size="sm" loading={isBusy(r, 'approve')} onClick={() => runAction(r, 'approve', 'Request approved')}>Approve</Button>,
        <Button key="reject" variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setRejectFor(r)}>Reject</Button>,
      );
    }
    if (r.status === 'approved' && canPurchase) {
      actions.push(<Button key="purchase" size="sm" onClick={() => setPurchaseFor(r)}>Record Purchase</Button>);
    }
    if (r.status === 'purchased' && canPurchase) {
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
          {canRequest && (
            <Button onClick={() => setCreateOpen(true)}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              New Part Request
            </Button>
          )}
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
            <option value="customer">Customer</option>
            <option value="garage">Garage</option>
          </Select>
          <Select className="sm:w-48" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">All statuses</option>
            {ALL_STATUSES.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
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
                    <tr key={r.id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
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

      <CreateRequestModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => reload({ silent: true })}
        vehicles={vehicles}
        customers={customers}
      />
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
    </div>
  );
}
