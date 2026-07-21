import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { num, fmtAgo, fmtDate, aed } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';

// The Maintenance Intelligence Center's operational dashboard — "what is happening in my workshop right
// now?". One screen the team keeps open all day → it self-refreshes. Every number is real; nothing faked.

// KPI → the workflow_status set it counts, so clicking a KPI filters the live table to exactly those cars.
const FILTERS = {
  all:                 null,
  in_workshop:         ['under_repair', 'repair_review', 'ready_for_pickup'],
  waiting_dispatch:    ['inspection_pending'],
  waiting_garage:      ['awaiting_dispatch', 'in_transit'],
  waiting_approval:    ['pending_review', 'triage_approval_pending', 'recommendation_pending', 'repair_review'],
  under_repair:        ['under_repair'],
  waiting_test_drive:  ['inspection_requested', 'inspection_diagnostic'],
  waiting_reinspection: ['ready_for_reinspection', 'reinspection_failed'],
  ready_for_delivery:  ['ready_for_pickup'],
};

// Rows are colour-coded ONLY by workflow status — a calm left accent per stage tone.
const STAGE_BAR = {
  red: 'bg-red-500', amber: 'bg-amber-400', green: 'bg-emerald-400', emerald: 'bg-emerald-400',
  blue: 'bg-blue-400', violet: 'bg-violet-400', teal: 'bg-teal-400', slate: 'bg-slate-300', gray: 'bg-slate-300',
};

// Responsible-party → a muted enterprise tone (kept restrained — one chip, one hue).
const PARTY_TONE = { inspector: 'cyan', supervisor: 'violet', garage: 'amber', driver: 'blue', vendor: 'orange', system: 'slate' };
const DELAY_TONE = { on_track: 'emerald', at_risk: 'amber', overdue: 'red' };

const days = (v) => (v == null ? '—' : v === 0 ? 'Today' : `${num(v)}d`);
const sla = (h) => (h == null ? '—' : h < 0 ? `${Math.round(Math.abs(h) / 24) || 1}d over` : h >= 48 ? `${Math.round(h / 24)}d` : `${h}h`);

