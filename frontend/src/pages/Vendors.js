import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import Pagination from '../components/ui/Pagination';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import VendorsAnalytics from '../components/analytics/VendorsAnalytics';
import { num } from '../lib/format';
import VendorForm, { vendorToForm, cleanPayload } from './vendors/VendorForm';

const PAGE_SIZE = 15;

const TYPE_TONE = {
  garage: 'blue',
  parts_supplier: 'violet',
  insurance: 'emerald',
  service_center: 'cyan',
  fuel_station: 'amber',
  other: 'gray',
};
const DOT = { blue: 'bg-blue-500', violet: 'bg-violet-500', emerald: 'bg-emerald-500', cyan: 'bg-cyan-500', amber: 'bg-amber-500', gray: 'bg-slate-400' };
const TYPES = ['garage', 'parts_supplier', 'insurance', 'service_center', 'fuel_station', 'other'];
const label = (t) => (t || '').replace(/_/g, ' ');

const isUrl = (s) => typeof s === 'string' && /^https?:\/\//.test(s);

export default function Vendors() {
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('vendors.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Vendor');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [active, setActive] = useState('');
  const [page, setPage] = useState(1);

  // create / edit modal
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState({});
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // delete confirm
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const openCreate = () => {
    setEditing(null);
    setForm(vendorToForm(null));
    setFormErrors({});
    setModalOpen(true);
  };
  const openEdit = (v) => {
    setEditing(v);
    setForm(vendorToForm(v));
    setFormErrors({});
    setModalOpen(true);
  };
  const onField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  const save = async () => {
    setSaving(true);
    setFormErrors({});
    try {
      const payload = cleanPayload(form);
      if (editing) {
        await api.post(`/Vendor/${editing.id}`, payload);
        toast.success('Vendor updated');
      } else {
        await api.post('/Vendor', payload);
        toast.success('Vendor created');
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error('Please fix the highlighted fields');
      } else {
        toast.error(res?.message || res?.msg || 'Could not save vendor');
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Vendor/${toDelete.id}`);
      toast.success('Vendor deleted');
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not delete vendor');
    } finally {
      setDeleting(false);
    }
  };

  const list = useMemo(() => data || [], [data]);

  const counts = useMemo(() => {
    const c = {};
    list.forEach((v) => { c[v.type] = (c[v.type] || 0) + 1; });
    return c;
  }, [list]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return list.filter((v) => {
      const matchSearch = !q || [v.name, v.phone, v.notes].some((f) => (f || '').toString().toLowerCase().includes(q));
      const matchType = !type || v.type === type;
      const matchActive = active === '' || (active === 'active' ? v.active : !v.active);
      return matchSearch && matchType && matchActive;
    });
  }, [list, search, type, active]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const toggleType = (t) => { setType(type === t ? '' : t); setPage(1); };

  const tiles = [
    { t: 'garage', label: 'Garages' },
    { t: 'parts_supplier', label: 'Parts suppliers' },
    { t: 'insurance', label: 'Insurers' },
    { t: 'service_center', label: 'Service centers' },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Vendors & Suppliers" subtitle={loading ? 'Loading…' : `${num(filtered.length)} of ${num(list.length)} vendors`}>
          {canManage && (
            <Button onClick={openCreate}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              Add Vendor
            </Button>
          )}
        </PageHeader>

        {/* Summary tiles (click to filter by type) */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {tiles.map((tile) => (
            <button
              key={tile.t}
              onClick={() => toggleType(tile.t)}
              className={`hover-lift relative flex items-center justify-between rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-left shadow-soft ${type === tile.t ? 'ring-2 ring-indigo-500' : ''}`}
            >
              <div>
                <div className="flex items-center gap-2">
                  <span className={`h-2 w-2 rounded-full ${DOT[TYPE_TONE[tile.t]]}`} />
                  <p className="text-xs font-medium text-slate-500">{tile.label}</p>
                </div>
                <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts[tile.t] || 0)}</p>
              </div>
              {type === tile.t && <span className="text-xs font-medium text-indigo-600">Filtering ✓</span>}
            </button>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search name, phone or specialization…" />
          <Select className="sm:w-48" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
            <option value="">All types</option>
            {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}
          </Select>
          <Select className="sm:w-36" value={active} onChange={(e) => { setActive(e.target.value); setPage(1); }}>
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && <VendorsAnalytics vendors={filtered} />}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Name</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Type</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Phone</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Specialization / Link</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right">Cars Insured</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Status</th>
                  {canManage && <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right">Actions</th>}
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={canManage ? 7 : 6} />
              ) : (
                <tbody>
                  {paged.map((v) => (
                    <tr key={v.id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                      <td className="border-b border-slate-100 px-5 py-3.5 font-medium text-slate-900">{v.name}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5"><Badge tone={TYPE_TONE[v.type] || 'gray'}>{label(v.type)}</Badge></td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600" dir="ltr">{v.phone || <span className="text-slate-300">—</span>}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                        {isUrl(v.notes) ? (
                          <a href={v.notes} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium text-indigo-600 hover:text-indigo-700">
                            Map
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M14 5h5v5M19 5l-9 9M10 5H5v14h14v-5" /></svg>
                          </a>
                        ) : (v.notes || <span className="text-slate-300">—</span>)}
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-right tabular-nums text-slate-600">{v.type === 'insurance' ? num(v.insured_vehicles_count || 0) : <span className="text-slate-300">—</span>}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        {v.active ? <Badge tone="green">Active</Badge> : <Badge tone="gray">Inactive</Badge>}
                      </td>
                      {canManage && (
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex justify-end gap-2">
                            <Button variant="secondary" size="sm" onClick={() => openEdit(v)}>Edit</Button>
                            <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(v)}>Delete</Button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && <EmptyState title="No vendors found" message="Try a different search or filter." />}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>
      </div>

      {/* Create / Edit modal */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? 'Edit Vendor' : 'Add Vendor'}
        subtitle={editing ? editing.name : 'Enter the vendor details'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={save} loading={saving}>{editing ? 'Save Changes' : 'Create Vendor'}</Button>
          </>
        }
      >
        <VendorForm values={form} onChange={onField} errors={formErrors} />
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete vendor?"
        confirmText="Delete"
        message={toDelete ? `This will remove ${toDelete.name || 'this vendor'}.` : ''}
      />
    </div>
  );
}
