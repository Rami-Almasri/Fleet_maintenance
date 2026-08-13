import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { Skeleton, MetricGridSkeleton } from '../components/ui/Skeleton';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import Icon from '../components/ui/Icon';
import FleetStatusCard from '../components/ui/FleetStatusCard';
import MaintenanceCarsCard from '../components/workflow/MaintenanceCarsCard';
import { fmtAgo } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

// The four inspection pillars, each owned by a role. A user sees the queues for the pillars their
// role owns; managers / admins / super-admin see them all. This is the "role-based views" contract.
const SEVERITY_TONE = { high: 'red', medium: 'amber', low: 'slate' };

// How many rows a long queue shows before "Show all" — the page opens on the few most urgent, not 60+.
const DEFAULT_VISIBLE = 5;

// A calm left accent per queue, echoing the Vehicle Status board's tone bars.
const carCell = (car, plate, tone) => (
  <div className="flex min-w-0 items-center gap-3">
    <span className={`h-9 w-1.5 shrink-0 rounded-full ${tone}`} />
    <div className="min-w-0">
      <p className="font-semibold text-slate-900">{car}</p>
      {plate && <p className="truncate text-xs text-slate-400">{plate}</p>}
    </div>
  </div>
);

// Compact "how long undocumented" — hours up to 2 days, then days. `t` is threaded in because this
// is a plain module-level helper, not a component, and its output is words on screen.
const fmtHours = (h, t) => (h == null ? '' : h >= 48 ? t('{n}d', { n: Math.floor(h / 24) }) : t('{n}h', { n: h }));

// A collapsible "at risk" handover queue (check-out or check-in). Cars with MISSING data float to the
// very top marked RED, then the most-overdue first. Only the 5 most urgent show until "Show all".
function HandoverQueue({ title, subtitle, accent, rows, total, critical, loading, onRowClick }) {
  const { t } = useI18n();
  const [expanded, setExpanded] = useState(false);

  const sorted = useMemo(
    () => [...rows].sort(
      (a, b) => (b.missing_data ? 1 : 0) - (a.missing_data ? 1 : 0) || (b.hours || 0) - (a.hours || 0),
    ),
    [rows],
  );
  const visible = expanded ? sorted : sorted.slice(0, DEFAULT_VISIBLE);
  const truncated = total > rows.length ? t(' · first {shown} of {total}', { shown: rows.length, total }) : '';

  const columns = [
    { key: 'car', header: t('Car'), render: (r) => carCell(r.car, r.plate_no, r.missing_data ? 'bg-red-500' : accent) },
    { key: 'customer', header: t('Customer'), cellClass: 'text-slate-700', render: (r) => r.customer || '—' },
    {
      key: 'flags', header: t('Status'), render: (r) => (
        <div className="flex flex-wrap items-center gap-1.5">
          {r.missing_data && <Badge tone="red">{t('Missing data')}</Badge>}
          {r.overdue && <Badge tone="amber">{t('{duration} overdue', { duration: fmtHours(r.hours, t) })}</Badge>}
        </div>
      ),
    },
    {
      key: 'when', header: t('When'), align: 'right', cellClass: 'whitespace-nowrap text-slate-500',
      render: (r) => (r.when ? fmtAgo(r.when) : '—'),
    },
  ];

  return (
    <SectionCard
      title={title}
      subtitle={`${t('{n} undocumented', { n: total })}${subtitle ? ` · ${subtitle}` : ''}${truncated}`}
      actions={<Badge tone={critical > 0 ? 'red' : 'emerald'}>{t('{n} critical', { n: critical })}</Badge>}
    >
      <DataTable
        columns={columns}
        rows={visible}
        rowKey={(r) => r.contract_id}
        loading={loading}
        onRowClick={onRowClick}
        empty={t('Every recent handover is documented.')}
      />
      {sorted.length > DEFAULT_VISIBLE && (
        <div className="border-t border-slate-100 px-5 py-3 text-center">
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            className="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 transition hover:text-indigo-800"
          >
            {expanded ? t('Show less') : t('Show all {n}', { n: sorted.length })}
            <Icon.ArrowRight className={`h-3.5 w-3.5 transition-transform ${expanded ? '-rotate-90' : 'rotate-90'}`} />
          </button>
        </div>
      )}
    </SectionCard>
  );
}