function exportCsv(rows) {
  const cols = [
    ['Ticket', (r) => r.ticket_no], ['Plate', (r) => r.plate_no], ['Vehicle', (r) => r.car],
    ['Stage', (r) => r.stage], ['Party', (r) => r.party], ['Responsible', (r) => r.responsible],
    ['Garage', (r) => r.garage], ['Faults', (r) => r.active_faults], ['Severity', (r) => r.severity_label],
    ['Days in', (r) => r.days_in_maintenance], ['Expected', (r) => (r.expected_completion || '').slice(0, 10)],
    ['SLA hrs', (r) => r.sla_remaining_hours], ['Delay', (r) => r.delay_status], ['Priority', (r) => r.priority],
    ['Waiting reason', (r) => r.waiting_reason],
  ];
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const csv = [cols.map((c) => c[0]).join(','), ...rows.map((r) => cols.map((c) => esc(c[1](r))).join(','))].join('\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  const a = document.createElement('a');
  a.href = url; a.download = `car-status-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
  URL.revokeObjectURL(url);
}

export default function CarStatus() {
  const navigate = useNavigate();
  const [filter, setFilter] = useState('in_workshop'); // land on the cars physically in the workshop
  const [q, setQ] = useState('');
  const [view, setView] = useState('cards'); // cards | table

  const fetcher = useCallback(async () => (await api.get('/car-status')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 30000 });

  const kpis = data?.kpis || {};
  const rows = useMemo(() => data?.rows || [], [data]);
  const w = data?.widgets || {};
  const topModels = data?.top_models || [];

  const openVehicle = useCallback((vehicleId) => vehicleId && navigate(`/car-status/${vehicleId}`), [navigate]);

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    const set = FILTERS[filter];
    return rows.filter((r) => {
      if (set && !set.includes(r.workflow_status)) return false;
      if (!term) return true;
      return [r.plate_no, r.car, r.garage, r.responsible, r.ticket_no].some((f) => (f || '').toLowerCase().includes(term));
    });
  }, [rows, filter, q]);

  const columns = [
    {
      key: 'car', header: 'Vehicle',
      render: (r) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className={`h-9 w-1.5 shrink-0 rounded-full ${STAGE_BAR[r.stage_tone] || STAGE_BAR.slate}`} title={r.stage} />
          <div className="min-w-0">
            <p className="truncate font-semibold text-slate-900">{r.car || 'Vehicle'}</p>
            <p className="truncate text-xs text-slate-400">{r.plate_no || '—'}</p>
          </div>
        </div>
      ),
    },
    { key: 'ticket_no', header: 'Ticket', cellClass: 'tabular-nums text-slate-500', render: (r) => r.ticket_no },
    { key: 'stage', header: 'Stage', render: (r) => <Badge tone={r.stage_tone} dot>{r.stage}</Badge> },
    {
      key: 'responsible', header: 'Responsible', tooltip: 'The party that holds the car at this stage.',
      render: (r) => (
        <div className="flex min-w-0 items-center gap-2">
          <Badge tone={PARTY_TONE[r.party] || 'slate'} className="capitalize">{r.party}</Badge>
          <span className="truncate text-xs text-slate-500">{r.responsible}</span>
        </div>
      ),
    },
    { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
    {
      key: 'active_faults', header: 'Faults', align: 'right', cellClass: 'tabular-nums',
      render: (r) => <span className="text-slate-700">{num(r.active_faults)}<span className="text-slate-400">/{num(r.fault_total)}</span></span>,
    },
    {
      key: 'severity', header: 'Severity',
      render: (r) => (r.severity_label ? <Badge tone={r.severity_tone}>{r.severity_emoji} {r.severity_label}</Badge> : <span className="text-slate-300">—</span>),
    },
    {
      key: 'progress', header: 'Progress', tooltip: 'Position through the repair pipeline.',
      render: (r) => (
        <div className="flex items-center gap-2">
          <div className="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
            <div className={`h-full rounded-full ${r.blocked ? 'bg-red-400' : 'bg-emerald-400'}`} style={{ width: `${r.progress}%` }} />
          </div>
          <span className="tabular-nums text-xs text-slate-400">{r.progress}%</span>
        </div>
      ),
    },
    { key: 'reinspection_required', header: 'Re-insp', align: 'center', render: (r) => (r.reinspection_required ? <Badge tone="violet" dot>Yes</Badge> : <span className="text-slate-300">—</span>) },
    { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => days(r.days_in_maintenance) },
    { key: 'expected_completion', header: 'Expected', cellClass: 'whitespace-nowrap text-slate-600', render: (r) => (r.expected_completion ? fmtDate(r.expected_completion) : '—') },
    {
      key: 'sla_remaining_hours', header: 'SLA', align: 'right',
      tooltip: 'Time left before the SLA / expected completion is breached.',
      render: (r) => <span className={`tabular-nums ${r.delay_status === 'overdue' ? 'font-semibold text-red-600' : r.delay_status === 'at_risk' ? 'text-amber-600' : 'text-slate-600'}`}>{sla(r.sla_remaining_hours)}</span>,
    },
    { key: 'delay_status', header: 'Delay', render: (r) => <Badge tone={DELAY_TONE[r.delay_status]} dot>{r.delay_status.replace('_', ' ')}</Badge> },
    { key: 'waiting_reason', header: 'Waiting reason', cellClass: 'text-slate-500 max-w-[200px] truncate', render: (r) => r.waiting_reason || '—' },
    { key: 'priority', header: 'Priority', cellClass: 'capitalize text-slate-600', render: (r) => r.priority || '—' },
    { key: 'last_update', header: 'Updated', cellClass: 'whitespace-nowrap text-slate-500', render: (r) => fmtAgo(r.last_update) || '—' },
    {
      key: 'actions', header: '', align: 'right',
      render: (r) => (
        <button type="button" onClick={(e) => { e.stopPropagation(); openVehicle(r.vehicle_id); }}
          className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-200">
          Open <Icon.ArrowRight className="h-3.5 w-3.5" />
        </button>
      ),
    },
  ];

  const KPI = ({ label, k, icon, tone, filterKey, hint }) => (
    <MetricCard label={label} value={num(kpis[k])} icon={icon} tone={tone} hint={hint}
      onClick={filterKey ? () => setFilter(filterKey) : undefined} />
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1700px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Car Status"
          subtitle="The maintenance department's live command center — every vehicle inside the workflow, who holds it, what it's waiting on, and how close it is to breaching SLA. Click any car for its full intelligence profile."
        />

        {loading && !data ? (
          <MetricGridSkeleton count={6} />
        ) : (
          <>
            <MetricGrid cols={6}>
              <KPI label="In Workshop" k="in_workshop" icon={<Icon.Wrench />} tone="red" filterKey="in_workshop" hint="Physically at a garage now" />
              <KPI label="Under Repair" k="under_repair" icon={<Icon.Wrench />} tone="red" filterKey="under_repair" hint="Being worked on" />
              <KPI label="Waiting Parts" k="waiting_parts" icon={<Icon.Coins />} tone="amber" hint="A part is on order" />
              <KPI label="Waiting Re-inspection" k="waiting_reinspection" icon={<Icon.Shield />} tone="violet" filterKey="waiting_reinspection" hint="Final QA sign-off" />
              <KPI label="Ready for Delivery" k="ready_for_delivery" icon={<Icon.Check />} tone="emerald" filterKey="ready_for_delivery" hint="Awaiting pickup" />
              <KPI label="Overdue" k="overdue" icon={<Icon.Alert />} tone="red" hint="Past expected completion" />
            </MetricGrid>
            <MetricGrid cols={6}>
              <KPI label="In Maintenance" k="in_maintenance" icon={<Icon.Car />} tone="amber" filterKey="all" hint="All vehicles in the workflow" />
              <KPI label="Waiting Dispatch" k="waiting_dispatch" icon={<Icon.Route />} tone="violet" filterKey="waiting_dispatch" hint="Awaiting garage decision" />
              <KPI label="Waiting Garage" k="waiting_garage" icon={<Icon.Truck />} tone="blue" filterKey="waiting_garage" hint="Assigned / en route" />
              <KPI label="Waiting Approval" k="waiting_approval" icon={<Icon.Shield />} tone="blue" filterKey="waiting_approval" hint="Blocked on a sign-off" />
              <KPI label="High Severity" k="high_severity" icon={<Icon.Alert />} tone="red" hint="Critical / high faults" />
              <KPI label="Repeat Repairs" k="repeat_repairs" icon={<Icon.Refresh />} tone="amber" hint="Back 2+ times this month" />
            </MetricGrid>
          </>
        )}

        {/* Which car models most go to maintenance — fleet-intelligence KPI. */}
        {!!topModels.length && <TopModels models={topModels} />}

        {/* The live workshop list — defaults to the cars physically In Workshop. */}
        <SectionCard
          title={filter === 'in_workshop' ? 'In Workshop' : 'Live Workshop'}
          subtitle={loading && !data ? 'Loading…' : `${visible.length} vehicle${visible.length === 1 ? '' : 's'}${filter === 'in_workshop' ? ' physically at a garage' : ` of ${rows.length}`}`}
          actions={
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex flex-wrap gap-1">
                {[['in_workshop', 'In Workshop'], ['all', 'All'], ['waiting_dispatch', 'Dispatch'], ['waiting_garage', 'Garage'], ['waiting_approval', 'Approval'], ['waiting_reinspection', 'Re-inspect'], ['ready_for_delivery', 'Ready']].map(([key, label]) => (
                  <button key={key} onClick={() => setFilter(key)}
                    className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${filter === key ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'}`}>
                    {label}
                  </button>
                ))}
              </div>
              <SearchInput value={q} onChange={setQ} placeholder="Plate, car, ticket…" className="w-48" />
              {/* Cards (clean, default) vs the detailed table. */}
              <div className="flex overflow-hidden rounded-full border border-slate-200">
                {[['cards', 'Cards'], ['table', 'Table']].map(([key, label]) => (
                  <button key={key} onClick={() => setView(key)}
                    className={`px-3 py-1.5 text-xs font-semibold transition ${view === key ? 'bg-slate-900 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'}`}>
                    {label}
                  </button>
                ))}
              </div>
              <button type="button" onClick={() => exportCsv(visible)}
                className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                <Icon.Download className="h-3.5 w-3.5" /> Export
              </button>
            </div>
          }
        >
          {error && !data ? (
            <div className="px-5 py-12 text-center text-sm text-red-500">{error}</div>
          ) : view === 'table' ? (
            <DataTable columns={columns} rows={visible} rowKey={(r) => r.ticket_id} loading={loading && !data}
              onRowClick={(r) => openVehicle(r.vehicle_id)} empty="No vehicles in the workshop right now." stickyHeader dense />
          ) : loading && !data ? (
            <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
              {Array.from({ length: 8 }).map((_, i) => <div key={i} className="h-36 animate-pulse rounded-2xl bg-slate-100" />)}
            </div>
          ) : visible.length === 0 ? (
            <div className="px-5 py-12 text-center text-sm text-slate-400">No vehicles in the workshop right now.</div>
          ) : (
            <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
              {visible.map((r) => <VehicleCard key={r.ticket_id} r={r} onOpen={openVehicle} />)}
            </div>
          )}
        </SectionCard>

        {/* Management attention — the single roll-up a manager scans first (full width). */}
        <ManagementAttention rows={w.management_attention} onOpen={openVehicle} />

        {/* Operational widgets — always-on, no clicks needed. */}
        <div className="grid grid-cols-1 gap-6 2xl:grid-cols-2">
          <WaitingParts rows={w.waiting_parts} onOpen={openVehicle} />
          <ExceedingSla rows={w.exceeding_sla} onOpen={openVehicle} />
          <RepeatRepairs rows={w.repeat_repairs} onOpen={openVehicle} />
          <RepeatFailures rows={w.repeat_failures} onOpen={openVehicle} />
          <WaitingApproval rows={w.waiting_approval} onOpen={openVehicle} />
          <WaitingGarage rows={w.waiting_garage_accept} onOpen={openVehicle} />
          <WaitingInvoice rows={w.waiting_invoice} onOpen={openVehicle} />
          {SHOW_FINANCIALS && <HighestCost rows={w.highest_cost} onOpen={openVehicle} />}
          <LongestOpen rows={w.longest_open} onOpen={openVehicle} />
          <RecentlyFinished rows={w.recently_finished} onOpen={openVehicle} />
        </div>
      </div>
    </div>
  );
}

