import { useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from './ui/Toast';
import Button from './ui/Button';
import Badge from './ui/Badge';
import Modal from './ui/Modal';
import { SectionCard } from './ui/Table';
import SearchSelect from './ui/SearchSelect';
import Icon from './ui/Icon';
import { Input, Textarea, Select } from './ui/Field';
import { fmtDate } from '../lib/format';

// A blank service record. Money fields are gone — this is a Technical Service Log now.
const EMPTY = { invoice_date: '', vendor_id: '', notes: '', items: [{ description: '', category_key: '' }] };

/**
 * The Service Records on a contract — a money-FREE technical log of the parts/services done on the
 * car (e.g. "Oil Filter", "Brake Pads"), each stamped with its date and the garage. Users with
 * billing.manage can add/edit/delete website records; OfficeManager-synced rows are read-only and
 * only shown when they actually carry work items. Feeds the per-vehicle Service History.
 */
export default function ContractInvoices({ contract, onChanged }) {
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = can('billing.manage');

  // Only the technical records: website ones, plus any synced row that carries work items.
  const records = useMemo(
    () => (contract.invoices || []).filter((i) => i.origin === 'manual' || (i.items?.length)),
    [contract.invoices],
  );

  const today = new Date().toISOString().slice(0, 10);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [garages, setGarages] = useState([]);
  const [categories, setCategories] = useState([]); // findings catalog → category options

  // Garage list + the Findings category vocabulary (so each work item can be tagged consistently).
  useEffect(() => {
    let alive = true;
    Promise.all([
      api.get('/Vendor').then((r) => r.data?.data).catch(() => []),
      api.get('/maintenance-tickets/findings-catalog').then((r) => r.data?.data?.categories).catch(() => []),
    ]).then(([g, cats]) => {
      if (!alive) return;
      const all = Array.isArray(g) ? g : g?.items || [];
      const shops = all.filter((x) => x.type === 'garage');
      setGarages(shops.length ? shops : all);
      setCategories(Array.isArray(cats) ? cats : []);
    });
    return () => { alive = false; };
  }, []);

  const garageOptions = useMemo(() => garages.map((g) => ({ id: g.id, label: g.name, sub: g.phone || g.type })), [garages]);
  const catLabel = useMemo(() => Object.fromEntries(categories.map((c) => [c.key, c.title || c.label || c.key])), [categories]);

  const err = (f) => errors[f]?.[0] || '';
  const set = (f) => (e) => setForm((s) => ({ ...s, [f]: e.target.value }));

  // ── work-item rows ──
  const setItem = (i, f, v) => setForm((s) => ({ ...s, items: s.items.map((it, idx) => (idx === i ? { ...it, [f]: v } : it)) }));
  const addItem = () => setForm((s) => ({ ...s, items: [...s.items, { description: '', category_key: '' }] }));
  const removeItem = (i) => setForm((s) => ({ ...s, items: s.items.length > 1 ? s.items.filter((_, idx) => idx !== i) : s.items }));

  const openNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, invoice_date: today, items: [{ description: '', category_key: '' }] });
    setErrors({});
    setOpen(true);
  };

  const openEdit = (rec) => {
    setEditing(rec);
    setForm({
      invoice_date: rec.date || '',
      vendor_id: rec.vendor_id ? String(rec.vendor_id) : '',
      notes: rec.notes || '',
      items: rec.items?.length ? rec.items.map((it) => ({ description: it.description, category_key: it.category_key || '' })) : [{ description: '', category_key: '' }],
    });
    setErrors({});
    setOpen(true);
  };

  const submit = async () => {
    const items = form.items.map((it) => ({ description: it.description.trim(), category_key: it.category_key || null })).filter((it) => it.description);
    if (!items.length) { toast.error('Add at least one part or service.'); return; }
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        contract_id: contract.id,
        invoice_date: form.invoice_date || null,
        vendor_id: form.vendor_id ? Number(form.vendor_id) : null,
        notes: form.notes || null,
        items,
      };
      if (editing) {
        await api.post(`/Invoice/${editing.id}`, payload);
        toast.success('Service record updated');
      } else {
        await api.post('/Invoice', payload);
        toast.success('Service record added');
      }
      setOpen(false);
      onChanged?.();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not save the service record');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (rec) => {
    if (!window.confirm(`Delete service record ${rec.number}? This cannot be undone.`)) return;
    try {
      await api.delete(`/Invoice/${rec.id}`);
      toast.success('Service record deleted');
      onChanged?.();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the record');
    }
  };

  return (
    <div className="space-y-5">
      <SectionCard
        title="Service Records"
        subtitle="Parts & services done on this car — a technical log (no charges). Feeds the vehicle's Service History."
        actions={canManage ? <Button variant="secondary" size="sm" onClick={openNew}><Icon.Plus className="h-4 w-4" /> Add service record</Button> : null}
      >
        {records.length === 0 ? (
          <div className="px-5 py-10 text-center text-sm text-slate-400">
            No service records yet{canManage ? ' — use “Add service record” above.' : '.'}
          </div>
        ) : (
          <ul className="divide-y divide-slate-100">
            {records.map((rec) => (
              <li key={rec.id ?? rec.number} className="px-5 py-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      <span className="inline-flex items-center gap-1.5 text-slate-500">
                        <Icon.Calendar className="h-3.5 w-3.5 text-slate-400" /> {rec.date ? fmtDate(rec.date) : 'No date'}
                      </span>
                      {rec.garage && (
                        <span className="inline-flex items-center gap-1.5 font-medium text-slate-700">
                          <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" /> {rec.garage}
                        </span>
                      )}
                      <Badge tone={rec.origin === 'manual' ? 'indigo' : 'gray'}>{rec.origin === 'manual' ? 'Web' : 'OM'}</Badge>
                    </div>
                    {rec.items?.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {rec.items.map((it) => (
                          <span key={it.id} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                            {it.description}
                            {it.category_key && <span className="text-slate-400">· {catLabel[it.category_key] || it.category_key}</span>}
                          </span>
                        ))}
                      </div>
                    )}
                    {rec.notes && <p className="mt-1.5 text-xs text-slate-400">{rec.notes}</p>}
                  </div>
                  {canManage && rec.editable && (
                    <span className="inline-flex shrink-0 gap-2">
                      <button onClick={() => openEdit(rec)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Edit</button>
                      <span className="text-slate-200">·</span>
                      <button onClick={() => remove(rec)} className="text-xs font-medium text-red-500 hover:text-red-600">Delete</button>
                    </span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </SectionCard>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? `Edit service record ${editing.number}` : 'Add service record'}
        subtitle="Log the parts/services done — and the garage that did them"
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={submit} loading={saving}>{editing ? 'Save changes' : 'Add record'}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input label="Service date" type="date" value={form.invoice_date} onChange={set('invoice_date')} error={err('invoice_date')} />
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">Garage</span>
              <SearchSelect value={form.vendor_id} onChange={(v) => setForm((s) => ({ ...s, vendor_id: v }))} options={garageOptions} placeholder="Pick the garage…" />
            </div>
          </div>

          <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">Parts / services done</span>
            <div className="space-y-2">
              {form.items.map((it, i) => (
                <div key={i} className="flex items-center gap-2">
                  <Input
                    className="flex-1"
                    value={it.description}
                    onChange={(e) => setItem(i, 'description', e.target.value)}
                    placeholder="e.g. Oil Filter, Brake Pads, Airbag Sensor"
                  />
                  <Select className="w-40" value={it.category_key} onChange={(e) => setItem(i, 'category_key', e.target.value)}>
                    <option value="">Category…</option>
                    {categories.map((c) => <option key={c.key} value={c.key}>{c.title || c.label || c.key}</option>)}
                  </Select>
                  <button type="button" onClick={() => removeItem(i)} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-500" title="Remove">
                    <Icon.X className="h-4 w-4" />
                  </button>
                </div>
              ))}
            </div>
            <button type="button" onClick={addItem} className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700">
              <Icon.Plus className="h-3.5 w-3.5" /> Add another item
            </button>
          </div>

          <Textarea label="Notes (optional)" rows={2} value={form.notes} onChange={set('notes')} error={err('notes')} placeholder="Anything else about this visit…" />
        </div>
      </Modal>
    </div>
  );
}
