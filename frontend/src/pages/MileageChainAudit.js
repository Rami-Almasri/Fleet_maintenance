import { useCallback, useMemo, useState } from 'react';
import Pagination from '../components/ui/Pagination';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import Badge, { ContractTypeBadge } from '../components/ui/Badge';
import Button from '../components/ui/Button';
import DataTable, { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { Tooltip } from '../components/ui/Tooltip';
import { useToast } from '../components/ui/Toast';
import { Card, PageHeader, EmptyState } from '../components/ui/Misc';
import { useI18n } from '../i18n/I18nContext';
import { num, fmtDate } from '../lib/format';

// Per-status presentation for a single handoff link. `label` is an English phrase key — resolve it
// through t() at render time.
const STATUS = {
  match:    { tone: 'green', label: 'Match' },
  mismatch: { tone: 'red',   label: 'Mismatch' },
  missing:  { tone: 'amber', label: 'Missing' },
};

// The semantic funnel. "Errors" = everything that isn't a clean match (mismatch + missing).
const FILTERS = [
  { key: 'all',     label: 'All' },
  { key: 'matches', label: 'Matches only' },
  { key: 'errors',  label: 'Errors only' },
];

// A reading is "real" when it's above the no-reading placeholder (null / 0 / 1).
const isReal = (v) => v !== null && v !== undefined && v > 1;

// One odometer reading: the number, a "missing" marker, and a badge when it's a manual fix/note.
function ReadingCell({ value, raw, overridden, note }) {
  const { t } = useI18n();
  const correctedBase = isReal(raw) ? t('Corrected from {km} km', { km: num(raw) }) : t('Corrected');
  const correctedTip = note ? `${correctedBase} · ${note}` : correctedBase;
  return (
    <span className="inline-flex items-center gap-1.5 tabular-nums">
      {isReal(value) ? (
        <span className="font-semibold text-slate-700">{num(value)}</span>
      ) : (
        <span className="font-medium text-amber-500">{t('— no reading')}</span>
      )}
      {overridden && (
        <Tooltip content={correctedTip}>
          <span className="cursor-help rounded bg-indigo-100 px-1 text-[10px] font-bold uppercase tracking-wide text-indigo-600">{t('fix')}</span>
        </Tooltip>
      )}
      {!overridden && note && (
        <Tooltip content={note}>
          <span className="cursor-help rounded bg-slate-100 px-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">{t('note')}</span>
        </Tooltip>
      )}
    </span>
  );
}

// The signed gap between the previous end and the next start.
function DeltaCell({ delta, status }) {
  if (status === 'missing' || delta === null || delta === undefined) return <span className="text-slate-300">—</span>;
  if (delta === 0) return <span className="font-semibold text-emerald-600">0</span>;
  const high = delta > 0;
  return (
    <span className={`font-semibold ${high ? 'text-red-600' : 'text-amber-600'}`}>
      {high ? '▲ +' : '▼ '}{num(Math.abs(delta))} km
    </span>
  );
}

// A contract reference: number + type badge + the relevant date.
function ContractRef({ no, type, date, dateLabel }) {
  return (
    <div className="leading-tight">
      <span className="inline-flex items-center gap-1.5">
        <span className="font-medium text-slate-700">#{no || '—'}</span>
        {type && <ContractTypeBadge type={type} />}
      </span>
      <div className="text-[11px] text-slate-400">{dateLabel}: {date ? fmtDate(date) : '—'}</div>
    </div>
  );
}

// ── Quick Fix modal: correct (or note) either reading of one broken handoff ─────────────
function QuickFixModal({ vehicleLabel, link, onClose, onSaved }) {
  const toast = useToast();
  const { t } = useI18n();
  const [busy, setBusy] = useState(null); // field currently saving/reverting

  const rows = [
    {
      field: 'in_milage', label: t('End of previous contract'), contractId: link.from_contract_id,
      contractNo: link.from_contract_no, value: link.end_mileage, raw: link.end_raw,
      overridden: link.end_overridden, note: link.end_note,
    },
    {
      field: 'out_milage', label: t('Start of next contract'), contractId: link.to_contract_id,
      contractNo: link.to_contract_no, value: link.start_mileage, raw: link.start_raw,
      overridden: link.start_overridden, note: link.start_note,
    },
  ];

  // Editable state per field, seeded from the current effective value/note.
  const [form, setForm] = useState(() =>
    Object.fromEntries(rows.map((r) => [r.field, { value: isReal(r.value) ? String(r.value) : '', note: r.note || '' }])));

  const set = (field, patch) => setForm((f) => ({ ...f, [field]: { ...f[field], ...patch } }));

  const save = async (row) => {
    const entry = form[row.field];
    const trimmed = entry.value.trim();
    const value = trimmed === '' ? null : parseInt(trimmed, 10);
    if (trimmed !== '' && (Number.isNaN(value) || value < 0)) {
      toast.error(t('Enter a whole number of kilometres (or leave blank for a note only).'));
      return;
    }
    setBusy(row.field);
    try {
      await api.post(`/MileageChain/audit/${row.contractId}/override`, {
        field: row.field, value, note: entry.note.trim() || null,
      });
      toast.success(t('Saved correction on #{no}.', { no: row.contractNo }));
      onSaved();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not save the correction.'));
    } finally {
      setBusy(null);
    }
  };

  const revert = async (row) => {
    setBusy(row.field);
    try {
      await api.delete(`/MileageChain/audit/${row.contractId}/override`, { data: { field: row.field } });
      toast.success(t('Reverted #{no} to the synced reading.', { no: row.contractNo }));
      onSaved();
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not revert the correction.'));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={t('Quick Fix — {vehicle}', { vehicle: vehicleLabel })} className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div>
            <h3 className="text-base font-semibold text-slate-900">{t('Quick Fix — {vehicle}', { vehicle: vehicleLabel })}</h3>
            <p className="mt-0.5 text-xs text-slate-400">
              {t("Corrections are an overlay; they don't change the synced contract and survive the next sync.")}
            </p>
          </div>
          <button onClick={onClose} aria-label={t('Close')} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div className="space-y-4 px-5 py-4">
          {rows.map((row) => (
            <div key={row.field} className="rounded-xl border border-slate-200 p-4">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-semibold text-slate-700">{row.label}</span>
                <span className="text-xs text-slate-400">#{row.contractNo}</span>
              </div>
              <div className="mt-1 text-[11px] text-slate-400">
                {t('Synced reading: {value}', { value: isReal(row.raw) ? `${num(row.raw)} km` : t('none') })}
                {row.overridden && <span className="ms-1 font-semibold text-indigo-600">{t('· currently corrected')}</span>}
              </div>

              <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_2fr]">
                <label className="block">
                  <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Corrected km')}</span>
                  <input
                    type="number" min="0" inputMode="numeric"
                    value={form[row.field].value}
                    onChange={(e) => set(row.field, { value: e.target.value })}
                    placeholder={t('blank = note only')}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                  />
                </label>
                <label className="block">
                  <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Note')}</span>
                  <input
                    type="text" maxLength={1000}
                    value={form[row.field].note}
                    onChange={(e) => set(row.field, { note: e.target.value })}
                    placeholder={t('e.g. typo — extra digit at handover')}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                  />
                </label>
              </div>

              <div className="mt-3 flex items-center justify-end gap-2">
                {row.overridden && (
                  <Button variant="ghost" size="sm" disabled={busy === row.field} onClick={() => revert(row)}>
                    {t('Revert')}
                  </Button>
                )}
                <Button size="sm" loading={busy === row.field} onClick={() => save(row)}>
                  {t('Save')}
                </Button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// Handoffs shown per car before paging — keeps each vehicle's table short and the page light.
const PER_CAR = 5;

// One vehicle's chain, with its own local pagination so a long history pages 5 handoffs at a time.
function VehicleChainCard({ vehicle, buildColumns }) {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const links = vehicle.links;
  const label = vehicle.plate || t('Vehicle #{id}', { id: vehicle.vehicle_id });
  const breaks = links.filter((l) => l.status !== 'match').length;
  const pageCount = Math.max(1, Math.ceil(links.length / PER_CAR));
  const safePage = Math.min(page, pageCount);
  const slice = links.slice((safePage - 1) * PER_CAR, safePage * PER_CAR);

  return (
    <SectionCard
      title={label}
      subtitle={vehicle.car || '—'}
      actions={breaks > 0
        ? <Badge tone="red">{breaks === 1 ? t('1 break') : t('{n} breaks', { n: breaks })}</Badge>
        : <Badge tone="green">{t('Clean chain')}</Badge>}
    >
      <DataTable
        columns={buildColumns(label)}
        rows={slice}
        rowKey={(l) => `${l.from_contract_id}-${l.to_contract_id}`}
        highlightRow={(l) => l.status !== 'match'}
        empty={t('No handoffs.')}
      />
      {links.length > PER_CAR && (
        <Pagination
          page={safePage}
          pageCount={pageCount}
          total={links.length}
          pageSize={PER_CAR}
          onPage={setPage}
        />
      )}
    </SectionCard>
  );
}

export default function MileageChainAudit({ embedded = false }) {
  const { can } = usePermissions();
  const { t } = useI18n();
  const canManage = can('contracts.manage');

  const [filter, setFilter] = useState('all');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null); // { vehicleLabel, link }

  // Server-side: each request returns one filtered, paginated page of vehicles + the fleet-wide
  // summary. Filtering/paging on the backend keeps the payload small and the page fast.
  const fetcher = useCallback(
    async () => (await api.get('/MileageChain/audit', { params: { page, filter } })).data.data,
    [page, filter],
  );
  const { data, loading, error, reload } = useFetch(fetcher, [page, filter]);

  // Switching the funnel resets to page 1 (the new filter has its own page count).
  const changeFilter = (key) => { setFilter(key); setPage(1); };

  const summary = data?.summary || { vehicles: 0, links: 0, matches: 0, mismatches: 0, missing: 0 };
  const pagination = data?.pagination || { page: 1, per_page: 20, total: 0, total_pages: 1 };
  // Already filtered + paginated by the backend — render as-is.
  const visible = useMemo(() => data?.vehicles || [], [data]);

  // Columns are built per vehicle so the Quick Fix action can title its modal with the car.
  const buildColumns = (vehicleLabel) => [
    { key: 'prev', header: t('Previous contract'), render: (l) => (
      <ContractRef no={l.from_contract_no} type={l.from_type} date={l.from_in_date} dateLabel={t('Returned')} />
    ) },
    { key: 'end', header: t('End km'), align: 'right', render: (l) => (
      <ReadingCell value={l.end_mileage} raw={l.end_raw} overridden={l.end_overridden} note={l.end_note} />
    ) },
    { key: 'arrow', header: '', align: 'center', cellClass: 'text-slate-300 rtl:-scale-x-100', render: () => '→' },
    { key: 'next', header: t('Next contract'), render: (l) => (
      <ContractRef no={l.to_contract_no} type={l.to_type} date={l.to_out_date} dateLabel={t('Picked up')} />
    ) },
    { key: 'start', header: t('Start km'), align: 'right', render: (l) => (
      <ReadingCell value={l.start_mileage} raw={l.start_raw} overridden={l.start_overridden} note={l.start_note} />
    ) },
    { key: 'delta', header: 'Δ', align: 'right', cellClass: 'tabular-nums', render: (l) => <DeltaCell delta={l.delta} status={l.status} /> },
    { key: 'status', header: t('Status'), render: (l) => <Badge tone={STATUS[l.status].tone}>{t(STATUS[l.status].label)}</Badge> },
    { key: 'fix', header: '', align: 'right', render: (l) => (
      canManage
        ? <button onClick={() => setEditing({ vehicleLabel, link: l })} className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">{t('Quick Fix')}</button>
        : null
    ) },
  ];

  const refreshBtn = (
    <button
      onClick={reload}
      className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-slate-700"
    >
      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v6h6M20 20v-6h-6M20 9A8 8 0 0 0 6.3 5.3L4 8m16 8-2.3 2.7A8 8 0 0 1 4 15" />
      </svg>
      {t('Refresh')}
    </button>
  );

  const inner = (
    <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        {embedded ? (
          <div className="flex justify-end">{refreshBtn}</div>
        ) : (
          <PageHeader
            title={t('Mileage Chain Audit')}
            subtitle={t("Verifies the odometer hands off cleanly between a car's consecutive contracts: the mileage one contract recorded on return should equal the next contract's pickup reading. Fix a mis-typed reading with a non-destructive Quick Fix.")}
          >
            {refreshBtn}
          </PageHeader>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <MetricGrid cols={5}>
          <MetricCard label={t('Vehicles')} value={num(summary.vehicles)} tone="slate" hint={t('cars with a chain to audit')} loading={loading} onClick={() => changeFilter('all')} />
          <MetricCard label={t('Handoffs')} value={num(summary.links)} tone="blue" hint={t('consecutive contract pairs')} loading={loading} onClick={() => changeFilter('all')} />
          <MetricCard label={t('Matches')} value={num(summary.matches)} tone="emerald" hint={t('clean handoffs')} loading={loading} onClick={() => changeFilter('matches')} />
          <MetricCard label={t('Mismatches')} value={num(summary.mismatches)} tone="red" hint={t('readings disagree')} loading={loading} onClick={() => changeFilter('errors')} />
          <MetricCard label={t('Missing')} value={num(summary.missing)} tone="amber" hint={t('a reading is blank')} loading={loading} onClick={() => changeFilter('errors')} />
        </MetricGrid>

        {/* Semantic funnel */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="me-1 text-xs font-medium uppercase tracking-wide text-slate-500">{t('Show')}</span>
          {FILTERS.map((f) => (
            <button
              key={f.key}
              onClick={() => changeFilter(f.key)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition ${
                filter === f.key
                  ? 'bg-indigo-600 text-white ring-indigo-600 shadow-sm'
                  : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
              }`}
            >
              {t(f.label)}
            </button>
          ))}
        </div>

        {/* Per-vehicle chains */}
        {loading ? (
          <SectionCard title={t('Loading chains…')}>
            <DataTable columns={buildColumns('')} rows={[]} loading skeletonRows={6} />
          </SectionCard>
        ) : visible.length === 0 ? (
          <Card>
            <EmptyState
              title={filter === 'errors' ? t('No broken handoffs 🎉') : t('Nothing to show')}
              message={filter === 'errors'
                ? t('Every consecutive contract hands the odometer over cleanly.')
                : filter === 'matches'
                  ? t('No clean handoffs in the current data.')
                  : t('No vehicles have two or more contracts to chain yet.')}
            />
          </Card>
        ) : (
          <>
            {visible.map((v) => (
              <VehicleChainCard key={v.vehicle_id} vehicle={v} buildColumns={buildColumns} />
            ))}

            {pagination.total_pages > 1 && (
              <Card className="p-0">
                <Pagination
                  page={pagination.page}
                  pageCount={pagination.total_pages}
                  total={pagination.total}
                  pageSize={pagination.per_page}
                  onPage={setPage}
                />
              </Card>
            )}
          </>
        )}
    </div>
  );

  const modal = editing && (
    <QuickFixModal
      vehicleLabel={editing.vehicleLabel}
      link={editing.link}
      onClose={() => setEditing(null)}
      onSaved={() => { setEditing(null); reload(); }}
    />
  );

  if (embedded) return (<>{inner}{modal}</>);

  return (
    <div className="py-8">
      {inner}
      {modal}
    </div>
  );
}
