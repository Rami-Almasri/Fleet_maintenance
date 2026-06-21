import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
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
import { usePageStat } from '../components/PageStat';
import { aed2, num } from '../lib/format';
import CustomerForm, { customerToForm, cleanPayload } from './customers/CustomerForm';

const PAGE_SIZE = 15;

const balanceBadge = (b) => {
  const v = Number(b || 0);
  if (v > 0) return { tone: 'red', text: aed2(v) + ' owed' };
  // negative balance = money the customer paid in advance, available for next rent
  if (v < 0) return { tone: 'green', text: aed2(Math.abs(v)) + ' wallet' };
  return { tone: 'gray', text: 'Settled' };
};

export default function Customers() {
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('customers.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Customer');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const [search, setSearch] = useState('');
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
    setForm(customerToForm(null));
    setFormErrors({});
    setModalOpen(true);
  };
  const openEdit = (c) => {
    setEditing(c);
    setForm(customerToForm(c));
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
        await api.post(`/Customer/${editing.id}`, payload);
        toast.success('Customer updated');
      } else {
        await api.post('/Customer', payload);
        toast.success('Customer created');
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error('Please fix the highlighted fields');
      } else {
        toast.error(res?.message || res?.msg || 'Could not save customer');
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Customer/${toDelete.id}`);
      toast.success('Customer deleted');
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not delete customer');
    } finally {
      setDeleting(false);
    }
  };

  const list = useMemo(() => data || [], [data]);
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return list;
    return list.filter((c) =>
      [c.name_en, c.name_ar, c.customer_no, c.mobile1, c.mobile2, c.email, c.passport_no, c.license_no, c.id_no]
        .some((f) => (f || '').toString().toLowerCase().includes(q))
    );
  }, [list, search]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  // Floating page gauge: share of customers carrying wallet credit (paid ahead).
  const inCredit = useMemo(() => list.filter((c) => Number(c.balance || 0) < 0).length, [list]);
  usePageStat({
    percent: list.length ? (inCredit / list.length) * 100 : null,
    label: 'In credit',
    color: 'emerald',
    hint: `${inCredit} of ${list.length} customers have wallet credit available`,
  });

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Customers" subtitle={loading ? 'Loading…' : `${num(filtered.length)} of ${num(list.length)} customers`}>
          {canManage && (
            <Button onClick={openCreate}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              Add Customer
            </Button>
          )}
        </PageHeader>

        <SearchInput
          value={search}
          onChange={(v) => { setSearch(v); setPage(1); }}
          placeholder="Search name, mobile, email, passport, license…"
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Customer</th>
                  <th className="px-6 py-3">Mobile</th>
                  <th className="px-6 py-3">Contracts</th>
                  <th className="px-6 py-3">Balance</th>
                  <th className="px-6 py-3 text-right">Actions</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={5} />
              ) : (
                <tbody className="divide-y divide-gray-50">
                  {paged.map((c) => {
                    const b = balanceBadge(c.balance);
                    return (
                      <tr key={c.id} className="hover:bg-gray-50/60">
                        <td className="px-6 py-3">
                          <Link to={`/customers/${c.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{c.name_en || '—'}</Link>
                          <div className="text-xs text-gray-400">#{c.customer_no || c.id}</div>
                        </td>
                        <td className="px-6 py-3 text-gray-600">{c.mobile1 || '—'}</td>
                        <td className="px-6 py-3 text-gray-600">{num(c.contracts_count)}</td>
                        <td className="px-6 py-3"><Badge tone={b.tone}>{b.text}</Badge></td>
                        <td className="px-6 py-3">
                          <div className="flex justify-end gap-2">
                            <Link to={`/customers/${c.id}`}>
                              <Button variant="secondary" size="sm">View</Button>
                            </Link>
                            {canManage && <Button variant="secondary" size="sm" onClick={() => openEdit(c)}>Edit</Button>}
                            {canManage && <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(c)}>Delete</Button>}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && <EmptyState title="No customers found" message="Try a different search." />}
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
        title={editing ? 'Edit Customer' : 'Add Customer'}
        subtitle={editing ? (editing.name_en || `#${editing.customer_no || editing.id}`) : 'Enter the customer details'}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={save} loading={saving}>{editing ? 'Save Changes' : 'Create Customer'}</Button>
          </>
        }
      >
        <CustomerForm values={form} onChange={onField} errors={formErrors} />
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete customer?"
        confirmText="Delete"
        message={toDelete ? `This will remove ${toDelete.name_en || 'this customer'} (#${toDelete.customer_no || toDelete.id}). This can be undone via the database (soft delete).` : ''}
      />
    </div>
  );
}
