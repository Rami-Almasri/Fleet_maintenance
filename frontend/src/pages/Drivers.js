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
import { usePageStat } from '../components/PageStat';
import DriversAnalytics from '../components/analytics/DriversAnalytics';
import { fmtDate, num, dayBadge } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';
import DriverForm, { DRIVER_STATUSES, driverToForm, cleanPayload } from './drivers/DriverForm';

const PAGE_SIZE = 15;

const STATUS_TONE = { active: 'green', suspended: 'red' };

// Days until a licence expires (null when there's no date).
const daysToExpiry = (d) => {
  if (!d) return null;
  const diff = new Date(d).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0);
  return Math.round(diff / 86400000);
};

export default function Drivers() {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('drivers.manage');
  // Enum VALUES stay English on the wire; only the words the user reads are translated.
  const statusLabel = (s) => (s === 'active' ? t('Active') : s === 'suspended' ? t('Suspended') : s || '—');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Driver');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
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

  const list = useMemo(() => data || [], [data]);
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return list.filter((d) => {
      const matchStatus = !status || d.status === status;
      const matchSearch = !q || [d.name, d.license_no, d.phone].some((f) => (f || '').toString().toLowerCase().includes(q));
      return matchStatus && matchSearch;
    });
  }, [list, search, status]);

  const activeCount = useMemo(() => list.filter((d) => d.status === 'active').length, [list]);
  usePageStat({
    percent: list.length ? (activeCount / list.length) * 100 : null,
    label: t('Active'),
    color: 'emerald',
    hint: t('{active} of {total} drivers active', { active: activeCount, total: list.length }),
  });

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const resetFilters = (fn) => { fn(); setPage(1); };

  const openCreate = () => {
    setEditing(null);
    setForm(driverToForm(null));
    setFormErrors({});
    setModalOpen(true);
  };
  const openEdit = (d) => {
    setEditing(d);
    setForm(driverToForm(d));
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
        await api.post(`/Driver/${editing.id}`, payload);
        toast.success(t('Driver updated'));
      } else {
        await api.post('/Driver', payload);
        toast.success(t('Driver created'));
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error(t('Please fix the highlighted fields'));
      } else {
        toast.error(res?.message || res?.msg || t('Could not save driver'));
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Driver/${toDelete.id}`);
      toast.success(t('Driver deleted'));
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || t('Could not delete driver'));
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Drivers')}
          subtitle={loading ? t('Loading…') : t('{shown} of {total} drivers', { shown: num(filtered.length), total: num(list.length) })}
        >
          {canManage && (
            <Button onClick={openCreate}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              {t('Add Driver')}
            </Button>
          )}
        </PageHeader>

        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput
            className="flex-1"
            value={search}
            onChange={(v) => resetFilters(() => setSearch(v))}
            placeholder={t('Search name, licence or phone…')}
          />
          <Select className="sm:w-44" value={status} onChange={(e) => resetFilters(() => setStatus(e.target.value))}>
            <option value="">{t('All statuses')}</option>
            {DRIVER_STATUSES.map((s) => (
              <option key={s} value={s}>{statusLabel(s)}</option>
            ))}
          </Select>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && <DriversAnalytics drivers={filtered} />}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('Name')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('Phone')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('Licence No.')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('Licence Expiry')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">{t('Status')}</th>
                  {canManage && <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('Actions')}</th>}
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={canManage ? 6 : 5} />
              ) : (
                <tbody>
                  {paged.map((d) => {
                    const days = daysToExpiry(d.license_expiry);
                    const exp = days !== null && days <= 30 ? dayBadge(days) : null;
                    return (
                      <tr key={d.id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                        <td className="border-b border-slate-100 px-5 py-3.5 font-medium text-slate-900">
                          {d.name || '—'}
                          {d.origin && d.origin !== 'web' && <span className="ms-2 text-xs text-slate-400">({d.origin})</span>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600" dir="ltr">{d.phone || <span className="text-slate-300">—</span>}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{d.license_no || <span className="text-slate-300">—</span>}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                          {d.license_expiry ? (
                            <span className="inline-flex items-center gap-2">
                              {fmtDate(d.license_expiry)}
                              {exp && <Badge tone={exp.tone}>{days < 0 ? t('expired') : `${days}d`}</Badge>}
                            </span>
                          ) : <span className="text-slate-300">—</span>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5"><Badge tone={STATUS_TONE[d.status] || 'gray'}>{statusLabel(d.status)}</Badge></td>
                        {canManage && (
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <div className="flex justify-end gap-2">
                              <Button variant="secondary" size="sm" onClick={() => openEdit(d)}>{t('Edit')}</Button>
                              <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(d)}>{t('Delete')}</Button>
                            </div>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && (
              <EmptyState title={t('No drivers found')} message={list.length === 0 ? t('Add your first driver to get started.') : t('Try a different search or filter.')} />
            )}
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
        title={editing ? t('Edit Driver') : t('Add Driver')}
        subtitle={editing ? editing.name : t('Enter the driver details')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('Save Changes') : t('Create Driver')}</Button>
          </>
        }
      >
        <DriverForm values={form} onChange={onField} errors={formErrors} />
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title={t('Delete driver?')}
        confirmText={t('Delete')}
        message={toDelete ? t('This will remove {name}. This can be undone via the database (soft delete).', { name: toDelete.name || t('this driver') }) : ''}
      />
    </div>
  );
}
