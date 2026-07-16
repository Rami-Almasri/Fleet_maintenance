import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import MaintenanceTimelineDrawer from '../components/vehicle-status/MaintenanceTimelineDrawer';
import { num, fmtSeconds } from '../lib/format';

// Reporting windows for the History mode. "Live now" is the default all-day board (cars in the shop
// right now); the rest look back over closed + in-flight journeys so a manager can review "last month".
const PERIODS = [
  { key: 'live', label: 'Live now' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_month', label: 'Last month' },
  { key: 'last_3_months', label: 'Last 3 months' },
];

// Short absolute date "05 Jul" / "05 Jul 2026" from an ISO string, for the history list.
const shortDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return '—';
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

// This board is maintenance-only — every car currently inside the workshop workflow, nothing else.
// Cars in the "maintenance" bucket are grouped into the four stages a follow-up manager cares about:
// who's waiting on the team, who's on the road to/from the garage, who's under repair, who's ready to sign off.
const STAGE_GROUPS = [
  { key: 'all', label: 'All' },
  { key: 'action', label: 'Needs Action', stages: ['inspection_requested', 'under_diagnosis', 'awaiting_dispatch', 'awaiting_pickup', 'reinspection_failed', 'grounded'] },
  { key: 'transit', label: 'In Transit', stages: ['to_garage'] },
  { key: 'garage', label: 'In Garage', stages: ['in_garage', 'in_garage_noticket'] },
  { key: 'return', label: 'Returning', stages: ['ready_for_pickup', 'in_our_park'] },
  { key: 'signoff', label: 'Ready to Sign-off', stages: ['repair_review', 'reinspection'] },
];

// A car sitting in the same stage this long or longer is flagged as overdue (needs a nudge).
const SLA_DAYS = 3;

const inGroup = (row, key) => {
  const g = STAGE_GROUPS.find((x) => x.key === key);
  return !g || !g.stages ? true : g.stages.includes(row.stage);
};

const days = (v) => (v == null ? '—' : v === 0 ? 'Today' : `${num(v)}d`);

// A calm per-status left accent (rounded pill) instead of washing the whole row in one colour.
const TONE_BAR = {
  red: 'bg-red-400',
  amber: 'bg-amber-400',
  green: 'bg-emerald-400',
  blue: 'bg-blue-400',
  violet: 'bg-violet-400',
  gray: 'bg-slate-300',
  slate: 'bg-slate-300',
};

export default function VehicleStatusDashboard() {
  // Reporting window + the car whose history drawer is open.
  const [period, setPeriod] = useState('live');
  const [selected, setSelected] = useState(null); // { id, title, subtitle } for the timeline drawer

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/vehicle-status');
    return data.data; // { rows, counts }
  }, []);
  // A screen the team keeps open all day → silently self-refresh.
  const { data, loading, error } = useFetch(fetcher, [], {
    refreshInterval: 30000,
  });

  // History mode — only fetched when a look-back period is picked (kept idle in the live view).
  const historyFetcher = useCallback(async () => {
    if (period === 'live') return null;
    const { data: res } = await api.get('/vehicle-status/history', { params: { period } });
    return res.data; // { journeys, counts }
  }, [period]);
  const { data: hist, loading: histLoading, error: histError } = useFetch(historyFetcher, [period]);
  const journeys = hist?.journeys || [];

  // Maintenance-only: keep just the cars inside the workshop workflow.
  const maint = useMemo(() => (data?.rows || []).filter((r) => r.bucket === 'maintenance'), [data]);

  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');

  // Headline numbers for the KPI strip — all derived from the maintenance rows.
  const kpi = useMemo(() => {
    const count = (key) => maint.filter((r) => inGroup(r, key)).length;
    const longest = maint.reduce(
      (acc, r) => ((r.days_in_status ?? -1) > (acc.days_in_status ?? -1) ? r : acc),
      { days_in_status: null },
    );
    return {
      total: maint.length,
      action: count('action'),
      garage: count('garage'),
      signoff: count('signoff'),
      overdue: maint.filter((r) => (r.days_in_status ?? 0) >= SLA_DAYS).length,
      longest: longest.days_in_status != null ? longest : null,
    };
  }, [maint]);

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    return maint.filter((r) => {
      if (!inGroup(r, filter)) return false;
      if (!term) return true;
      return (
        (r.plate_no || '').toLowerCase().includes(term) ||
        (r.model || '').toLowerCase().includes(term) ||
        (r.make || '').toLowerCase().includes(term) ||
        (r.title || '').toLowerCase().includes(term)
      );
    });
  }, [maint, filter, q]);

  const columns = [
    {
      key: 'title',
      header: 'Car',
      render: (r) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className={`h-9 w-1.5 shrink-0 rounded-full ${TONE_BAR[r.status_tone] || TONE_BAR.gray}`} />
          <div className="min-w-0">
            <p className="font-semibold text-slate-900">{r.title}</p>
            {r.subtitle && <p className="truncate text-xs text-slate-400">{r.subtitle}</p>}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Stage',
      render: (r) => <Badge tone={r.status_tone}>{r.status}</Badge>,
    },
    {
      key: 'owner',
      header: 'With',
      tooltip: 'Who holds the car right now — the person or role responsible at this stage.',
      cellClass: 'text-slate-700',
      render: (r) => r.owner || '—',
    },
    {
      key: 'next_action',
      header: 'Next Action',
      tooltip: 'The next step to move this car forward — click the row to go do it.',
      cellClass: 'font-medium text-slate-800',
      render: (r) => r.next_action || '—',
    },
    {
      key: 'days_in_status',
      header: 'Waiting',
      align: 'right',
      tooltip: `How long the car has sat in its current stage. ${SLA_DAYS}+ days is flagged as overdue.`,
      render: (r) => {
        const overdue = (r.days_in_status ?? 0) >= SLA_DAYS;
        return (
          <span className="inline-flex items-center justify-end gap-1.5 tabular-nums">
            {overdue && <span className="h-1.5 w-1.5 rounded-full bg-red-500" title="Overdue" />}
            <span className={overdue ? 'font-semibold text-red-600' : 'text-slate-600'}>{days(r.days_in_status)}</span>
          </span>
        );
      },
    },
  ];

  // History mode — one summary row per past/in-flight maintenance journey in the window.
  const historyColumns = [
    {
      key: 'car',
      header: 'Car',
      render: (j) => (
        <div className="min-w-0">
          <p className="font-semibold text-slate-900">{j.vehicle?.title || '—'}</p>
          {j.vehicle?.plate_no && <p className="truncate text-xs text-slate-400">{j.vehicle.plate_no}</p>}
        </div>
      ),
    },
    {
      key: 'outcome',
      header: 'Outcome',
      render: (j) => <Badge tone={j.open ? 'amber' : 'green'}>{j.outcome}</Badge>,
    },
    {
      key: 'when',
      header: 'When',
      cellClass: 'text-slate-600 whitespace-nowrap',
      render: (j) => `${shortDate(j.opened_at)}${j.closed_at ? ` → ${shortDate(j.closed_at)}` : ''}`,
    },
    {
      key: 'total',
      header: 'Total time',
      align: 'right',
      cellClass: 'tabular-nums text-slate-700',
      render: (j) => fmtSeconds(j.total_seconds),
    },
    {
      key: 'stages',
      header: 'Stages',
      align: 'right',
      cellClass: 'tabular-nums text-slate-500',
      render: (j) => num(j.stage_count),
    },
    {
      key: 'last',
      header: 'Last handled by',
      cellClass: 'text-slate-600',
      render: (j) => j.last_responsible || '—',
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Maintenance Status Board"
          subtitle="Every car currently in the workshop — where it is, who holds it, what's next, and how long it's been waiting. Pick a period to review past journeys, or click any car for its full stage-by-stage history."
        />

        {/* Reporting window — the live all-day board, or a look-back over past journeys. */}
        <div className="flex flex-wrap items-center gap-1.5">
          {PERIODS.map((p) => (
            <button
              key={p.key}
              onClick={() => setPeriod(p.key)}
              className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition ${
                period === p.key ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
              }`}
            >
              {p.label}
            </button>
          ))}
        </div>

        {period !== 'live' ? (
          <SectionCard
            title="Maintenance history"
            subtitle={
              histLoading && !hist
                ? 'Loading…'
                : `${journeys.length} journey${journeys.length === 1 ? '' : 's'} · ${hist?.counts?.open || 0} still open · ${hist?.counts?.closed || 0} completed`
            }
          >
            {histError && !hist ? (
              <div className="px-5 py-12 text-center text-sm text-red-500">{histError}</div>
            ) : (
              <DataTable
                columns={historyColumns}
                rows={journeys}
                rowKey={(j) => j.ticket_id || `${j.vehicle?.id}-${j.opened_at}`}
                loading={histLoading && !hist}
                onRowClick={(j) => j.vehicle && setSelected({ id: j.vehicle.id, title: j.vehicle.title, subtitle: j.vehicle.plate_no })}
                empty="No maintenance happened in this period."
                stickyHeader
              />
            )}
          </SectionCard>
        ) : (
        <>
        {/* KPI strip — clicking a card jumps the stage filter. */}
        {loading && !data ? (
          <MetricGridSkeleton count={5} />
        ) : (
          <MetricGrid cols={5}>
            <MetricCard
              label="In Maintenance"
              value={num(kpi.total)}
              icon={<Icon.Wrench className="h-5 w-5" />}
              tone="amber"
              onClick={() => setFilter('all')}
              hint="Cars in the workshop workflow"
            />
            <MetricCard
              label="Needs Action"
              value={num(kpi.action)}
              icon={<Icon.Alert className="h-5 w-5" />}
              tone="red"
              onClick={() => setFilter('action')}
              hint="Waiting on the team"
            />
            <MetricCard
              label="In Garage"
              value={num(kpi.garage)}
              icon={<Icon.Wrench className="h-5 w-5" />}
              tone="violet"
              onClick={() => setFilter('garage')}
              hint="Under repair now"
            />
            <MetricCard
              label="Ready to Sign-off"
              value={num(kpi.signoff)}
              icon={<Icon.Check className="h-5 w-5" />}
              tone="green"
              onClick={() => setFilter('signoff')}
              hint="Re-inspection"
            />
            <MetricCard
              label="Longest Waiting"
              value={kpi.longest ? days(kpi.longest.days_in_status) : '—'}
              icon={<Icon.Clock className="h-5 w-5" />}
              tone={kpi.longest && kpi.longest.days_in_status >= SLA_DAYS ? 'red' : 'slate'}
              hint={kpi.longest ? kpi.longest.title : 'Nothing waiting'}
            />
          </MetricGrid>
        )}

        <SectionCard
          title="Workshop follow-up"
          subtitle={
            kpi.overdue > 0
              ? `${visible.length} of ${maint.length} cars · ${kpi.overdue} overdue`
              : `${visible.length} of ${maint.length} cars`
          }
          actions={
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex flex-wrap gap-1">
                {STAGE_GROUPS.map((f) => (
                  <button
                    key={f.key}
                    onClick={() => setFilter(f.key)}
                    className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                      filter === f.key
                        ? 'bg-slate-900 text-white shadow-sm'
                        : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                    }`}
                  >
                    {f.label}
                  </button>
                ))}
              </div>
              <SearchInput value={q} onChange={setQ} placeholder="Plate or model…" className="w-44" />
            </div>
          }
        >
          {error && !data ? (
            <div className="px-5 py-12 text-center text-sm text-red-500">{error}</div>
          ) : (
            <DataTable
              columns={columns}
              rows={visible}
              rowKey={(r) => r.id}
              loading={loading && !data}
              onRowClick={(r) => setSelected({ id: r.id, title: r.title, subtitle: r.subtitle })}
              empty="No cars in maintenance right now."
              stickyHeader
            />
          )}
        </SectionCard>
        </>
        )}
      </div>

      <MaintenanceTimelineDrawer
        vehicle={selected}
        period={period}
        onClose={() => setSelected(null)}
      />
    </div>
  );
}
