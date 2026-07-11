import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import Pagination from '../components/ui/Pagination';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import Drawer from '../components/ui/Drawer';
import { aed2, num, fmtDate } from '../lib/format';

// ── Date-window presets ──────────────────────────────────────────────────────
// The source report is a period snapshot, and the fleet's contract history runs back to 2011 with
// odometers that were reset/re-plated over the years — so an all-time sum is noisy. Default to the
// current year, and let the user widen or narrow the window (or go all-time) from here.
const iso = (d) => d.toISOString().slice(0, 10);
const monthsAgo = (n) => { const d = new Date(); d.setMonth(d.getMonth() - n); return iso(d); };
const PRESETS = [
  { key: 'ytd', label: 'This year', from: () => `${new Date().getFullYear()}-01-01`, to: () => null },
  { key: '3m', label: 'Last 3 months', from: () => monthsAgo(3), to: () => null },
  { key: '6m', label: 'Last 6 months', from: () => monthsAgo(6), to: () => null },
  { key: '12m', label: 'Last 12 months', from: () => monthsAgo(12), to: () => null },
  { key: 'all', label: 'All time', from: () => null, to: () => null },
];

// A tone for the out-of-contract (leakage) column — the further a car's travel drifts from what its
// contracts explain, the louder it reads.
const oocTone = (r) => (r.rollback_flag ? 'text-red-600' : r.leakage_flag ? 'text-amber-600' : 'text-slate-500');

const TYPE_LABEL = { C: 'Rental', R: 'Rental', P: 'Rental', T: 'Test drive', U: 'Maintenance' };

// Rows shown per page in the per-car reconciliation table.
const PAGE_SIZE = 25;

// Sortable header — click to sort, click again to flip (mirrors the Profitability page).
function SortHeader({ label, col, sort, setSort, align = 'left' }) {
  const active = sort.col === col;
  return (
    <button
      type="button"
      onClick={() => setSort((s) => ({ col, dir: s.col === col && s.dir === 'desc' ? 'asc' : 'desc' }))}
      className={`inline-flex items-center gap-1 ${align === 'right' ? 'flex-row-reverse' : ''} font-semibold uppercase tracking-wide ${active ? 'text-indigo-600' : 'text-slate-500 hover:text-slate-700'}`}
    >
      {label}
      <span className={`text-[10px] ${active ? 'opacity-100' : 'opacity-0'}`}>{sort.dir === 'desc' ? '▼' : '▲'}</span>
    </button>
  );
}

const kmCell = (v) => (v === null || v === undefined ? <span className="text-slate-300">—</span> : `${num(v)} km`);

/**
 * Fuel & Mileage Reconciliation — the live version of the fuel_data_plate_summary report.
 * Per car, for a chosen period: real odometer travel vs. the kilometres explained by contracts →
 * the unexplained "out-of-contract" gap (office / transport / unlogged use), plus fuel debited back
 * for not refuelling. Click any car to see its contract-by-contract ledger with the gap before each leg.
 */
