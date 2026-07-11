import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge, { ContractTypeBadge } from '../components/ui/Badge';
import DataTable, { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import Icon from '../components/ui/Icon';
import { PageHeader, EmptyState, Spinner } from '../components/ui/Misc';
import { num, fmtDate } from '../lib/format';

const STATUS_TONE = {
  done: 'green', completed: 'green', partial: 'amber',
  failed: 'red', running: 'blue', 'dry-run': 'slate', aborted: 'amber',
};

// "2026-06-20T12:41:51Z" -> "20 Jun 2026, 12:41"
const fmtDateTime = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d)) return String(v);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

// Human labels for contract columns so the feed reads like English, not a schema.
const FIELD_LABELS = {
  contract_no: 'Contract No', contract_type: 'Type', state: 'State',
  vehicle_id: 'Vehicle', customer_id: 'Customer', external_id: 'OM Serial',
  parent_contract_id: 'Parent Contract', carried_balance: 'Carried Balance',
  day_price: 'Day Price', week_price: 'Week Price', month_price: 'Month Price',
  hour_price: 'Hour Price', year_price: 'Year Price',
  out_date: 'Out Date', out_time: 'Out Time', out_milage: 'Out Mileage', out_fuel: 'Out Fuel', opened_by: 'Opened By',
  in_date: 'In Date', in_time: 'In Time', in_milage: 'In Mileage', in_fuel: 'In Fuel', closed_by: 'Closed By',
  days: 'Days', km: 'KM',
  rents_debit: 'Rent', breachs_debit: 'Breaches', salik_debit: 'Salik', damages_debit: 'Damages',
  extra_charges_debit: 'Extra Charges', co_driver_debit: 'Co-driver', km_debit: 'KM Charge',
  fuel_debit: 'Fuel', gps_debit: 'GPS',
};
const FIELD_ORDER = Object.keys(FIELD_LABELS);

const labelOf = (k) => FIELD_LABELS[k] || k.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

// Backend scalarises dates to "YYYY-MM-DD"; render those as friendly dates, everything else as-is.
const fmtVal = (v) => {
  if (v === null || v === undefined || v === '') return null;
  const s = String(v);
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return fmtDate(s);
  return s;
};

const Dash = () => <span className="text-slate-300">—</span>;

