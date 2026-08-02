import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge, { VehicleStatusBadge, ContractTypeBadge } from '../components/ui/Badge';
import Pagination from '../components/ui/Pagination';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import { usePageStat } from '../components/PageStat';
import RegistrationsAnalytics from '../components/analytics/RegistrationsAnalytics';
import { fmtDate, dayBadge, num } from '../lib/format';

const PAGE_SIZE = 15;

// Registration / insurance coverage cell.
function CoverageCell({ has, date, days }) {
  if (!has) return <Badge tone="red">None</Badge>;
  const b = dayBadge(days);
  return (
    <div>
      <Badge tone={b.tone}>{b.text === '—' ? 'Valid' : b.text}</Badge>
      <div className="mt-1 text-xs text-slate-400">{fmtDate(date)}</div>
    </div>
  );
}

// Clickable column header that sorts by soonest expiry, with an active ↓ indicator.
function SortHeader({ label, active, onClick }) {
  return (
    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">
      <button onClick={onClick} className={`group inline-flex items-center gap-1 uppercase tracking-wide transition ${active ? 'text-indigo-600' : 'text-slate-500 hover:text-slate-700'}`} title="Sort by soonest expiry">
        {label}
        <svg className={`h-3.5 w-3.5 transition ${active ? 'opacity-100' : 'opacity-30 group-hover:opacity-60'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="M12 5v14M19 12l-7 7-7-7" />
        </svg>
      </button>
    </th>
  );
}

const DOT = { green: 'bg-emerald-500', red: 'bg-red-500', blue: 'bg-blue-500', amber: 'bg-amber-500' };

// Clickable summary tile that toggles a filter.
function StatTile({ label, value, tone, active, onClick }) {
  return (
    <button
      onClick={onClick}
      className={`hover-lift relative flex items-center justify-between rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${
        active ? 'ring-2 ring-indigo-500' : ''
      }`}
    >
      <div>
        <div className="flex items-center gap-2">
          <span className={`h-2 w-2 rounded-full ${DOT[tone]}`} />
          <p className="text-xs font-medium text-slate-500">{label}</p>
        </div>
        <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{value}</p>
      </div>
      {active && <span className="text-xs font-medium text-indigo-600">Filtering</span>}
    </button>
  );
}

export default function Registrations() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Registration/coverage');
    return data.data || [];
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const [search, setSearch] = useState('');
  const [ins, setIns] = useState('all'); // all | with | without
  const [reg, setReg] = useState('all');
  const [sort, setSort] = useState('default'); // default | insurance | registration
  const [page, setPage] = useState(1);

  const list = useMemo(() => data || [], [data]);

  const counts = useMemo(() => ({
    insured: list.filter((r) => r.has_insurance).length,
    uninsured: list.filter((r) => !r.has_insurance).length,
    registered: list.filter((r) => r.has_registration).length,
    unregistered: list.filter((r) => !r.has_registration).length,
  }), [list]);

  // Floating page gauge: share of cars with valid insurance.
  usePageStat({
    percent: list.length ? (counts.insured / list.length) * 100 : null,
    label: 'Insured',
    color: 'blue',
    hint: `${counts.insured} of ${list.length} cars have valid insurance`,
  });

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return list.filter((r) => {
      const matchSearch = !q || [r.plate_no, r.vin, r.make, r.model, r.chasis_no].some((f) => (f || '').toLowerCase().includes(q));
      const matchIns = ins === 'all' || (ins === 'with' ? r.has_insurance : !r.has_insurance);
      const matchReg = reg === 'all' || (reg === 'with' ? r.has_registration : !r.has_registration);
      return matchSearch && matchIns && matchReg;
    });
  }, [list, search, ins, reg]);

  // When focused on insurance (or registration), surface the soonest-to-expire first.
  const sorted = useMemo(() => {
    if (sort === 'default') return filtered;
    const key = sort === 'insurance' ? 'insurance_days_left' : 'registration_days_left';
    return [...filtered].sort((a, b) => {
      const av = a[key], bv = b[key];
      if (av == null && bv == null) return 0;
      if (av == null) return 1;   // cars with no coverage sort last
      if (bv == null) return -1;
      return av - bv;             // ascending: already-expired & soonest-to-expire first
    });
  }, [filtered, sort]);

  const pageCount = Math.ceil(sorted.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = sorted.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  // Clicking a tile both filters AND sorts by that dimension's urgency.
  const toggle = (setter, current, value, dim) => {
    setter(current === value ? 'all' : value);
    setSort(dim);
    setPage(1);
  };
  const onSearch = (v) => { setSearch(v); setPage(1); };
  const clearAll = () => { setSearch(''); setIns('all'); setReg('all'); setSort('default'); setPage(1); };

  const hasFilters = search || ins !== 'all' || reg !== 'all' || sort !== 'default';

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Registration & Insurance" subtitle="Mulkiya & insurance coverage for every car in the fleet." />

        {/* Summary tiles (click to filter) */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatTile label="Insured" value={loading ? '…' : num(counts.insured)} tone="green" active={ins === 'with'} onClick={() => toggle(setIns, ins, 'with', 'insurance')} />
          <StatTile label="Not insured" value={loading ? '…' : num(counts.uninsured)} tone="red" active={ins === 'without'} onClick={() => toggle(setIns, ins, 'without', 'insurance')} />
          <StatTile label="Registered" value={loading ? '…' : num(counts.registered)} tone="blue" active={reg === 'with'} onClick={() => toggle(setReg, reg, 'with', 'registration')} />
          <StatTile label="Not registered" value={loading ? '…' : num(counts.unregistered)} tone="amber" active={reg === 'without'} onClick={() => toggle(setReg, reg, 'without', 'registration')} />
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={onSearch} placeholder="Search plate, VIN, chassis, make or model…" />
          <Select className="sm:w-44" value={ins} onChange={(e) => { setIns(e.target.value); setSort('insurance'); setPage(1); }}>
            <option value="all">Insurance: all</option>
            <option value="with">With insurance</option>
            <option value="without">Without insurance</option>
          </Select>
          <Select className="sm:w-44" value={reg} onChange={(e) => { setReg(e.target.value); setSort('registration'); setPage(1); }}>
            <option value="all">Registration: all</option>
            <option value="with">With registration</option>
            <option value="without">Without registration</option>
          </Select>
          <Select className="sm:w-56" value={sort} onChange={(e) => { setSort(e.target.value); setPage(1); }}>
            <option value="default">Sort: plate (default)</option>
            <option value="insurance">Insurance: soonest expiry first</option>
            <option value="registration">Registration: soonest expiry first</option>
          </Select>
          {hasFilters && (
            <button onClick={clearAll} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
              Clear
            </button>
          )}
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && <RegistrationsAnalytics rows={filtered} />}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Vehicle</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">VIN / Chassis</th>
                  <SortHeader label="Registration" active={sort === 'registration'} onClick={() => { setSort(sort === 'registration' ? 'default' : 'registration'); setPage(1); }} />
                  <SortHeader label="Insurance" active={sort === 'insurance'} onClick={() => { setSort(sort === 'insurance' ? 'default' : 'insurance'); setPage(1); }} />
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Insurer</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Car Status</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3">Contract Status</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {paged.map((r) => (
                    <tr key={r.vehicle_id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        <div className="font-medium text-slate-900">{r.plate_no || <span className="text-slate-400">no plate</span>}</div>
                        <div className="text-xs text-slate-400">{[r.make, r.model].filter(Boolean).join(' ') || '—'}</div>
                      </td>
                      <td className="border-b border-slate-100 px-5 py-3.5 font-mono text-xs text-slate-500">{r.chasis_no || r.vin || '—'}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5"><CoverageCell has={r.has_registration} date={r.registration_expiry} days={r.registration_days_left} /></td>
                      <td className="border-b border-slate-100 px-5 py-3.5"><CoverageCell has={r.has_insurance} date={r.insurance_expiry} days={r.insurance_days_left} /></td>
                      <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{r.insurer || '—'}</td>
                      <td className="border-b border-slate-100 px-5 py-3.5"><VehicleStatusBadge status={r.status} /></td>
                      <td className="border-b border-slate-100 px-5 py-3.5">
                        {r.has_open_contract ? (
                          <div>
                            <ContractTypeBadge type={r.contract_type} />
                            <div className="mt-1 text-xs text-slate-400">
                              {[r.contract_no, r.contract_customer].filter(Boolean).join(' · ') || '—'}
                            </div>
                          </div>
                        ) : (
                          <Badge tone="gray">No open contract</Badge>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && <EmptyState title="No cars match these filters" message="Try clearing the filters or search." />}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>
      </div>
    </div>
  );
}