export default function FuelMileage({ embedded = false }) {
  const navigate = useNavigate();
  const [presetKey, setPresetKey] = useState('ytd');
  const preset = PRESETS.find((p) => p.key === presetKey) || PRESETS[0];
  const from = preset.from();
  const to = preset.to();

  const fetcher = useCallback(async () => {
    const params = {};
    if (from) params.from = from;
    if (to) params.to = to;
    const { data } = await api.get('/FuelMileage', { params });
    return data.data;
  }, [from, to]);
  const { data, loading, error } = useFetch(fetcher, [from, to]);

  const [q, setQ] = useState('');
  const [onlyFlagged, setOnlyFlagged] = useState(false);
  const [sort, setSort] = useState({ col: 'out_of_contract', dir: 'desc' });
  const [sel, setSel] = useState(null); // vehicle row selected for the drawer

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (onlyFlagged) list = list.filter((r) => r.leakage_flag || r.rollback_flag);
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    const { col, dir } = sort;
    const mul = dir === 'desc' ? -1 : 1;
    return [...list].sort((a, b) => {
      const av = a[col] ?? (typeof a[col] === 'string' ? '' : 0);
      const bv = b[col] ?? 0;
      if (typeof av === 'string' || typeof bv === 'string') return mul * String(av).localeCompare(String(bv));
      return mul * ((av ?? 0) - (bv ?? 0));
    });
  }, [data, q, sort, onlyFlagged]);

  // Client-side paging — 25 cars a page. Reset to page 1 whenever the list changes underneath us
  // (new data window, search, flag filter, or re-sort) so we never strand the user on an empty page.
  const [page, setPage] = useState(1);
  useEffect(() => { setPage(1); }, [q, onlyFlagged, sort, presetKey]);
  const pageCount = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount);
  const pagedRows = rows.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const s = data?.summary || {};

  const exportCsv = () => {
    const head = ['Plate', 'Car', 'Contracts', 'First Out', 'Last In', 'Actual km', 'Contract km', 'Out-of-Contract km', 'Fuel Debit (AED)', 'Flag'];
    const lines = rows.map((r) => [
      r.plate, r.car, r.contracts, r.first_out, r.last_in, r.actual_mileage, r.contract_mileage,
      r.out_of_contract, r.total_fuel_debit, r.rollback_flag ? 'odometer rollback' : r.leakage_flag ? 'leakage' : '',
    ].map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(','));
    const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `fuel-mileage-${from || 'all'}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const columns = [
    {
      key: 'plate', align: 'left', cellClass: 'font-medium',
      header: <SortHeader label="Car" col="plate" sort={sort} setSort={setSort} />,
      render: (r) => (
        <>
          <Link to={`/vehicles/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
          <div className="text-xs text-slate-400">{r.car || '—'}</div>
        </>
      ),
    },
    {
      key: 'contracts', align: 'right', cellClass: 'tabular-nums text-slate-600',
      tooltip: 'Number of contracts (movements) for this car in the window. Open = still out.',
      header: <SortHeader label="Trips" col="contracts" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (
        <>
          {num(r.contracts)}
          {r.open_now > 0 && <div className="text-[11px] text-blue-500">{r.open_now} open</div>}
        </>
      ),
    },
    {
      key: 'span', align: 'right', cellClass: 'tabular-nums text-slate-500 text-xs',
      tooltip: 'First odometer OUT → last odometer IN in the window.',
      header: 'Odometer span',
      render: (r) => (r.first_out === null ? <span className="text-slate-300">—</span> : (
        <span>{num(r.first_out)} <span className="text-slate-300">→</span> {num(r.last_in)}</span>
      )),
    },
    {
      key: 'actual_mileage', align: 'right', cellClass: 'tabular-nums text-slate-700',
      tooltip: 'Actual travel = last IN − first OUT (real odometer movement over the window).',
      header: <SortHeader label="Actual" col="actual_mileage" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.actual_mileage < 0
        ? <span className="tabular-nums font-semibold text-red-600" title="Odometer ran backwards">{num(r.actual_mileage)} km</span>
        : kmCell(r.actual_mileage)),
    },
    {
      key: 'contract_mileage', align: 'right', cellClass: 'tabular-nums text-slate-700',
      tooltip: 'Kilometres explained by contracts = Σ (IN − OUT) over every trip.',
      header: <SortHeader label="On contract" col="contract_mileage" sort={sort} setSort={setSort} align="right" />,
      render: (r) => kmCell(r.contract_mileage),
    },
    {
      key: 'out_of_contract', align: 'right',
      tooltip: 'Out-of-contract km = Actual − On-contract. Kilometres the car moved with NO contract to explain them — office runs, transport, unlogged use. Big gaps get flagged.',
      header: <SortHeader label="Out of contract" col="out_of_contract" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.out_of_contract === null ? <span className="text-slate-300">—</span> : (
        <span className={`inline-flex items-center gap-1.5 tabular-nums font-semibold ${oocTone(r)}`}>
          {r.rollback_flag && <Icon.Alert className="h-3.5 w-3.5" />}
          {num(r.out_of_contract)} km
        </span>
      )),
    },
    {
      key: 'total_fuel_debit', align: 'right', cellClass: 'tabular-nums text-amber-600',
      tooltip: 'Fuel charged back to customers for returning the car under-fuelled, summed over the window.',
      header: <SortHeader label="Fuel debit" col="total_fuel_debit" sort={sort} setSort={setSort} align="right" />,
      render: (r) => (r.total_fuel_debit ? aed2(r.total_fuel_debit) : <span className="text-slate-300">—</span>),
    },
  ];

  const inner = (
    <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        {!embedded && (
          <PageHeader
            title="Fuel & Mileage Reconciliation"
            subtitle="Per car, for the chosen period: real odometer travel vs. the kilometres your contracts explain — the gap is distance driven off-contract. Plus fuel debited back for under-fuelled returns. Click any car for its trip-by-trip ledger."
          />
        )}

        {/* Period selector */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Period</span>
          {PRESETS.map((p) => (
            <button
              key={p.key}
              type="button"
              onClick={() => setPresetKey(p.key)}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition ${presetKey === p.key ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'}`}
            >
              {p.label}
            </button>
          ))}
          <button
            type="button"
            onClick={exportCsv}
            disabled={!rows.length}
            className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-600 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 disabled:opacity-40"
          >
            <Icon.Download className="h-4 w-4" /> Export CSV
          </button>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <>
            <MetricGridSkeleton count={4} />
            <SectionCard title="Fleet — mileage reconciliation per car">
              <DataTable columns={columns} rows={[]} loading rowKey={() => 0} />
            </SectionCard>
          </>
        ) : (
          <>
            <MetricGrid cols={4}>
              <MetricCard
                label="Out-of-Contract km"
                value={`${num(s.total_out_of_contract)} km`}
                tone={Math.abs(Number(s.total_out_of_contract)) > 0 ? 'amber' : 'emerald'}
                icon={<Icon.Route className="h-5 w-5" />}
                hint={`${num(s.cars_with_leakage)} car${s.cars_with_leakage === 1 ? '' : 's'} flagged`}
                tooltip="Fleet-wide distance driven with no contract to explain it — the reconciliation gap. Individual cars can be far larger than this net figure."
              />
              <MetricCard
                label="Total Fuel Debit"
                value={aed2(s.total_fuel_debit)}
                tone="slate"
                icon={<Icon.Coins className="h-5 w-5" />}
                hint="Charged back for under-fuelled returns"
                tooltip="Sum of fuel_debit across every contract in the window — money billed to customers for not refuelling."
              />
              <MetricCard
                label="Actual vs On-Contract"
                value={`${num(s.total_actual_km)} km`}
                tone="blue"
                icon={<Icon.Gauge className="h-5 w-5" />}
                hint={`${num(s.total_contract_km)} km explained by contracts`}
                tooltip="Total real odometer travel across the fleet vs. how much of it your contracts account for."
              />
              <MetricCard
                label="Cars / Contracts"
                value={`${num(s.vehicles)} / ${num(s.contracts)}`}
                tone="indigo"
                icon={<Icon.Car className="h-5 w-5" />}
                hint={s.cars_with_rollback ? `${num(s.cars_with_rollback)} odometer rollback${s.cars_with_rollback === 1 ? '' : 's'}` : 'No odometer rollbacks'}
                tooltip="Cars with at least one trip in the window, and the total number of trips (contracts) reconciled."
              />
            </MetricGrid>

            {/* Controls */}
            <div className="flex flex-wrap items-center gap-3">
              <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
              <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={onlyFlagged} onChange={(e) => setOnlyFlagged(e.target.checked)} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                Only flagged cars (leakage / rollback)
              </label>
              <span className="ml-auto text-xs text-slate-400">{num(rows.length)} of {num(s.vehicles)} cars</span>
            </div>

            <SectionCard
              title="Fleet — mileage reconciliation per car"
              subtitle="Sorted by the biggest out-of-contract gap. Click a car for its contract-by-contract ledger."
              actions={<span className="text-xs text-slate-400">{num(rows.length)} car{rows.length === 1 ? '' : 's'}</span>}
            >
              <DataTable
                columns={columns}
                rows={pagedRows}
                rowKey={(r) => r.vehicle_id}
                onRowClick={(r) => setSel(r)}
                highlightRow={(r) => r.rollback_flag}
                empty={q ? 'No cars match your search.' : 'No contracts in this period.'}
              />
              {rows.length > PAGE_SIZE && (
                <Pagination
                  page={safePage}
                  pageCount={pageCount}
                  total={rows.length}
                  pageSize={PAGE_SIZE}
                  onPage={setPage}
                />
              )}
            </SectionCard>
          </>
        )}
    </div>
  );

  const drawer = (
    <VehicleLedgerDrawer vehicle={sel} from={from} to={to} onClose={() => setSel(null)} onOpenProfile={(id) => { setSel(null); navigate(`/vehicles/${id}`); }} />
  );

  if (embedded) return (<>{inner}{drawer}</>);

  return (
    <div className="py-8">
      {inner}
      {drawer}
    </div>
  );
}

