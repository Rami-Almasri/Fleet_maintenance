import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { SearchInput } from '../components/ui/Misc';
import DataTable from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { num, fmtDate } from '../lib/format';

// Car Status — the maintenance department's flagship screen: every vehicle that currently has an OPEN
// maintenance ticket, presented as a premium fleet board. Who holds each car, what it's waiting on, how
// close it is to breaching SLA. Click any car for its full intelligence profile. Self-refreshes.

// Filter key → the workflow_status set it counts (null = handled by a row flag below).
const FILTERS = {
  all:                  null,
  in_workshop:          ['under_repair', 'repair_review', 'ready_for_pickup'],
  waiting_dispatch:     ['inspection_pending', 'awaiting_dispatch', 'in_transit'],
  waiting_approval:     ['pending_review', 'triage_approval_pending', 'recommendation_pending', 'repair_review'],
  waiting_parts:        null,
  waiting_reinspection: ['ready_for_reinspection', 'reinspection_failed'],
  ready_for_delivery:   ['ready_for_pickup'],
  overdue:              null,
};

// Stage tone → the visual language of a card: a gradient banner, a tinted icon plate, a progress fill.
const STAGE_GRAD = {
  red: 'from-rose-500 to-red-600', amber: 'from-amber-400 to-orange-500', green: 'from-emerald-400 to-teal-500',
  emerald: 'from-emerald-400 to-teal-500', blue: 'from-blue-500 to-indigo-500', violet: 'from-violet-500 to-purple-600',
  teal: 'from-teal-400 to-cyan-500', slate: 'from-slate-400 to-slate-500', gray: 'from-slate-400 to-slate-500',
};
const STAGE_TINT = {
  red: 'bg-rose-50 text-rose-600 ring-rose-200', amber: 'bg-amber-50 text-amber-600 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-600 ring-emerald-200', emerald: 'bg-emerald-50 text-emerald-600 ring-emerald-200',
  blue: 'bg-blue-50 text-blue-600 ring-blue-200', violet: 'bg-violet-50 text-violet-600 ring-violet-200',
  teal: 'bg-teal-50 text-teal-600 ring-teal-200', slate: 'bg-slate-100 text-slate-500 ring-slate-200', gray: 'bg-slate-100 text-slate-500 ring-slate-200',
};
const STAGE_BAR = {
  red: 'bg-red-500', amber: 'bg-amber-400', green: 'bg-emerald-400', emerald: 'bg-emerald-400',
  blue: 'bg-blue-400', violet: 'bg-violet-400', teal: 'bg-teal-400', slate: 'bg-slate-300', gray: 'bg-slate-300',
};
const PARTY_TONE = { inspector: 'cyan', supervisor: 'violet', garage: 'amber', driver: 'blue', vendor: 'orange', system: 'slate' };
const DELAY_TONE = { on_track: 'emerald', at_risk: 'amber', overdue: 'red' };

