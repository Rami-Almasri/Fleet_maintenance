import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import { Card, PageHeader, SearchInput, Spinner, EmptyState } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import { num } from '../lib/format';

// "2026-06-16T09:12:00+04:00" -> a short local "as of" label; tolerant of nulls.
function fmtAsOf(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return isNaN(d.getTime()) ? '' : d.toLocaleString();
}

const PAGE_SIZE = 25;
const COMPARABLE = ['name', 'year', 'plate', 'status', 'odometer'];
const COLUMNS = ['name', 'year', 'plate', 'status', 'odometer', 'color', 'category'];
const LABEL = {
  name: 'Name', year: 'Year', plate: 'Plate', status: 'Status',
  odometer: 'Odometer', color: 'Color', category: 'Category',
};

function StatCard({ label, value, tone = 'gray' }) {
  const color = { gray: 'text-gray-900', indigo: 'text-indigo-600', green: 'text-emerald-600', amber: 'text-amber-600', red: 'text-red-600' }[tone];
  return (
    <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
      <p className="text-xs font-medium text-gray-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${color}`}>{num(value)}</p>
    </div>
  );
}

function Cell({ f }) {
  if (!f) return <span className="text-gray-300">—</span>;
  if (!f.compared) return <span className="text-gray-400">{f.sheet || '—'}</span>;
  if (!f.different) return <span className="text-gray-600">{f.sheet || '—'}</span>;
  return (
    <div className="space-y-1">
      <div className="flex items-center gap-1.5">
        <span className="rounded bg-gray-100 px-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Sheet</span>
        <span className="text-gray-700">{f.sheet || '∅'}</span>
      </div>
      <div className="flex items-center gap-1.5">
        <span className="rounded bg-indigo-100 px-1 text-[10px] font-semibold uppercase tracking-wide text-indigo-600">API</span>
        <span className="font-medium text-indigo-700">{f.api || '∅'}</span>
      </div>
    </div>
  );
}