// ── Drill-down drawer: one car's contract-by-contract ledger ─────────────────
function VehicleLedgerDrawer({ vehicle, from, to, onClose, onOpenProfile }) {
  const [state, setState] = useState({ loading: false, data: null, error: '' });

  useEffect(() => {
    if (!vehicle) return undefined;
    let alive = true;
    setState({ loading: true, data: null, error: '' });
    const params = {};
    if (from) params.from = from;
    if (to) params.to = to;
    api.get(`/FuelMileage/${vehicle.vehicle_id}`, { params })
      .then(({ data }) => { if (alive) setState({ loading: false, data: data.data, error: '' }); })
      .catch((err) => { if (alive) setState({ loading: false, data: null, error: err.response?.data?.message || err.message || 'Failed to load ledger' }); });
    return () => { alive = false; };
  }, [vehicle, from, to]);

  const t = state.data?.totals || vehicle || {};

  return (
    <Drawer
      open={!!vehicle}
      onClose={onClose}
      eyebrow="Fuel & Mileage ledger"
      title={vehicle ? (vehicle.plate || `#${vehicle.vehicle_id}`) : ''}
      subtitle={vehicle?.car || undefined}
      width="lg"
      footer={vehicle && (
        <div className="flex items-center justify-between">
          <span className="text-xs text-slate-400">{num(t.contracts)} trip{t.contracts === 1 ? '' : 's'} in period</span>
          <button onClick={() => onOpenProfile(vehicle.vehicle_id)} className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-700">
            Open car profile <Icon.ArrowRight className="h-4 w-4" />
          </button>
        </div>
      )}
    >
      {state.error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{state.error}</div>}

      {/* Totals strip */}
      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <MiniStat label="Actual" value={t.actual_mileage != null ? `${num(t.actual_mileage)} km` : '—'} tone={t.actual_mileage < 0 ? 'red' : 'slate'} />
        <MiniStat label="On contract" value={t.contract_mileage != null ? `${num(t.contract_mileage)} km` : '—'} />
        <MiniStat label="Out of contract" value={t.out_of_contract != null ? `${num(t.out_of_contract)} km` : '—'} tone={t.rollback_flag ? 'red' : t.leakage_flag ? 'amber' : 'slate'} />
        <MiniStat label="Fuel debit" value={t.total_fuel_debit ? aed2(t.total_fuel_debit) : '—'} tone="amber" />
      </div>

      {(t.rollback_flag || t.leakage_flag) && (
        <div className={`mb-4 flex items-start gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-inset ${t.rollback_flag ? 'bg-red-50 text-red-700 ring-red-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'}`}>
          <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0" />
          <span>
            {t.rollback_flag
              ? 'Odometer runs backwards over this period — a reading was mis-entered or the cluster was reset. Verify the trips below.'
              : 'This car drove a meaningful distance with no contract to explain it. The rows with a highlighted gap show where.'}
          </span>
        </div>
      )}

      {state.loading ? (
        <div className="space-y-2">{[...Array(6)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
      ) : (
        <div className="overflow-hidden rounded-xl ring-1 ring-slate-200">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
              <tr>
                <th className="px-3 py-2 text-left font-semibold">Trip</th>
                <th className="px-3 py-2 text-left font-semibold">Out → In</th>
                <th className="px-3 py-2 text-right font-semibold">Odometer</th>
                <th className="px-3 py-2 text-right font-semibold">Trip km</th>
                <th className="px-3 py-2 text-right font-semibold" title="Km driven between the previous return and this pickup — off-contract">Gap before</th>
                <th className="px-3 py-2 text-right font-semibold">Fuel</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white">
              {(state.data?.contracts || []).map((c) => (
                <tr key={c.id} className={c.negative ? 'bg-red-50/50' : c.gap_before > 5 ? 'bg-amber-50/40' : ''}>
                  <td className="px-3 py-2">
                    <div className="font-medium text-slate-700">{c.contract_no || `#${c.id}`}</div>
                    <div className="text-[11px] text-slate-400">{TYPE_LABEL[c.contract_type] || c.contract_type || '—'}{c.open ? ' · open' : ''}</div>
                  </td>
                  <td className="px-3 py-2 text-xs text-slate-500">
                    {fmtDate(c.out_date) || '—'} <span className="text-slate-300">→</span> {c.in_date ? fmtDate(c.in_date) : <span className="text-blue-500">out</span>}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums text-slate-500">
                    {c.out_milage != null ? num(c.out_milage) : '—'} <span className="text-slate-300">→</span> {c.in_milage != null ? num(c.in_milage) : '—'}
                  </td>
                  <td className={`px-3 py-2 text-right tabular-nums font-medium ${c.negative ? 'text-red-600' : 'text-slate-700'}`}>
                    {c.contract_mileage != null ? num(c.contract_mileage) : '—'}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {c.gap_before == null ? <span className="text-slate-300">—</span>
                      : c.gap_before > 5 ? <span className="font-semibold text-amber-600">+{num(c.gap_before)}</span>
                      : c.gap_before < 0 ? <span className="text-red-600">{num(c.gap_before)}</span>
                      : <span className="text-slate-400">{num(c.gap_before)}</span>}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums text-amber-600">{c.fuel_debit ? aed2(c.fuel_debit) : <span className="text-slate-300">—</span>}</td>
                </tr>
              ))}
              {!state.loading && !(state.data?.contracts || []).length && (
                <tr><td colSpan={6} className="px-3 py-8 text-center text-sm text-slate-400">No trips in this period.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </Drawer>
  );
}

function MiniStat({ label, value, tone = 'slate' }) {
  const tones = { slate: 'text-slate-800', red: 'text-red-600', amber: 'text-amber-600' };
  return (
    <div className="rounded-xl bg-white px-3 py-2.5 ring-1 ring-slate-200">
      <div className="text-[11px] uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 text-base font-bold tabular-nums ${tones[tone] || tones.slate}`}>{value}</div>
    </div>
  );
}
