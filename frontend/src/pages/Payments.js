import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Modal from '../components/ui/Modal';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import { Input, Select, Textarea } from '../components/ui/Field';
import { PAYMENT_METHODS } from '../components/ContractPayments';
import { aed2, fmtDate, num } from '../lib/format';

const methodLabel = (v) => PAYMENT_METHODS.find((m) => m.value === v)?.label || v || '—';
const EMPTY = { amount: '', paid_on: '', method: 'cash', reference: '', notes: '' };

/**
 * The global payments / receipts ledger — every collection recorded on the website,
 * across all contracts. Search, page through them, edit/delete, and record a new one
 * (pick the contract it settles). Per-invoice allocation lives on the contract page.
 */
export default function Payments() {
  const { can } = usePermissions();
  const toast = useToast();
  const canManage = can('billing.manage');

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({ page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [loading, setLoading] = useState(true);

  // Create / edit modal
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // Contract picker (create only)
  const [cQuery, setCQuery] = useState('');
  const [cResults, setCResults] = useState([]);
  const [cLoading, setCLoading] = useState(false);
  const [contract, setContract] = useState(null);
  const cTimer = useRef(null);

  const today = new Date().toISOString().slice(0, 10);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);
  useEffect(() => { setPage(1); }, [debounced]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/Payment', { params: { page, search: debounced || undefined } });
      const d = data.data || {};
      setRows(d.items || []);
      setMeta({ page: d.page || 1, last_page: d.last_page || 1, total: d.total || 0 });
    } catch (e) {
      toast.error('Failed to load payments');
    } finally {
      setLoading(false);
    }
  }, [page, debounced, toast]);

  useEffect(() => { load(); }, [load]);

  const set = (f) => (e) => setForm((s) => ({ ...s, [f]: e.target.value }));
  const err = (f) => errors[f]?.[0] || '';

  const openNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, paid_on: today });
    setContract(null);
    setCQuery('');
    setCResults([]);
    setErrors({});
    setOpen(true);
  };

  const openEdit = (p) => {
    setEditing(p);
    setForm({ amount: p.amount ?? '', paid_on: p.paid_on || '', method: p.method || 'cash', reference: p.reference || '', notes: p.notes || '' });
    setContract(p.contract ? { id: p.contract.id, contract_no: p.contract.contract_no, contract_type: p.contract.contract_type } : null);
    setErrors({});
    setOpen(true);
  };

  // Live contract search for the create modal.
  useEffect(() => {
    if (!open || editing) return undefined;
    if (cTimer.current) clearTimeout(cTimer.current);
    if (!cQuery.trim()) { setCResults([]); return undefined; }
    cTimer.current = setTimeout(async () => {
      setCLoading(true);
      try {
        const { data } = await api.get('/Contract', { params: { search: cQuery.trim() } });
        setCResults(data.data?.items || []);
      } catch (e) {
        setCResults([]);
      } finally {
        setCLoading(false);
      }
    }, 300);
    return () => cTimer.current && clearTimeout(cTimer.current);
  }, [cQuery, open, editing]);

  const submit = async () => {
    if (!editing && !contract) { toast.error('Pick a contract for this payment'); return; }
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        amount: form.amount === '' ? 0 : Number(form.amount),
        paid_on: form.paid_on || null,
        method: form.method || null,
        reference: form.reference || null,
        notes: form.notes || null,
      };
      if (editing) {
        await api.post(`/Payment/${editing.id}`, payload);
        toast.success('Payment updated');
      } else {
        await api.post('/Payment', { ...payload, contract_id: contract.id });
        toast.success('Payment recorded');
      }
      setOpen(false);
      load();
    } catch (e) {
      const r = e.response?.data;
      if (r?.errors) { setErrors(r.errors); toast.error('Please fix the highlighted fields'); }
      else toast.error(r?.message || r?.msg || 'Could not save the payment');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (p) => {
    if (!window.confirm(`Delete payment ${p.payment_ref} (${aed2(p.amount)})? This cannot be undone.`)) return;
    try {
      await api.delete(`/Payment/${p.id}`);
      toast.success('Payment deleted');
      load();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the payment');
    }
  };

  // Page-level rollup for the KPI tiles (sum of the receipts visible on this page).
  const pageTotal = rows.reduce((sum, p) => sum + Number(p.amount || 0), 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Payments / Receipts" subtitle="Every collection recorded on the website, across all contracts.">
          <SearchInput value={search} onChange={setSearch} placeholder="Search receipt, ref, method, customer…" className="w-72" />
          {canManage && (
            <Button onClick={openNew}>
              <Icon.Plus className="h-4 w-4" /> Record payment
            </Button>
          )}
        </PageHeader>

        <MetricGrid cols={3}>
          <MetricCard
            label="Total Receipts"
            value={num(meta.total)}
            tone="indigo"
            icon={<Icon.Card className="h-5 w-5" />}
            hint={debounced ? `Matching “${debounced}”` : 'Across all contracts'}
            tooltip="Total number of payment receipts recorded on the website (across all pages)."
          />
          <MetricCard
            label="On This Page"
            value={num(rows.length)}
            tone="slate"
            icon={<Icon.Invoice className="h-5 w-5" />}
            hint={meta.last_page > 1 ? `Page ${meta.page} of ${meta.last_page}` : 'All on one page'}
            tooltip="Receipts shown on the current page."
          />
          <MetricCard
            label="Collected (page)"
            value={aed2(pageTotal)}
            tone="emerald"
            icon={<Icon.Coins className="h-5 w-5" />}
            hint="Sum of receipts on this page"
            tooltip="Sum of the payment amounts visible on the current page only — not the full ledger."
          />
        </MetricGrid>

        <SectionCard
          title="Receipts"
          actions={<span className="text-xs text-slate-400">{num(meta.total)} payment{meta.total === 1 ? '' : 's'}</span>}
        >
          <DataTable
            rows={rows}
            rowKey={(p) => p.id}
            loading={loading}
            empty={canManage ? 'No payments yet — record the first receipt with “Record payment”.' : 'Nothing has been recorded yet.'}
            columns={[
              {
                key: 'receipt', header: 'Receipt', cellClass: 'font-medium text-slate-900',
                render: (p) => (
                  <>
                    {p.payment_ref || '—'}
                    {p.reference && <p className="mt-0.5 text-xs font-normal text-slate-400">Ref: {p.reference}</p>}
                  </>
                ),
              },
              { key: 'date', header: 'Date', cellClass: 'text-slate-500', render: (p) => fmtDate(p.paid_on) },
              {
                key: 'contract', header: 'Contract',
                render: (p) => (
                  <>
                    {p.contract ? (
                      <Link to={`/contracts/${p.contract.id}`} className="text-indigo-600 hover:text-indigo-700">
                        {p.contract.contract_no || `#${p.contract.id}`}
                      </Link>
                    ) : '—'}
                    {p.invoice?.number && <span className="ml-1.5 text-xs text-slate-400">→ {p.invoice.number}</span>}
                  </>
                ),
              },
              { key: 'customer', header: 'Customer', render: (p) => p.customer?.name_en || (p.customer ? `#${p.customer.customer_no}` : '—') },
              { key: 'method', header: 'Method', cellClass: 'capitalize', render: (p) => methodLabel(p.method) },
              {
                key: 'amount', header: 'Amount', align: 'right',
                cellClass: 'tabular-nums font-semibold text-emerald-600', render: (p) => aed2(p.amount),
              },
              ...(canManage ? [{
                key: 'actions', header: 'Actions', align: 'right',
                render: (p) => (
                  <span className="inline-flex gap-2">
                    <button onClick={() => openEdit(p)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Edit</button>
                    <span className="text-slate-200">·</span>
                    <button onClick={() => remove(p)} className="text-xs font-medium text-red-500 hover:text-red-600">Delete</button>
                  </span>
                ),
              }] : []),
            ]}
          />
        </SectionCard>

        {/* Pager */}
        {!loading && meta.last_page > 1 && (
          <div className="flex items-center justify-between text-sm text-slate-500">
            <span>{meta.total} payment{meta.total === 1 ? '' : 's'}</span>
            <span className="inline-flex items-center gap-3">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</Button>
              <span>Page {meta.page} of {meta.last_page}</span>
              <Button variant="secondary" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
            </span>
          </div>
        )}
      </div>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={editing ? `Edit payment ${editing.payment_ref || ''}` : 'Record payment'}
        subtitle={editing ? 'Update the receipt details' : 'Pick the contract this receipt settles'}
        size="lg"
        footer={(
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={submit} loading={saving}>{editing ? 'Save changes' : 'Record payment'}</Button>
          </>
        )}
      >
        <div className="space-y-4">
          {/* Contract picker — create only; on edit the contract is fixed */}
          {editing ? (
            <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm ring-1 ring-inset ring-slate-100">
              <span className="text-slate-500">Contract</span>{' '}
              <span className="font-medium text-slate-900">{editing.contract?.contract_no || `#${editing.contract?.id}`}</span>
            </div>
          ) : contract ? (
            <div className="flex items-center justify-between rounded-xl bg-indigo-50 px-4 py-3 text-sm ring-1 ring-inset ring-indigo-100">
              <span>
                <span className="text-slate-500">Contract</span>{' '}
                <span className="font-semibold text-slate-900">{contract.contract_no || `#${contract.id}`}</span>
                {contract.contract_type && <Badge tone="indigo" className="ml-2">{contract.contract_type}</Badge>}
              </span>
              <button onClick={() => setContract(null)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Change</button>
            </div>
          ) : (
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">Contract <span className="text-red-500">*</span></span>
              <SearchInput value={cQuery} onChange={setCQuery} placeholder="Search by contract no, plate, or customer…" />
              {cQuery.trim() && (
                <div className="mt-2 max-h-48 overflow-auto rounded-lg border border-slate-200">
                  {cLoading ? (
                    <div className="px-3 py-2 text-sm text-slate-400">Searching…</div>
                  ) : cResults.length === 0 ? (
                    <div className="px-3 py-2 text-sm text-slate-400">No matching contracts</div>
                  ) : cResults.map((ct) => (
                    <button
                      key={ct.id}
                      type="button"
                      onClick={() => { setContract(ct); setCQuery(''); setCResults([]); }}
                      className="block w-full px-3 py-2 text-left hover:bg-slate-50"
                    >
                      <span className="text-sm font-medium text-slate-900">{ct.contract_no || `#${ct.id}`}</span>
                      <span className="ml-2 text-xs text-slate-400">
                        {ct.contract_type} · {ct.customer?.name_en || ct.vehicle?.plate_no || ''}
                      </span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input label="Amount (AED)" type="number" step="0.01" min="0.01" value={form.amount} onChange={set('amount')} error={err('amount')} required />
            <Input label="Paid on" type="date" value={form.paid_on} onChange={set('paid_on')} error={err('paid_on')} />
            <Select label="Method" value={form.method} onChange={set('method')} error={err('method')}>
              {PAYMENT_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select>
          </div>
          <Input label="Reference (txn / cheque no)" value={form.reference} onChange={set('reference')} error={err('reference')} />
          <Textarea label="Notes" rows={2} value={form.notes} onChange={set('notes')} error={err('notes')} />
        </div>
      </Modal>
    </div>
  );
}