const days = (v) => (v == null ? '—' : v === 0 ? 'Today' : `${num(v)}d`);
const sla = (h) => (h == null ? '—' : h < 0 ? `${Math.round(Math.abs(h) / 24) || 1}d over` : h >= 48 ? `${Math.round(h / 24)}d` : `${h}h`);
const initials = (s) => (s || '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '—';

function exportCsv(rows) {
  const cols = [
    ['Ticket', (r) => r.ticket_no], ['Plate', (r) => r.plate_no], ['Vehicle', (r) => r.car],
    ['Stage', (r) => r.stage], ['Responsible', (r) => r.responsible], ['Garage', (r) => r.garage],
    ['Faults', (r) => r.active_faults], ['Severity', (r) => r.severity_label],
    ['Days in', (r) => r.days_in_maintenance], ['Expected', (r) => (r.expected_completion || '').slice(0, 10)],
    ['SLA hrs', (r) => r.sla_remaining_hours], ['Delay', (r) => r.delay_status], ['Waiting reason', (r) => r.waiting_reason],
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
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');
  const [view, setView] = useState('cards'); // premium cards lead; table on demand

  const fetcher = useCallback(async () => (await api.get('/car-status')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 30000 });
  // (premium card view is the default; the table stays available for dense scanning)

  const kpis = data?.kpis || {};
  const rows = useMemo(() => data?.rows || [], [data]);

  const openVehicle = useCallback((vehicleId) => vehicleId && navigate(`/car-status/${vehicleId}`), [navigate]);

  const matchesFilter = useCallback((r) => {
    if (filter === 'all') return true;
    if (filter === 'waiting_parts') return r.waiting_parts;
    if (filter === 'overdue') return r.is_overdue;
    const set = FILTERS[filter];
    return set ? set.includes(r.workflow_status) : true;
  }, [filter]);

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (!matchesFilter(r)) return false;
      if (!term) return true;
      return [r.plate_no, r.car, r.garage, r.responsible, r.ticket_no].some((f) => (f || '').toLowerCase().includes(term));
    });
  }, [rows, matchesFilter, q]);

  const columns = [
    {
      key: 'car', header: 'Vehicle',
      render: (r) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className={`h-9 w-1.5 shrink-0 rounded-full ${STAGE_BAR[r.stage_tone] || STAGE_BAR.slate}`} title={r.stage} />
          <div className="min-w-0">
            <p className="truncate font-semibold text-slate-900">{r.car || 'Vehicle'}</p>
            <p className="truncate text-xs text-slate-400">{r.plate_no || '—'} · {r.ticket_no}</p>
          </div>
        </div>
      ),
    },
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
      key: 'waiting_reason', header: 'Waiting on', cellClass: 'text-slate-500 max-w-[220px] truncate',
      render: (r) => (
        <span className="flex items-center gap-1.5">
          {r.waiting_parts && <Badge tone="amber">Parts</Badge>}
          {r.reinspection_required && <Badge tone="violet">Re-insp</Badge>}
          <span className="truncate">{r.waiting_reason || '—'}</span>
        </span>
      ),
    },
    { key: 'days_in_maintenance', header: 'Days in', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => days(r.days_in_maintenance) },
    { key: 'expected_completion', header: 'Expected', cellClass: 'whitespace-nowrap text-slate-600', render: (r) => (r.expected_completion ? fmtDate(r.expected_completion) : '—') },
    {
      key: 'sla_remaining_hours', header: 'SLA', align: 'right',
      tooltip: 'Time left before the expected completion / SLA is breached.',
      render: (r) => (
        <div className="flex items-center justify-end gap-2">
          <span className={`tabular-nums ${r.delay_status === 'overdue' ? 'font-semibold text-red-600' : r.delay_status === 'at_risk' ? 'text-amber-600' : 'text-slate-600'}`}>{sla(r.sla_remaining_hours)}</span>
          <Badge tone={DELAY_TONE[r.delay_status]} dot>{r.delay_status.replace('_', ' ')}</Badge>
        </div>
      ),
    },
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

  const TILES = [
    { key: 'all',                 label: 'In Maintenance',     kpi: 'in_maintenance',     icon: <Icon.Car />,    tone: 'indigo',  hint: 'Open tickets' },
    { key: 'in_workshop',         label: 'In Workshop',        kpi: 'in_workshop',        icon: <Icon.Wrench />, tone: 'amber',   hint: 'At a garage' },
    { key: 'waiting_parts',       label: 'Waiting Parts',      kpi: 'waiting_parts',      icon: <Icon.Coins />,  tone: 'amber',   hint: 'On order' },
    { key: 'waiting_reinspection', label: 'Re-inspection',     kpi: 'waiting_reinspection', icon: <Icon.Shield />, tone: 'violet', hint: 'Final QA' },
    { key: 'ready_for_delivery',  label: 'Ready',              kpi: 'ready_for_delivery', icon: <Icon.Check />,  tone: 'emerald', hint: 'Awaiting pickup' },
    { key: 'overdue',             label: 'Overdue',            kpi: 'overdue',            icon: <Icon.Alert />,  tone: 'red',     hint: 'Past due' },
  ];

  const CHIPS = [
    ['all', 'All open'], ['in_workshop', 'In Workshop'], ['waiting_dispatch', 'Dispatch'],
    ['waiting_approval', 'Approval'], ['waiting_parts', 'Parts'], ['waiting_reinspection', 'Re-inspect'],
    ['ready_for_delivery', 'Ready'], ['overdue', 'Overdue'],
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1500px] space-y-6 px-4 sm:px-6 lg:px-8">
        <HeroHeader kpis={kpis} loading={loading && !data} />

        {/* Clickable stat tiles — the KPI strip doubles as the primary filter. */}
        <div className="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {TILES.map((t) => (
            <StatTile key={t.key} {...t} value={kpis[t.kpi]} active={filter === t.key}
              loading={loading && !data} onClick={() => setFilter(t.key)} />
          ))}
        </div>

        {/* The board. */}
        <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div className="min-w-0">
              <h3 className="truncate text-base font-semibold text-slate-900">Cars in Open Maintenance</h3>
              <p className="mt-0.5 truncate text-xs text-slate-400">
                {loading && !data ? 'Loading…' : `${visible.length} vehicle${visible.length === 1 ? '' : 's'}${filter === 'all' ? '' : ` of ${rows.length}`} · live, refreshes every 30s`}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <SearchInput value={q} onChange={setQ} placeholder="Plate, car, ticket…" className="w-48" />
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
          </div>

          {/* Filter chips */}
          <div className="flex flex-wrap gap-1.5 border-b border-slate-100 bg-slate-50/40 px-5 py-3">
            {CHIPS.map(([key, label]) => (
              <button key={key} onClick={() => setFilter(key)}
                className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${filter === key ? 'bg-slate-900 text-white shadow-sm' : 'bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-100'}`}>
                {label}
              </button>
            ))}
          </div>

          {error && !data ? (
            <div className="px-5 py-16 text-center text-sm text-red-500">{error}</div>
          ) : view === 'table' ? (
            <DataTable columns={columns} rows={visible} rowKey={(r) => r.ticket_id} loading={loading && !data}
              onRowClick={(r) => openVehicle(r.vehicle_id)} empty="No cars in open maintenance right now. 🎉" stickyHeader dense />
          ) : loading && !data ? (
            <div className="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
              {Array.from({ length: 8 }).map((_, i) => <div key={i} className="h-52 animate-pulse rounded-2xl bg-slate-100" />)}
            </div>
          ) : visible.length === 0 ? (
            <div className="px-5 py-20 text-center">
              <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-500 ring-1 ring-emerald-200">
                <Icon.Check className="h-7 w-7" />
              </div>
              <p className="text-sm font-medium text-slate-500">No cars in open maintenance right now.</p>
              <p className="mt-1 text-xs text-slate-400">The whole fleet is on the road. 🎉</p>
            </div>
          ) : (
            <div className="stagger grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
              {visible.map((r) => <VehicleCard key={r.ticket_id} r={r} onOpen={openVehicle} />)}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ── Hero ────────────────────────────────────────────────────────────────────────────────────────

function HeroHeader({ kpis, loading }) {
  const stats = [
    { label: 'In maintenance', value: kpis.in_maintenance },
    { label: 'In workshop', value: kpis.in_workshop },
    { label: 'Overdue', value: kpis.overdue, danger: true },
  ];
  return (
    <div className="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950 px-6 py-7 text-white shadow-soft sm:px-8">
      {/* decorative glow orbs */}
      <div className="pointer-events-none absolute -right-16 -top-24 h-64 w-64 rounded-full bg-indigo-500/20 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-cyan-500/10 blur-3xl" />
      <div className="relative flex flex-wrap items-end justify-between gap-6">
        <div className="min-w-0">
          <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-cyan-200 ring-1 ring-white/15">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
            </span>
            Maintenance Command · Live
          </div>
          <h1 className="font-display text-3xl font-bold tracking-tight sm:text-4xl">Car Status</h1>
          <p className="mt-2 max-w-2xl text-sm text-slate-300">
            Every vehicle currently in open maintenance — its stage, who holds it, what it's waiting on and its SLA.
            Click any car for its full maintenance profile.
          </p>
        </div>
        <div className="flex gap-6">
          {stats.map((s) => (
            <div key={s.label} className="text-right">
              <p className={`font-display text-3xl font-bold tabular-nums leading-none ${s.danger ? 'text-rose-300' : 'text-white'}`}>
                {loading ? '—' : num(s.value)}
              </p>
              <p className="mt-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">{s.label}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ── Stat tile (KPI + filter) ────────────────────────────────────────────────────────────────────

const TILE_TONE = {
  indigo:  { icon: 'bg-indigo-100 text-indigo-600', ring: 'ring-indigo-500', accent: 'from-indigo-500 to-blue-500' },
  amber:   { icon: 'bg-amber-100 text-amber-600',   ring: 'ring-amber-500',  accent: 'from-amber-400 to-orange-500' },
  violet:  { icon: 'bg-violet-100 text-violet-600', ring: 'ring-violet-500', accent: 'from-violet-500 to-purple-500' },
  emerald: { icon: 'bg-emerald-100 text-emerald-600', ring: 'ring-emerald-500', accent: 'from-emerald-400 to-teal-500' },
  red:     { icon: 'bg-red-100 text-red-600',       ring: 'ring-red-500',    accent: 'from-rose-500 to-red-600' },
};

function StatTile({ label, value, icon, tone = 'indigo', hint, active, loading, onClick }) {
  const t = TILE_TONE[tone] || TILE_TONE.indigo;
  return (
    <button type="button" onClick={onClick}
      className={`hover-lift group relative overflow-hidden rounded-2xl border bg-white p-4 text-left shadow-soft transition ${active ? `border-transparent ring-2 ${t.ring}` : 'border-slate-200/60'}`}>
      {/* top accent bar — full colour when active, subtle otherwise */}
      <span className={`absolute inset-x-0 top-0 h-1 bg-gradient-to-r ${t.accent} ${active ? 'opacity-100' : 'opacity-0 group-hover:opacity-60'} transition-opacity`} />
      <div className="flex items-start justify-between gap-2">
        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${t.icon}`}>{icon}</span>
        {active && <span className="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">Filtered</span>}
      </div>
      <p className="mt-3 font-display text-2xl font-bold leading-none tracking-tight tabular-nums text-slate-900">
        {loading ? '—' : num(value)}
      </p>
      <p className="mt-1 truncate text-xs font-medium text-slate-500">{label}</p>
      {hint && <p className="truncate text-[11px] text-slate-400">{hint}</p>}
    </button>
  );
}