// Which car models most often go to maintenance — a horizontal bar ranking (readable for long names).
function TopModels({ models = [] }) {
  const max = Math.max(1, ...models.map((m) => m.value));
  return (
    <SectionCard title="Most Maintained Models" subtitle="Which car types go to maintenance most (all-time ticket volume)">
      <div className="grid grid-cols-1 gap-x-8 gap-y-2.5 px-5 py-4 lg:grid-cols-2">
        {models.map((m, i) => (
          <div key={m.label} className="flex items-center gap-3">
            <span className="w-6 shrink-0 text-right text-xs font-bold tabular-nums text-slate-300">{i + 1}</span>
            <span className="w-40 shrink-0 truncate text-sm font-medium text-slate-700" title={m.label}>{m.label}</span>
            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100">
              <div className="h-full rounded-full bg-navy" style={{ width: `${(m.value / max) * 100}%`, background: '#334155' }} />
            </div>
            <span className="w-8 shrink-0 text-right text-sm font-semibold tabular-nums text-slate-700">{num(m.value)}</span>
          </div>
        ))}
      </div>
    </SectionCard>
  );
}

// Left-accent colour per stage tone — the ONLY colour coding on a card (calm, enterprise).
const STAGE_ACCENT = {
  red: 'border-l-red-500', amber: 'border-l-amber-400', green: 'border-l-emerald-400', emerald: 'border-l-emerald-400',
  blue: 'border-l-blue-400', violet: 'border-l-violet-400', teal: 'border-l-teal-400', slate: 'border-l-slate-300', gray: 'border-l-slate-300',
};