export default function ReadinessDashboard() {
  const toast = useToast();
  const { t } = useI18n();
  const navigate = useNavigate();
  const { can, hasRole, isSuperAdmin } = usePermissions();

  // Role → which pillars this user owns. Managers/admins oversee everything.
  const seeAll = isSuperAdmin || hasRole('admin') || hasRole('manager');
  const isLogistics = seeAll || hasRole('logistics');
  const isSupervisor = seeAll || hasRole('supervisor');
  const isInspector = seeAll || hasRole('inspector');
  const canSetReady = can('maintenance.initiate') || can('maintenance.delegate');
  // Inline cleaning-entry buttons on the Missing Data board. Gated on vehicles.manage — the same
  // permission the backend setChecklistField endpoint enforces (there is no vehicles.edit permission).
  const canEditVehicle = can('vehicles.manage');
  const noQueues = !isLogistics && !isSupervisor && !isInspector;

  const [confirm, setConfirm] = useState(null); // the sign-off row pending confirmation
  const [reason, setReason] = useState('');     // warning-override reason, when advisories are open
  const [busyId, setBusyId] = useState(null);
  const [cleaningBusy, setCleaningBusy] = useState(null); // vehicle_id whose cleaning entry is saving

  const fetcher = useCallback(async () => (await api.get('/readiness')).data.data, []);
  const { data, loading, error, reload, mutate } = useFetch(fetcher, [], {
    refreshInterval: 30000,
    paused: () => confirm !== null,
  });

  const checkouts = data?.checkouts || [];
  const checkins = data?.checkins || [];
  const damage = data?.damage_reviews || [];
  const signoffs = data?.garage_signoffs || [];
  const missingData = data?.missing_data || [];
  const counts = data?.counts || {};

  // Fleet Status — a live snapshot for the headline donut, limited to the three operational
  // states the team works with. The "Unavailable" catch-all (out-of-order / suspended / office-use
  // / sold / disposed / other) is dropped because those cars surface in no list; the donut totals
  // only the active, accounted-for fleet.
  const fleet = data?.fleet_status || {};
  const activeFleet = (fleet.available || 0) + (fleet.rented || 0) + (fleet.maintenance || 0);
  const fleetSegments = [
    { label: t('Available'), value: fleet.available || 0, color: 'green' },
    { label: t('Rented'), value: fleet.rented || 0, color: 'blue' },
    { label: t('Maintenance'), value: fleet.maintenance || 0, color: 'yellow' },
  ];

  const setReady = async (row) => {
    setBusyId(row.vehicle_id);
    try {
      const { data: res } = await api.post(`/vehicle-status/${row.vehicle_id}/set-ready`, {
        override_reason: reason.trim() || undefined,
      });
      toast.success(res.message || t('{car} set to Ready', { car: row.car }));
      setConfirm(null);
      setReason('');
      await reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not set the car to Ready'));
    } finally {
      setBusyId(null);
    }
  };

  const goToCar = (r) => r.vehicle_id && navigate(`/vehicles/${r.vehicle_id}`);

  // Inline Mark Clean / Mark Dirty from the Missing Data board. Both values fill the blank cleaning
  // entry, so the row leaves the board immediately (optimistic), then we reconcile with the server.
  const setCleaning = async (row, value) => {
    if (cleaningBusy) return;
    setCleaningBusy(row.vehicle_id);
    // Optimistic: drop the row from the Missing Data board and decrement its count right away.
    mutate((prev) => {
      if (!prev) return prev;
      const missing_data = (prev.missing_data || []).filter((r) => r.vehicle_id !== row.vehicle_id);
      const counts = { ...(prev.counts || {}) };
      if (typeof counts.missing_data === 'number') counts.missing_data = Math.max(0, counts.missing_data - 1);
      return { ...prev, missing_data, counts };
    });
    try {
      await api.post(`/readiness/vehicle/${row.vehicle_id}/checklist-field`, { field: 'cleaning_status', value });
      toast.success(
        value === 'clean'
          ? t('{car} marked clean', { car: row.car })
          : t('{car} marked dirty', { car: row.car }),
      );
      await reload({ silent: true }); // reconcile totals with the server
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not update the cleaning status'));
      await reload({ silent: true }); // roll the optimistic drop back to the true state
    } finally {
      setCleaningBusy(null);
    }
  };

  // Open readiness advisories on the row being confirmed — drives the override warning + reason prompt.
  const confirmAdvisories = (confirm && confirm.readiness && !confirm.readiness.gate_ready)
    ? (confirm.readiness.gate_blockers || [])
    : [];

  // ── Columns ──────────────────────────────────────────────────────────────────────────────────
  const damageColumns = [
    { key: 'car', header: t('Car'), render: (r) => carCell(r.car, r.plate_no, 'bg-amber-400') },
    {
      key: 'damage', header: t('Damage'), render: (r) => (
        <div className="flex items-center gap-2">
          <Badge tone={SEVERITY_TONE[r.severity] || 'slate'}>{r.severity}</Badge>
          <span className="text-slate-700">{r.damage_type}{r.body_part ? ` · ${r.body_part}` : ''}</span>
        </div>
      ),
    },
    { key: 'inspector', header: t('Flagged by'), cellClass: 'text-slate-600', render: (r) => r.inspector || '—' },
    {
      key: 'flagged_at', header: t('When'), align: 'right', cellClass: 'whitespace-nowrap text-slate-500',
      render: (r) => (r.flagged_at ? fmtAgo(r.flagged_at) : '—'),
    },
  ];

  const signoffColumns = [
    { key: 'car', header: t('Car'), render: (r) => carCell(r.car, r.plate_no, 'bg-violet-400') },
    { key: 'stage', header: t('Stage'), render: (r) => <Badge tone="violet">{r.stage}</Badge> },
    { key: 'inspector', header: t('Inspector'), cellClass: 'text-slate-600', render: (r) => r.inspector || '—' },
    {
      key: 'days_waiting', header: t('Waiting'), align: 'right', cellClass: 'tabular-nums',
      render: (r) => (r.days_waiting == null ? '—' : r.days_waiting === 0 ? t('Today') : t('{n}d', { n: r.days_waiting })),
    },
    {
      key: 'action', header: '', align: 'right',
      render: (r) => {
        if (!canSetReady) return <span className="text-xs text-slate-300">{t('No access')}</span>;
        // Advisory-only: a car with open readiness items is NEVER blocked here. If it has advisories we
        // flag the button amber so the user knows the confirm step will ask them to record a reason.
        const advisories = (r.readiness && !r.readiness.gate_ready) ? (r.readiness.gate_blockers || []) : [];
        return (
          <Button
            variant={advisories.length ? 'warning' : 'success'}
            size="sm"
            loading={busyId === r.vehicle_id}
            onClick={(e) => { e.stopPropagation(); setReason(''); setConfirm(r); }}
          >
            {advisories.length ? `${t('Set to Ready')} ⚠` : t('Set to Ready')}
          </Button>
        );
      },
    },
  ];

  // MANAGER — Missing Data: which cars have a blank Cleaning entry, and who to chase.
  const STATUS_LABEL = (v) => {
    if (!v || v === 'pending') return t('Not entered');
    if (v === 'clean') return t('Clean');
    if (v === 'dirty') return t('Dirty');
    return v.charAt(0).toUpperCase() + v.slice(1);
  };
  const missingColumns = [
    { key: 'car', header: t('Car'), render: (r) => carCell(r.car, r.plate_no, 'bg-red-400') },
    {
      key: 'missing', header: t('Missing'), render: (r) => (
        <div className="flex flex-wrap items-center gap-1.5">
          {(r.missing || []).map((m) => <Badge key={m} tone="red">{m}</Badge>)}
        </div>
      ),
    },
    {
      key: 'cleaning_status', header: t('Cleaning'), cellClass: 'text-slate-600',
      render: (r) => <span className={!r.cleaning_status || r.cleaning_status === 'pending' ? 'text-red-500' : ''}>{STATUS_LABEL(r.cleaning_status)}</span>,
    },
    // Inline set-buttons — only for users who can actually persist the change (vehicles.manage).
    ...(canEditVehicle ? [{
      key: 'actions', header: '', align: 'right',
      render: (r) => (
        <div className="flex shrink-0 items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
          <Button
            variant="success"
            size="sm"
            loading={cleaningBusy === r.vehicle_id}
            onClick={(e) => { e.stopPropagation(); setCleaning(r, 'clean'); }}
          >
            {t('Mark Clean')}
          </Button>
          <Button
            variant="secondary"
            size="sm"
            disabled={cleaningBusy === r.vehicle_id}
            onClick={(e) => { e.stopPropagation(); setCleaning(r, 'dirty'); }}
          >
            {t('Mark Dirty')}
          </Button>
        </div>
      ),
    }] : []),
  ];

  // ── KPI cards (only for the queues this user owns) ─────────────────────────────────────────────
  const kpis = useMemo(() => {
    const c = data?.counts || {};
    const list = [];
    if (isLogistics) {
      list.push({ label: t('Check-outs to document'), value: c.checkouts, icon: <Icon.Truck className="h-5 w-5" />, tone: 'blue' });
      list.push({ label: t('Check-ins to document'), value: c.checkins, icon: <Icon.Route className="h-5 w-5" />, tone: 'cyan' });
    }
    if (isSupervisor) list.push({ label: t('Damage reviews'), value: c.damage_reviews, icon: <Icon.Alert className="h-5 w-5" />, tone: 'amber' });
    if (isInspector) list.push({ label: t('Garage sign-offs'), value: c.garage_signoffs, icon: <Icon.Wrench className="h-5 w-5" />, tone: 'violet' });
    // MANAGER-only: cars missing a Cleaning / Tools-Docs entry — a data-entry accountability alert.
    if (seeAll) list.push({ label: t('Missing data'), value: c.missing_data, icon: <Icon.Flag className="h-5 w-5" />, tone: 'red' });
    return list;
  }, [isLogistics, isSupervisor, isInspector, seeAll, data, t]);

  const liveBadge = (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />{t('Live')}
    </span>
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Readiness')}
          subtitle={t('Fleet status at a glance, then the cars that need attention first — undocumented handovers over 24h and cars missing a data entry. The full lists stay collapsed until you ask for them.')}
        />

        {noQueues ? (
          <SectionCard title={t('Readiness')}>
            <EmptyState
              title={t('No readiness queue for your role')}
              message={t('Check-out / check-in tasks are shown to Logistics, damage reviews to Supervisors, and garage sign-offs to the Inspector.')}
            />
          </SectionCard>
        ) : (
          <>
            {/* ── Fleet Status donut + headline counts — the first thing you see. ── */}
            <div className="grid gap-6 lg:grid-cols-3">
              {loading && !data ? (
                <Skeleton className="h-[440px] w-full rounded-2xl lg:col-span-1" />
              ) : (
                <FleetStatusCard
                  className="lg:col-span-1"
                  title={t('Fleet Status')}
                  centerLabel={t('Active Fleet')}
                  unit={t('cars')}
                  segments={fleetSegments}
                  total={activeFleet}
                  periods={[]}
                  headerRight={liveBadge}
                />
              )}
              <div className="lg:col-span-2">
                {loading && !data ? (
                  <MetricGridSkeleton count={Math.max(kpis.length, 2)} />
                ) : (
                  <MetricGrid cols={2}>
                    {kpis.map((k) => (
                      <MetricCard key={k.label} label={k.label} value={k.value ?? 0} icon={k.icon} tone={k.tone} />
                    ))}
                  </MetricGrid>
                )}
              </div>
            </div>

            {error && !data && (
              <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-600">{error}</div>
            )}

            {/* ── Cars in Maintenance — every car currently in the shop, overdue ones first. Fetches its
                   own live /Fleet/life-status feed. Clicking a row opens the car's profile. ── */}
            <MaintenanceCarsCard onRowClick={goToCar} />

            {/* ── CRITICAL: undocumented handovers, most urgent first, collapsed to the top few. ── */}
            {isLogistics && (
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <HandoverQueue
                  title={t('Check-Out · At risk')}
                  subtitle={t('handed out, no documented handover')}
                  accent="bg-blue-400"
                  rows={checkouts}
                  total={counts.checkouts || 0}
                  critical={counts.checkouts_critical || 0}
                  loading={loading && !data}
                  onRowClick={goToCar}
                />
                <HandoverQueue
                  title={t('Check-In · At risk')}
                  subtitle={t('returned, no documented return')}
                  accent="bg-cyan-400"
                  rows={checkins}
                  total={counts.checkins || 0}
                  critical={counts.checkins_critical || 0}
                  loading={loading && !data}
                  onRowClick={goToCar}
                />
              </div>
            )}

            {/* INSPECTOR — Garage sign-offs. The actionable queue: the guarded "Set to Ready". */}
            {isInspector && (
              <SectionCard
                title={t('Garage Inspection · Pending sign-off')}
                subtitle={t("Repairs back from the garage awaiting the Inspector's re-inspection sign-off. The Pre-Delivery gate guards each release.")}
              >
                <DataTable
                  columns={signoffColumns}
                  rows={signoffs}
                  rowKey={(r) => r.ticket_id}
                  loading={loading && !data}
                  onRowClick={goToCar}
                  empty={t('Nothing waiting for sign-off.')}
                  stickyHeader
                />
              </SectionCard>
            )}

            {/* SUPERVISOR — Damage assessments awaiting approval. */}
            {isSupervisor && (
              <SectionCard
                title={t('Damage Assessment · Awaiting approval')}
                subtitle={t('Damage flagged during inspection that a Supervisor still needs to review and approve.')}
              >
                <DataTable
                  columns={damageColumns}
                  rows={damage}
                  rowKey={(r) => r.id}
                  loading={loading && !data}
                  onRowClick={goToCar}
                  empty={t('No damage waiting for review.')}
                  stickyHeader
                />
              </SectionCard>
            )}

            {/* MANAGER — Missing Data: the full Cleaning data-entry accountability list (highlights also appear above). */}
            {seeAll && (
              <MissingDataBoard rows={missingData} total={counts.missing_data || 0} columns={missingColumns} loading={loading && !data} onRowClick={goToCar} />
            )}
          </>
        )}
      </div>

      <ConfirmDialog
        open={confirm !== null}
        onClose={() => { setConfirm(null); setReason(''); }}
        onConfirm={() => confirm && setReady(confirm)}
        loading={busyId != null}
        title={t('Set car to Ready?')}
        confirmText={t('Set to Ready')}
        variant={confirmAdvisories.length ? 'warning' : 'success'}
        confirmDisabled={confirmAdvisories.length > 0 && !reason.trim()}
        message={
          confirm
            ? t('Sign {car} back into service? This closes its open maintenance and returns it to the Available pool.', { car: confirm.car })
            : ''
        }
      >
        {confirmAdvisories.length > 0 && (
          <div className="mt-3 space-y-3">
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
              <p className="mb-1 text-xs font-semibold text-amber-800">
                {confirmAdvisories.length === 1
                  ? t('This car still has 1 open readiness advisory — you can proceed, but it will be logged as a warning override:')
                  : t('This car still has {n} open readiness advisory items — you can proceed, but it will be logged as a warning override:', { n: confirmAdvisories.length })}
              </p>
              <ul className="space-y-0.5 text-xs text-amber-700">
                {confirmAdvisories.map((b, i) => (
                  <li key={i}>• <span className="font-medium">{b.pillar}</span>: {b.detail}</li>
                ))}
              </ul>
            </div>
            <label className="block">
              <span className="text-xs font-medium text-slate-600">{t('Reason for overriding')} <span className="text-red-500">*</span></span>
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={2}
                autoFocus
                placeholder={t('Why is this car being set Ready despite the open advisories?')}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
              />
            </label>
          </div>
        )}
      </ConfirmDialog>
    </div>
  );
}

