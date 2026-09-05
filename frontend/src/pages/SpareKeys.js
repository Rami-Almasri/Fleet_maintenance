import { useCallback, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import DataTable, { SectionCard } from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import { EmptyState, ErrorState, SearchInput } from '../components/ui/Misc';
import { aed2, fmtDate, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

/**
 * Spare Key Requirements — the outstanding-work board for the people who own this process.
 *
 * It is a TAB on the Parts hub rather than a page of its own, because a spare key is a part and the
 * people working this board are the people working the one beside it. The stages it counts are the
 * stages of the ordinary procurement chain; nothing here is a second workflow.
 *
 * The one rule worth stating out loud: a REJECTED requirement is counted as outstanding. A refused
 * purchase ends a buy, not a need — the car still has no spare key, and a board that quietly filed
 * it under "done" would be the fastest way to lose one.
 */

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const STATUS_TONE = {
  required: 'slate',
  purchase_requested: 'blue',
  approved: 'cyan',
  ordered: 'violet',
  received: 'amber',
  completed: 'green',
  rejected: 'red',
  cancelled: 'gray',
};

// English doubles as the i18n phrase key.
const STATUS_LABEL = {
  required: 'Required',
  purchase_requested: 'Purchase requested',
  approved: 'Approved',
  ordered: 'Ordered',
  received: 'Awaiting the rest',
  completed: 'Completed',
  rejected: 'Purchase rejected',
  cancelled: 'Cancelled',
};

const REASON_LABEL = {
  missing: 'No spare key',
  additional: 'Additional key',
  lost: 'Key lost',
  replacement: 'Replacement',
  other: 'Other',
};

/**
 * The same reason, said in the past tense once the requirement is closed.
 *
 * "Why" is the reason the requirement was RAISED, not a statement about the car today — but printed
 * flat beside a green "Completed" badge, "No spare key" reads as a contradiction of it. A closed row
 * therefore says what WAS true when somebody wrote the car down.
 */
const CLOSED_REASON_LABEL = {
  missing: 'Was missing a spare key',
  additional: 'An extra key was wanted',
  lost: 'A key had been lost',
  replacement: 'A key needed replacing',
  other: 'Other',
};

const reasonLabel = (row, t) => {
  const map = row.is_open ? REASON_LABEL : CLOSED_REASON_LABEL;
  return map[row.reason_code] ? t(map[row.reason_code]) : row.reason_code;
};

/** The stages a manager scans first — the ones that mean somebody has to do something. */
const OUTSTANDING = ['required', 'purchase_requested', 'approved', 'ordered', 'received', 'rejected'];

export default function SpareKeys() {
  const { t } = useI18n();
  const { can } = usePermissions();
  const toast = useToast();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState('');
  const [busyId, setBusyId] = useState(null);

  const status = params.get('key_status') || '';

  const fetcher = useCallback(
    async () => payload(await api.get('/spare-keys/board', { params: { status: status || undefined } })),
    [status]
  );
  const { data, loading, error, reload } = useFetch(fetcher, [status], { refreshInterval: 60000 });

  const counts = data?.counts || {};
  const rows = useMemo(() => data?.rows || [], [data]);

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return rows;
    return rows.filter((r) =>
      [r.vehicle?.plate_display, r.vehicle?.plate_no, r.vehicle?.car, r.requested_by_name, r.notes]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(needle))
    );
  }, [rows, q]);

  const setStatus = (next) => {
    const p = new URLSearchParams(params);
    if (next) p.set('key_status', next); else p.delete('key_status');
    setParams(p, { replace: true });
  };

  const advance = async (row, path, body, message) => {
    setBusyId(row.id);
    try {
      await api.post(`/spare-keys/${row.id}/${path}`, body);
      toast.success(message);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || t('That did not work.'));
    } finally {
      setBusyId(null);
    }
  };

  const columns = useMemo(() => [
    {
      key: 'vehicle',
      header: t('Vehicle'),
      render: (r) => (
        <div className="min-w-0">
          <Link to={`/vehicles/${r.vehicle_id}?tab=components`} className="font-semibold text-indigo-600 hover:underline">
            {r.vehicle?.plate_display || r.vehicle?.plate_no || `#${r.vehicle_id}`}
          </Link>
          <div className="truncate text-xs text-slate-400">
            {[r.vehicle?.car, r.vehicle?.year, r.vehicle?.color].filter(Boolean).join(' · ')}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('Stage'),
      render: (r) => (
        <div>
          <Badge tone={STATUS_TONE[r.status] || 'gray'}>
            {STATUS_LABEL[r.status] ? t(STATUS_LABEL[r.status]) : r.status}
          </Badge>
          {r.quantity > 1 && (
            <div className="mt-0.5 text-xs text-slate-400">
              {t('{n} of {total} received', { n: num(r.received_quantity), total: num(r.quantity) })}
            </div>
          )}
        </div>
      ),
    },
    {
      key: 'reason',
      header: t('Why'),
      render: (r) => (
        <div className="min-w-0">
          <div className="text-sm text-slate-700">{reasonLabel(r, t)}</div>
          {r.source === 'sheet_import' && (
            <Badge tone="gray">{t('From the sheet')}</Badge>
          )}
        </div>
      ),
    },
    {
      key: 'raised',
      header: t('Raised'),
      render: (r) => (
        <div>
          {/* An imported row has the SHEET's dates and no app timestamp. Labelling which is which is
              what stops a spreadsheet date being read as something this system recorded. */}
          <div>{r.started_on ? fmtDate(r.started_on) : fmtDate(r.requested_at)}</div>
          <div className="text-xs text-slate-400">{r.requested_by_name || '—'}</div>
        </div>
      ),
    },
    {
      key: 'chain',
      header: t('Procurement'),
      render: (r) => {
        if (r.purchase) {
          return (
            <div className="text-xs text-slate-500">
              <div className="font-medium text-slate-700">{r.purchase.supplier || t('Supplier not named')}</div>
              <div>{aed2(r.purchase.price)}{r.purchase.delivered_at ? ` · ${t('received')}` : ` · ${t('not yet received')}`}</div>
            </div>
          );
        }
        if (r.purchase_request) {
          return (
            <div className="text-xs text-slate-500">
              <div className="font-medium text-slate-700">{t('Request #{id}', { id: r.purchase_request.id })}</div>
              <div>{r.purchase_request.approved_at ? t('approved by {who}', { who: r.purchase_request.approved_by_name || '—' }) : t('awaiting approval')}</div>
            </div>
          );
        }
        if ((r.rejected_requests || []).length > 0) {
          return <span className="text-xs text-rose-600">{t('{n} refused attempt(s)', { n: num(r.rejected_requests.length) })}</span>;
        }
        // An imported row has no procurement chain and never will: the sheet recorded the need and
        // its dates only. Saying "nothing requested YET" there implies somebody still has to act.
        if (r.source === 'sheet_import') {
          return <span className="text-xs text-slate-400">{t('Not recorded on the sheet')}</span>;
        }
        return <span className="text-xs text-slate-400">{t('Nothing requested yet')}</span>;
      },
    },
    {
      key: 'action',
      header: '',
      align: 'right',
      render: (r) => {
        if (!r.is_open) return null;
        if (['required', 'rejected'].includes(r.status) && can('parts.request')) {
          return (
            <Button
              size="sm"
              disabled={busyId === r.id}
              onClick={() => advance(r, 'purchase-request', {},
                t('Purchase request created — it is on the parts board awaiting approval.'))}
            >
              {r.status === 'rejected' ? t('Request again') : t('Create Purchase Request')}
            </Button>
          );
        }
        if (['ordered', 'received'].includes(r.status) && can('parts.purchase')) {
          return (
            <Button
              size="sm"
              disabled={busyId === r.id}
              onClick={() => advance(r, 'receive', {}, t('Spare key received and registered to the vehicle.'))}
            >
              {t('Mark Received')}
            </Button>
          );
        }
        // Approval and purchase happen on the parts board — this deliberately links there rather
        // than duplicating the money gate on a second screen.
        if (r.purchase_request) {
          return (
            <Link to={`/parts?request=${r.purchase_request.id}`} className="text-sm font-medium text-indigo-600 hover:underline">
              {t('Open on the parts board')}
            </Link>
          );
        }
        return null;
      },
    },
  ], [t, can, busyId]); // eslint-disable-line react-hooks/exhaustive-deps

  if (error) return <ErrorState message={t('Could not load the spare-key board.')} onRetry={reload} />;

  return (
    <div className="space-y-6">
      {loading && !data ? (
        <MetricGridSkeleton count={4} />
      ) : (
        <MetricGrid cols={4}>
          <MetricCard
            label={t('Outstanding')}
            value={num(data?.open_total ?? 0)}
            tone={(data?.open_total ?? 0) > 0 ? 'amber' : undefined}
            icon={<Icon.Alert className="h-5 w-5" />}
            hint={t('Cars still owed a spare key — a refused purchase counts, because the car still has none')}
          />
          <MetricCard
            label={t('Required')}
            value={num(counts.required ?? 0)}
            icon={<Icon.Plus className="h-5 w-5" />}
            hint={t('Nothing has been requested for these yet')}
          />
          <MetricCard
            label={t('In procurement')}
            value={num((counts.purchase_requested ?? 0) + (counts.approved ?? 0) + (counts.ordered ?? 0))}
            icon={<Icon.Coins className="h-5 w-5" />}
            hint={t('Requested, approved or bought — waiting on the chain')}
          />
          <MetricCard
            label={t('Completed')}
            value={num(counts.completed ?? 0)}
            icon={<Icon.Check className="h-5 w-5" />}
            hint={t('Every key asked for is now on the car')}
          />
        </MetricGrid>
      )}

      <SectionCard
        title={t('Spare Key Requirements')}
        subtitle={data?.data_origin}
        actions={
          <div className="flex flex-wrap items-center justify-end gap-2">
            <SearchInput className="w-full sm:w-56" value={q} onChange={setQ} placeholder={t('Plate, car, person…')} />
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
              aria-label={t('Filter by stage')}
            >
              <option value="">{t('All stages')}</option>
              <option value={OUTSTANDING.join(',')}>{t('Outstanding only')}</option>
              {Object.entries(STATUS_LABEL).map(([k, v]) => (
                <option key={k} value={k}>{`${t(v)} (${num(counts[k] ?? 0)})`}</option>
              ))}
            </select>
          </div>
        }
      >
        {!loading && filtered.length === 0 ? (
          <EmptyState
            title={t('Nothing here')}
            message={t('No spare-key requirement matches this filter. Requirements are raised from a vehicle’s Components tab.')}
          />
        ) : (
          <DataTable
            columns={columns}
            rows={filtered}
            rowKey={(r) => r.id}
            loading={loading && !data}
            highlightRow={(r) => r.status === 'rejected' || r.status === 'required'}
            empty={t('Nothing matches that search.')}
            stickyHeader
          />
        )}
      </SectionCard>
    </div>
  );
}
