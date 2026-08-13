import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import api from '../../api/client';
import { useToast } from '../../components/ui/Toast';
import Button from '../../components/ui/Button';
import ConfirmDialog from '../../components/ui/ConfirmDialog';
import { usePermissions } from '../../hooks/usePermissions';
import { Card, PageHeader, Spinner } from '../../components/ui/Misc';
import { Input, Select } from '../../components/ui/Field';
import SearchSelect from '../../components/ui/SearchSelect';
import { aed2 } from '../../lib/format';
import { useAuth } from '../../auth/AuthContext';
import RentalReadinessGate from './RentalReadinessGate';
import RentalReadinessInline from './RentalReadinessInline';
import { useI18n } from '../../i18n/I18nContext';

const TYPES = [{ v: 'C', l: 'Rental' }, { v: 'U', l: 'Maintenance' }, { v: 'R', l: 'Booking' }];

// Local date/time defaults for the "fill itself" behaviour. HH:mm matches the
// 24-hour strings the form already stores (e.g. "14:30"); YYYY-MM-DD matches the
// <input type="date"> value. Both read the operator's local clock.
const pad2 = (n) => String(n).padStart(2, '0');
const nowHM = () => { const d = new Date(); return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`; };
const todayYMD = () => { const d = new Date(); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
const diffDays = (a, b) => { if (!a || !b) return 0; const d = Math.round((new Date(b) - new Date(a)) / 86400000); return d > 0 ? d : 0; };

// People who can be marked responsible for a maintenance visit. Currently one person
// owns the workshop, so the Responsible field is restricted to (and defaults to) him.
const MAINTENANCE_RESPONSIBLES = ['ABDULLAH HESHAM FAWAZ'];

// Every maintenance visit is booked to the workshop's own account — ABDULLAH HESHAM
// FAWAZ, customer #10097 — so picking the Maintenance type auto-selects him as the
// customer. Matched by customer_no first, name as a fallback.
const MAINTENANCE_CUSTOMER_NO = '10097';
const MAINTENANCE_CUSTOMER_NAME = 'ABDULLAH HESHAM FAWAZ';

function Section({ title, children, cols = 2 }) {
  return (
    <Card className="p-6">
      <h3 className="mb-4 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h3>
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
  // Bound as `tr`, not `t` — this file already uses `t` as a map/callback parameter in several
  // places (TYPES, tags), and shadowing the resolver there would silently break those labels.
  const { t: tr, tf, tp } = useI18n();
  const { id } = useParams();
  const isEdit = Boolean(id);
  const navigate = useNavigate();
  const toast = useToast();
  const { user } = useAuth();
  const { can } = usePermissions();
  const me = user?.name || user?.username || '';

  // Which fields the form filled in by itself (vehicle pricing, clock, current
  // user) — drives the little ✨ "auto" hints so the operator can see what was
  // pre-filled and trust (or override) it.
  const [auto, setAuto] = useState({});
  const markAuto = (...fields) => setAuto((a) => ({ ...a, ...Object.fromEntries(fields.map((f) => [f, true])) }));
  const clearAuto = (field) => setAuto((a) => (a[field] ? { ...a, [field]: false } : a));

  const [form, setForm] = useState({ contract_type: 'C', state: 'open' });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  // Visual Condition Grading: the mandatory cosmetic-acknowledgment prompt for Orange cars.
  const [cosmeticAck, setCosmeticAck] = useState(false);
  // Yellow-grade rental: a manager may override the block (Option A) by giving a reason.
  const [mgrOverride, setMgrOverride] = useState(false);   // the manager-override prompt is open
  const [overrideReason, setOverrideReason] = useState('');
  // Rental Readiness Checklist: the 8-point pre-confirm gate (new handovers only). Once cleared, the
  // pending doSubmit options are replayed so the condition-gate/override flow still lands the contract.
  const [showChecklist, setShowChecklist] = useState(false);
  const [checklistPassed, setChecklistPassed] = useState(false);
  const [pendingOpts, setPendingOpts] = useState({});
  // Deferred Maintenance advisory: a one-time "rent anyway?" prompt for a car that owes the workshop.
  const [showDeferAck, setShowDeferAck] = useState(false);
  const [deferAck, setDeferAck] = useState(false);
  // Re-arm the readiness gate + deferred-maintenance advisory whenever the chosen car changes.
  useEffect(() => { setChecklistPassed(false); setDeferAck(false); }, [form.vehicle_id]);
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

  // onChange that also drops the ✨ "auto" badge — the moment the operator edits a
  // self-filled field, it's their value, not ours.
  const setClearing = (field) => (e) => { clearAuto(field); setForm((f) => ({ ...f, [field]: e.target.value })); };

  // Label that grows a small "auto" chip while the value was filled by the form.
  const autoLabel = (text, field) =>
    auto[field] ? (
      <span className="inline-flex items-center gap-1.5">
        {text}
        <span className="rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 ring-1 ring-indigo-100">✨ {tr('contractForm.auto')}</span>
      </span>
    ) : (
      text
    );

  // Picking a car pre-fills Out Mileage with its current odometer AND the Pricing
  // block (Day / Week / Month) with the vehicle's stored rental rates — new
  // contracts only, so we never clobber the recorded figures of an existing one.
  const onVehicleChange = (v) => {
    const has = (x) => x !== null && x !== undefined && x !== '';
    setForm((f) => {
      const next = { ...f, vehicle_id: v };
      if (!isEdit) {
        const veh = vehicles.find((x) => String(x.id) === String(v));
        if (veh) {
          const filled = [];
          if (has(veh.odometer)) { next.out_milage = veh.odometer; filled.push('out_milage'); }
          if (has(veh.day_rent_value)) { next.day_price = veh.day_rent_value; filled.push('day_price'); }
          if (has(veh.week_rent_value)) { next.week_price = veh.week_rent_value; filled.push('week_price'); }
          if (has(veh.month_rent_value)) { next.month_price = veh.month_rent_value; filled.push('month_price'); }
          if (filled.length) markAuto(...filled);
        }
      }
      return next;
    });
  };

  // Setting the pickup date auto-stamps Out Time with the current clock if the
  // operator hasn't typed one — "if I don't insert it, take the time itself".
  const onOutDateChange = (e) => {
    const v = e.target.value;
    setForm((f) => {
      const next = { ...f, out_date: v };
      if (v && !f.out_time) { next.out_time = nowHM(); markAuto('out_time'); }
      return next;
    });
  };

  // Same for the return: setting In Date stamps In Time if it's still blank.
  const onInDateChange = (e) => {
    const v = e.target.value;
    setForm((f) => {
      const next = { ...f, in_date: v };
      if (v && !f.in_time) { next.in_time = nowHM(); markAuto('in_time'); }
      return next;
    });
  };

  // Closing the contract stamps who closed it (the signed-in user) and back-fills
  // the return date/time if the operator jumped straight to "Closed".
  const onStateChange = (e) => {
    const v = e.target.value;
    setForm((f) => {
      const next = { ...f, state: v };
      if (v === 'closed') {
        const filled = [];
        if (!f.closed_by && me) { next.closed_by = me; filled.push('closed_by'); }
        if (!f.in_date) { next.in_date = todayYMD(); filled.push('in_date'); }
        if (!f.in_time) { next.in_time = nowHM(); filled.push('in_time'); }
        if (filled.length) markAuto(...filled);
      }
      return next;
    });
  };

  // Create a walk-in customer inline, then select them for this contract.
  const createCustomer = async () => {
    const name = (newCust?.name || '').trim();
    if (!name) { toast.error(tr('Enter the customer name first')); return; }
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
      toast.success(tr('Customer “{name}” created', { name: c.name_en || name }));
    } catch (e) {
      toast.error(e.response?.data?.message || tr('Could not create customer'));
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
        // New contract: auto-assign the next contract number so the user never types one,
        // and pre-fill the "alive" defaults — today's pickup date, the current time, and
        // the signed-in user as "Opened By" — all of which stay editable.
        const nRes = await api.get('/Contract/next-no').catch(() => null);
        const autoNo = nRes?.data?.data?.contract_no;
        setForm((f) => ({
          ...f,
          contract_no: autoNo || f.contract_no,
          out_date: f.out_date || todayYMD(),
          out_time: f.out_time || nowHM(),
          opened_by: f.opened_by || me,
        }));
        markAuto('out_date', 'out_time', ...(me ? ['opened_by'] : []));
      }
    } catch (e) {
      toast.error(tr('Failed to load form data'));
    } finally {
      setLoading(false);
    }
  }, [id, isEdit, toast, me, tr]);

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

  // --- Visual Condition Grading (Abu Marouf) — the booking gate ---
  // A Rental (C) or Booking (R) is a customer handover, so the car's condition grade gates it:
  //   red / yellow  → blocked (safety / maintenance barrier; the backend refuses it too)
  //   orange        → allowed, but a mandatory condition-acknowledgment prompt fires on submit
  //                   and is recorded on the contract (the customer was told before handover).
  //   green         → allowed, no prompt.
  //   green         → clean, no prompt.
  const isHandover = form.contract_type === 'C' || form.contract_type === 'R';
  const selectedVehicle = useMemo(
    () => vehicles.find((x) => String(x.id) === String(form.vehicle_id)),
    [vehicles, form.vehicle_id],
  );
  const conditionGrade = selectedVehicle?.condition_grade || 'green';
  const conditionNote = selectedVehicle?.condition_note;
  // Only Orange (cosmetic) needs the customer to acknowledge the condition at handover.
  const needsConditionAck = isHandover && conditionGrade === 'orange';
  // Red = absolute block. Yellow = a manager-overridable block (Option A): a user holding
  // `operations.override` may rent it with a recorded reason; anyone else is blocked.
  const conditionBlocksRent = conditionGrade === 'red';
  const isYellowHandover = isHandover && conditionGrade === 'yellow';
  const canOverrideYellow = can('operations.override');
  // Deferred Maintenance — two related advisories, both flexible (never a hard block):
  //   • inMaintenance : the car is IN the workshop now. Renting it pulls it out early — its
  //     maintenance ticket is closed and it's flagged to return (sends pull_from_maintenance so the
  //     backend eligibility guard releases it and closes the ticket = no dual contracts).
  //   • owesMaintenance : the car already carries the flag (came back from an earlier deferral) and
  //     still owes the shop — a soft "rent again anyway?" reminder.
  const inMaintenance = isHandover
    && (selectedVehicle?.operational_status === 'maintenance' || !!selectedVehicle?.under_maintenance);
  const owesMaintenance = isHandover && !!selectedVehicle?.is_deferred_maintenance;
  // MANDATORY maintenance — the inspector marked this ticket non-deferrable at the Decide step. Unlike a
  // deferrable in-shop car (which we offer to pull out), a mandatory car is grounded until the workshop
  // finishes: an absolute block, like Red, with no pull-out offer.
  const mandatoryMaintenance = isHandover && !!selectedVehicle?.maintenance_mandatory;
  // Only a DEFERRABLE in-shop car gets the "pull it out / rent anyway?" confirm — never a mandatory one.
  const deferAdvisory = (inMaintenance || owesMaintenance) && !mandatoryMaintenance;

  // Switching to Maintenance auto-fills the workshop owner as both the "Responsible"
  // and the billed Customer (#10097), the moment the type flips — new contracts only,
  // and never overwriting a value the operator already chose.
  useEffect(() => {
    if (!isMaintenance || isEdit) return;
    if (form.responsible && form.customer_id) return; // both already set, nothing to default
    const mc = !form.customer_id
      ? (customers.find((c) => String(c.customer_no) === MAINTENANCE_CUSTOMER_NO)
        || customers.find((c) => (c.name_en || '').trim().toUpperCase() === MAINTENANCE_CUSTOMER_NAME))
      : null;
    setForm((f) => ({
      ...f,
      responsible: f.responsible || MAINTENANCE_RESPONSIBLES[0],
      customer_id: f.customer_id || (mc ? mc.id : f.customer_id),
    }));
    if (mc && !form.customer_id) markAuto('customer_id');
  }, [isMaintenance, isEdit, customers, form.responsible, form.customer_id]);
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
    critical: { label: tr('Critical'), emoji: '🔴' }, special: { label: tr('Special'), emoji: '🟣' },
    minor: { label: tr('Minor'), emoji: '🟡' }, routine: { label: tr('Routine'), emoji: '🟢' },
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

  // --- Live rent estimate ---
  // Reacts to the (auto-filled) Day/Week/Month prices and the rental length, breaking
  // the duration into the cheapest mix of month + week + day rates so the operator sees
  // the expected rent before touching the Charges block. One click drops it into Rents.
  const rentEstimate = useMemo(() => {
    const days = Number(form.days) || diffDays(form.out_date, form.in_date);
    const day = Number(form.day_price) || 0;
    const week = Number(form.week_price) || 0;
    const month = Number(form.month_price) || 0;
    if (!days || (!day && !week && !month)) return null;
    let rem = days, total = 0;
    const parts = [];
    if (month) { const m = Math.floor(rem / 30); if (m) { total += m * month; rem -= m * 30; parts.push(tr('{n}×month', { n: m })); } }
    if (week) { const w = Math.floor(rem / 7); if (w) { total += w * week; rem -= w * 7; parts.push(tr('{n}×week', { n: w })); } }
    const perDay = day || (week ? week / 7 : month / 30);
    if (rem > 0 && perDay) { total += rem * perDay; parts.push(tr('{n}×day', { n: rem })); }
    return { total: Number(total.toFixed(2)), parts, days };
  }, [form.days, form.out_date, form.in_date, form.day_price, form.week_price, form.month_price, tr]);

  // Apply the wallet: collect only (charges − wallet) as cash. The pre-paid credit is
  // consumed automatically through the customer's running balance — no double counting.
  const applyWallet = () => {
    if (charges <= 0) {
      toast.error(tr('Enter the contract charges (Contract Debit) first, then apply the wallet.'));
      return;
    }
    setForm((f) => ({
      ...f,
      contract_credit: Number(cashToCollect.toFixed(2)),
      contract_balance: Number((charges - cashToCollect).toFixed(2)),
    }));
    toast.success(tr('Applied {applied} from wallet · collect {cash} from customer', { applied: aed2(applied), cash: aed2(cashToCollect) }));
  };

  // Gate the save on the vehicle's condition grade before doing anything else (new
  // handovers only — editing an existing contract just corrects data).
  const submit = () => {
    // Required-field gate — mirrors the backend required_if rules (StoreContractRequest) so a missing
    // vehicle/customer is caught inline on the field instead of only after a 422 round-trip. A real
    // contract (rental C / booking R / maintenance U) must name a vehicle; a rental/booking must also
    // name the customer.
    const type = form.contract_type || 'C';
    const missing = {};
    if (['C', 'R', 'U'].includes(type) && !form.vehicle_id) {
      missing.vehicle_id = [tr('Select a vehicle for this contract.')];
    }
    if (['C', 'R'].includes(type) && !form.customer_id) {
      missing.customer_id = [tr('Select a customer for this contract.')];
    }
    if (Object.keys(missing).length) {
      setErrors(missing);
      toast.error(tr('Please fix the highlighted fields'));
      return;
    }

    // Red = absolute block, no exception.
    if (!isEdit && isHandover && conditionBlocksRent) {
      toast.error(tr('This vehicle is graded Red (critical / grounded) and cannot be rented or booked. Pick another car or send it to maintenance.'));
      return;
    }
    // Mandatory maintenance = absolute block. The inspector marked this ticket non-deferrable at the
    // Decide step, so the car is grounded until the workshop completes it — no pull-out is offered.
    if (!isEdit && mandatoryMaintenance) {
      toast.error(tr('This vehicle is in mandatory maintenance and cannot be rented until the workshop completes it. Pick another car.'));
      return;
    }
    // Yellow = manager-overridable. Managers get a reason prompt; everyone else is blocked.
    if (!isEdit && isYellowHandover && !mgrOverride) {
      if (!canOverrideYellow) {
        toast.error(tr('This vehicle is graded Yellow (maintenance needed). Only a manager can override to rent or book it.'));
        return;
      }
      setOverrideReason('');
      setMgrOverride(true);   // fire the manager-override prompt; doSubmit runs on confirm
      return;
    }
    if (!isEdit && needsConditionAck && !cosmeticAck) {
      setCosmeticAck(true);   // fire the mandatory acknowledgment prompt; doSubmit runs on confirm
      return;
    }
    proceedToCommit();
  };

  // After the condition gate clears, a new handover must pass the 8-point Readiness Checklist before
  // it is actually saved. The checklist runs once; its "Confirm" replays doSubmit with these opts
  // (e.g. the manager-override flag). Edits and maintenance visits skip straight to the save.
  const proceedToCommit = (opts = {}) => {
    // Deferred-maintenance advisory: confirm once (pull it out / rent anyway?) before the checklist.
    if (!isEdit && deferAdvisory && !deferAck) {
      setPendingOpts(opts);
      setShowDeferAck(true);
      return;
    }
    afterDefer(opts);
  };

  // Everything after the deferred-maintenance advisory: the 8-point readiness checklist, then save.
  const afterDefer = (opts = {}) => {
    if (!isEdit && isHandover && form.vehicle_id && !checklistPassed) {
      setPendingOpts(opts);
      setShowChecklist(true);
      return;
    }
    doSubmit(opts);
  };

  const doSubmit = async (opts = {}) => {
    setSaving(true);
    setErrors({});
    try {
      // Final self-fill guarantees, independent of the UI handlers: a pickup/return
      // date without a time gets the current clock, and whoever saves is recorded
      // as having opened (and, if closing, closed) the contract.
      const filled = { ...form };
      if (filled.out_date && !filled.out_time) filled.out_time = nowHM();
      if (filled.in_date && !filled.in_time) filled.in_time = nowHM();
      if (!filled.opened_by && me) filled.opened_by = me;
      if (filled.state === 'closed' && !filled.closed_by && me) filled.closed_by = me;

      const payload = cleanPayload(filled);
      // Deferred Maintenance: renting a car that's in the workshop pulls it out early — tell the
      // backend to close the maintenance ticket (no dual contracts) and raise the "owes maintenance"
      // flag. Only sent for a new handover on an in-shop car; the confirm prompt already fired.
      if (!isEdit && isHandover && inMaintenance && !mandatoryMaintenance) {
        payload.pull_from_maintenance = true;
      }
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
      // Record the sales agent's condition acknowledgment for an Orange/Yellow handover.
      // doSubmit only ever runs once the grade gate has passed (green, or confirmed prompt),
      // so it's safe to stamp the ack whenever the car isn't green.
      if (!isEdit && needsConditionAck) {
        payload.condition_acknowledged = true;
        payload.condition_ack_by = me || undefined;
      }
      // Yellow-grade manager override: send the flag + reason so the backend records who
      // overrode it and why (the controller re-checks the operations.override permission).
      if (!isEdit && opts.managerOverride) {
        payload.manager_override = true;
        payload.override_reason = overrideReason.trim() || undefined;
      }
      let res;
      if (isEdit) {
        res = await api.post(`/Contract/${id}`, payload);
        toast.success(tr('Contract updated'));
        navigate(`/contracts/${id}`);
      } else {
        res = await api.post('/Contract', payload);
        toast.success(tr('Contract created'));
        const newId = res.data?.data?.id;
        navigate(newId ? `/contracts/${newId}` : '/contracts');
      }
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors?.condition_ack) {
        // Backend safety net: the car needs a recorded acknowledgment. Re-open the prompt.
        toast.error(r.errors.condition_ack[0] || tr('Confirm the vehicle condition before handover.'));
        setCosmeticAck(true);
      } else if (r?.errors?.manager_override) {
        // Backend safety net: a Yellow car needs a manager override. Re-open it for managers.
        toast.error(r.errors.manager_override[0] || tr('A manager override is required for this vehicle.'));
        if (canOverrideYellow) setMgrOverride(true);
      } else if (r?.errors?.vehicle_id) {
        // Backend safety net: a hard vehicle-level block (mandatory maintenance, sold, already rented…).
        // vehicle_id isn't a visible field, so surface it as a toast rather than an inline error.
        toast.error(r.errors.vehicle_id[0] || tr('This vehicle cannot be rented or booked.'));
      } else if (r?.errors) { setErrors(r.errors); toast.error(tr('Please fix the highlighted fields')); }
      else toast.error(r?.message || r?.msg || tr('Could not save contract'));
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  // Matches the Field.js baseInput look exactly, so hand-wired inputs and <Input> fields read identically.
  const inputCls = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="space-y-2">
          <Link to={isEdit ? `/contracts/${id}` : '/contracts'} className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition-colors hover:text-slate-700">
            <svg aria-hidden="true" className="h-4 w-4 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
            {isEdit ? tr('Contract') : tr('Contracts')}
          </Link>
          <PageHeader
            title={isEdit ? tr('Edit Contract #{no}', { no: form.contract_no || id }) : tr('New Contract')}
            subtitle={isEdit ? tr('Correct the recorded details of this contract.') : tr('Fill in the sections below — auto-filled values stay editable.')}
          />
        </div>

        {wallet > 0 && (
          <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 shadow-soft">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-sm font-semibold text-emerald-800">
                  💰 {tr('{who} has {amount} available credit (wallet)', { who: selectedCustomer?.name_en || tr('This customer'), amount: aed2(wallet) })}
                </p>
                <p className="mt-0.5 text-xs text-emerald-700">
                  {charges > 0
                    ? tr('Apply it to cover {applied} of {charges} charges — collect only {cash} from the customer.', { applied: aed2(applied), charges: aed2(charges), cash: aed2(cashToCollect) })
                    : tr('Money paid earlier and not yet used. Enter the charges (Contract Debit) below, then apply it.')}
                </p>
              </div>
              <Button variant="secondary" onClick={applyWallet} disabled={charges <= 0}>
                {tr('Apply to this contract')}
              </Button>
            </div>
          </div>
        )}

        <Section title={tr('contractForm.sections.basics')}>
          <Input
            label={tr(isEdit ? 'contractForm.contractNo' : 'contractForm.contractNoAuto')}
            required
            value={form.contract_no || ''}
            onChange={set('contract_no')}
            error={err('contract_no')}
            readOnly={!isEdit}
            title={!isEdit ? tr('contractForm.generatedAuto') : undefined}
            className={!isEdit ? 'bg-slate-50 text-slate-500' : ''}
          />
          <Select label={tr('contractForm.type')} value={form.contract_type || 'C'} onChange={set('contract_type')} error={err('contract_type')}>
            {TYPES.map((ty) => <option key={ty.v} value={ty.v}>{tf(`contractForm.contractType.${ty.v}`, ty.l)}</option>)}
          </Select>
          <Select label={tr('contractForm.state')} value={form.state || 'open'} onChange={onStateChange} error={err('state')}>
            <option value="open">{tr('contractForm.open')}</option>
            <option value="closed">{tr('contractForm.closed')}</option>
          </Select>
        </Section>

        <Section title={tr('contractForm.sections.parties')}>
          <label className="block">
            <div className="mb-1 flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-sm font-medium text-slate-700">
                {tr('Customer')}
                {auto.customer_id && (
                  <span className="rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 ring-1 ring-indigo-100">✨ {tr('contractForm.auto')}</span>
                )}
              </span>
              <button
                type="button"
                onClick={() => setNewCust(newCust ? null : { name: '', mobile: '' })}
                className="text-xs font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {newCust ? tr('Pick existing') : tr('+ New customer')}
              </button>
            </div>
            {newCust ? (
              <div className="space-y-2 rounded-lg border border-indigo-200 bg-indigo-50/40 p-3">
                <input
                  autoFocus
                  className={inputCls}
                  placeholder={tr('Full name *')}
                  value={newCust.name}
                  onChange={(e) => setNewCust((n) => ({ ...n, name: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createCustomer(); } }}
                />
                <input
                  className={inputCls}
                  placeholder={tr('contractForm.mobileOptional')}
                  value={newCust.mobile}
                  onChange={(e) => setNewCust((n) => ({ ...n, mobile: e.target.value }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); createCustomer(); } }}
                />
                <div className="flex justify-end">
                  <Button variant="secondary" onClick={createCustomer} loading={creatingCust}>{tr('contractForm.addCustomer')}</Button>
                </div>
              </div>
            ) : (
              <SearchSelect value={form.customer_id} onChange={(v) => { clearAuto('customer_id'); setVal('customer_id', v); }} options={customerOptions} placeholder={tr('contractForm.searchCustomer')} />
            )}
            {err('customer_id') && <span className="mt-1 block text-xs text-red-600">{err('customer_id')}</span>}
          </label>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">{tr('contractForm.vehicle')}</span>
            <SearchSelect value={form.vehicle_id} onChange={onVehicleChange} options={vehicleOptions} placeholder={tr('contractForm.searchPlate')} />
            {err('vehicle_id') && <span className="mt-1 block text-xs text-red-600">{err('vehicle_id')}</span>}
          </label>
        </Section>

        {/* Visual Condition Grade alert — a car picked for a rental/booking that isn't Perfect. */}
        {isHandover && conditionGrade === 'orange' && (
          <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800 shadow-soft">
            <p className="font-semibold">⚠️ {tr('contractForm.cosmetic.title')}</p>
            <p className="mt-0.5">
              {conditionNote ? `“${conditionNote}” — ` : ''}
              {tr('contractForm.cosmetic.body')}
            </p>
          </div>
        )}
        {isHandover && conditionGrade === 'yellow' && (
          <div className="rounded-2xl border border-yellow-300 bg-yellow-50 px-5 py-4 text-sm text-yellow-800 shadow-soft">
            <p className="font-semibold">🔧 {tr('This vehicle is graded Yellow (maintenance needed).')}</p>
            <p className="mt-0.5">
              {conditionNote ? `“${conditionNote}” — ` : ''}
              {canOverrideYellow
                ? tr('Renting it needs a manager override — you’ll be asked for a reason, which is recorded on the contract.')
                : tr('It cannot be rented or booked without a manager’s override. Send it to the garage, or pick another car.')}
            </p>
          </div>
        )}
        {isHandover && conditionGrade === 'red' && (
          <div className="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 shadow-soft">
            <p className="font-semibold">⛔ {tr('This vehicle is graded Red (critical / grounded).')}</p>
            <p className="mt-0.5">
              {conditionNote ? `“${conditionNote}” — ` : ''}
              {tr('It cannot be rented or booked. Pick another car, or send this one to maintenance first.')}
            </p>
          </div>
        )}

        {/* Deferred Maintenance — advisory only (never a block); a confirm prompt fires on submit. */}
        {inMaintenance && (
          <div className="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 shadow-soft">
            <p className="font-semibold">🛠️↩️ {tr('contractForm.inShop.title')}</p>
            <p className="mt-0.5">
              {tr('contractForm.inShop.before')} <span className="font-semibold">{tr('contractForm.inShop.closeTicket')}</span> {tr('contractForm.inShop.after')}
            </p>
          </div>
        )}
        {!inMaintenance && owesMaintenance && (
          <div className="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 shadow-soft">
            <p className="font-semibold">🛠️↩️ {tr('contractForm.deferred.title')}</p>
            <p className="mt-0.5">
              {selectedVehicle?.deferred_maintenance_reason ? `“${selectedVehicle.deferred_maintenance_reason}” — ` : ''}
              {tr('contractForm.deferred.body')}
            </p>
          </div>
        )}

        {/* Upfront readiness: the moment a car is picked for a rental/booking, show whether it
            passes its condition checks — same authority as the blocking gate shown on confirm. */}
        {isHandover && form.vehicle_id && (
          <RentalReadinessInline vehicleId={form.vehicle_id} />
        )}

        <Section title={tr('contractForm.sections.out')} cols={3}>
          <Input label={autoLabel(tr('contractForm.fields.outDate'), 'out_date')} type="date" value={form.out_date || ''} onChange={onOutDateChange} />
          <Input label={autoLabel(tr('contractForm.fields.outTime'), 'out_time')} value={form.out_time || ''} onChange={setClearing('out_time')} placeholder="14:30" />
          <Input label={autoLabel(tr('contractForm.fields.outMileage'), 'out_milage')} type="number" value={form.out_milage ?? ''} onChange={setClearing('out_milage')} />
          <Input label={tr('contractForm.fields.outFuel')} value={form.out_fuel || ''} onChange={set('out_fuel')} />
          <Input label={autoLabel(tr('contractForm.fields.openedBy'), 'opened_by')} value={form.opened_by || ''} onChange={setClearing('opened_by')} />
        </Section>

        <Section title={tr('contractForm.sections.in')} cols={3}>
          <Input label={autoLabel(tr('contractForm.fields.inDate'), 'in_date')} type="date" value={form.in_date || ''} onChange={onInDateChange} />
          <Input label={autoLabel(tr('contractForm.fields.inTime'), 'in_time')} value={form.in_time || ''} onChange={setClearing('in_time')} placeholder="12:00" />
          <Input label={tr('contractForm.fields.inMileage')} type="number" value={form.in_milage ?? ''} onChange={set('in_milage')} />
          <Input label={tr('contractForm.fields.inFuel')} value={form.in_fuel || ''} onChange={set('in_fuel')} />
          <Input label={autoLabel(tr('contractForm.fields.closedBy'), 'closed_by')} value={form.closed_by || ''} onChange={setClearing('closed_by')} />
          <Input label={tr('contractForm.fields.days')} type="number" value={form.days ?? ''} onChange={set('days')} />
          <Input label={tr('contractForm.fields.km')} type="number" value={form.km ?? ''} onChange={set('km')} />
        </Section>

        {isMaintenance ? (
          <>
            <Section title={tr('contractForm.sections.maintDetails')} cols={2}>
              <label className="block">
                <span className="mb-1 block text-sm font-medium text-slate-700">{tr('contractForm.fields.garage')}</span>
                <SearchSelect value={form.vendor_id} onChange={(v) => setVal('vendor_id', v)} options={vendorOptions} placeholder={tr('contractForm.searchGarage')} />
                {err('vendor_id') && <span className="mt-1 block text-xs text-red-600">{err('vendor_id')}</span>}
              </label>
              <Input label={tr('contractForm.fields.expectedReturn')} type="date" value={form.expected_return_date || ''} onChange={set('expected_return_date')} />
              <Select label={tr('contractForm.fields.responsible')} value={form.responsible || ''} onChange={set('responsible')}>
                <option value="">{tr('contractForm.select')}</option>
                {MAINTENANCE_RESPONSIBLES.map((p) => <option key={p} value={p}>{p}</option>)}
                {/* keep any pre-existing responsible from an older record selectable */}
                {form.responsible && !MAINTENANCE_RESPONSIBLES.includes(form.responsible) && (
                  <option value={form.responsible}>{form.responsible}</option>
                )}
              </Select>
              <Input label={tr('contractForm.fields.approvedBy')} value={form.approved_by || ''} onChange={set('approved_by')} />
            </Section>

            {/* Issue keywords / tags */}
            <Card className="p-6">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{tr('contractForm.tags.title')}</h3>
                {predicted && (
                  <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${LEVEL_CHIP[predicted]}`}>
                    {tr('contractForm.tags.priority')} {LEVEL_META[predicted].emoji} {LEVEL_META[predicted].label}
                  </span>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                {tags.map((t) => {
                  const lvl = levelOf(t);
                  return (
                    <span key={t} className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset ${lvl ? LEVEL_CHIP[lvl] : 'bg-slate-50 text-slate-700 ring-slate-200'}`}>
                      {t}
                      <button type="button" onClick={() => removeTag(t)} aria-label={tr('contractForm.tags.remove', { tag: t })} className="opacity-50 transition hover:opacity-100">×</button>
                    </span>
                  );
                })}
                {tags.length === 0 && <span className="text-sm text-slate-400">{tr('contractForm.tags.empty')}</span>}
              </div>
              <div className="mt-3 flex gap-2">
                <input
                  value={tagInput}
                  onChange={(e) => setTagInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(tagInput); } }}
                  placeholder={tr('contractForm.tags.placeholder')}
                  className={inputCls}
                />
                <Button variant="secondary" onClick={() => addTag(tagInput)}>{tr('contractForm.tags.add')}</Button>
              </div>
              {reasons.length > 0 && (
                <div className="mt-3">
                  <p className="mb-1.5 text-xs text-slate-400">{tr('Common reasons (colour = priority):')}</p>
                  <div className="flex flex-wrap gap-1.5">
                    {reasons
                      .filter((r) => !tags.some((x) => x.toLowerCase() === r.reason.toLowerCase()))
                      .map((r) => (
                        <button
                          type="button"
                          key={r.reason}
                          onClick={() => addTag(r.reason)}
                          title={`${LEVEL_META[r.level]?.label || ''}${r.reason_ar ? ' · ' + r.reason_ar : ''}`}
                          className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition hover:brightness-95 ${LEVEL_CHIP[r.level] || 'bg-slate-100 text-slate-600 ring-slate-200'}`}
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
                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{tr('contractForm.items.title')}</h3>
                <Button variant="secondary" onClick={addItem}>{tr('contractForm.items.add')}</Button>
              </div>
              <div className="space-y-2">
                <div className="hidden gap-2 px-1 text-xs font-medium uppercase tracking-wide text-slate-400 sm:grid sm:grid-cols-12">
                  <div className="sm:col-span-5">{tr('contractForm.items.service')}</div>
                  <div className="text-end sm:col-span-2">{tr('contractForm.items.cost')}</div>
                  <div className="sm:col-span-4">{tr('contractForm.items.notes')}</div>
                  <div className="sm:col-span-1" />
                </div>
                {items.map((it, idx) => (
                  <div key={idx} className="grid grid-cols-1 gap-2 sm:grid-cols-12 sm:items-center">
                    <input className={`${inputCls} sm:col-span-5`} value={it.service_name} onChange={(e) => setItem(idx, 'service_name', e.target.value)} placeholder={tr('contractForm.items.servicePlaceholder')} />
                    <input type="number" step="0.01" className={`${inputCls} text-end sm:col-span-2`} value={it.cost} onChange={(e) => setItem(idx, 'cost', e.target.value)} placeholder="0.00" />
                    <input className={`${inputCls} sm:col-span-4`} value={it.notes} onChange={(e) => setItem(idx, 'notes', e.target.value)} placeholder={tr('contractForm.items.notesPlaceholder')} />
                    <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(idx)} className="text-red-600 hover:bg-red-50 sm:col-span-1" title={tr('contractForm.items.remove')}>{tr('contractForm.items.remove')}</Button>
                  </div>
                ))}
                {items.length === 0 && (
                  <p className="py-4 text-center text-sm text-slate-400">{tr('contractForm.items.empty')}</p>
                )}
              </div>
              <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                <span className="text-sm font-medium text-slate-500">{tr('contractForm.items.total')}</span>
                <span className="text-xl font-bold text-slate-900">{aed2(itemsTotal)}</span>
              </div>
            </Card>

            <Section title={tr('contractForm.sections.notes')} cols={1}>
              <label className="block sm:col-span-2">
                <span className="mb-1 block text-sm font-medium text-slate-700">{tr('contractForm.maintNotes')}</span>
                <textarea value={form.maintenance_notes || ''} onChange={set('maintenance_notes')} rows={3} className={inputCls} />
              </label>
            </Section>
          </>
        ) : (
          <>
            <Section title={tr('contractForm.sections.pricing')} cols={3}>
              <Input label={autoLabel(tr('contractForm.fields.dayPrice'), 'day_price')} type="number" step="0.01" value={form.day_price ?? ''} onChange={setClearing('day_price')} />
              <Input label={autoLabel(tr('contractForm.fields.weekPrice'), 'week_price')} type="number" step="0.01" value={form.week_price ?? ''} onChange={setClearing('week_price')} />
              <Input label={autoLabel(tr('contractForm.fields.monthPrice'), 'month_price')} type="number" step="0.01" value={form.month_price ?? ''} onChange={setClearing('month_price')} />
            </Section>

            {rentEstimate && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4 shadow-soft">
                <div>
                  <p className="text-sm font-semibold text-indigo-900">
                    {tr('contractForm.estimate.title')} · {aed2(rentEstimate.total)}
                  </p>
                  <p className="mt-0.5 text-xs text-indigo-600">
                    {tp('contractForm.estimate.days', rentEstimate.days, { n: rentEstimate.days })} → {rentEstimate.parts.join(' + ')} {tr('contractForm.estimate.atRates')}
                  </p>
                </div>
                <Button variant="secondary" onClick={() => { clearAuto('rents_debit'); setVal('rents_debit', rentEstimate.total); }}>
                  {tr('contractForm.estimate.useAsRents')}
                </Button>
              </div>
            )}

            <Section title={tr('contractForm.sections.charges')} cols={3}>
              <Input label={tr('contractForm.fields.rents')} type="number" step="0.01" value={form.rents_debit ?? ''} onChange={set('rents_debit')} />
              <Input label={tr('contractForm.fields.salik')} type="number" step="0.01" value={form.salik_debit ?? ''} onChange={set('salik_debit')} />
              <Input label={tr('contractForm.fields.damages')} type="number" step="0.01" value={form.damages_debit ?? ''} onChange={set('damages_debit')} />
              <Input label={tr('contractForm.fields.cdw')} type="number" step="0.01" value={form.cdw_debit ?? ''} onChange={set('cdw_debit')} />
              <Input label={tr('contractForm.fields.vat')} type="number" step="0.01" value={form.vat_debit ?? ''} onChange={set('vat_debit')} />
              <Input label={tr('contractForm.fields.deposit')} type="number" step="0.01" value={form.deposit_debit ?? ''} onChange={set('deposit_debit')} />
            </Section>

            <Section title={tr('contractForm.sections.totals')} cols={2}>
              <Input label={tr('contractForm.fields.contractDebit')} type="number" step="0.01" value={form.contract_debit ?? ''} onChange={set('contract_debit')} />
              <Input label={tr('contractForm.fields.contractCredit')} type="number" step="0.01" value={form.contract_credit ?? ''} onChange={set('contract_credit')} />
              <Input label={tr('contractForm.fields.balance')} type="number" step="0.01" value={form.contract_balance ?? ''} onChange={set('contract_balance')} />
              <Input label={tr('contractForm.fields.depositHeld')} type="number" step="0.01" value={form.contract_deposit ?? ''} onChange={set('contract_deposit')} />
            </Section>
          </>
        )}

        <div className="flex items-center justify-end gap-3 border-t border-slate-200/60 pt-5">
          <Button variant="secondary" onClick={() => navigate(isEdit ? `/contracts/${id}` : '/contracts')} disabled={saving}>{tr('Cancel')}</Button>
          <Button onClick={submit} loading={saving}>{isEdit ? tr('Save Changes') : tr('Create Contract')}</Button>
        </div>
      </div>

      {/* Mandatory condition acknowledgment before handing over an Orange-graded car. */}
      <ConfirmDialog
        open={cosmeticAck}
        onClose={() => setCosmeticAck(false)}
        onConfirm={() => { setCosmeticAck(false); proceedToCommit(); }}
        loading={saving}
        variant="primary"
        title={tr('contractForm.cosmeticAck.title')}
        confirmText={tr('contractForm.cosmeticAck.confirm')}
        message={
          tr('contractForm.cosmeticAck.message', { note: conditionNote ? ` (“${conditionNote}”)` : '' })
        }
      />

      {/* Deferred-maintenance advisory — flexible "rent anyway?" prompt, never a hard block. */}
      <ConfirmDialog
        open={showDeferAck}
        onClose={() => setShowDeferAck(false)}
        onConfirm={() => { setShowDeferAck(false); setDeferAck(true); afterDefer(pendingOpts); }}
        loading={saving}
        variant="warning"
        title={tr(inMaintenance ? 'contractForm.deferAck.titleInShop' : 'contractForm.deferAck.title')}
        confirmText={tr(inMaintenance ? 'contractForm.deferAck.confirmInShop' : 'contractForm.deferAck.confirm')}
        message={
          inMaintenance
            ? tr('contractForm.deferAck.messageInShop', {
              plate: selectedVehicle?.plate_no || tr('contractForm.thisVehicle'),
            })
            : tr('contractForm.deferAck.message', {
              plate: selectedVehicle?.plate_no || tr('contractForm.thisVehicle'),
              note: selectedVehicle?.deferred_maintenance_reason ? ` — “${selectedVehicle.deferred_maintenance_reason}”` : '',
            })
        }
      />

      {/* Manager override before renting a Yellow-graded (maintenance-needed) car. */}
      <ConfirmDialog
        open={mgrOverride}
        onClose={() => setMgrOverride(false)}
        onConfirm={() => { setMgrOverride(false); proceedToCommit({ managerOverride: true }); }}
        loading={saving}
        variant="warning"
        title={tr('contractForm.override.title')}
        confirmText={tr('contractForm.override.confirm')}
        confirmDisabled={!overrideReason.trim()}
        message={
          tr('contractForm.override.message', {
            plate: selectedVehicle?.plate_no || tr('contractForm.thisVehicle'),
            note: conditionNote ? ` — “${conditionNote}”` : '',
          })
        }
      >
        <label className="mt-3 block">
          <span className="text-xs font-medium text-slate-600">{tr('contractForm.override.reason')} <span className="text-red-500">*</span></span>
          <textarea
            value={overrideReason}
            onChange={(e) => setOverrideReason(e.target.value)}
            rows={2}
            autoFocus
            placeholder={tr('Why is this Yellow car being rented?')}
            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
          />
        </label>
      </ConfirmDialog>

      {/* The 8-point Rental Readiness Checklist — final interactive gate before a handover is saved. */}
      {showChecklist && form.vehicle_id && (
        <RentalReadinessGate
          vehicleId={form.vehicle_id}
          vehicleLabel={selectedVehicle?.plate_no || selectedVehicle?.name}
          saving={saving}
          onBack={() => setShowChecklist(false)}
          onProceed={() => { setShowChecklist(false); setChecklistPassed(true); doSubmit(pendingOpts); }}
        />
      )}
    </div>
  );
}