// One clean card per vehicle in the workshop — the default dashboard view. Click → the car's page.
function VehicleCard({ r, onOpen }) {
  return (
    <button
      type="button"
      onClick={() => onOpen(r.vehicle_id)}
      className={`hover-lift flex flex-col gap-3 rounded-2xl border border-l-4 border-slate-200/60 bg-white p-4 text-left shadow-soft ${STAGE_ACCENT[r.stage_tone] || STAGE_ACCENT.slate}`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-semibold text-slate-900">{r.car || 'Vehicle'}</p>
          <p className="truncate text-xs text-slate-400">{r.plate_no || '—'} · {r.ticket_no}</p>
        </div>
        <Badge tone={r.stage_tone} dot>{r.stage}</Badge>
      </div>

      <div className="flex items-center gap-2 text-xs">
        <Badge tone={PARTY_TONE[r.party] || 'slate'} className="capitalize">{r.party}</Badge>
        <span className="min-w-0 truncate text-slate-500">{r.responsible}{r.garage ? ` · ${r.garage}` : ''}</span>
      </div>

      <div className="flex items-center gap-2">
        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
          <div className={`h-full rounded-full ${r.blocked ? 'bg-red-400' : 'bg-emerald-400'}`} style={{ width: `${r.progress}%` }} />
        </div>
        <span className="tabular-nums text-[11px] text-slate-400">{r.progress}%</span>
      </div>

      <div className="flex items-center justify-between border-t border-slate-100 pt-2.5 text-xs">
        <div className="flex items-center gap-2 text-slate-500">
          <span className="tabular-nums">{num(r.active_faults)} fault{r.active_faults === 1 ? '' : 's'}</span>
          {r.severity_label && <Badge tone={r.severity_tone}>{r.severity_emoji}</Badge>}
          {r.waiting_parts && <Badge tone="amber">Parts</Badge>}
          {r.reinspection_required && <Badge tone="violet">Re-insp</Badge>}
        </div>
        <div className="flex items-center gap-1.5">
          <span className="tabular-nums text-slate-400">{days(r.days_in_maintenance)}</span>
          <Badge tone={DELAY_TONE[r.delay_status]} dot>{r.delay_status.replace('_', ' ')}</Badge>
        </div>
      </div>
    </button>
  );
}

// ── Widgets ─────────────────────────────────────────────────────────────────────────────────────

const carCell = (r) => (
  <div className="min-w-0">
    <p className="truncate font-semibold text-slate-900">{r.car || 'Vehicle'}</p>
    <p className="truncate text-xs text-slate-400">{r.plate_no || '—'}</p>
  </div>
);

const Widget = ({ title, subtitle, columns, rows = [], rowKey, onRowClick, empty, highlightRow }) => (
  <SectionCard title={title} subtitle={subtitle}>
    <DataTable columns={columns} rows={rows} rowKey={rowKey} onRowClick={onRowClick} highlightRow={highlightRow} empty={empty} dense stickyHeader />
  </SectionCard>
);

const ATTN_TONE = { Overdue: 'red', 'Recurring failure': 'red', 'High severity': 'red', 'Waiting approval': 'blue', 'Waiting parts': 'amber', 'Excessive duration': 'violet' };

const ManagementAttention = ({ rows = [], onOpen }) => (
  <SectionCard
    title="Requires management attention"
    subtitle={`${rows.length} vehicle${rows.length === 1 ? '' : 's'} flagged — overdue, recurring, blocked, high-severity or long-running`}
  >
    <DataTable
      columns={[
        { key: 'car', header: 'Vehicle', render: carCell },
        { key: 'stage', header: 'Stage', render: (r) => <Badge tone={r.stage_tone} dot>{r.stage}</Badge> },
        { key: 'reasons', header: 'Why', render: (r) => <div className="flex flex-wrap gap-1">{(r.reasons || []).map((x, i) => <Badge key={i} tone={ATTN_TONE[x] || 'slate'}>{x}</Badge>)}</div> },
        { key: 'responsible', header: 'With', cellClass: 'text-slate-600', render: (r) => r.responsible },
        { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
        { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums', render: (r) => days(r.days_in_maintenance) },
      ]}
      rows={rows} rowKey={(r) => r.ticket_id} highlightRow={(r) => (r.reasons || []).includes('Overdue')}
      onRowClick={(r) => onOpen(r.vehicle_id)} empty="Nothing needs attention right now. 🎉" dense stickyHeader />
  </SectionCard>
);

const WaitingInvoice = ({ rows = [], onOpen }) => (
  <Widget title="Waiting for invoice" subtitle={`${rows.length} back in service, invoice outstanding`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} highlightRow={(r) => r.overdue} empty="No outstanding invoices."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
      { key: 'days_waiting', header: 'Waiting', align: 'right', cellClass: 'tabular-nums', render: (r) => (r.days_waiting == null ? '—' : `${r.days_waiting}d`) },
      { key: 'overdue', header: '', align: 'right', render: (r) => (r.overdue ? <Badge tone="red" dot>Overdue</Badge> : null) },
    ]} />
);

const WaitingParts = ({ rows = [], onOpen }) => (
  <Widget title="Waiting for parts" subtitle={`${rows.length} open part request${rows.length === 1 ? '' : 's'}`}
    rows={rows} rowKey={(r) => r.request_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No vehicles waiting on parts."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'part', header: 'Missing part', cellClass: 'font-medium text-slate-800', render: (r) => r.part || '—' },
      { key: 'purchase_status', header: 'Status', render: (r) => <Badge tone={r.purchase_status === 'purchased' ? 'blue' : r.purchase_status === 'approved' ? 'violet' : 'amber'}>{r.purchase_status}</Badge> },
      { key: 'vendor', header: 'Vendor', cellClass: 'text-slate-600', render: (r) => r.vendor || '—' },
      { key: 'days_waiting', header: 'Waiting', align: 'right', cellClass: 'tabular-nums', render: (r) => (r.days_waiting == null ? '—' : `${r.days_waiting}d`) },
    ]} />
);