// The manager's full Missing-Data accountability list — collapsed to the top few until "Show all".
function MissingDataBoard({ rows, total, columns, loading, onRowClick }) {
  const { t } = useI18n();
  const [expanded, setExpanded] = useState(false);
  const visible = expanded ? rows : rows.slice(0, DEFAULT_VISIBLE);
  const truncated = total > rows.length ? t(' · first {shown} of {total}', { shown: rows.length, total }) : '';

  return (
    <SectionCard
      title={t('Missing Data · Cleaning')}
      subtitle={
        total === 1
          ? `${t('1 car with a blank cleaning entry — the data entry is overdue')}${truncated}`
          : `${t('{n} cars with a blank cleaning entry — the data entry is overdue', { n: total })}${truncated}`
      }
      actions={<Badge tone={total > 0 ? 'red' : 'emerald'}>{t('{n} to enter', { n: total })}</Badge>}
    >
      <DataTable
        columns={columns}
        rows={visible}
        rowKey={(r) => r.vehicle_id}
        loading={loading}
        onRowClick={onRowClick}
        empty={t('Every car has its Cleaning status on record.')}
      />
      {rows.length > DEFAULT_VISIBLE && (
        <div className="border-t border-slate-100 px-5 py-3 text-center">
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            className="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 transition hover:text-indigo-800"
          >
            {expanded ? t('Show less') : t('Show all {n}', { n: rows.length })}
            <Icon.ArrowRight className={`h-3.5 w-3.5 transition-transform ${expanded ? '-rotate-90' : 'rotate-90'}`} />
          </button>
        </div>
      )}
    </SectionCard>
  );
}
