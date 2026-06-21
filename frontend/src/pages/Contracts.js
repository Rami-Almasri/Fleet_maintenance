import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge, { ContractStateBadge, ContractTypeBadge } from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { CursorPagination } from '../components/ui/Pagination';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import { aed2, fmtDate } from '../lib/format';

const SERVER_PAGE = 50;

const TYPES = [
  { value: '', label: 'All types' },
  { value: 'C', label: 'Rental' },
  { value: 'U', label: 'Maintenance' },
  { value: 'R', label: 'Booking' },
];
const STATES = [
  { value: '', label: 'All states' },
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
];
const BALANCES = [
  { value: '', label: 'Any balance' },
  { value: 'owes', label: 'Owes (balance > 0)' },
  { value: 'credit', label: 'Credit (overpaid)' },
  { value: 'settled', label: 'Settled (0)' },
];
const SERIALS = [
  { value: '', label: 'Any serial' },
  { value: 'present', label: 'Has serial' },
  { value: 'missing', label: 'No serial' },
];

const balTone = (b) => {
  const v = Number(b || 0);
  if (v > 0) return 'red';
  if (v < 0) return 'green';
  return 'gray';
};

export default function Contracts() {
  const [page, setPage] = useState(1);
  const [type, setType] = useState('');
  const [state, setState] = useState('');
  const [balance, setBalance] = useState('');
  const [serial, setSerial] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');

  // debounce the search box
  useEffect(() => {
    const t = setTimeout(() => { setSearch(searchInput); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Contract', {
      params: {
        page,
        contract_type: type || undefined,
        state: state || undefined,
        balance: balance || undefined,
        contract_serial: serial || undefined,
        search: search || undefined,
      },
    });
    return data.data || {};
  }, [page, type, state, balance, serial, search]);
  const { data, loading, error } = useFetch(fetcher, [page, type, state, balance, serial, search]);

  const rows = data?.items || [];
  const total = data?.total ?? null;
  const hasNext = rows.length === SERVER_PAGE;

  const onType = (e) => { setType(e.target.value); setPage(1); };
  const onState = (e) => { setState(e.target.value); setPage(1); };
  const onBalance = (e) => { setBalance(e.target.value); setPage(1); };
  const onSerial = (e) => { setSerial(e.target.value); setPage(1); };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Contracts" subtitle="Every vehicle movement — rentals, maintenance, transfers.">
          <Link to="/contracts/new">
            <Button>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
              New Contract
            </Button>
          </Link>
        </PageHeader>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={searchInput} onChange={setSearchInput} placeholder="Search contract no., plate, VIN or customer…" />
          <Select className="sm:w-44" value={type} onChange={onType}>
            {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <Select className="sm:w-40" value={state} onChange={onState}>
            {STATES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
          <Select className="sm:w-48" value={balance} onChange={onBalance}>
            {BALANCES.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
          </Select>
          <Select className="sm:w-44" value={serial} onChange={onSerial}>
            {SERIALS.map((n) => <option key={n.value} value={n.value}>{n.label}</option>)}
          </Select>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Contract</th>
                  <th className="px-6 py-3">Customer</th>
                  <th className="px-6 py-3">Vehicle</th>
                  <th className="px-6 py-3">Type</th>
                  <th className="px-6 py-3">State</th>
                  <th className="px-6 py-3">Out Date</th>
                  <th className="px-6 py-3 text-right">Balance</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody className="divide-y divide-gray-50">
                  {rows.map((c) => (
                    <tr key={c.id} className="hover:bg-gray-50/60">
                      <td className="px-6 py-3 font-medium">
                        <Link to={`/contracts/${c.id}`} className="text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>
                      </td>
                      <td className="px-6 py-3 text-gray-700">
                        {c.customer ? (
                          <Link to={`/customers/${c.customer_id}`} className="block hover:text-indigo-700">
                            <div className="font-medium text-indigo-600">{c.customer.name_en || `#${c.customer.customer_no}`}</div>
                            {c.customer.name_en && <div className="text-xs text-gray-400">#{c.customer.customer_no}</div>}
                          </Link>
                        ) : <span className="text-gray-400">—</span>}
                      </td>
                      <td className="px-6 py-3 text-gray-700">
                        {c.vehicle ? (
                          <div>
                            <div className="font-medium text-gray-900">{c.vehicle.plate_no || '—'}</div>
                            <div className="text-xs text-gray-400">{[c.vehicle.make, c.vehicle.model].filter(Boolean).join(' ')}</div>
                          </div>
                        ) : <span className="text-gray-400">—</span>}
                      </td>
                      <td className="px-6 py-3"><ContractTypeBadge type={c.contract_type} /></td>
                      <td className="px-6 py-3"><ContractStateBadge state={c.state} /></td>
                      <td className="px-6 py-3 text-gray-500">{fmtDate(c.out_date)}</td>
                      <td className="px-6 py-3 text-right">
                        <Badge tone={balTone(c.contract_balance)}>{aed2(c.contract_balance)}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && rows.length === 0 && <EmptyState title="No contracts match these filters" message="Try clearing the type, state or search." />}
          </div>

          {!loading && rows.length > 0 && <CursorPagination page={page} onPage={setPage} hasNext={hasNext} count={rows.length} total={total} />}
        </Card>
      </div>
    </div>
  );
}
