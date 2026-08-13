import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { useToast } from '../components/ui/Toast';
import { Card, PageHeader, EmptyState } from '../components/ui/Misc';
import { Tooltip, InfoTip } from '../components/ui/Tooltip';
import { Skeleton } from '../components/ui/Skeleton';
import { usePageStat } from '../components/PageStat';
import { num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

// Per-status presentation: the left accent bar, the badge, and the label.
// 'fixed' is a client-only state set right after a successful Apply (the server can't tell
// "just fixed" from "always matched" — both are diff 0).
const STATUS = {
  needs_review:     { tone: 'amber',   bar: 'bg-amber-400',   label: 'Needs review' },
  within_tolerance: { tone: 'blue',    bar: 'bg-blue-400',    label: 'Within tolerance' },
  correct:          { tone: 'green',   bar: 'bg-emerald-400', label: 'Matching' },
  no_history:       { tone: 'gray',    bar: 'bg-slate-200',    label: 'No history' },
  fixed:            { tone: 'emerald', bar: 'bg-emerald-400', label: 'Fixed ✓' },
};

// Threshold presets for "only show gaps bigger than…". Mirrors the backend ?min_diff (0 = any gap).
const THRESHOLDS = [0, 100, 500, 1000, 5000];

const km = (v) => (v === null || v === undefined ? '—' : `${num(v)} km`);

// The coloured pill that explains the gap in plain words.
function DiffPill({ diff }) {
  const { t } = useI18n();
  if (diff === null || diff === undefined) {
    return <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-400 ring-1 ring-inset ring-slate-200">{t('No contract history')}</span>;
  }
  if (diff === 0) {
    return <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-600/20">{t('✓ Matches history')}</span>;
  }
  const high = diff > 0;
  const gap = num(Math.abs(diff));
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${
      high ? 'bg-red-50 text-red-600 ring-red-600/20' : 'bg-amber-50 text-amber-600 ring-amber-600/20'
    }`}>
      {high ? '▲' : '▼'} {high ? t('{gap} km too high', { gap }) : t('{gap} km too low', { gap })}
    </span>
  );
}

// One vehicle as a comparison card: System odometer  →  Scanner value, with the gap + action.
function ReconCard({ row, applied, busy, canApply, onApply }) {
  const { t } = useI18n();
  const ov = applied[row.vehicle_id];
  const system = ov ? ov.system_odometer : row.system_odometer;
  const diff = ov ? ov.difference : row.difference;
  const status = ov ? 'fixed' : row.status;
  const meta = STATUS[status] || STATUS.needs_review;
  const actionable = !ov && row.scanner_value != null && diff !== 0;

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft ring-1 ring-slate-900/5 transition hover:-translate-y-0.5 hover:shadow-card">
      <span className={`absolute inset-y-0 start-0 w-1.5 ${meta.bar}`} />
      <div className="p-5 ps-6">
        {/* Identity + status */}
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <Link to={`/vehicles/${row.vehicle_id}`} className="text-lg font-bold text-indigo-600 hover:text-indigo-700">
              {row.plate || `#${row.vehicle_id}`}
            </Link>
            <p className="truncate text-xs text-slate-400">{row.car || '—'}</p>
          </div>
          <Badge tone={meta.tone}>{t(meta.label)}</Badge>
        </div>

        {/* The comparison: suspect system value → trusted scanner value */}
        <div className="mt-4 grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-xl bg-slate-50/70 px-4 py-3 ring-1 ring-inset ring-slate-100">
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('System now')}</p>
            <p className="mt-0.5 text-xl font-bold tabular-nums text-slate-500">{km(system)}</p>
          </div>
          <svg className="h-5 w-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14M13 6l6 6-6 6" />
          </svg>
          <Tooltip
            content={row.baseline != null
              ? t('Start-mileage baseline: {baseline}. The trusted odometer the scanner rebuilt from contract history.', { baseline: km(row.baseline) })
              : t('The trusted odometer the scanner rebuilt from contract history.')}
          >
            <div className="text-end">
              <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Scanner value')}</p>
              <p className="mt-0.5 text-xl font-bold tabular-nums text-emerald-600">{km(row.scanner_value)}</p>
            </div>
          </Tooltip>
        </div>

        {/* Gap + action */}
        <div className="mt-4 flex items-center justify-between gap-3">
          <DiffPill diff={diff} />
          {ov ? (
            <span className="text-xs font-semibold text-emerald-600">{t('Applied ✓')}</span>
          ) : actionable && canApply ? (
            <Tooltip content={t('Overwrite the stored system odometer with the scanner value (the start-mileage baseline rebuilt from contract history).')}>
              <Button size="sm" loading={busy === row.vehicle_id} disabled={!!busy} onClick={() => onApply(row)}>
                {t('Apply baseline')}
              </Button>
            </Tooltip>
          ) : actionable && !canApply ? (
            <span className="text-xs text-slate-300">{t('No permission')}</span>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export default function MileageReconciliation({ embedded = false }) {
  const { can } = usePermissions();
  const canApply = can('vehicles.manage');
  const toast = useToast();
  const { t } = useI18n();

  const [minDiff, setMinDiff] = useState(100);
  const [showAll, setShowAll] = useState(false);
  const [statusFilter, setStatusFilter] = useState(null); // null = no legend filter

  // A legend filter needs every car loaded (so all statuses are present), then we filter
  // client-side; otherwise the threshold chips drive what's fetched.
  const loadAll = showAll || statusFilter !== null;
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Vehicle/mileage-reconciliation', {
      params: { min_diff: minDiff, all: loadAll ? 1 : 0 },
    });
    return data.data;
  }, [minDiff, loadAll]);
  // Pass the deps so useFetch re-runs whenever a filter changes (otherwise it locks onto the
  // first fetcher and clicking a filter does nothing).
  const { data, loading, error, reload } = useFetch(fetcher, [minDiff, loadAll]);

  // Per-row local overrides after an Apply (so a card flips to "Fixed" without a full reload).
  const [applied, setApplied] = useState({}); // vehicle_id -> { system_odometer, difference }
  const [busy, setBusy] = useState(0);        // vehicle_id currently applying

  const apply = async (row) => {
    setBusy(row.vehicle_id);
    try {
      const { data: res } = await api.post(`/Vehicle/${row.vehicle_id}/apply-baseline`);
      const r = res.data;
      setApplied((m) => ({ ...m, [row.vehicle_id]: { system_odometer: r.new, difference: 0 } }));
      toast.success(t('{car}: odometer set to {value}', {
        car: row.plate || `#${row.vehicle_id}`,
        value: km(r.new),
      }));
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not apply the scanner value'));
    } finally {
      setBusy(0);
    }
  };

  const rows = data?.rows || [];
  const s = data?.summary || { needs_review: 0, within_tolerance: 0, matching: 0, no_history: 0, total_gap_km: 0 };
  const active = s.needs_review + s.within_tolerance + s.matching + s.no_history;

  // The funnel segments double as a clickable legend. `status` is the matching row status the
  // backend emits ('matching' is stored as 'correct' on each row).
  const segments = [
    { key: 'needs_review',     status: 'needs_review',     value: s.needs_review,     bar: 'bg-amber-400',   dot: 'bg-amber-400',   label: t('Needs review') },
    { key: 'within_tolerance', status: 'within_tolerance', value: s.within_tolerance, bar: 'bg-blue-400',    dot: 'bg-blue-400',    label: t('Within 100 km'), tip: t('Within tolerance: the system odometer is within ±100 km of the scanner value, so no action is needed.') },
    { key: 'matching',         status: 'correct',          value: s.matching,         bar: 'bg-emerald-400', dot: 'bg-emerald-400', label: t('Already matching') },
    { key: 'no_history',       status: 'no_history',       value: s.no_history,       bar: 'bg-slate-300',    dot: 'bg-slate-300',    label: t('No history') },
  ];

  // When a legend filter is active, narrow the loaded rows to that category (client-side).
  const displayedRows = statusFilter ? rows.filter((r) => r.status === statusFilter) : rows;
  const thresholdsDisabled = showAll || statusFilter !== null;

  usePageStat({
    percent: loading || !active ? null : (s.needs_review / active) * 100,
    label: t('To review'),
    color: 'amber',
    hint: t('{n} of {total} cars need an odometer review', { n: num(s.needs_review), total: num(active) }),
  });

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
    <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        {embedded ? (
          <div className="flex justify-end">{refreshBtn}</div>
        ) : (
          <PageHeader
            title={t('Mileage Reconciliation')}
            subtitle={t('Where the stored odometer disagrees with the mileage the scanner rebuilt from contract history. Review each gap and adopt the trusted value with one click.')}
          >
            {refreshBtn}
          </PageHeader>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Hero summary: the headline number + a visual funnel of the whole fleet */}
        <Card className="bg-gradient-to-br from-white to-indigo-50/40 p-6">
          <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-4xl font-bold tracking-tight text-amber-600 tabular-nums">{num(s.needs_review)}</p>
              <p className="mt-1 text-sm font-medium text-slate-600">
                {s.needs_review === 1 ? t('car needs a mileage review') : t('cars need a mileage review')}
                <span className="text-slate-400"> {t('· of {total} active', { total: num(active) })}</span>
              </p>
            </div>
            {s.total_gap_km > 0 && (
              <div className="text-start sm:text-end">
                <p className="text-2xl font-bold tracking-tight text-slate-900 tabular-nums">{km(s.total_gap_km)}</p>
                <p className="text-xs text-slate-400">{t('total odometer gap to reconcile')}</p>
              </div>
            )}
          </div>

          {/* Segmented funnel bar — each block is clickable to filter to that category */}
          <div className="mt-5 flex h-3 overflow-hidden rounded-full bg-slate-100">
            {segments.map((seg) => seg.value > 0 && (
              <button
                key={seg.key}
                type="button"
                onClick={() => setStatusFilter((cur) => (cur === seg.status ? null : seg.status))}
                style={{ flexGrow: seg.value }}
                className={`${seg.bar} min-w-[8px] transition hover:opacity-80 ${
                  statusFilter && statusFilter !== seg.status ? 'opacity-30' : ''
                }`}
                title={t('{label}: {count} — click to filter', { label: seg.label, count: num(seg.value) })}
              />
            ))}
          </div>

          {/* Legend — clickable filter chips. Click again (or "Show all") to clear. */}
          <div className="mt-4 flex flex-wrap items-center gap-2">
            {segments.map((seg) => {
              const isActive = statusFilter === seg.status;
              return (
                <span key={seg.key} className="inline-flex items-center gap-1">
                  <button
                    type="button"
                    onClick={() => setStatusFilter(isActive ? null : seg.status)}
                    disabled={seg.value === 0}
                    className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm transition disabled:cursor-not-allowed disabled:opacity-40 ${
                      isActive
                        ? 'bg-white shadow-sm ring-2 ring-indigo-400'
                        : 'ring-1 ring-transparent hover:bg-white/70 hover:ring-slate-200'
                    }`}
                  >
                    <span className={`h-2.5 w-2.5 rounded-full ${seg.dot}`} />
                    <span className="font-bold tabular-nums text-slate-800">{num(seg.value)}</span>
                    <span className="text-slate-500">{seg.label}</span>
                  </button>
                  {seg.tip && <InfoTip content={seg.tip} />}
                </span>
              );
            })}
            {statusFilter && (
              <button
                type="button"
                onClick={() => setStatusFilter(null)}
                className="ms-1 inline-flex items-center gap-1 rounded-full px-2.5 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50"
              >
                {t('Clear filter ✕')}
              </button>
            )}
          </div>
        </Card>

        {/* Controls: gap threshold + full-fleet audit toggle */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="me-1 text-xs font-medium uppercase tracking-wide text-slate-500">{t('Show gaps')}</span>
            {THRESHOLDS.map((v) => (
              <button
                key={v}
                onClick={() => setMinDiff(v)}
                disabled={thresholdsDisabled}
                className={`rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition disabled:opacity-40 ${
                  minDiff === v && !thresholdsDisabled
                    ? 'bg-indigo-600 text-white ring-indigo-600 shadow-sm'
                    : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                }`}
              >
                {v === 0 ? t('Any gap') : t('> {km} km', { km: num(v) })}
              </button>
            ))}
          </div>
          <label className="ms-auto flex cursor-pointer select-none items-center gap-2 text-xs font-medium text-slate-600">
            <input
              type="checkbox"
              checked={showAll}
              onChange={(e) => setShowAll(e.target.checked)}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            {t('Show every car (incl. matching & no-history)')}
          </label>
        </div>

        {/* The list */}
        {loading ? (
          <div className="grid gap-4 md:grid-cols-2">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="relative overflow-hidden rounded-2xl border border-slate-200/70 bg-white p-5 ps-6 shadow-soft ring-1 ring-slate-900/5">
                <span className="absolute inset-y-0 start-0 w-1.5 bg-slate-100" />
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 space-y-2">
                    <Skeleton className="h-5 w-24" />
                    <Skeleton className="h-3 w-32" />
                  </div>
                  <Skeleton className="h-5 w-20 rounded-full" />
                </div>
                <Skeleton className="mt-4 h-16 w-full rounded-xl" />
                <div className="mt-4 flex items-center justify-between gap-3">
                  <Skeleton className="h-6 w-32 rounded-full" />
                  <Skeleton className="h-7 w-24 rounded-lg" />
                </div>
              </div>
            ))}
          </div>
        ) : displayedRows.length === 0 ? (
          <Card>
            <EmptyState
              title={t('Nothing to reconcile 🎉')}
              message={statusFilter
                ? t('No cars in this category.')
                : showAll
                  ? t('No active cars found.')
                  : t('No cars have a gap over {km} km between the stored odometer and the scanner\'s value.', { km: num(minDiff) })}
            />
          </Card>
        ) : (
          <>
            <div className="grid gap-4 md:grid-cols-2">
              {displayedRows.map((row) => (
                <ReconCard
                  key={row.vehicle_id}
                  row={row}
                  applied={applied}
                  busy={busy}
                  canApply={canApply}
                  onApply={apply}
                />
              ))}
            </div>
            <p className="px-1 text-xs text-slate-400">
              {displayedRows.length === 1
                ? t('Showing 1 car. “Apply baseline” sets the stored odometer to the scanner value.')
                : t('Showing {n} cars. “Apply baseline” sets the stored odometer to the scanner value.', { n: num(displayedRows.length) })}
            </p>
          </>
        )}
    </div>
  );

  if (embedded) return inner;
  return <div className="py-8">{inner}</div>;
}