// ── Vehicle card (the star) ─────────────────────────────────────────────────────────────────────

const STAGE_STROKE = {
  red: '#ef4444', amber: '#f59e0b', green: '#10b981', emerald: '#10b981', blue: '#3b82f6',
  violet: '#8b5cf6', teal: '#14b8a6', slate: '#94a3b8', gray: '#94a3b8',
};

// A slim SVG donut — the car's pipeline progress, colored by stage. A premium touch over a flat bar.
function ProgressRing({ value = 0, stroke = '#3b82f6', blocked = false }) {
  const r = 26, c = 2 * Math.PI * r;
  const pct = Math.max(0, Math.min(100, value));
  return (
    <div className="relative h-[62px] w-[62px] shrink-0">
      <svg viewBox="0 0 62 62" className="h-full w-full -rotate-90">
        <circle cx="31" cy="31" r={r} fill="none" stroke="rgb(241 245 249)" strokeWidth="6" />
        <circle cx="31" cy="31" r={r} fill="none" stroke={blocked ? '#ef4444' : stroke} strokeWidth="6"
          strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (pct / 100) * c}
          style={{ transition: 'stroke-dashoffset 0.6s cubic-bezier(0.21,1.02,0.73,1)' }} />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center font-display text-sm font-bold tabular-nums text-slate-700">{pct}%</span>
    </div>
  );
}

