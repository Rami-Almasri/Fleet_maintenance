import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { fmtDate, num } from '../lib/format';

// How each reconciliation outcome is shown.
const FLAGS = {
  sheet_back:  { tone: 'red',   label: 'Sheet says back · contract open' },
  in_progress: { tone: 'amber', label: 'In garage' },
  no_sheet:    { tone: 'gray',  label: 'No sheet event' },
};

// Filter chips across the top of the table.
const TABS = [
  { key: '', label: 'All' },
  { key: 'sheet_back', label: 'Needs closing' },
  { key: 'in_progress', label: 'In progress' },
  { key: 'no_sheet', label: 'Not in sheet' },
];

function Stat({ label, value, tone = 'text-gray-900', hint }) {
  return (
    <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
      <p className="text-xs font-medium text-gray-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
      {hint && <p className="mt-0.5 text-[11px] text-gray-400">{hint}</p>}
    </div>
  );
}

export default function MaintenanceReturns() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/MaintenanceReturns');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const [tab, setTab] = useState('');

  const summary = data?.summary || {};
  const rows = useMemo(() => data?.rows || [], [data]);
  const shown = useMemo(() => (tab ? rows.filter((r) => r.flag === tab) : rows), [rows, tab]);

  // Floating page gauge: of the cars in the garage, how many the sheet says are
  // already back but whose contract is still open (i.e. should be closed).
  usePageStat({
    percent: summary.in_garage ? (summary.sheet_says_back / summary.in_garage) * 100 : null,
    label: 'To close',
    color: 'red',
    hint: `${summary.sheet_says_back || 0} of ${summary.in_garage || 0} in-garage contracts: the sheet shows the car back`,
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Return Reconciliation"
          subtitle="Cars the N-Maintenance sheet shows are back from the garage, but whose maintenance contract is still open in OfficeManager — these contracts just need closing."
        >
          <Link to="/maintenance" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Maintenance board →</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label="In garage (open contracts)" value={num(summary.in_garage)} />
          <Stat label="🔴 Sheet says back" value={num(summary.sheet_says_back)} tone="text-red-600" hint="Contract still open" />
          <Stat label="🟡 Still in progress" value={num(summary.in_progress)} tone="text-amber-600" hint="Sheet & contract agree" />
          <Stat label="No sheet event" value={num(summary.no_sheet)} tone="text-gray-500" hint="Nothing to compare" />
        </div>

        {/* Filter chips */}
        <div className="flex flex-wrap gap-2">
          {TABS.map((t) => {
            const count = t.key ? rows.filter((r) => r.flag === t.key).length : rows.length;
            const active = tab === t.key;
            return (
              <button
                key={t.key || 'all'}
                onClick={() => setTab(t.key)}
                className={`inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition ${
                  active
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                }`}
              >
                {t.label}
                <span className={`rounded-full px-1.5 text-xs font-semibold ${active ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'}`}>{count}</span>
              </button>
            );
          })}
        </div>

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm stagger-rows">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-6 py-3">Car</th>
                  <th className="px-6 py-3">Contract</th>
                  <th className="px-6 py-3">Out to garage</th>
                  <th className="px-6 py-3 text-center">Days open</th>
                  <th className="px-6 py-3">Garage</th>
                  <th className="px-6 py-3 text-center">Sheet stage</th>
                  <th className="px-6 py-3">Sheet says back</th>
                  <th className="px-6 py-3">Reconciliation</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {shown.map((r) => {
                  const f = FLAGS[r.flag] || FLAGS.no_sheet;
                  return (
                    <tr key={r.contract_id} className={`hover:bg-gray-50/60 ${r.flag === 'sheet_back' ? 'bg-red-50/40' : ''}`}>
                      <td className="px-6 py-3">
                        {r.vehicle_id
                          ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                          : <span className="font-medium text-gray-700">{r.plate || '—'}</span>}
                        <div className="text-xs text-gray-400">{r.car || '—'}</div>
                      </td>
                      <td className="px-6 py-3">
                        <Link to={`/contracts/${r.contract_id}`} className="text-indigo-600 hover:text-indigo-700">#{r.contract_no || r.contract_id}</Link>
                      </td>
                      <td className="px-6 py-3 text-gray-500">{fmtDate(r.out_date)}</td>
                      <td className="px-6 py-3 text-center text-gray-700 tabular-nums">{r.days_open != null ? `${r.days_open}d` : '—'}</td>
                      <td className="px-6 py-3 text-gray-600">{r.garage || '—'}</td>
                      <td className="px-6 py-3 text-center">
                        {r.sheet_stage
                          ? <Badge tone={r.sheet_stage === 'IN' ? 'green' : 'blue'}>{r.sheet_stage}</Badge>
                          : <span className="text-gray-300">—</span>}
                      </td>
                      <td className="px-6 py-3 text-gray-600">
                        {r.sheet_returned_on
                          ? <span>{fmtDate(r.sheet_returned_on)} <span className="text-xs text-gray-400">({r.days_since_return}d ago)</span></span>
                          : <span className="text-gray-300">—</span>}
                      </td>
                      <td className="px-6 py-3"><Badge tone={f.tone}>{f.label}</Badge></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {shown.length === 0 && (
            <EmptyState
              title={rows.length === 0 ? 'Nothing in the garage' : 'No cars in this view'}
              message={rows.length === 0 ? 'No cars have an open maintenance contract right now.' : 'Try a different filter above.'}
            />
          )}
        </Card>

        <p className="text-xs text-gray-400">
          A car counts as “back” when its latest <span className="font-medium">N-Maintenance &amp; Repair</span> sheet event is a closing
          <span className="font-medium"> IN</span> or carries an actual return date. The contract is “open” when OfficeManager still has no
          return date on it. Closing the contract in OfficeManager clears the mismatch on the next sync.
        </p>
      </div>
    </div>
  );
}