const ExceedingSla = ({ rows = [], onOpen }) => (
  <Widget title="Exceeding SLA" subtitle={`${rows.length} at risk or overdue`} highlightRow={(r) => r.delay_status === 'overdue'}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="Everything within SLA. 🎉"
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'stage', header: 'Stage', render: (r) => <Badge tone={r.stage_tone}>{r.stage}</Badge> },
      { key: 'delay_status', header: 'Delay', render: (r) => <Badge tone={DELAY_TONE[r.delay_status]} dot>{r.delay_status.replace('_', ' ')}</Badge> },
      { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums', render: (r) => days(r.days_in_maintenance) },
      { key: 'responsible', header: 'With', cellClass: 'text-slate-600', render: (r) => r.responsible },
    ]} />
);

const RepeatRepairs = ({ rows = [], onOpen }) => (
  <Widget title="Repaired multiple times this month" subtitle={`${rows.length} vehicle${rows.length === 1 ? '' : 's'} back 2+ times`}
    rows={rows} rowKey={(r) => r.vehicle_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No repeat repairs this month."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'repairs', header: 'Repairs', align: 'right', render: (r) => <Badge tone="red">{r.repairs}×</Badge> },
      { key: 'repeat_faults', header: 'Repeat faults', render: (r) => (r.repeat_faults?.length ? <div className="flex flex-wrap gap-1">{r.repeat_faults.map((f, i) => <Badge key={i} tone="amber">{f.category} ×{f.count}</Badge>)}</div> : <span className="text-slate-400">—</span>) },
      { key: 'garages', header: 'Garages', cellClass: 'text-slate-600', render: (r) => (r.garages?.join(', ') || '—') },
    ]} />
);