// A mini UAE-style number plate — a small delight for a car-loving audience.
function PlateChip({ plate }) {
  return (
    <span className="inline-flex items-stretch overflow-hidden rounded-md border border-slate-300 bg-white text-xs font-bold shadow-sm">
      <span className="flex items-center bg-slate-900 px-1.5 text-[8px] font-black uppercase tracking-tight text-white">UAE</span>
      <span className="flex items-center px-2 py-0.5 font-mono tracking-widest text-slate-800">{plate || '—'}</span>
    </span>
  );
}

function VehicleCard({ r, onOpen }) {
  const grad = STAGE_GRAD[r.stage_tone] || STAGE_GRAD.slate;
  const tint = STAGE_TINT[r.stage_tone] || STAGE_TINT.slate;
  const stroke = STAGE_STROKE[r.stage_tone] || STAGE_STROKE.slate;
  const overdue = r.delay_status === 'overdue';
  const atRisk = r.delay_status === 'at_risk';

  return (
    <button
      type="button"
      onClick={() => onOpen(r.vehicle_id)}
      className={`hover-lift group relative flex flex-col overflow-hidden rounded-2xl border bg-white text-left shadow-soft ${overdue ? 'border-red-200 ring-1 ring-red-100' : 'border-slate-200/60'}`}
    >
      {/* stage colour edge */}
      <span className={`h-1.5 w-full shrink-0 bg-gradient-to-r ${grad}`} />

      <div className="flex flex-1 flex-col gap-4 p-5">
        {/* top: stage + SLA */}
        <div className="flex items-center justify-between gap-2">
          <Badge tone={r.stage_tone} dot>{r.stage}</Badge>
          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold ${overdue ? 'bg-red-50 text-red-600 ring-1 ring-red-200' : atRisk ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-200' : 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200'}`}>
            {overdue && <Icon.Alert className="h-3 w-3" />}
            {sla(r.sla_remaining_hours)}
          </span>
        </div>

        {/* identity + progress ring */}
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="mb-1.5 flex items-center gap-2">
              <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 ${tint}`}>
                <Icon.Car className="h-5 w-5" />
              </span>
              <h3 className="truncate font-display text-lg font-bold leading-tight text-slate-900">{r.car || 'Vehicle'}</h3>
            </div>
            <div className="flex items-center gap-2">
              <PlateChip plate={r.plate_no} />
              <span className="text-xs text-slate-400">{r.ticket_no}</span>
            </div>
          </div>
          <ProgressRing value={r.progress} stroke={stroke} blocked={r.blocked} />
        </div>

        {/* responsible panel */}
        <div className="flex items-center gap-2.5 rounded-xl bg-slate-50 p-2.5 ring-1 ring-slate-100">
          <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ring-1 ${tint}`}>
            {initials(r.responsible)}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-xs font-semibold text-slate-700">{r.responsible}</p>
            <p className="truncate text-[11px] text-slate-400">{r.garage || r.waiting_reason || '—'}</p>
          </div>
          <Badge tone={PARTY_TONE[r.party] || 'slate'} className="shrink-0 capitalize">{r.party}</Badge>
        </div>

        {/* footer chips */}
        <div className="mt-auto flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3 text-xs">
          <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600" title="Open / total faults">
            <Icon.Wrench className="h-3 w-3" /> {num(r.active_faults)}/{num(r.fault_total)}
          </span>
          {r.severity_label && <Badge tone={r.severity_tone}>{r.severity_emoji} {r.severity_label}</Badge>}
          {r.waiting_parts && <Badge tone="amber" dot>Parts</Badge>}
          {r.reinspection_required && <Badge tone="violet" dot>Re-insp</Badge>}
          <span className="ml-auto inline-flex items-center gap-1 text-[11px] font-medium text-slate-400">
            <Icon.Clock className="h-3 w-3" /> {days(r.days_in_maintenance)}
          </span>
        </div>
      </div>
    </button>
  );
}