// ── New-contract card: the full record laid out as a clean definition grid ──────────────
function NewContractCard({ rec }) {
  const snap = rec.snapshot || {};
  const keys = [
    ...FIELD_ORDER.filter((k) => snap[k] !== null && snap[k] !== undefined && snap[k] !== ''),
    ...Object.keys(snap).filter((k) => !FIELD_LABELS[k] && snap[k] !== null && snap[k] !== undefined && snap[k] !== ''),
  ];

  return (
    <div className="overflow-hidden rounded-2xl border border-emerald-200/70 bg-white shadow-soft ring-1 ring-emerald-500/5">
      <div className="flex items-center justify-between gap-3 border-b border-emerald-100 bg-emerald-50/50 px-4 py-3">
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
            <Icon.Plus className="h-4 w-4" strokeWidth={2.2} />
          </span>
          <div>
            <p className="text-sm font-semibold text-slate-900">
              {rec.contract_id
                ? <Link to={`/contracts/${rec.contract_id}`} className="text-emerald-700 hover:text-emerald-800">New contract #{rec.contract_no || rec.contract_id}</Link>
                : <>New contract #{rec.contract_no || '—'}</>}
            </p>
            <p className="text-[11px] text-slate-400">OM Serial {rec.external_id || '—'}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {snap.contract_type && <ContractTypeBadge type={snap.contract_type} />}
          <Badge tone="emerald" className="font-semibold">New</Badge>
        </div>
      </div>
      <dl className="grid grid-cols-2 gap-x-6 gap-y-2.5 px-4 py-3.5 sm:grid-cols-3">
        {keys.map((k) => (
          <div key={k} className="min-w-0">
            <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{labelOf(k)}</dt>
            <dd className="truncate text-sm font-medium text-slate-700">{fmtVal(snap[k]) ?? <Dash />}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

// ── Update card: ONLY the fields that changed, side-by-side was → now ───────────────────
function UpdateCard({ rec }) {
  const changes = rec.changes || {};
  const fields = [
    ...FIELD_ORDER.filter((k) => changes[k]),
    ...Object.keys(changes).filter((k) => !FIELD_LABELS[k]),
  ];

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
        <p className="text-sm font-semibold text-slate-900">
          {rec.contract_id
            ? <Link to={`/contracts/${rec.contract_id}`} className="text-indigo-600 hover:text-indigo-700">Contract #{rec.contract_no || rec.contract_id}</Link>
            : <>Contract #{rec.contract_no || '—'}</>}
        </p>
        <Badge tone="indigo" className="font-semibold">{num(rec.changed_count)} field{rec.changed_count === 1 ? '' : 's'} changed</Badge>
      </div>
      <table className="min-w-full text-sm">
        <thead>
          <tr className="text-left text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            <th className="px-4 py-2">Field</th>
            <th className="px-4 py-2">Was</th>
            <th className="w-8 px-1 py-2" />
            <th className="px-4 py-2">Now</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-50">
          {fields.map((k) => {
            const ch = changes[k] || {};
            return (
              <tr key={k} className="align-middle">
                <td className="px-4 py-2 font-medium text-slate-600">{labelOf(k)}</td>
                <td className="px-4 py-2">
                  {fmtVal(ch.old) === null
                    ? <Dash />
                    : <span className="rounded-md bg-red-50 px-1.5 py-0.5 text-red-600 line-through decoration-red-300">{fmtVal(ch.old)}</span>}
                </td>
                <td className="px-1 py-2 text-slate-300"><Icon.ArrowRight className="h-4 w-4" /></td>
                <td className="px-4 py-2">
                  {fmtVal(ch.new) === null
                    ? <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-400">cleared</span>
                    : <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700">{fmtVal(ch.new)}</span>}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

// ── Detail feed for one run ─────────────────────────────────────────────────────────────
function RunFeed({ runId }) {
  const fetcher = useCallback(async () => (await api.get(`/Sync/audit/${runId}`)).data.data, [runId]);
  const { data, loading, error } = useFetch(fetcher, [runId]);

  if (loading) return <div className="flex justify-center py-16"><Spinner className="h-7 w-7" /></div>;
  if (error) return <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;

  const run = data?.run || {};
  const inserts = data?.inserts || [];
  const updates = data?.updates || [];
  const fieldsChanged = updates.reduce((s, u) => s + (u.changed_count || 0), 0);
  const nothing = inserts.length === 0 && updates.length === 0;
  const unfinished = ['running', 'aborted', 'failed', 'partial'].includes(run.status);

  return (
    <div className="space-y-6">
      <MetricGrid cols={4}>
        <MetricCard label="New records" value={num(data?.inserts_total ?? inserts.length)} tone="emerald" icon={<Icon.Plus />} hint="Brand-new contracts" />
        <MetricCard label="Updated records" value={num(data?.updates_total ?? updates.length)} tone="indigo" icon={<Icon.Refresh />} hint="Existing contracts changed" />
        <MetricCard label="Fields changed" value={num(fieldsChanged)} tone="violet" icon={<Icon.Activity />} hint="Across loaded updates" />
        <MetricCard label="Auto-corrections" value={num(run.corrections || 0)} tone="amber" icon={<Icon.Shield />} hint="Stale values cleared" />
      </MetricGrid>

      {nothing && (
        <SectionCard>
          <EmptyState
            title={unfinished ? "This run didn't finish" : 'No record changes in this run'}
            message={unfinished
              ? 'It was interrupted or a phase failed before the change feed was recorded — so there is nothing to show. Re-run the sync and let it finish: the “Checking for recently-returned contracts…” step queries the OM API and can take a minute, so don’t close the window until you see “Sync done.”'
              : "This sync didn't create or modify any contract — nothing new to review."}
          />
        </SectionCard>
      )}

      {inserts.length > 0 && (
        <section className="space-y-3">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold text-slate-900">New contracts</h3>
            <Badge tone="emerald">{num(data?.inserts_total ?? inserts.length)}</Badge>
            {data?.inserts_total > inserts.length && (
              <span className="text-xs text-slate-400">showing first {num(inserts.length)}</span>
            )}
          </div>
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {inserts.map((rec, i) => <NewContractCard key={rec.contract_id || i} rec={rec} />)}
          </div>
        </section>
      )}

      {updates.length > 0 && (
        <section className="space-y-3">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold text-slate-900">Updated records</h3>
            <Badge tone="indigo">{num(data?.updates_total ?? updates.length)}</Badge>
            {data?.updates_total > updates.length && (
              <span className="text-xs text-slate-400">showing first {num(updates.length)}, biggest changes first</span>
            )}
          </div>
          <div className="space-y-4">
            {updates.map((rec, i) => <UpdateCard key={rec.contract_id || i} rec={rec} />)}
          </div>
        </section>
      )}
    </div>
  );
}

export default function SyncAudit() {
  const fetcher = useCallback(async () => (await api.get('/Sync/audit')).data.data, []);
  const { data, loading, error } = useFetch(fetcher);
  const [selectedId, setSelectedId] = useState(null);

  const runs = data?.runs || [];
  // Default to the most recent run so the feed is populated on first load.
  const activeId = selectedId ?? runs[0]?.id ?? null;

  const columns = [
    { key: 'when', header: 'When', cellClass: 'whitespace-nowrap font-medium text-slate-700', render: (r) => fmtDateTime(r.started_at) },
    { key: 'action', header: 'Run', render: (r) => r.action || '—' },
    { key: 'scanned', header: 'Scanned', align: 'right', cellClass: 'tabular-nums', render: (r) => (r.scanned == null ? <Dash /> : num(r.scanned)) },
    {
      key: 'created', header: 'New', align: 'right', cellClass: 'tabular-nums',
      render: (r) => (r.created > 0 ? <span className="font-semibold text-emerald-700">+{num(r.created)}</span> : <span className="text-slate-300">0</span>),
    },
    {
      key: 'updated', header: 'Updated', align: 'right', cellClass: 'tabular-nums',
      render: (r) => (r.updated > 0 ? <span className="font-semibold text-indigo-600">{num(r.updated)}</span> : <span className="text-slate-300">0</span>),
    },
    {
      key: 'corrections', header: 'Corrections', align: 'right',
      render: (r) => (r.corrections > 0 ? <Badge tone="amber" className="font-semibold">{num(r.corrections)}</Badge> : <span className="text-slate-300">0</span>),
    },
    { key: 'status', header: 'Status', render: (r) => <Badge tone={STATUS_TONE[r.status] || 'slate'}>{r.status}</Badge> },
    {
      key: 'view', header: '', align: 'right',
      render: (r) => (r.id === activeId
        ? <span className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600">Viewing<Icon.ArrowRight className="h-3.5 w-3.5" /></span>
        : <span className="text-xs font-medium text-slate-400">View</span>),
    },
  ];

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Sync Audit"
          subtitle="Your data-change news feed: what each CMD sync brought in (new contracts) and exactly what it changed (field-by-field). Run the sync, then refresh."
        />

        {error && (
          <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <SectionCard title="Sync runs" subtitle="Newest first · click a run to see its change feed below">
          <DataTable
            columns={columns}
            rows={runs}
            rowKey={(r) => r.id}
            onRowClick={(r) => setSelectedId(r.id)}
            highlightRow={(r) => r.status === 'failed' || r.status === 'partial'}
            empty="No sync runs yet. Run the CMD sync (php artisan om:sync --contracts) and refresh."
          />
        </SectionCard>

        {activeId && (
          <div className="space-y-4">
            <RunFeed key={activeId} runId={activeId} />
          </div>
        )}

        {runs.length === 0 && (
          <SectionCard>
            <EmptyState title="No sync runs yet" message="Run the CMD sync (php artisan om:sync --contracts) and refresh to see the change feed here." />
          </SectionCard>
        )}
      </div>
    </div>
  );
}