const RepeatFailures = ({ rows = [], onOpen }) => (
  <Widget title="Repeat failures" subtitle={`${rows.length} failed a QC re-inspection this month`}
    rows={rows} rowKey={(r) => r.vehicle_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No repeat failures this month."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'occurrences', header: 'Recurrences', align: 'right', render: (r) => <Badge tone="red">{r.occurrences}×</Badge> },
      { key: 'last_seen', header: 'Last', cellClass: 'whitespace-nowrap text-slate-500', render: (r) => fmtAgo(r.last_seen) },
    ]} />
);

const WaitingApproval = ({ rows = [], onOpen }) => (
  <Widget title="Waiting for approval" subtitle={`${rows.length} blocked on a sign-off`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="Nothing awaiting approval."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'approval_type', header: 'Approval', cellClass: 'font-medium text-slate-800', render: (r) => r.approval_type },
      { key: 'approver', header: 'Approver', cellClass: 'text-slate-600', render: (r) => r.approver },
      { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums', render: (r) => days(r.days_in_maintenance) },
    ]} />
);

const WaitingGarage = ({ rows = [], onOpen }) => (
  <Widget title="Waiting for garage acceptance" subtitle={`${rows.length} assigned / en route, not yet received`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No cars waiting on a garage."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'stage', header: 'Stage', render: (r) => <Badge tone={r.stage_tone}>{r.stage}</Badge> },
      { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
      { key: 'responsible', header: 'Driver', cellClass: 'text-slate-600', render: (r) => r.responsible },
    ]} />
);