export default function SheetApiDiff() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/VehicleCsvSync/diff');
    return data.data;
  }, []);
  const { data, loading, error, setData } = useFetch(fetcher);
  const [refreshing, setRefreshing] = useState(false);

  // Force a server-side recompute (?fresh=1). Keeps the current (cached) table on screen
  // and swaps it in when the fresh result arrives — no blank loading state.
  const refresh = useCallback(async () => {
    setRefreshing(true);
    try {
      const { data: res } = await api.get('/VehicleCsvSync/diff', { params: { fresh: 1 } });
      setData(res.data);
    } catch {
      // leave the existing data in place on failure
    } finally {
      setRefreshing(false);
    }
  }, [setData]);

  const [tab, setTab] = useState('diffs');
  const [search, setSearch] = useState('');
  const [field, setField] = useState('');       // '' = any field; else only cars differing in this field
  const [onlyDiffs, setOnlyDiffs] = useState(true);
  const [page, setPage] = useState(1);

  const matched = useMemo(() => data?.matched || [], [data]);
  const missing = useMemo(() => data?.missing || [], [data]);
  const summary = data?.summary || {};

  // index each car's fields by name for column access
  const rowsAll = useMemo(() => matched.map((m) => ({
    ...m,
    map: Object.fromEntries((m.fields || []).map((f) => [f.field, f])),
  })), [matched]);

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rowsAll.filter((m) => {
      const matchSearch = !q || [m.vin, m.code, m.map.name?.sheet, m.map.name?.api].some((f) => (f || '').toString().toLowerCase().includes(q));
      const matchField = !field || m.map[field]?.different;
      const matchOnly = !onlyDiffs || m.diff_count > 0;
      return matchSearch && matchField && matchOnly;
    });
  }, [rowsAll, search, field, onlyDiffs]);

  const pageCount = Math.ceil((tab === 'diffs' ? rows.length : missing.length) / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = (tab === 'diffs' ? rows : missing).slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const reset = (fn) => { fn(); setPage(1); };

  if (loading) {
    return (
      <div className="flex flex-col items-center gap-3 py-24 text-sm text-gray-500">
        <Spinner className="h-8 w-8" />
        Reading the Faster sheet and comparing to the live API by VIN…
      </div>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[90rem] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Sheet ↔ API Diff"
          subtitle="The Faster sheet matched to the live OfficeManager API by chassis (VIN), field by field. Read-only."
        >
          <div className="flex items-center gap-3">
            {data?.as_of && (
              <span className="text-xs text-gray-400">as of {fmtAsOf(data.as_of)}</span>
            )}
            <Button variant="secondary" size="sm" onClick={refresh} loading={refreshing} disabled={refreshing || loading}>
              Refresh
            </Button>
          </div>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          <StatCard label="Cars in sheet" value={summary.sheet_total} />
          <StatCard label="Matched by VIN" value={summary.matched} tone="green" />
          <StatCard label="With differences" value={summary.with_diffs} tone="amber" />
          <StatCard label="Identical" value={summary.identical} tone="green" />
          <StatCard label="Missing in API" value={summary.missing_in_api} tone="red" />
          <StatCard label="API vehicles" value={summary.api_vehicles} />
        </div>

        <Card>
          <div className="flex flex-wrap items-center gap-1 border-b border-gray-100 px-3">
            <button onClick={() => { setTab('diffs'); setPage(1); }}
              className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition ${tab === 'diffs' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
              Comparison <Badge tone={tab === 'diffs' ? 'indigo' : 'green'}>{num(summary.matched)}</Badge>
            </button>
            <button onClick={() => { setTab('missing'); setPage(1); }}
              className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition ${tab === 'missing' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
              Missing in API <Badge tone={tab === 'missing' ? 'indigo' : 'red'}>{num(summary.missing_in_api)}</Badge>
            </button>
          </div>

          {tab === 'diffs' && (
            <>
              <div className="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 sm:flex-row sm:items-center">
                <SearchInput className="flex-1" value={search} onChange={(v) => reset(() => setSearch(v))} placeholder="Search VIN, code, name…" />
                <Select className="sm:w-52" value={field} onChange={(e) => reset(() => setField(e.target.value))}>
                  <option value="">Any differing field</option>
                  {COMPARABLE.map((f) => <option key={f} value={f}>Differs in {LABEL[f]}</option>)}
                </Select>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                  <input type="checkbox" checked={onlyDiffs} onChange={(e) => reset(() => setOnlyDiffs(e.target.checked))}
                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                  Only cars with differences
                </label>
              </div>

              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                  <thead className="bg-gray-50/60">
                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                      <th className="px-4 py-3">Car</th>
                      {COLUMNS.map((c) => <th key={c} className="px-4 py-3">{LABEL[c]}</th>)}
                      <th className="px-4 py-3 text-right">Diffs</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {paged.map((m) => (
                      <tr key={m.vin} className="align-top hover:bg-gray-50/60">
                        <td className="px-4 py-3">
                          <div className="font-mono text-xs text-gray-600">{m.vin}</div>
                          <div className="text-xs text-gray-400">code {m.code || '—'} · serial {m.car_serial || '—'}</div>
                          <div className="text-xs text-gray-400">owner {m.owner_no || '—'} · traffic {m.traffic_id || '—'}</div>
                        </td>
                        {COLUMNS.map((c) => {
                          const f = m.map[c];
                          return (
                            <td key={c} className={`px-4 py-3 ${f?.different ? 'bg-amber-50/50' : ''}`}>
                              <Cell f={f} />
                            </td>
                          );
                        })}
                        <td className="px-4 py-3 text-right">
                          {m.diff_count > 0
                            ? <Badge tone="amber">{m.diff_count}</Badge>
                            : <Badge tone="green">match</Badge>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {rows.length === 0 && <EmptyState title="Nothing here" message="No cars match your search/filter." />}
              </div>
              {rows.length > 0 && <Pagination page={safePage} pageCount={pageCount} total={rows.length} pageSize={PAGE_SIZE} onPage={setPage} />}
            </>
          )}

          {tab === 'missing' && (
            <>
              <div className="border-b border-gray-100 px-4 py-3 text-sm text-gray-500">
                Cars in the sheet whose chassis (VIN) was <strong>not found</strong> in the API.
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                  <thead className="bg-gray-50/60">
                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                      <th className="px-6 py-3">Name</th>
                      <th className="px-6 py-3">Code</th>
                      <th className="px-6 py-3">Year</th>
                      <th className="px-6 py-3">Plate</th>
                      <th className="px-6 py-3">Status</th>
                      <th className="px-6 py-3">Chassis (VIN)</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {paged.map((m) => (
                      <tr key={m.vin} className="hover:bg-gray-50/60">
                        <td className="px-6 py-3 font-medium text-gray-800">{m.name || '—'}</td>
                        <td className="px-6 py-3 text-gray-500">{m.code || '—'}</td>
                        <td className="px-6 py-3 text-gray-500">{m.year || '—'}</td>
                        <td className="px-6 py-3 text-gray-600">{m.plate || '—'}</td>
                        <td className="px-6 py-3 text-gray-600">{m.status || '—'}</td>
                        <td className="px-6 py-3 font-mono text-xs text-red-600">{m.vin}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {missing.length === 0 && <EmptyState title="All matched 🎉" message="Every sheet car was found in the API." />}
              </div>
              {missing.length > 0 && <Pagination page={safePage} pageCount={pageCount} total={missing.length} pageSize={PAGE_SIZE} onPage={setPage} />}
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
