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
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { usePageStat } from '../components/PageStat';
import CustomersAnalytics from '../components/analytics/CustomersAnalytics';
import { aed2, num } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';
import { useI18n } from '../i18n/I18nContext';
import CustomerForm, { customerToForm, cleanPayload } from './customers/CustomerForm';

const PAGE_SIZE = 15;

const balanceBadge = (t, b) => {
  const v = Number(b || 0);
  if (v > 0) return { tone: 'red', text: t('{amount} owed', { amount: aed2(v) }) };
  // negative balance = money the customer paid in advance, available for next rent
  if (v < 0) return { tone: 'green', text: t('{amount} wallet', { amount: aed2(Math.abs(v)) }) };
  return { tone: 'gray', text: t('Settled') };
};

export default function Customers() {
  const { t } = useI18n();
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
        toast.success(t('Customer updated'));
      } else {
        await api.post('/Customer', payload);
        toast.success(t('Customer created'));
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error(t('Please fix the highlighted fields'));
      } else {
        toast.error(res?.message || res?.msg || t('Could not save customer'));
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Customer/${toDelete.id}`);
      toast.success(t('Customer deleted'));
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || t('Could not delete customer'));
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
    percent: SHOW_FINANCIALS && list.length ? (inCredit / list.length) * 100 : null,
    label: t('In credit'),
    color: 'emerald',
    hint: t('{inCredit} of {total} customers have wallet credit available', { inCredit, total: list.length }),
  });

  // Headline aggregates over the full list (derived directly from per-customer balance).
  const owedCount = useMemo(() => list.filter((c) => Number(c.balance || 0) > 0).length, [list]);
  const walletTotal = useMemo(
    () => list.reduce((s, c) => { const v = Number(c.balance || 0); return v < 0 ? s + Math.abs(v) : s; }, 0),
    [list]
  );
  const owedTotal = useMemo(
    () => list.reduce((s, c) => { const v = Number(c.balance || 0); return v > 0 ? s + v : s; }, 0),
    [list]
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Customers')}
          subtitle={loading ? t('Loading…') : t('{shown} of {total} customers', { shown: num(filtered.length), total: num(list.length) })}
        >
          {canManage && (
            <Button onClick={openCreate}>
              <Icon.Plus className="h-4 w-4" />
              {t('Add Customer')}
            </Button>
          )}
        </PageHeader>

        {/* Summary metrics */}
        {loading ? (
          <MetricGridSkeleton count={SHOW_FINANCIALS ? 4 : 1} />
        ) : (
          <MetricGrid cols={SHOW_FINANCIALS ? 4 : 1}>
            <MetricCard
              label={t('Total Customers')}
              value={num(list.length)}
              tone="indigo"
              icon={<Icon.Users className="h-5 w-5" />}
              hint={search ? t('{n} match the search', { n: num(filtered.length) }) : t('Across the fleet')}
            />
            {/* Wallet / Credit / Outstanding money cards — financials only */}
            {SHOW_FINANCIALS && (
              <>
                <MetricCard
                  label={t('In Credit')}
                  value={num(inCredit)}
                  tone="emerald"
                  icon={<Icon.Coins className="h-5 w-5" />}
                  hint={list.length ? t('{pct}% of customers', { pct: Math.round((inCredit / list.length) * 100) }) : '—'}
                  tooltip={t('Customers carrying wallet credit — money paid in advance, available toward their next rental.')}
                />
                <MetricCard
                  label={t('Wallet Credit')}
                  value={aed2(walletTotal)}
                  tone="green"
                  icon={<Icon.Cash className="h-5 w-5" />}
                  hint={t('Total carried-forward credit held')}
                  tooltip={t('Sum of all advance payments customers have on file (negative balances).')}
                />
                <MetricCard
                  label={t('Outstanding')}
                  value={aed2(owedTotal)}
                  tone={owedTotal > 0 ? 'red' : 'slate'}
                  icon={<Icon.Invoice className="h-5 w-5" />}
                  hint={owedCount === 1 ? t('1 customer owing') : t('{n} customers owing', { n: num(owedCount) })}
                  tooltip={t('Total amount owed across all customers (positive balances).')}
                />
              </>
            )}
          </MetricGrid>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && (
          <CustomersAnalytics customers={filtered} showFinancials={SHOW_FINANCIALS} />
        )}

        <SectionCard
          title={t('All Customers')}
          subtitle={loading ? undefined : t('{n} shown', { n: num(filtered.length) })}
          actions={
            <div className="w-full sm:w-80">
              <SearchInput
                value={search}
                onChange={(v) => { setSearch(v); setPage(1); }}
                placeholder={t('Search name, mobile, email, passport, license…')}
              />
            </div>
          }
        >
          <DataTable
            rows={paged}
            rowKey={(c) => c.id}
            loading={loading}
            skeletonRows={PAGE_SIZE}
            empty={t('No customers found. Try a different search.')}
            highlightRow={(c) => SHOW_FINANCIALS && Number(c.balance || 0) > 0}
            columns={[
              {
                key: 'customer', header: t('Customer'), cellClass: 'font-medium',
                render: (c) => (
                  <>
                    <Link to={`/customers/${c.id}`} className="text-indigo-600 hover:text-indigo-700">{c.name_en || '—'}</Link>
                    <div className="text-xs text-slate-400">#{c.customer_no || c.id}</div>
                  </>
                ),
              },
              { key: 'mobile', header: t('Mobile'), cellClass: 'text-slate-500', render: (c) => c.mobile1 || '—' },
              {
                key: 'contracts', header: t('Contracts'), align: 'right', cellClass: 'tabular-nums text-slate-500',
                render: (c) => num(c.contracts_count),
              },
              ...(SHOW_FINANCIALS ? [{
                key: 'balance', header: t('Balance'), align: 'right',
                tooltip: t('Positive = amount owed. Negative = wallet credit (advance paid, available toward the next rental).'),
                render: (c) => { const b = balanceBadge(t, c.balance); return <Badge tone={b.tone}>{b.text}</Badge>; },
              }] : []),
              {
                key: 'actions', header: t('Actions'), align: 'right',
                render: (c) => (
                  <div className="flex justify-end gap-2">
                    <Link to={`/customers/${c.id}`}>
                      <Button variant="secondary" size="sm">{t('View')}</Button>
                    </Link>
                    {canManage && <Button variant="secondary" size="sm" onClick={() => openEdit(c)}>{t('Edit')}</Button>}
                    {canManage && <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(c)}>{t('Delete')}</Button>}
                  </div>
                ),
              },
            ]}
          />

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </SectionCard>
      </div>

      {/* Create / Edit modal */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? t('Edit Customer') : t('Add Customer')}
        subtitle={editing ? (editing.name_en || `#${editing.customer_no || editing.id}`) : t('Enter the customer details')}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('Save changes') : t('Create Customer')}</Button>
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
        title={t('Delete customer?')}
        confirmText={t('Delete')}
        message={toDelete
          ? t('This will remove {name} (#{no}). This can be undone via the database (soft delete).', {
            name: toDelete.name_en || t('this customer'),
            no: toDelete.customer_no || toDelete.id,
          })
          : ''}
      />
    </div>
  );
}