const HighestCost = ({ rows = [], onOpen }) => (
  <Widget title="Highest maintenance cost this month" subtitle={`Top ${rows.length} by cost`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No costed repairs this month."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
      { key: 'cost', header: 'Cost', align: 'right', cellClass: 'tabular-nums font-semibold text-slate-800', render: (r) => aed(r.cost) },
    ]} />
);

const LongestOpen = ({ rows = [], onOpen }) => (
  <Widget title="Longest open repairs" subtitle={`${rows.length} oldest still in the workshop`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="Nothing open."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'stage', header: 'Stage', render: (r) => <Badge tone={r.stage_tone}>{r.stage}</Badge> },
      { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums font-semibold text-slate-700', render: (r) => days(r.days_in_maintenance) },
      { key: 'responsible', header: 'With', cellClass: 'text-slate-600', render: (r) => r.responsible },
    ]} />
);

const RecentlyFinished = ({ rows = [], onOpen }) => (
  <Widget title="Recently completed repairs" subtitle={`${rows.length} signed off this week`}
    rows={rows} rowKey={(r) => r.ticket_id} onRowClick={(r) => onOpen(r.vehicle_id)} empty="No repairs finished this week."
    columns={[
      { key: 'car', header: 'Vehicle', render: carCell },
      { key: 'closed_at', header: 'Finished', cellClass: 'whitespace-nowrap', render: (r) => (r.today ? <Badge tone="emerald" dot>Today</Badge> : fmtAgo(r.closed_at)) },
      { key: 'garage', header: 'Garage', cellClass: 'text-slate-600', render: (r) => r.garage || '—' },
      { key: 'repair_hours', header: 'Duration', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => (r.repair_hours == null ? '—' : r.repair_hours >= 48 ? `${Math.round(r.repair_hours / 24)}d` : `${r.repair_hours}h`) },
    ]} />
);
