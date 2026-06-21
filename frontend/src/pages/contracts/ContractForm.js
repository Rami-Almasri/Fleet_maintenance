import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import api from '../../api/client';
import { useToast } from '../../components/ui/Toast';
import Button from '../../components/ui/Button';
import { Card, Spinner } from '../../components/ui/Misc';
import { Input, Select } from '../../components/ui/Field';
import SearchSelect from '../../components/ui/SearchSelect';
import { aed2 } from '../../lib/format';

const TYPES = [{ v: 'C', l: 'Rental' }, { v: 'U', l: 'Maintenance' }, { v: 'R', l: 'Booking' }];

// People who can be marked responsible for a maintenance visit. Currently one person
// owns the workshop, so the Responsible field is restricted to (and defaults to) him.
const MAINTENANCE_RESPONSIBLES = ['ABDULLAH HESHAM FAWAZ'];

function Section({ title, children, cols = 2 }) {
  return (
    <Card className="p-6">
      <h3 className="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</h3>
      <div className={`grid grid-cols-1 gap-4 ${cols === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>{children}</div>
    </Card>
  );
}

const NUM_FIELDS = [
  'out_milage', 'in_milage', 'days', 'km',
  'day_price', 'week_price', 'month_price',
  'rents_debit', 'salik_debit', 'damages_debit', 'cdw_debit', 'vat_debit', 'deposit_debit',
  'contract_debit', 'contract_credit', 'contract_balance', 'contract_deposit',
];

function cleanPayload(values) {
  const out = {};
  Object.entries(values).forEach(([k, v]) => {
    if (v === '' || v === null || v === undefined) return;
    out[k] = v;
  });
  return out;
}

export default function ContractForm() {
  const { id } = useParams();
  const isEdit = Boolean(id);
  const navigate = useNavigate();
  const toast = useToast();

  const [form, setForm] = useState({ contract_type: 'C', state: 'open' });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [vehicles, setVehicles] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [loading, setLoading] = useState(true);

  // maintenance: invoice-style line items + issue tags (kept outside `form`)
  const [items, setItems] = useState([]);     // [{ service_name, cost, notes }]
  const [tags, setTags] = useState([]);        // ["Engine", "Brakes", ...]
  const [tagInput, setTagInput] = useState('');
  const [reasons, setReasons] = useState([]);  // controlled reason->status vocabulary

  // Inline "new customer" panel for the New Contract form: null = closed,
  // { name, mobile } = open. Lets you add a walk-in customer without leaving the page.
  const [newCust, setNewCust] = useState(null);
  const [creatingCust, setCreatingCust] = useState(false);

  const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
  const setVal = (field, value) => setForm((f) => ({ ...f, [field]: value }));
  const err = (f) => (errors[f] ? errors[f][0] : '');

  // Picking a car pre-fills Out Mileage with its current odometer (new contracts only,
  // so we never clobber the recorded pickup mileage of an existing contract).
  const onVehicleChange = (v) => setForm((f) => {
    const next = { ...f, vehicle_id: v };
    if (!isEdit) {
      const veh = vehicles.find((x) => String(x.id) === String(v));
      if (veh && veh.odometer !== null && veh.odometer !== undefined && veh.odometer !== '') {
        next.out_milage = veh.odometer;
      }
    }
    return next;
  });

  // Create a walk-in customer inline, then select them for this contract.
  const createCustomer = async () => {
    const name = (newCust?.name || '').trim();
    if (!name) { toast.error('Enter the customer name first'); return; }
    setCreatingCust(true);
    try {
      const { data } = await api.post('/Customer', {
        name_en: name,
        mobile1: (newCust.mobile || '').trim() || undefined,
      });
      const c = data.data;
      setCustomers((list) => [c, ...list]);   // appears in the picker immediately
      setVal('customer_id', c.id);             // and is selected for this contract
      setNewCust(null);
      toast.success(`Customer “${c.name_en || name}” created`);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not create customer');
    } finally {
      setCreatingCust(false);
    }
  };

  // load option lists + (edit) the contract
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [vRes, cRes, venRes, rRes] = await Promise.all([
        api.get('/Vehicle'), api.get('/Customer'), api.get('/Vendor'),
        api.get('/Maintenance/reasons').catch(() => ({ data: { data: [] } })),
      ]);
      setVehicles(vRes.data.data || []);
      setCustomers(cRes.data.data || []);
      setVendors(venRes.data.data || []);
      setReasons(rRes.data.data || []);
      if (isEdit) {
        const { data } = await api.get(`/Contract/${id}`);
        const c = data.data;
        const d = (x) => (x ? String(x).slice(0, 10) : '');
        setForm({
          ...c,
          out_date: d(c.out_date), in_date: d(c.in_date),
          expected_return_date: d(c.expected_return_date),
          customer_id: c.customer_id || '', vehicle_id: c.vehicle_id || '', vendor_id: c.vendor_id || '',
        });
        setItems((c.items || []).map((i) => ({ service_name: i.service_name || '', cost: i.cost ?? '', notes: i.notes || '' })));
        setTags(Array.isArray(c.maintenance_tags) ? c.maintenance_tags : []);
      } else {
        // New contract: auto-assign the next contract number so the user never types one.
        const nRes = await api.get('/Contract/next-no').catch(() => null);
        const autoNo = nRes?.data?.data?.contract_no;
        if (autoNo) setForm((f) => ({ ...f, contract_no: autoNo }));
      }
    } catch (e) {
      toast.error('Failed to load form data');
    } finally {
      setLoading(false);
    }
  }, [id, isEdit, toast]);

  useEffect(() => { load(); }, [load]);

  const vehicleOptions = useMemo(() => vehicles.map((v) => ({
    id: v.id, label: `${v.plate_no || v.vin}`, sub: [v.make, v.model].filter(Boolean).join(' '),
  })), [vehicles]);
  const customerOptions = useMemo(() => customers.map((c) => ({
    id: c.id, label: c.name_en || `#${c.customer_no}`, sub: c.mobile1 || `#${c.customer_no}`,
  })), [customers]);
  const vendorOptions = useMemo(() => vendors.map((v) => ({
    id: v.id, label: v.name || `#${v.id}`, sub: v.type || v.phone || '',
  })), [vendors]);

  // --- Maintenance mode (contract_type 'U') ---
  const isMaintenance = form.contract_type === 'U';

  // Default the maintenance "Responsible" to the workshop owner the moment the contract
  // is switched to Maintenance (new contracts only; never overwrite an existing value).
  useEffect(() => {
    if (isMaintenance && !isEdit) {
      setForm((f) => (f.responsible ? f : { ...f, responsible: MAINTENANCE_RESPONSIBLES[0] }));
    }
  }, [isMaintenance, isEdit]);
  const itemsTotal = useMemo(
    () => items.reduce((s, i) => s + (Number(i.cost) || 0), 0),
    [items],
  );
  // Issue keywords come from the controlled reason->status vocabulary (the sheet).
  const levelOf = (text) => reasons.find((r) => r.reason.toLowerCase() === String(text).toLowerCase())?.level || null;
  const LEVEL_CHIP = {
    critical: 'bg-red-50 text-red-700 ring-red-200',
    special: 'bg-violet-50 text-violet-700 ring-violet-200',
    minor: 'bg-amber-50 text-amber-700 ring-amber-200',
    routine: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  };
  const LEVEL_META = {
    critical: { label: 'Critical', emoji: '🔴' }, special: { label: 'Special', emoji: '🟣' },
    minor: { label: 'Minor', emoji: '🟡' }, routine: { label: 'Routine', emoji: '🟢' },
  };
  const LEVEL_RANK = { critical: 0, special: 1, minor: 2, routine: 3 };
  // Predicted priority for this visit = most severe level among the chosen tags.
  const predicted = tags
    .map(levelOf).filter(Boolean)
    .sort((a, b) => LEVEL_RANK[a] - LEVEL_RANK[b])[0] || null;

  const addItem = () => setItems((x) => [...x, { service_name: '', cost: '', notes: '' }]);
  const removeItem = (idx) => setItems((x) => x.filter((_, i) => i !== idx));
  const setItem = (idx, field, value) => setItems((x) => x.map((it, i) => (i === idx ? { ...it, [field]: value } : it)));

  const addTag = (t) => {
    const v = (t || '').trim();
    if (!v) return;
    setTags((x) => (x.some((y) => y.toLowerCase() === v.toLowerCase()) ? x : [...x, v]));
    setTagInput('');
  };
  const removeTag = (t) => setTags((x) => x.filter((y) => y !== t));

  // --- Available wallet (money the customer already paid, carried from prior contracts) ---
  // Only offered on NEW contracts: the selected customer's wallet reflects their other
  // contracts, so applying it here is clean (this contract isn't saved yet).
  const selectedCustomer = useMemo(
    () => customers.find((c) => String(c.id) === String(form.customer_id)),
    [customers, form.customer_id],
  );
  const wallet = (!isEdit && selectedCustomer) ? Number(selectedCustomer.available_wallet || 0) : 0;
  const charges = Number(form.contract_debit || 0);
  const applied = Math.min(wallet, charges);          // wallet portion this contract can absorb
  const cashToCollect = Math.max(0, charges - applied);

  // Apply the wallet: collect only (charges − wallet) as cash. The pre-paid credit is
  // consumed automatically through the customer's running balance — no double counting.
  const applyWallet = () => {
    if (charges <= 0) {
      toast.error('Enter the contract charges (Contract Debit) first, then apply the wallet.');
      return;
    }
    setForm((f) => ({
      ...f,
      contract_credit: Number(cashToCollect.toFixed(2)),
      contract_balance: Number((charges - cashToCollect).toFixed(2)),
    }));
    toast.success(`Applied ${aed2(applied)} from wallet · collect ${aed2(cashToCollect)} from customer`);
  };

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const payload = cleanPayload(form);
      // coerce numeric fields
      NUM_FIELDS.forEach((k) => { if (payload[k] !== undefined) payload[k] = Number(payload[k]); });

      if (isMaintenance) {
        // a maintenance visit: invoice items + tags; the bill = sum of item costs
        payload.contract_type = 'U';
        payload.maintenance_tags = tags;
        payload.items = items
          .filter((i) => (i.service_name || '').trim() !== '')
          .map((i) => ({
            service_name: i.service_name.trim(),
            cost: Number(i.cost) || 0,
            notes: (i.notes || '').trim() || null,
          }));
        payload.contract_debit = Number(itemsTotal.toFixed(2));
      }
      let res;
      if (isEdit) {
        res = await api.post(`/Contract/${id}`, payload);
        toast.success('Contract updated');
        navigate(`/contracts/${id}`);
      } else {
        res = await api.post('/Contract', payload);
        toast.success('Contract created');
        const newId = res.data?.data?.id;
        navigate(newId ? `/contracts/${newId}` : '/contracts');
      }
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not save contract');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
          <Link to={isEdit ? `/contracts/${id}` : '/contracts'} className="inline-flex items-center gap-1 text-sm font-medium text-gray-500 transition hover:text-gray-700">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
            {isEdit ? 'Contract' : 'Contracts'}
          </Link>
          <h1 className="mt-2 text-2xl font-bold tracking-tight text-gray-900">{isEdit ? `Edit Contract #${form.contract_no || id}` : 'New Contract'}</h1>
        </div>

        {wallet > 0 && (
          <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-sm font-semibold text-emerald-800">
                  💰 {selectedCustomer?.name_en || 'This customer'} has {aed2(wallet)} available credit (wallet)
                </p>
                <p className="mt-0.5 text-xs text-emerald-700">
                  {charges > 0
                    ? `Apply it to cover ${aed2(applied)} of ${aed2(charges)} charges — collect only ${aed2(cashToCollect)} from the customer.`
                    : 'Money paid earlier and not yet used. Enter the charges (Contract Debit) below, then apply it.'}
                </p>
              </div>
              <Button variant="secondary" onClick={applyWallet} disabled={charges <= 0}>
                Apply to this contract
              </Button>
            </div>
          </div>
        )}

        <Section title="Basics">
          <Input
            label={isEdit ? 'Contract No.' : 'Contract No. (auto)'}
            required
            value={form.contract_no || ''}
            onChange={set('contract_no')}
            error={err('contract_no')}
            readOnly={!isEdit}
            title={!isEdit ? 'Generated automatically' : undefined}
            className={!isEdit ? 'bg-gray-50 text-gray-500' : ''}
          />
          <Select label="Type" value={form.contract_type || 'C'} onChange={set('contract_type')} error={err('contract_type')}>
            {TYPES.map((t) => <option key={t.v} value={t.v}>{t.l}</option>)}
          </Select>
          <Select label="State" value={form.state || 'open'} onChange={set('state')} error={err('state')}>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </Select>
        </Section>

        <Section title="Parties">
          <label className="block">
            <div className="mb-1 flex items-center justify-between">
              <span className="block text-sm font-medium text-gray-700">Customer</span>
              <button
                type="button"
                onClick={() => setNewCust(newCust ? null : { name: '', mobile: '' })}
                className="text-xs font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {newCust ? 'Pick existing' : '+ New customer'}
              </button>
            </div>
            {newCust ? (
              <div className="space-y-2 rounded-lg border border-indigo-200 bg-indigo-50/40 p-3">
                <input
                  autoFocus
                  className={inputCls}
                  placeholder="Full name *"
                  value={newCust.name}
                  onChange={(e) => setNewCust((n) => ({ ...n, name: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createCustomer(); } }}
                />
                <input
                  className={inputCls}
                  placeholder="Mobile (optional)"
                  value={newCust.mobile}
                  onChange={(e) => setNewCust((n) => ({ ...n, mobile: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createCustomer(); } }}
                />
                <div className="flex justify-end">
                  <Button variant="secondary" onClick={createCustomer} loading={creatingCust}>Add customer</Button>
                </div>
              </div>
            ) : (
              <SearchSelect value={form.customer_id} onChange={(v) => setVal('customer_id', v)} options={customerOptions} placeholder="Search customer…" />
            )}
            {err('customer_id') && <span className="mt-1 block text-xs text-red-600">{err('customer_id')}</span>}
          </label>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-gray-700">Vehicle</span>
            <SearchSelect value={form.vehicle_id} onChange={onVehicleChange} options={vehicleOptions} placeholder="Search plate / VIN…" />
            {err('vehicle_id') && <span className="mt-1 block text-xs text-red-600">{err('vehicle_id')}</span>}
          </label>
        </Section>

        <Section title="Out (pickup)" cols={3}>
          <Input label="Out Date" type="date" value={form.out_date || ''} onChange={set('out_date')} />
          <Input label="Out Time" value={form.out_time || ''} onChange={set('out_time')} placeholder="14:30" />
          <Input label="Out Mileage" type="number" value={form.out_milage ?? ''} onChange={set('out_milage')} />
          <Input label="Out Fuel" value={form.out_fuel || ''} onChange={set('out_fuel')} />
          <Input label="Opened By" value={form.opened_by || ''} onChange={set('opened_by')} />
        </Section>

        <Section title="In (return)" cols={3}>
          <Input label="In Date" type="date" value={form.in_date || ''} onChange={set('in_date')} />
          <Input label="In Time" value={form.in_time || ''} onChange={set('in_time')} placeholder="12:00" />
          <Input label="In Mileage" type="number" value={form.in_milage ?? ''} onChange={set('in_milage')} />
          <Input label="In Fuel" value={form.in_fuel || ''} onChange={set('in_fuel')} />
          <Input label="Closed By" value={form.closed_by || ''} onChange={set('closed_by')} />
          <Input label="Days" type="number" value={form.days ?? ''} onChange={set('days')} />
          <Input label="KM" type="number" value={form.km ?? ''} onChange={set('km')} />
        </Section>

        {isMaintenance ? (
          <>
            <Section title="Maintenance Details" cols={2}>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-gray-700">Garage / Vendor</span>
                <SearchSelect value={form.vendor_id} onChange={(v) => setVal('vendor_id', v)} options={vendorOptions} placeholder="Search garage / vendor…" />
                {err('vendor_id') && <span className="mt-1 block text-xs text-red-600">{err('vendor_id')}</span>}
              </label>
              <Input label="Expected Return" type="date" value={form.expected_return_date || ''} onChange={set('expected_return_date')} />
              <Select label="Responsible" value={form.responsible || ''} onChange={set('responsible')}>
                <option value="">Select…</option>
                {MAINTENANCE_RESPONSIBLES.map((p) => <option key={p} value={p}>{p}</option>)}
                {/* keep any pre-existing responsible from an older record selectable */}
                {form.responsible && !MAINTENANCE_RESPONSIBLES.includes(form.responsible) && (
                  <option value={form.responsible}>{form.responsible}</option>
                )}
              </Select>
              <Input label="Approved By" value={form.approved_by || ''} onChange={set('approved_by')} />
            </Section>

            {/* Issue keywords / tags */}
            <Card className="p-6">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Issue Keywords / Tags</h3>
                {predicted && (
                  <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${LEVEL_CHIP[predicted]}`}>
                    Priority: {LEVEL_META[predicted].emoji} {LEVEL_META[predicted].label}
                  </span>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                {tags.map((t) => {
                  const lvl = levelOf(t);
                  return (
                    <span key={t} className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset ${lvl ? LEVEL_CHIP[lvl] : 'bg-gray-50 text-gray-700 ring-gray-200'}`}>
                      {t}
                      <button type="button" onClick={() => removeTag(t)} className="opacity-50 hover:opacity-100">×</button>
                    </span>
                  );
                })}
                {tags.length === 0 && <span className="text-sm text-gray-400">No issues yet — pick from the list below; each one sets the maintenance priority.</span>}
              </div>
              <div className="mt-3 flex gap-2">
                <input
                  value={tagInput}
                  onChange={(e) => setTagInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(tagInput); } }}
                  placeholder="Type a keyword and press Enter…"
                  className={inputCls}
                />
                <Button variant="secondary" onClick={() => addTag(tagInput)}>Add</Button>
              </div>
              {reasons.length > 0 && (
                <div className="mt-3">
                  <p className="mb-1.5 text-xs text-gray-400">Common reasons (colour = priority):</p>
                  <div className="flex flex-wrap gap-1.5">
                    {reasons
                      .filter((r) => !tags.some((x) => x.toLowerCase() === r.reason.toLowerCase()))
                      .map((r) => (
                        <button
                          type="button"
                          key={r.reason}
                          onClick={() => addTag(r.reason)}
                          title={`${LEVEL_META[r.level]?.label || ''}${r.reason_ar ? ' · ' + r.reason_ar : ''}`}
                          className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition hover:brightness-95 ${LEVEL_CHIP[r.level] || 'bg-gray-100 text-gray-600 ring-gray-200'}`}
                        >
                          + {r.reason}
                        </button>
                      ))}
                  </div>
                </div>
              )}
            </Card>

            {/* Invoice-style maintenance items */}
            <Card className="p-6">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Maintenance Items</h3>
                <Button variant="secondary" onClick={addItem}>+ Add item</Button>
              </div>
              <div className="space-y-2">
                <div className="hidden gap-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-400 sm:grid sm:grid-cols-12">
                  <div className="sm:col-span-5">Service</div>
                  <div className="text-right sm:col-span-2">Cost</div>
                  <div className="sm:col-span-4">Notes</div>
                  <div className="sm:col-span-1" />
                </div>
                {items.map((it, idx) => (
                  <div key={idx} className="grid grid-cols-1 gap-2 sm:grid-cols-12 sm:items-center">
                    <input className={`${inputCls} sm:col-span-5`} value={it.service_name} onChange={(e) => setItem(idx, 'service_name', e.target.value)} placeholder="e.g. Oil Change" />
                    <input type="number" step="0.01" className={`${inputCls} text-right sm:col-span-2`} value={it.cost} onChange={(e) => setItem(idx, 'cost', e.target.value)} placeholder="0.00" />
                    <input className={`${inputCls} sm:col-span-4`} value={it.notes} onChange={(e) => setItem(idx, 'notes', e.target.value)} placeholder="Notes (optional)" />
                    <button type="button" onClick={() => removeItem(idx)} className="rounded-lg px-2 py-2 text-sm font-medium text-red-500 hover:bg-red-50 sm:col-span-1" title="Remove">Remove</button>
                  </div>
                ))}
                {items.length === 0 && (
                  <p className="py-4 text-center text-sm text-gray-400">No items yet. Click “+ Add item” to add services like Oil Change, Brake Pads, Filter, Labor…</p>
                )}
              </div>
              <div className="mt-4 flex items-center justify-between border-t border-gray-100 pt-4">
                <span className="text-sm font-medium text-gray-500">Total Maintenance Cost</span>
                <span className="text-xl font-bold text-gray-900">{aed2(itemsTotal)}</span>
              </div>
            </Card>

            <Section title="Notes" cols={1}>
              <label className="block sm:col-span-2">
                <span className="mb-1 block text-sm font-medium text-gray-700">Maintenance Notes</span>
                <textarea value={form.maintenance_notes || ''} onChange={set('maintenance_notes')} rows={3} className={inputCls} />
              </label>
            </Section>
          </>
        ) : (
          <>
            <Section title="Pricing" cols={3}>
              <Input label="Day Price" type="number" step="0.01" value={form.day_price ?? ''} onChange={set('day_price')} />
              <Input label="Week Price" type="number" step="0.01" value={form.week_price ?? ''} onChange={set('week_price')} />
              <Input label="Month Price" type="number" step="0.01" value={form.month_price ?? ''} onChange={set('month_price')} />
            </Section>

            <Section title="Charges (debit)" cols={3}>
              <Input label="Rents" type="number" step="0.01" value={form.rents_debit ?? ''} onChange={set('rents_debit')} />
              <Input label="Salik" type="number" step="0.01" value={form.salik_debit ?? ''} onChange={set('salik_debit')} />
              <Input label="Damages" type="number" step="0.01" value={form.damages_debit ?? ''} onChange={set('damages_debit')} />
              <Input label="CDW" type="number" step="0.01" value={form.cdw_debit ?? ''} onChange={set('cdw_debit')} />
              <Input label="VAT" type="number" step="0.01" value={form.vat_debit ?? ''} onChange={set('vat_debit')} />
              <Input label="Deposit" type="number" step="0.01" value={form.deposit_debit ?? ''} onChange={set('deposit_debit')} />
            </Section>

            <Section title="Totals" cols={2}>
              <Input label="Contract Debit" type="number" step="0.01" value={form.contract_debit ?? ''} onChange={set('contract_debit')} />
              <Input label="Contract Credit" type="number" step="0.01" value={form.contract_credit ?? ''} onChange={set('contract_credit')} />
              <Input label="Balance" type="number" step="0.01" value={form.contract_balance ?? ''} onChange={set('contract_balance')} />
              <Input label="Deposit Held" type="number" step="0.01" value={form.contract_deposit ?? ''} onChange={set('contract_deposit')} />
            </Section>
          </>
        )}

        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={() => navigate(isEdit ? `/contracts/${id}` : '/contracts')} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>{isEdit ? 'Save Changes' : 'Create Contract'}</Button>
        </div>
      </div>
    </div>
  );
}
