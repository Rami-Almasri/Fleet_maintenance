import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge, { ContractStateBadge, ContractTypeBadge } from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { CursorPagination } from '../components/ui/Pagination';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import DataTable, { SectionCard } from '../components/ui/Table';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import { Select } from '../components/ui/Field';
import { aed2, fmtDate, num } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

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

  const columns = [
    {
      key: 'contract', header: 'Contract', cellClass: 'font-medium',
      render: (c) => (
        <Link to={`/contracts/${c.id}`} className="text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>
      ),
    },
    {
      key: 'customer', header: 'Customer',
      render: (c) => (
        c.customer ? (
          <Link to={`/customers/${c.customer_id}`} className="block hover:text-indigo-700">
            <div className="font-medium text-indigo-600">{c.customer.name_en || `#${c.customer.customer_no}`}</div>
            {c.customer.name_en && <div className="text-xs text-slate-400">#{c.customer.customer_no}</div>}
          </Link>
        ) : <span className="text-slate-400">—</span>
      ),
    },
    {
      key: 'vehicle', header: 'Vehicle',
      render: (c) => (
        c.vehicle ? (
          <div>
            <div className="font-medium text-slate-900">{c.vehicle.plate_no || '—'}</div>
            <div className="text-xs text-slate-400">{[c.vehicle.make, c.vehicle.model].filter(Boolean).join(' ')}</div>
          </div>
        ) : <span className="text-slate-400">—</span>
      ),
    },
    {
      key: 'type', header: 'Type', tooltip: 'C = Rental · U = Maintenance · R = Booking.',
      render: (c) => <ContractTypeBadge type={c.contract_type} />,
    },
    {
      key: 'state', header: 'State', tooltip: 'Lifecycle state of the contract (open = vehicle still out, closed = returned).',
      render: (c) => <ContractStateBadge state={c.state} />,
    },
    {
      key: 'out_date', header: 'Out Date', cellClass: 'text-slate-500',
      render: (c) => fmtDate(c.out_date),
    },
    // Balance column — financials only.
    ...(SHOW_FINANCIALS ? [{
      key: 'balance', header: 'Balance', align: 'right', cellClass: 'tabular-nums',
      tooltip: 'Outstanding amount: positive = customer owes, negative = credit/overpaid.',
      render: (c) => <Badge tone={balTone(c.contract_balance)}>{aed2(c.contract_balance)}</Badge>,
    }] : []),
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Contracts" subtitle="Every vehicle movement — rentals, maintenance, transfers.">
          <Link to="/contracts/new">
            <Button>
              <Icon.Plus className="h-4 w-4" />
              New Contract
            </Button>
          </Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <SectionCard
          title="All contracts"
          subtitle={total != null ? `${num(total)} total` : undefined}
          actions={
            <span className="inline-flex items-center gap-1 text-xs text-slate-400">
              <Icon.Filter className="h-3.5 w-3.5" />
              Type / state / balance
              <InfoTip content="Filters and search run server-side; results are paginated below." />
            </span>
          }
          bodyClass="space-y-4 p-4 sm:p-5"
        >
          {/* Filters — KEEP every control working exactly as before */}
          <div className="flex flex-col gap-3 lg:flex-row">
            <SearchInput className="flex-1" value={searchInput} onChange={setSearchInput} placeholder="Search contract no., plate, VIN or customer…" />
            <Select className="lg:w-44" value={type} onChange={onType}>
              {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </Select>
            <Select className="lg:w-40" value={state} onChange={onState}>
              {STATES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
            </Select>
            {SHOW_FINANCIALS && (
              <Select className="lg:w-48" value={balance} onChange={onBalance}>
                {BALANCES.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
              </Select>
            )}
            <Select className="lg:w-44" value={serial} onChange={onSerial}>
              {SERIALS.map((n) => <option key={n.value} value={n.value}>{n.label}</option>)}
            </Select>
          </div>

          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(c) => c.id}
            loading={loading}
            skeletonRows={8}
            // subtle highlight for contracts that owe money (positive balance) — financials only
            highlightRow={(c) => SHOW_FINANCIALS && Number(c.contract_balance || 0) > 0}
            empty="No contracts match these filters. Try clearing the type, state or search."
          />

          {!loading && rows.length > 0 && (
            <CursorPagination page={page} onPage={setPage} hasNext={hasNext} count={rows.length} total={total} />
          )}
        </SectionCard>
      </div>
    </div>
  );
}
